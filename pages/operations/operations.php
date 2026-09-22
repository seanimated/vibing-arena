<?php
declare(strict_types=1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/api/database.php';

// ── HEADERS ───────────────────────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// ── HELPERS ───────────────────────────────────────────────────────────────────

function json_ok(array $data): never {
    echo json_encode(array_merge(['success' => true], $data));
    exit;
}

function json_error(int $code, string $msg): never {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

function rows(PDO $pdo, string $sql, array $params = []): array {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function scalar(PDO $pdo, string $sql, array $params = []): mixed {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_NUM);
    return $row ? $row[0] : null;
}

function json_body(): ?array {
    $raw = file_get_contents('php://input');
    if (!$raw) return null;
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

function s(array $arr, string $key, string $default = ''): string {
    return isset($arr[$key]) ? trim((string) $arr[$key]) : $default;
}

// ── DB CONNECTION ─────────────────────────────────────────────────────────────
try {
    $pdo = Database::getConnection();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    json_error(503, 'Database connection failed: ' . $e->getMessage());
}

// ── ROUTE ─────────────────────────────────────────────────────────────────────
$method = $_SERVER['REQUEST_METHOD'];
$body   = [];
$action = '';

if ($method === 'GET') {
    $action = s($_GET, 'action', 'list');
} elseif ($method === 'POST') {
    $body   = json_body() ?? [];
    $action = s($body, 'action');
} else {
    json_error(405, 'Method not allowed');
}

try {
    match ($action) {
        'list'        => action_list($pdo),
        'filter_meta' => action_filter_meta($pdo),
        'update'      => action_update($pdo, $body),
        'delete'      => action_delete($pdo, $body),
        'bulk'        => action_bulk($pdo, $body),
        'import'      => action_import($pdo, $body),
        'export'      => action_export($pdo),
        'sources'     => action_sources($pdo),
        'admins'      => action_admins($pdo),
        'push_nts'    => action_push_nts($pdo, $body),
        default       => json_error(400, "Unknown action: $action"),
    };
} catch (PDOException $e) {
    json_error(500, 'Database error: ' . $e->getMessage());
}

// ══════════════════════════════════════════════════════════════════════════════
// HELPER: build WHERE clause from shared filter params
// Province filter checks BOTH rt.province (text) AND rt.states (JSON array)
// ══════════════════════════════════════════════════════════════════════════════
function build_filters(array $get): array
{
    $where  = [];
    $params = [];

    $statusRaw = s($get, 'status');
    $search    = s($get, 'search');
    $country   = s($get, 'country');
    $province  = s($get, 'province');
    $type      = s($get, 'type');
    $sourceId  = s($get, 'source');

    if ($statusRaw === 'Duplicate') {
        $where[] = 'rt.is_duplicate = 1';
    } elseif ($statusRaw === 'Approved') {
        $where[] = "rt.admin_status IN ('Approved','Pushed')";
    } elseif ($statusRaw === 'Pushed') {
        // admin_status flips to 'Pushed' via a best-effort update that can silently
        // no-op (see markRawPushed() in nts_push.php); nts_push_status on
        // published_tenders is the authoritative signal the NTS column itself
        // reads from, so match on either to avoid under-counting.
        $where[] = "(rt.admin_status = 'Pushed' OR pt.nts_push_status = 'Pushed')";
    } elseif ($statusRaw !== '' && $statusRaw !== 'All') {
        $where[]           = 'rt.admin_status = :status';
        $params[':status'] = $statusRaw;
    }

    if ($search !== '') {
        $where[]           = '(rt.title LIKE :search OR rt.reference_number LIKE :search OR rt.issuing_organisation LIKE :search)';
        $params[':search'] = '%' . $search . '%';
    }

    if ($country !== '') {
        $where[]            = "(COALESCE(rt.country,'South Africa') = :country)";
        $params[':country'] = $country;
    }

    if ($province !== '') {
        $where[]                = "(rt.province = :province OR JSON_SEARCH(rt.states, 'one', :province_js) IS NOT NULL)";
        $params[':province']    = $province;
        $params[':province_js'] = $province;
    }

    if ($type !== '') {
        $where[]         = 'rt.tender_type = :type';
        $params[':type'] = $type;
    }

    if ($sourceId !== '') {
        $where[]              = 'rt.source_id = :source_id';
        $params[':source_id'] = (int) $sourceId;
    }

    $clause = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
    return [$clause, $params];
}

// ══════════════════════════════════════════════════════════════════════════════
// ACTION: filter_meta
// Returns distinct countries + province/state values from the actual DB data.
// ══════════════════════════════════════════════════════════════════════════════
function action_filter_meta(PDO $pdo): never
{
    $countryRows = rows($pdo, "
        SELECT DISTINCT COALESCE(NULLIF(TRIM(country),''), 'South Africa') AS country
        FROM raw_tenders
        ORDER BY country ASC
    ");
    $countries = array_column($countryRows, 'country');

    $provinceRows = rows($pdo, "
        SELECT DISTINCT province
        FROM raw_tenders
        WHERE province IS NOT NULL AND TRIM(province) <> ''
        ORDER BY province ASC
    ");
    $textProvinces = array_column($provinceRows, 'province');

    $stateBlobs = rows($pdo, "
        SELECT states FROM raw_tenders
        WHERE states IS NOT NULL AND states <> '[]' AND states <> 'null'
    ");
    $jsonStates = [];
    foreach ($stateBlobs as $blob) {
        $arr = json_decode($blob['states'], true);
        if (is_array($arr)) {
            foreach ($arr as $v) {
                $v = trim((string) $v);
                if ($v !== '' && strtolower($v) !== 'all') {
                    $jsonStates[] = $v;
                }
            }
        }
    }

    $allProvinces = array_values(array_unique(array_merge($textProvinces, $jsonStates)));
    sort($allProvinces);

    $countryProvinceMap = [];

    $cpRows = rows($pdo, "
        SELECT
            COALESCE(NULLIF(TRIM(country),''), 'South Africa') AS country,
            province
        FROM raw_tenders
        WHERE province IS NOT NULL AND TRIM(province) <> ''
    ");
    foreach ($cpRows as $row) {
        $c = $row['country'];
        $p = trim($row['province']);
        if ($p !== '') $countryProvinceMap[$c][] = $p;
    }

    $cpJsonRows = rows($pdo, "
        SELECT COALESCE(NULLIF(TRIM(country),''), 'South Africa') AS country, states
        FROM raw_tenders
        WHERE states IS NOT NULL AND states <> '[]' AND states <> 'null'
    ");
    foreach ($cpJsonRows as $row) {
        $c   = $row['country'];
        $arr = json_decode($row['states'], true);
        if (is_array($arr)) {
            foreach ($arr as $v) {
                $v = trim((string) $v);
                if ($v !== '' && strtolower($v) !== 'all') {
                    $countryProvinceMap[$c][] = $v;
                }
            }
        }
    }

    foreach ($countryProvinceMap as $c => &$list) {
        $list = array_values(array_unique($list));
        sort($list);
    }
    unset($list);

    json_ok([
        'countries'            => $countries,
        'provinces'            => $allProvinces,
        'country_province_map' => $countryProvinceMap,
    ]);
}

// ══════════════════════════════════════════════════════════════════════════════
// ACTION: list
// ══════════════════════════════════════════════════════════════════════════════
function action_list(PDO $pdo): never
{
    $sort    = s($_GET, 'sort', 'scraped_at_desc');
    $page    = max(1, (int) ($_GET['page']     ?? 1));
    $perPage = min(200, max(1, (int) ($_GET['per_page'] ?? 20)));
    $offset  = ($page - 1) * $perPage;

    [$whereClause, $params] = build_filters($_GET);

    $orderBy = match ($sort) {
        'scraped_at_asc'   => 'rt.scraped_at ASC',
        'closing_date_asc' => 'rt.closing_date ASC',
        'title_asc'        => 'rt.title ASC',
        'title_desc'       => 'rt.title DESC',
        default            => 'rt.scraped_at DESC',
    };

    $total = (int) scalar($pdo, "
        SELECT COUNT(*)
        FROM raw_tenders rt
        JOIN scraper_sources ss ON rt.source_id = ss.id
        LEFT JOIN published_tenders pt ON pt.raw_tender_id = rt.id
        $whereClause
    ", $params);

    $dataSql = "
        SELECT
            rt.id,
            rt.admin_status,
            rt.title,
            rt.reference_number,
            rt.issuing_organisation,
            COALESCE(NULLIF(TRIM(rt.country),''), 'South Africa') AS country,
            rt.province,
            rt.states,
            rt.tender_type,
            rt.estimated_value,
            rt.currency,
            rt.closing_date,
            rt.closing_time,
            rt.published_date,
            rt.description,
            rt.contact_name,
            rt.contact_email,
            rt.contact_phone,
            rt.enquiries_email,
            rt.submission_address,
            rt.cidb_grade,
            rt.bbbee_requirement,
            rt.local_content_pct,
            rt.tender_document_url,
            rt.source_id,
            rt.is_duplicate,
            rt.duplicate_of_id,
            rt.assigned_to,
            rt.admin_notes,
            rt.source_url,
            rt.scraped_at,
            rt.reviewed_at,
            rt.reviewed_by,
            rt.bot_source_name,
            ss.name        AS source_name,
            ss.base_url    AS source_base_url,
            (SELECT COUNT(*)
             FROM tender_files tf
             WHERE tf.raw_tender_id = rt.id AND tf.is_active = 1) AS pdf_count,
            pt.id              AS published_id,
            pt.nts_push_status AS nts_push_status,
            pt.nts_uid         AS nts_uid,
            pt.nts_pushed_at   AS nts_pushed_at
        FROM raw_tenders rt
        JOIN scraper_sources ss ON rt.source_id = ss.id
        LEFT JOIN published_tenders pt ON pt.raw_tender_id = rt.id
        $whereClause
        ORDER BY $orderBy
        LIMIT :limit OFFSET :offset
    ";

    $stmt = $pdo->prepare($dataSql);
    foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
    $stmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset,  PDO::PARAM_INT);
    $stmt->execute();
    $tenders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($tenders as &$t) {
        $t['id']           = (int)  $t['id'];
        $t['pdf_count']    = (int)  $t['pdf_count'];
        $t['is_duplicate'] = (bool) $t['is_duplicate'];
        $t['assigned_to']  = $t['assigned_to'] ? (int) $t['assigned_to'] : null;

        // Resolve display source name: bot_source_name → scraper_sources.name
        $t['display_source_name'] = $t['bot_source_name'] ?? $t['source_name'] ?? null;

        // Decode states JSON for frontend use
        $statesDecoded = [];
        if (!empty($t['states'])) {
            $dec = json_decode($t['states'], true);
            if (is_array($dec)) {
                $statesDecoded = array_values(array_filter($dec, fn($v) => strtolower(trim($v)) !== 'all'));
            }
        }
        $t['states_array'] = $statesDecoded;

        // Fill province from first state if province column is empty
        if (empty($t['province']) && !empty($statesDecoded)) {
            $t['province'] = $statesDecoded[0];
        }
    }
    unset($t);

    // Tab counts — admin_status='Pushed' rows also count under Approved
    $countRows = rows($pdo, "
        SELECT admin_status, COUNT(*) AS cnt
        FROM raw_tenders
        GROUP BY admin_status
    ");

    $counts = [
        'All'          => (int) scalar($pdo, "SELECT COUNT(*) FROM raw_tenders"),
        'Pending'      => 0,
        'Under Review' => 0,
        'Approved'     => 0,
        'Rejected'     => 0,
        'Pushed'       => (int) scalar($pdo, "
            SELECT COUNT(DISTINCT rt.id)
            FROM raw_tenders rt
            LEFT JOIN published_tenders pt ON pt.raw_tender_id = rt.id
            WHERE rt.admin_status = 'Pushed' OR pt.nts_push_status = 'Pushed'
        "),
        'Duplicate'    => (int) scalar($pdo, "SELECT COUNT(*) FROM raw_tenders WHERE is_duplicate = 1"),
    ];

    foreach ($countRows as $row) {
        $s = $row['admin_status'];
        $c = (int) $row['cnt'];
        if ($s === 'Pushed') {
            $counts['Approved'] += $c;
        } elseif (isset($counts[$s])) {
            $counts[$s] += $c;
        }
    }

    json_ok([
        'tenders'  => $tenders,
        'total'    => $total,
        'page'     => $page,
        'per_page' => $perPage,
        'counts'   => $counts,
    ]);
}

// ══════════════════════════════════════════════════════════════════════════════
// ACTION: update
// ══════════════════════════════════════════════════════════════════════════════
function action_update(PDO $pdo, array $body): never
{
    $tender = $body['tender'] ?? [];
    $id     = (int) ($tender['id'] ?? 0);
    if ($id <= 0) json_error(400, 'Missing or invalid tender id');

    $allowed = [
        'title'                => 'string',
        'reference_number'     => 'string',
        'issuing_organisation' => 'string',
        'country'              => 'string',
        'province'             => 'string',
        'tender_type'          => 'string',
        'closing_date'         => 'string',
        'estimated_value'      => 'float',
        'currency'             => 'string',
        'description'          => 'string',
        'contact_name'         => 'string',
        'contact_email'        => 'string',
        'contact_phone'        => 'string',
        'cidb_grade'           => 'string',
        'bbbee_requirement'    => 'string',
        'admin_notes'          => 'string',
        'assigned_to'          => 'int',
        'admin_status'         => 'string',
    ];

    $setClauses = [];
    $params     = [':id' => $id];

    foreach ($allowed as $col => $type) {
        if (!array_key_exists($col, $tender)) continue;
        $val = $tender[$col];
        if ($val === '' || $val === null) {
            $val = null;
        } else {
            $val = match ($type) {
                'int'   => (int)   $val,
                'float' => (float) $val,
                default => trim((string) $val),
            };
        }
        if ($col === 'admin_status' && !in_array($val, [
            'Pending', 'Under Review', 'Approved', 'Rejected', 'Duplicate', 'Pushed'
        ], true)) {
            json_error(400, "Invalid admin_status: $val");
        }
        $setClauses[]        = "`$col` = :col_$col";
        $params[":col_$col"] = $val;
    }

    if (!$setClauses) json_error(400, 'No updatable fields provided');

    if (isset($tender['admin_status']) && $tender['admin_status'] === 'Approved') {
        $setClauses[] = '`reviewed_at` = NOW()';
    }

    $sql  = 'UPDATE raw_tenders SET ' . implode(', ', $setClauses) . ' WHERE id = :id';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    if (isset($tender['admin_status']) && $tender['admin_status'] === 'Approved') {
        push_to_published($pdo, $id, $tender);
    }

    json_ok(['affected' => $stmt->rowCount()]);
}

// ══════════════════════════════════════════════════════════════════════════════
// HELPER: upsert into published_tenders when approved
// Now copies all international-relevant fields so nts_push.php never relies
// solely on the LEFT JOIN back to raw_tenders.
//
// Migration required (run once):
// ALTER TABLE published_tenders
//   ADD COLUMN IF NOT EXISTS states              JSON          DEFAULT NULL AFTER province,
//   ADD COLUMN IF NOT EXISTS fields_of_expertise JSON          DEFAULT NULL AFTER tender_type,
//   ADD COLUMN IF NOT EXISTS closing_time        TIME          DEFAULT NULL AFTER closing_date,
//   ADD COLUMN IF NOT EXISTS published_date      DATE          DEFAULT NULL AFTER closing_time,
//   ADD COLUMN IF NOT EXISTS tender_document_url VARCHAR(1000) DEFAULT NULL AFTER source_url,
//   ADD COLUMN IF NOT EXISTS tender_image_url    VARCHAR(1000) DEFAULT NULL AFTER tender_document_url,
//   ADD COLUMN IF NOT EXISTS briefing_json       JSON          DEFAULT NULL,
//   ADD COLUMN IF NOT EXISTS submission_json     JSON          DEFAULT NULL,
//   ADD COLUMN IF NOT EXISTS submission_address  VARCHAR(500)  DEFAULT NULL,
//   ADD COLUMN IF NOT EXISTS bot_source_name     VARCHAR(255)  DEFAULT NULL;
// ══════════════════════════════════════════════════════════════════════════════
function push_to_published(PDO $pdo, int $rawId, array $tender): void
{
    $rawRows = rows($pdo, 'SELECT * FROM raw_tenders WHERE id = :id', [':id' => $rawId]);
    if (!$rawRows) return;
    $r = $rawRows[0];

    // Overlay any fields passed in from the edit form
    foreach ($tender as $k => $v) {
        if ($v !== null && $v !== '') $r[$k] = $v;
    }

    $description = trim((string) ($r['description'] ?? ''));
    if ($description === '') $description = $r['title'];

    $closingDate = $r['closing_date'] ?? null;
    if (!$closingDate) return;

    $country  = !empty($r['country']) ? $r['country'] : 'South Africa';
    $province = $r['province'] ?? null;

    if (empty($province) && !empty($r['states'])) {
        $stArr = json_decode($r['states'], true);
        if (is_array($stArr)) {
            foreach ($stArr as $sv) {
                $sv = trim((string) $sv);
                if ($sv !== '' && strtolower($sv) !== 'all') { $province = $sv; break; }
            }
        }
    }

    $approverId = 1;
    $existing   = scalar($pdo, 'SELECT id FROM published_tenders WHERE raw_tender_id = :rid', [':rid' => $rawId]);

    // Full parameter set — includes all fields needed by nts_push.php
    $p = [
        ':title'     => $r['title'],
        ':ref'       => $r['reference_number']     ?? null,
        ':desc'      => $description,
        ':org'       => $r['issuing_organisation'] ?? null,
        ':country'   => $country,
        ':province'  => $province,
        ':states'    => $r['states']               ?? null,
        ':type'      => $r['tender_type']          ?? null,
        ':expertise' => $r['fields_of_expertise']  ?? null,
        ':val'       => isset($r['estimated_value']) ? (float) $r['estimated_value'] : null,
        ':currency'  => $r['currency']             ?? 'ZAR',
        ':cd'        => $closingDate,
        ':ctime'     => $r['closing_time']         ?? null,
        ':pubdate'   => $r['published_date']       ?? null,
        ':cname'     => $r['contact_name']         ?? null,
        ':cemail'    => $r['contact_email']        ?? null,
        ':cphone'    => $r['contact_phone']        ?? null,
        ':cidb'      => $r['cidb_grade']           ?? null,
        ':bbbee'     => $r['bbbee_requirement']    ?? null,
        ':surl'      => $r['source_url']           ?? null,
        ':docurl'    => $r['tender_document_url']  ?? null,
        ':imgurl'    => $r['tender_image_url']     ?? null,
        ':briefjson' => $r['briefing_json']        ?? null,
        ':subjson'   => $r['submission_json']      ?? null,
        ':subaddr'   => $r['submission_address']   ?? null,
        ':botsrc'    => $r['bot_source_name']      ?? null,
    ];

    if ($existing) {
        $pdo->prepare("
            UPDATE published_tenders SET
                title                = :title,
                reference_number     = :ref,
                description          = :desc,
                issuing_organisation = :org,
                country              = :country,
                province             = :province,
                states               = :states,
                tender_type          = :type,
                fields_of_expertise  = :expertise,
                estimated_value      = :val,
                currency             = :currency,
                closing_date         = :cd,
                closing_time         = :ctime,
                published_date       = :pubdate,
                contact_name         = :cname,
                contact_email        = :cemail,
                contact_phone        = :cphone,
                cidb_grade           = :cidb,
                bbbee_requirement    = :bbbee,
                source_url           = :surl,
                tender_document_url  = :docurl,
                tender_image_url     = :imgurl,
                briefing_json        = :briefjson,
                submission_json      = :subjson,
                submission_address   = :subaddr,
                bot_source_name      = :botsrc,
                status               = 'Active',
                last_edited_at       = NOW()
            WHERE raw_tender_id = :rid
        ")->execute(array_merge($p, [':rid' => $rawId]));
    } else {
        $pdo->prepare("
            INSERT INTO published_tenders
                (raw_tender_id, title, reference_number, description, issuing_organisation,
                 country, province, states, tender_type, fields_of_expertise,
                 estimated_value, currency, closing_date, closing_time, published_date,
                 contact_name, contact_email, contact_phone,
                 cidb_grade, bbbee_requirement,
                 source_url, tender_document_url, tender_image_url,
                 briefing_json, submission_json, submission_address,
                 bot_source_name, approved_by, approved_at, status)
            VALUES
                (:rid, :title, :ref, :desc, :org,
                 :country, :province, :states, :type, :expertise,
                 :val, :currency, :cd, :ctime, :pubdate,
                 :cname, :cemail, :cphone,
                 :cidb, :bbbee,
                 :surl, :docurl, :imgurl,
                 :briefjson, :subjson, :subaddr,
                 :botsrc, :approver, NOW(), 'Active')
        ")->execute(array_merge($p, [':rid' => $rawId, ':approver' => $approverId]));
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// ACTION: delete
// ══════════════════════════════════════════════════════════════════════════════
function action_delete(PDO $pdo, array $body): never
{
    $id = (int) ($body['id'] ?? 0);
    if ($id <= 0) json_error(400, 'Missing or invalid tender id');
    $stmt = $pdo->prepare('DELETE FROM raw_tenders WHERE id = :id');
    $stmt->execute([':id' => $id]);
    if ($stmt->rowCount() === 0) json_error(404, 'Tender not found');
    json_ok(['deleted' => $id]);
}

// ══════════════════════════════════════════════════════════════════════════════
// ACTION: bulk
// ══════════════════════════════════════════════════════════════════════════════
function action_bulk(PDO $pdo, array $body): never
{
    $bulkAction = s($body, 'bulk_action');
    $ids        = array_filter(array_map('intval', (array) ($body['ids'] ?? [])));

    if (!$ids)        json_error(400, 'No tender IDs provided');
    if (!$bulkAction) json_error(400, 'No bulk_action specified');

    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    if ($bulkAction === 'delete') {
        $stmt = $pdo->prepare("DELETE FROM raw_tenders WHERE id IN ($placeholders)");
        $stmt->execute(array_values($ids));
        json_ok(['affected' => $stmt->rowCount()]);
    }

    $statusMap = [
        'approve'      => 'Approved',
        'under_review' => 'Under Review',
        'reject'       => 'Rejected',
    ];

    if (!isset($statusMap[$bulkAction])) json_error(400, "Unknown bulk_action: $bulkAction");

    $newStatus = $statusMap[$bulkAction];

    if ($newStatus === 'Approved') {
        foreach ($ids as $rawId) {
            push_to_published($pdo, $rawId, []);
        }
    }

    $stmt = $pdo->prepare("
        UPDATE raw_tenders
        SET admin_status = ?,
            reviewed_at  = IF(? IN ('Approved','Rejected'), NOW(), reviewed_at)
        WHERE id IN ($placeholders)
    ");
    $stmt->execute(array_merge([$newStatus, $newStatus], array_values($ids)));

    json_ok(['affected' => $stmt->rowCount()]);
}

// ══════════════════════════════════════════════════════════════════════════════
// ACTION: import
// ══════════════════════════════════════════════════════════════════════════════
function action_import(PDO $pdo, array $body): never
{
    $rows = $body['rows'] ?? [];
    if (!is_array($rows) || !$rows) json_error(400, 'No rows to import');

    $defaultSourceId = (int) (scalar($pdo, 'SELECT id FROM scraper_sources LIMIT 1') ?? 1);

    $inserted = 0;
    $skipped  = 0;

    $stmt = $pdo->prepare("
        INSERT INTO raw_tenders
            (source_id, title, reference_number, issuing_organisation,
             country, province, tender_type, closing_date, estimated_value, currency,
             description, contact_email, source_url, admin_status, scraped_at)
        VALUES
            (:source_id, :title, :ref, :org,
             :country, :province, :type, :cd, :val, :currency,
             :desc, :cemail, :surl, 'Pending', NOW())
    ");

    $checkDupe = $pdo->prepare("
        SELECT COUNT(*) FROM raw_tenders
        WHERE source_url = :url OR (title = :title AND closing_date = :cd)
    ");

    foreach ($rows as $row) {
        $title  = trim((string) ($row['title']      ?? ''));
        $srcUrl = trim((string) ($row['source_url'] ?? ''));
        if ($title === '') { $skipped++; continue; }

        $checkDupe->execute([':url' => $srcUrl ?: null, ':title' => $title, ':cd' => $row['closing_date'] ?? null]);
        if ((int) $checkDupe->fetchColumn() > 0) { $skipped++; continue; }

        $closingDate = null;
        if (!empty($row['closing_date'])) {
            $parsed = date_create($row['closing_date']);
            if ($parsed) $closingDate = date_format($parsed, 'Y-m-d');
        }

        $stmt->execute([
            ':source_id' => $defaultSourceId,
            ':title'     => $title,
            ':ref'       => trim((string) ($row['reference_number']     ?? '')) ?: null,
            ':org'       => trim((string) ($row['issuing_organisation'] ?? '')) ?: null,
            ':country'   => trim((string) ($row['country']             ?? '')) ?: null,
            ':province'  => trim((string) ($row['province']            ?? '')) ?: null,
            ':type'      => trim((string) ($row['tender_type']         ?? '')) ?: null,
            ':cd'        => $closingDate,
            ':val'       => is_numeric($row['estimated_value'] ?? '') ? (float) $row['estimated_value'] : null,
            ':currency'  => trim((string) ($row['currency'] ?? '')) ?: 'ZAR',
            ':desc'      => trim((string) ($row['description']         ?? '')) ?: null,
            ':cemail'    => trim((string) ($row['contact_email']       ?? '')) ?: null,
            ':surl'      => $srcUrl ?: null,
        ]);
        $inserted++;
    }

    json_ok(['inserted' => $inserted, 'skipped' => $skipped]);
}

// ══════════════════════════════════════════════════════════════════════════════
// ACTION: export
// ══════════════════════════════════════════════════════════════════════════════
function action_export(PDO $pdo): never
{
    [$whereClause, $params] = build_filters($_GET);

    $tenders = rows($pdo, "
        SELECT
            rt.id,
            rt.title,
            rt.reference_number,
            rt.issuing_organisation,
            COALESCE(NULLIF(TRIM(rt.country),''), 'South Africa') AS country,
            rt.province,
            rt.tender_type,
            rt.estimated_value,
            rt.currency,
            rt.closing_date,
            rt.published_date,
            rt.admin_status,
            rt.is_duplicate,
            rt.cidb_grade,
            rt.bbbee_requirement,
            rt.contact_name,
            rt.contact_email,
            rt.contact_phone,
            rt.source_url,
            rt.description,
            rt.admin_notes,
            rt.scraped_at,
            rt.bot_source_name,
            ss.name AS source_name
        FROM raw_tenders rt
        JOIN scraper_sources ss ON rt.source_id = ss.id
        $whereClause
        ORDER BY rt.scraped_at DESC
        LIMIT 5000
    ", $params);

    json_ok(['tenders' => $tenders]);
}

// ══════════════════════════════════════════════════════════════════════════════
// ACTION: sources
// ══════════════════════════════════════════════════════════════════════════════
function action_sources(PDO $pdo): never
{
    $sources = rows($pdo, "
        SELECT id, name, base_url, is_active, is_verified
        FROM scraper_sources
        ORDER BY is_active DESC, name ASC
    ");
    json_ok(['sources' => $sources]);
}

// ══════════════════════════════════════════════════════════════════════════════
// ACTION: admins
// ══════════════════════════════════════════════════════════════════════════════
function action_admins(PDO $pdo): never
{
    $admins = rows($pdo, "
        SELECT a.id, a.first_name, a.last_name, a.email, r.name AS role_name
        FROM admins a
        JOIN roles r ON a.role_id = r.id
        WHERE a.is_active = 1
        ORDER BY a.first_name ASC, a.last_name ASC
    ");
    json_ok(['admins' => $admins]);
}

// ══════════════════════════════════════════════════════════════════════════════
// ACTION: push_nts
// Handles both direct published_tenders IDs and raw_tender IDs.
// Falls back gracefully when a published_tenders row is missing for an
// already-approved raw tender (re-runs push_to_published, then retries).
// ══════════════════════════════════════════════════════════════════════════════
function action_push_nts(PDO $pdo, array $body): never
{
    require_once $_SERVER['DOCUMENT_ROOT'] . '/api/nts_push.php';

    $ids    = array_filter(array_map('intval', (array) ($body['ids']     ?? [])));
    $rawIds = array_filter(array_map('intval', (array) ($body['raw_ids'] ?? [])));

    if ($rawIds && !$ids) {
        $ph    = implode(',', array_fill(0, count($rawIds), '?'));
        $rows2 = rows($pdo, "
            SELECT pt.id
            FROM published_tenders pt
            JOIN raw_tenders rt ON rt.id = pt.raw_tender_id
            WHERE pt.raw_tender_id IN ($ph)
              AND rt.admin_status IN ('Approved','Pushed')
              AND pt.status = 'Active'
        ", array_values($rawIds));
        $ids = array_column($rows2, 'id');

        if (!$ids) {
            // Diagnose: find which raw IDs exist and what their status is
            $diagPh   = implode(',', array_fill(0, count($rawIds), '?'));
            $diagRows = rows($pdo, "
                SELECT rt.id, rt.title, rt.admin_status, rt.country,
                       (SELECT COUNT(*) FROM published_tenders pt2
                        WHERE pt2.raw_tender_id = rt.id) AS pub_count
                FROM raw_tenders rt
                WHERE rt.id IN ($diagPh)
            ", array_values($rawIds));

            $noPub = array_filter($diagRows, fn($r) =>
                $r['pub_count'] == 0 && in_array($r['admin_status'], ['Approved', 'Pushed'])
            );

            if ($noPub) {
                // Approved but no published_tenders row — re-run push_to_published for each
                foreach ($noPub as $row) {
                    push_to_published($pdo, (int) $row['id'], []);
                }
                // Retry the ID lookup
                $rows2 = rows($pdo, "
                    SELECT pt.id
                    FROM published_tenders pt
                    JOIN raw_tenders rt ON rt.id = pt.raw_tender_id
                    WHERE pt.raw_tender_id IN ($ph)
                      AND rt.admin_status IN ('Approved','Pushed')
                      AND pt.status = 'Active'
                ", array_values($rawIds));
                $ids = array_column($rows2, 'id');
            }

            if (!$ids) {
                $titles     = array_column($diagRows, 'title');
                $countries  = array_unique(array_column($diagRows, 'country'));
                $statusList = array_unique(array_column($diagRows, 'admin_status'));
                json_error(400, sprintf(
                    'Cannot push to NTS: %d tender(s) [%s] from [%s] have status [%s]. Approve them first.',
                    count($rawIds),
                    implode(', ', array_slice($titles, 0, 3)),
                    implode(', ', $countries),
                    implode(', ', $statusList)
                ));
            }
        }
    }

    if (!$ids) {
        $rows2 = rows($pdo, "
            SELECT id FROM published_tenders
            WHERE nts_push_status = 'Pending' AND status = 'Active'
            LIMIT 500
        ");
        $ids = array_column($rows2, 'id');
    }

    if (!$ids) {
        json_error(400, 'No eligible published tenders found. Approve tenders first.');
    }

    $adminId = 1;
    $result  = NtsApi::pushBatch($pdo, $ids, $adminId);
    json_ok($result);
}