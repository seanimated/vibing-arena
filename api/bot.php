<?php
/**
 * ============================================================================
 * SA Tender Portal — Bot Push API
 * File:    api/bot.php   |   Version 2.4 (structured submission/briefing addresses)
 *
 * Changes vs 2.3:
 *   - Captures the full submission / briefing address blocks, not just the
 *     one-line address. New stored columns (raw_tenders):
 *       submission_address_two, submission_address_city,
 *       submission_address_postal_code,
 *       briefing_address_one, briefing_address_two,
 *       briefing_address_city, briefing_address_postal_code
 *     (submission_address remains "Submission Address One"; briefing_address_one
 *      falls back to the legacy briefing_venue value.)
 *   - Each field is read from a flat top-level key OR the nested
 *     submission/submission_details and briefing/briefing_data objects, so both
 *     payload styles work:
 *       flat:  submission_address_two / submissionAddressTwo / …
 *       nested: submission.address_two / submission.city / submission.postal_code
 *               briefing.address_one / briefing.address_city / …
 *   - tender_document_price now defaults to 0 when the bot provides no value
 *     (previously stored NULL).
 *   - api_version bumped to 2.4-addresses.
 *
 * Changes vs 2.2:
 *   - derive_source_name() now covers international portals (UNDP, UNGM,
 *     World Bank, AfDB, UNOPS, IFC, ReliefWeb, etc.) so bot_source_name is
 *     always populated at INSERT time, even when the bot sends no source field.
 *   - Priority order for bot_source_name:
 *       1. Explicit field sent by bot (source / src / source_name)
 *       2. derive_source_name() from source_url   ← expanded in this version
 *       3. scraper_sources.name from header source ID
 *   - read-back stmt now also returns bot_source_name for verification.
 *   - api_version bumped to 2.3-auto-source.
 *
 * Backfill SQL for existing NULL bot_source_name rows:
 *   UPDATE raw_tenders SET bot_source_name = 'eTenders'
 *     WHERE bot_source_name IS NULL AND source_url LIKE '%etenders.gov.za%';
 *   UPDATE raw_tenders SET bot_source_name = 'National Treasury'
 *     WHERE bot_source_name IS NULL AND source_url LIKE '%treasury.gov.za%';
 *   UPDATE raw_tenders SET bot_source_name = 'Gauteng Tenders'
 *     WHERE bot_source_name IS NULL AND source_url LIKE '%tender.gauteng.gov.za%';
 *   UPDATE raw_tenders SET bot_source_name = 'eThekwini Tenders'
 *     WHERE bot_source_name IS NULL AND source_url LIKE '%tenders.durban.gov.za%';
 *   UPDATE raw_tenders SET bot_source_name = 'City of Cape Town'
 *     WHERE bot_source_name IS NULL AND source_url LIKE '%tenders.capetown.gov.za%';
 *   UPDATE raw_tenders SET bot_source_name = 'City of Tshwane'
 *     WHERE bot_source_name IS NULL AND source_url LIKE '%scm.tshwane.gov.za%';
 *   UPDATE raw_tenders SET bot_source_name = 'City of Joburg'
 *     WHERE bot_source_name IS NULL AND source_url LIKE '%bids.johannesburg.gov.za%';
 *   UPDATE raw_tenders SET bot_source_name = 'CIDB'
 *     WHERE bot_source_name IS NULL AND source_url LIKE '%cidb.org.za%';
 *   UPDATE raw_tenders SET bot_source_name = 'Eskom'
 *     WHERE bot_source_name IS NULL AND source_url LIKE '%eskom.co.za%';
 *   UPDATE raw_tenders SET bot_source_name = 'Transnet'
 *     WHERE bot_source_name IS NULL AND source_url LIKE '%transnet.net%';
 *   UPDATE raw_tenders SET bot_source_name = 'SANRAL'
 *     WHERE bot_source_name IS NULL AND source_url LIKE '%sanral.co.za%';
 *   UPDATE raw_tenders SET bot_source_name = 'PRASA'
 *     WHERE bot_source_name IS NULL AND source_url LIKE '%prasa.com%';
 *   UPDATE raw_tenders SET bot_source_name = 'SITA'
 *     WHERE bot_source_name IS NULL AND source_url LIKE '%sita.co.za%';
 *   UPDATE raw_tenders SET bot_source_name = 'SARS'
 *     WHERE bot_source_name IS NULL AND source_url LIKE '%sars.gov.za%';
 *   UPDATE raw_tenders SET bot_source_name = 'DBSA'
 *     WHERE bot_source_name IS NULL AND source_url LIKE '%dbsa.org%';
 *   -- International portals --
 *   UPDATE raw_tenders SET bot_source_name = 'UNDP Procurement Notices'
 *     WHERE bot_source_name IS NULL AND source_url LIKE '%procurement-notices.undp.org%';
 *   UPDATE raw_tenders SET bot_source_name = 'UNGM'
 *     WHERE bot_source_name IS NULL AND source_url LIKE '%ungm.org%';
 *   UPDATE raw_tenders SET bot_source_name = 'World Bank'
 *     WHERE bot_source_name IS NULL AND source_url LIKE '%worldbank.org%';
 *   UPDATE raw_tenders SET bot_source_name = 'African Development Bank'
 *     WHERE bot_source_name IS NULL AND source_url LIKE '%afdb.org%';
 *   UPDATE raw_tenders SET bot_source_name = 'UNOPS'
 *     WHERE bot_source_name IS NULL AND source_url LIKE '%unops.org%';
 *   UPDATE raw_tenders SET bot_source_name = 'IFC'
 *     WHERE bot_source_name IS NULL AND source_url LIKE '%ifc.org%';
 *   UPDATE raw_tenders SET bot_source_name = 'ReliefWeb'
 *     WHERE bot_source_name IS NULL AND source_url LIKE '%reliefweb.int%';
 *   UPDATE raw_tenders SET bot_source_name = 'Devex'
 *     WHERE bot_source_name IS NULL AND source_url LIKE '%devex.com%';
 * ============================================================================
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// ─── CONFIGURATION ────────────────────────────────────────────────────────────

define('BOT_API_KEYS', [
    '4f9b1e8a7f3c2d1e5a6b9c8d7e0f123456789abcdef123456789abcdef1234' => 'Primary Bot (Remote VPS)',
]);

define('MAX_BATCH_SIZE',        50);
define('RATE_LIMIT_PER_MINUTE', 60);
define('BOT_LOG_FILE',          __DIR__ . '/../logs/bot_api.log');
define('DEBUG_MODE',            true);

// ─── HEADERS ─────────────────────────────────────────────────────────────────

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Bot-API-Key, X-Bot-Source-Id, X-Bot-Job-Uid');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ─── HELPERS ─────────────────────────────────────────────────────────────────

function ok(mixed $data, string $msg = 'OK'): never {
    echo json_encode([
        'success'   => true,
        'message'   => $msg,
        'data'      => $data,
        'timestamp' => date('c'),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function fail(int $code, string $msg, array $extra = []): never {
    http_response_code($code);
    echo json_encode(array_merge([
        'success'   => false,
        'error'     => $msg,
        'timestamp' => date('c'),
    ], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

function log_error(string $msg, array $context = []): void {
    $dir = dirname(BOT_LOG_FILE);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $ctx  = !empty($context) ? ' | ' . json_encode($context) : '';
    $line = '[' . date('Y-m-d H:i:s') . '] [BOT_API] ' . $msg . $ctx . PHP_EOL;
    file_put_contents(BOT_LOG_FILE, $line, FILE_APPEND | LOCK_EX);
    if (DEBUG_MODE) error_log($msg . $ctx);
}

function sanitize(mixed $v, int $maxLen = 0): string {
    if ($v === null) return '';
    $s = trim((string)$v);
    if ($maxLen > 0 && strlen($s) > $maxLen) $s = substr($s, 0, $maxLen);
    return $s;
}

function get_db(): PDO {
    require_once __DIR__ . '/database.php';
    return Database::getConnection();
}

function safe_get(array $arr, string|array $keys, $default = null) {
    if (!is_array($arr)) return $default;
    $keys = is_array($keys) ? $keys : [$keys];
    foreach ($keys as $key) {
        if (isset($arr[$key])) return $arr[$key];
    }
    return $default;
}

function safe_json_encode($data): ?string {
    if ($data === null) return null;
    if (is_string($data)) {
        $decoded = json_decode($data, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) return $data;
        return null;
    }
    if (!is_array($data) || empty($data)) return null;
    $encoded = json_encode($data);
    return $encoded !== false ? $encoded : null;
}

function parse_date($raw): ?string {
    if (empty($raw)) return null;
    $ts = is_numeric($raw) ? (int)$raw : strtotime((string)$raw);
    return $ts !== false && $ts > 0 ? date('Y-m-d', $ts) : null;
}

function parse_time($raw): ?string {
    if (empty($raw)) return null;
    $ts = is_numeric($raw) ? (int)$raw : strtotime((string)$raw);
    return $ts !== false && $ts > 0 ? date('H:i:s', $ts) : null;
}

/**
 * Derive a friendly display name from a tender's source URL.
 *
 * This runs at INSERT time so every raw_tenders row always has a meaningful
 * bot_source_name stored permanently — no runtime URL parsing is needed
 * anywhere downstream (operations.php, nts_push.php, etc.).
 *
 * Priority in handle_push_tender():
 *   1. Explicit field sent by the bot  (source / src / source_name)
 *   2. This function called on source_url          ← covers UNDP + others
 *   3. scraper_sources.name from the X-Bot-Source-Id header
 */
function derive_source_name(string $url): string {
    if (!$url) return '';

    $host = strtolower(parse_url($url, PHP_URL_HOST) ?: '');
    if (!$host) return '';

    // Strip common subdomains that don't identify the portal
    $host = preg_replace('/^(www\.|m\.|portal\.|procurement\.)/', '', $host);

    $overrides = [
        // ── South African government portals ─────────────────────────────────
        'etenders.gov.za'                 => 'eTenders',
        'peims.treasury.gov.za'           => 'National Treasury',
        'treasury.gov.za'                 => 'National Treasury',
        'cidb.org.za'                     => 'CIDB',
        'tender.gauteng.gov.za'           => 'Gauteng Tenders',
        'tenders.durban.gov.za'           => 'eThekwini Tenders',
        'tenders.capetown.gov.za'         => 'City of Cape Town',
        'scm.tshwane.gov.za'              => 'City of Tshwane',
        'bids.johannesburg.gov.za'        => 'City of Joburg',
        'tenders.nelsonmandelabay.gov.za' => 'Nelson Mandela Bay',
        'tenders.buffalocity.gov.za'      => 'Buffalo City',
        'tenders.mangaung.co.za'          => 'Mangaung',
        'tenders.ekurhuleni.gov.za'       => 'Ekurhuleni',
        'tenders.dhet.gov.za'             => 'DHET',
        'tenders.dpsa.gov.za'             => 'DPSA',
        'tenders.health.gov.za'           => 'Dept of Health',
        'supplychain.westerncape.gov.za'  => 'Western Cape SCM',
        'tenders.kzndoh.gov.za'           => 'KZN Health',

        // ── South African SOEs and agencies ──────────────────────────────────
        'eskom.co.za'                     => 'Eskom',
        'transnet.net'                    => 'Transnet',
        'sanral.co.za'                    => 'SANRAL',
        'prasa.com'                       => 'PRASA',
        'sita.co.za'                      => 'SITA',
        'sars.gov.za'                     => 'SARS',
        'dbsa.org'                        => 'DBSA',
        'tenderlink.com'                  => 'TenderLink',

        // ── UN system and multilateral development banks ──────────────────────
        // These are the portals whose tenders appear as "international" in the
        // SA Tender Portal. Without explicit entries here, derive_source_name()
        // would fall through to the generic TLD-strip logic and produce names
        // like "Notices Undp" instead of "UNDP Procurement Notices".
        'notices.undp.org'                => 'UNDP Procurement Notices',
        'procurement-notices.undp.org'    => 'UNDP Procurement Notices',
        'undp.org'                        => 'UNDP',
        'ungm.org'                        => 'UNGM',
        'unops.org'                       => 'UNOPS',
        'ifc.org'                         => 'IFC',
        'worldbank.org'                   => 'World Bank',
        'projects.worldbank.org'          => 'World Bank',
        'afdb.org'                        => 'African Development Bank',
        'iadb.org'                        => 'Inter-American Development Bank',
        'adb.org'                         => 'Asian Development Bank',
        'isdb.org'                        => 'Islamic Development Bank',
        'ebrd.com'                        => 'EBRD',
        'eib.org'                         => 'European Investment Bank',
        'unhcr.org'                       => 'UNHCR',
        'unicef.org'                      => 'UNICEF',
        'wfp.org'                         => 'WFP',
        'who.int'                         => 'WHO',
        'ilo.org'                         => 'ILO',
        'fao.org'                         => 'FAO',
        'unhabitat.org'                   => 'UN-Habitat',
        'unodc.org'                       => 'UNODC',

        // ── International tender aggregators ─────────────────────────────────
        'devex.com'                       => 'Devex',
        'reliefweb.int'                   => 'ReliefWeb',
        'tendersinfo.com'                 => 'TendersInfo',
        'africatenders.com'               => 'Africa Tenders',
        'dgmarket.com'                    => 'dgMarket',
        'bidsaward.com'                   => 'BidsAward',
        'merx.com'                        => 'MERX',
    ];

    // Exact host match or suffix match (handles any subdomain of a known domain)
    foreach ($overrides as $pattern => $label) {
        if ($host === $pattern || str_ends_with($host, '.' . $pattern)) {
            return $label;
        }
    }

    // Generic fallback: strip common TLD segments and title-case the remainder.
    // e.g. "tenders.somemunicipality.gov.za" → "Somemunicipality"
    $tlds     = ['za','co','gov','org','net','com','ac','edu','io',
                 'ng','ke','rw','tz','ug','mw','zw','bw','na','sz','ls',
                 'int','un','eu'];
    $acronyms = ['etenders','cidb','sita','sars','sassa','prasa','sanral',
                 'nts','dti','dpsa','dhet','dbsa','undp','ungm','unops',
                 'ifc','afdb','iadb','adb','isdb','ebrd','eib','ilo',
                 'fao','who','wfp'];

    $parts = explode('.', $host);
    while (count($parts) > 1 && in_array(end($parts), $tlds, true)) {
        array_pop($parts);
    }

    $result = implode(' ', array_filter(
        array_map(function (string $p) use ($acronyms): string {
            if (strlen($p) <= 1) return '';
            return in_array(strtolower($p), $acronyms, true)
                ? strtoupper($p)
                : ucfirst($p);
        }, $parts)
    ));

    return $result;
}

// ─── RATE LIMITING ────────────────────────────────────────────────────────────

function check_rate_limit(string $ip): void {
    if (RATE_LIMIT_PER_MINUTE <= 0) return;
    $key    = 'bot_rl_' . md5($ip);
    $now    = time();
    $window = 60;
    if (!isset($_SESSION)) @session_start();
    $entry = $_SESSION[$key] ?? ['count' => 0, 'window_start' => $now];
    if (($now - $entry['window_start']) >= $window) $entry = ['count' => 0, 'window_start' => $now];
    $entry['count']++;
    $_SESSION[$key] = $entry;
    if ($entry['count'] > RATE_LIMIT_PER_MINUTE) {
        fail(429, 'Rate limit exceeded. Max ' . RATE_LIMIT_PER_MINUTE . ' requests/minute.');
    }
}

// ─── AUTHENTICATION ───────────────────────────────────────────────────────────

function authenticate(): string {
    $key = $_SERVER['HTTP_X_BOT_API_KEY'] ?? '';
    if (empty($key)) fail(401, 'Missing authentication header: X-Bot-API-Key');
    if (empty(BOT_API_KEYS)) fail(503, 'Bot API not configured.');

    $label = '';
    foreach (BOT_API_KEYS as $k => $l) {
        if (hash_equals($k, $key)) { $label = $l; break; }
    }
    if (!$label) {
        log_error("Invalid API key attempt", ['ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown']);
        fail(403, 'Invalid API key.');
    }
    return $label;
}

function get_source_id(): int {
    $id = (int)($_SERVER['HTTP_X_BOT_SOURCE_ID'] ?? 0);
    if ($id <= 0) fail(400, 'Missing or invalid header: X-Bot-Source-Id');
    return $id;
}

function validate_source(PDO $db, int $sourceId): array {
    $stmt = $db->prepare("SELECT id, name, is_active FROM scraper_sources WHERE id = ?");
    $stmt->execute([$sourceId]);
    $source = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$source) fail(404, "Scraper source #$sourceId not found.");
    if (!(bool)$source['is_active']) fail(403, "Scraper source #$sourceId is disabled.");
    return $source;
}

function compute_hash(string $title, string $desc): string {
    return hash('sha256', mb_strtolower(trim($title)) . '|' . mb_strtolower(trim($desc)));
}

// ════════════════════════════════════════════════════════════════════════════
// MAIN ROUTER
// ════════════════════════════════════════════════════════════════════════════

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail(405, 'Only POST requests are accepted.');

$body        = [];
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (str_contains($contentType, 'application/json')) {
    $raw  = file_get_contents('php://input');
    $body = json_decode($raw, true) ?? [];
    if (json_last_error() !== JSON_ERROR_NONE) {
        log_error("Invalid JSON", ['error' => json_last_error_msg(), 'raw' => substr($raw, 0, 500)]);
        fail(400, 'Invalid JSON payload');
    }
}

$action = sanitize($_POST['action'] ?? $body['action'] ?? '');

$ip       = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
check_rate_limit($ip);
$botLabel = authenticate();

try {
    $db = get_db();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Throwable $e) {
    log_error("DB connect failed", ['error' => $e->getMessage()]);
    fail(500, 'Database connection failed.');
}

try {
    match ($action) {
        'ping'        => handle_ping($db, $botLabel),
        'get_sources' => handle_get_sources($db, $botLabel),
        'job_start'   => handle_job_start($db, $botLabel),
        'job_finish'  => handle_job_finish($db, $botLabel),
        'push_tender' => handle_push_tender($db, $botLabel),
        default       => fail(400, "Unknown action: '$action'"),
    };
} catch (Throwable $e) {
    log_error("Handler error", [
        'action' => $action,
        'error'  => $e->getMessage(),
        'file'   => $e->getFile(),
        'line'   => $e->getLine(),
    ]);
    fail(500, 'Internal server error: ' . (DEBUG_MODE ? $e->getMessage() : 'Please check logs'));
}

// ════════════════════════════════════════════════════════════════════════════
// HANDLERS
// ════════════════════════════════════════════════════════════════════════════

function handle_ping(PDO $db, string $botLabel): never {
    $setting = $db->query("SELECT setting_value FROM system_settings WHERE setting_key = 'scraper_enabled'")->fetchColumn();
    ok([
        'bot_label'       => $botLabel,
        'server_time'     => date('c'),
        'scraper_enabled' => ($setting === 'true'),
        'api_version'     => '2.4-addresses',
        'php_version'     => PHP_VERSION,
    ], 'Pong');
}

function handle_get_sources(PDO $db, string $botLabel): never {
    $rows = $db->query("
        SELECT id, name, base_url, tender_list_url, scraper_type,
               request_interval_s, last_scraped_at, scrape_count
        FROM scraper_sources
        WHERE is_active = TRUE
        ORDER BY name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
    ok($rows, count($rows) . ' active source(s) returned');
}

function handle_job_start(PDO $db, string $botLabel): never {
    $sourceId    = get_source_id();
    $source      = validate_source($db, $sourceId);
    $triggeredBy = sanitize($_POST['triggered_by'] ?? 'cron');
    if (!in_array($triggeredBy, ['cron', 'manual', 'webhook'], true)) $triggeredBy = 'cron';
    $userAgent = sanitize($_POST['user_agent'] ?? $_SERVER['HTTP_USER_AGENT'] ?? '', 255);
    $jobUid    = bin2hex(random_bytes(16));

    $stmt = $db->prepare("
        INSERT INTO scraper_jobs
            (source_id, job_uid, status, triggered_by, started_at, user_agent, ip_used)
        VALUES (?, ?, 'Running', ?, NOW(), ?, ?)
    ");
    $stmt->execute([$sourceId, $jobUid, $triggeredBy, $userAgent, $_SERVER['REMOTE_ADDR'] ?? null]);

    ok([
        'job_uid'     => $jobUid,
        'job_id'      => (int)$db->lastInsertId(),
        'source_name' => $source['name'],
    ], "Job registered.");
}

function handle_job_finish(PDO $db, string $botLabel): never {
    $sourceId = get_source_id();
    validate_source($db, $sourceId);

    $jobUid = sanitize($_SERVER['HTTP_X_BOT_JOB_UID'] ?? $_POST['job_uid'] ?? '');
    if (empty($jobUid)) fail(400, 'Missing job_uid');

    $stmt = $db->prepare("SELECT id, status FROM scraper_jobs WHERE job_uid = ? AND source_id = ?");
    $stmt->execute([$jobUid, $sourceId]);
    $job = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$job) fail(404, "Job '$jobUid' not found");

    // Idempotent: finishing an already-closed job is a no-op, not an error.
    if ($job['status'] !== 'Running') {
        ok(['job_uid' => $jobUid, 'status' => $job['status']],
           "Job already {$job['status']} — no action taken.");
    }

    $status = sanitize($_POST['status'] ?? 'Completed');
    if (!in_array($status, ['Completed', 'Failed', 'Cancelled'], true)) $status = 'Completed';

    $pagesScraped   = max(0, (int)($_POST['pages_scraped']   ?? 0));
    $tendersFound   = max(0, (int)($_POST['tenders_found']   ?? 0));
    $tendersNew     = max(0, (int)($_POST['tenders_new']     ?? 0));
    $tendersUpdated = max(0, (int)($_POST['tenders_updated'] ?? 0));
    $pdfsDownloaded = max(0, (int)($_POST['pdfs_downloaded'] ?? 0));
    $errorCount     = max(0, (int)($_POST['error_count']     ?? 0));
    $errorLog       = sanitize($_POST['error_log'] ?? '');

    $duration = (int)$db->query(
        "SELECT TIMESTAMPDIFF(SECOND, started_at, NOW()) FROM scraper_jobs WHERE id = {$job['id']}"
    )->fetchColumn();

    // GREATEST() so per-push counts aren't zeroed if the bot omits them.
    $db->prepare("
        UPDATE scraper_jobs SET
            status = ?, finished_at = NOW(), duration_seconds = ?,
            pages_scraped   = GREATEST(pages_scraped, ?),
            tenders_found   = GREATEST(tenders_found, ?),
            tenders_new     = GREATEST(tenders_new, ?),
            tenders_updated = GREATEST(tenders_updated, ?),
            pdfs_downloaded = GREATEST(pdfs_downloaded, ?),
            error_count = ?, error_log = ?
        WHERE id = ?
    ")->execute([$status, $duration, $pagesScraped, $tendersFound, $tendersNew,
                 $tendersUpdated, $pdfsDownloaded, $errorCount, $errorLog ?: null, $job['id']]);

    ok([
        'job_uid'          => $jobUid,
        'status'           => $status,
        'duration_seconds' => $duration,
        'tenders_new'      => $tendersNew,
    ], "Job marked as $status.");
}

function handle_push_tender(PDO $db, string $botLabel): never {
    $sourceId = get_source_id();
    $source   = validate_source($db, $sourceId);
    $jobUid   = sanitize($_SERVER['HTTP_X_BOT_JOB_UID'] ?? $_POST['job_uid'] ?? '');

    $jobId = null;
    if ($jobUid) {
        $stmt = $db->prepare("SELECT id FROM scraper_jobs WHERE job_uid = ? AND source_id = ?");
        $stmt->execute([$jobUid, $sourceId]);
        $jobRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($jobRow) $jobId = (int)$jobRow['id'];
    }

    global $body;
    $tendersRaw = $_POST['tenders'] ?? $body['tenders'] ?? null;

    if ($tendersRaw !== null) {
        if (is_string($tendersRaw)) {
            $tenders = json_decode($tendersRaw, true);
            if (!is_array($tenders)) fail(400, "'tenders' must be a valid JSON array.");
        } else {
            $tenders = $tendersRaw;
        }
    } else {
        $tenders = [array_merge($_POST, $body)];
    }

    if (!is_array($tenders) || empty($tenders)) fail(400, "No tenders provided.");
    if (count($tenders) > MAX_BATCH_SIZE) fail(400, "Batch too large. Max " . MAX_BATCH_SIZE . " tenders.");

    $inserted   = 0;
    $duplicates = 0;
    $errors     = 0;
    $results    = [];

    $insertStmt = $db->prepare("
        INSERT INTO raw_tenders (
            scraper_job_id, source_id, source_tender_id, source_url, bot_source_name, content_hash,
            title, reference_number, description, issuing_organisation,
            country, province, states,
            tender_type, fields_of_expertise, estimated_value, currency,
            closing_date, closing_time, published_date,
            compulsory_briefing, briefing_date, briefing_venue, briefing_json,
            briefing_address_one, briefing_address_two,
            briefing_address_city, briefing_address_postal_code,
            contact_name, contact_email, contact_phone, enquiries_email,
            submission_address, submission_json,
            submission_address_two, submission_address_city,
            submission_address_postal_code,
            cidb_grade, bbbee_requirement, local_content_pct,
            tender_document_url, tender_document_price, tender_image_url,
            raw_html_snippet, admin_status, scraped_at
        ) VALUES (
            :job_id, :source_id, :source_tender_id, :source_url, :bot_source_name, :content_hash,
            :title, :reference_number, :description, :issuing_organisation,
            :country, :province, :states,
            :tender_type, :fields_of_expertise, :estimated_value, :currency,
            :closing_date, :closing_time, :published_date,
            :compulsory_briefing, :briefing_date, :briefing_venue, :briefing_json,
            :briefing_address_one, :briefing_address_two,
            :briefing_address_city, :briefing_address_postal_code,
            :contact_name, :contact_email, :contact_phone, :enquiries_email,
            :submission_address, :submission_json,
            :submission_address_two, :submission_address_city,
            :submission_address_postal_code,
            :cidb_grade, :bbbee_requirement, :local_content_pct,
            :tender_document_url, :tender_document_price, :tender_image_url,
            :raw_html_snippet, 'Pending', NOW()
        )
    ");

    // Read-back now includes bot_source_name so the caller can verify what was stored
    $readBackStmt   = $db->prepare("SELECT country, province, states, bot_source_name FROM raw_tenders WHERE id = ?");
    $dupCheckById   = $db->prepare("SELECT id FROM raw_tenders WHERE source_id = ? AND source_tender_id = ?");
    $dupCheckByHash = $db->prepare("SELECT id FROM raw_tenders WHERE content_hash = ?");

    foreach ($tenders as $idx => $t) {
        try {
            if (!is_array($t)) {
                $results[] = ['index' => $idx, 'status' => 'error', 'reason' => 'Invalid tender data'];
                $errors++;
                continue;
            }

            $title     = sanitize(safe_get($t, 'title'), 1000);
            $sourceUrl = sanitize(safe_get($t, ['source_url', 'tender_document_url', 'url']), 1000);

            if (empty($title))     { $results[] = ['index'=>$idx,'status'=>'error','reason'=>'title is required'];      $errors++; continue; }
            if (empty($sourceUrl)) { $results[] = ['index'=>$idx,'status'=>'error','reason'=>'source_url is required']; $errors++; continue; }

            // ── BOT SOURCE NAME ───────────────────────────────────────────────
            // Priority 1: explicit field the bot sends (source / src / source_name)
            // Priority 2: derived from source_url at insert time — stored permanently
            //             so downstream code (nts_push.php) never needs to re-parse URLs
            // Priority 3: scraper_sources.name from the X-Bot-Source-Id header
            $botSourceName = sanitize(safe_get($t, ['source', 'src', 'source_name']), 255) ?: null;
            if (empty($botSourceName)) {
                $derived = derive_source_name($sourceUrl);
                $botSourceName = $derived ?: null;
            }
            if (empty($botSourceName)) {
                $botSourceName = $source['name'] ?: null;
            }
            // ── END BOT SOURCE NAME ───────────────────────────────────────────

            $sourceTenderId = sanitize(safe_get($t, ['source_tender_id', 'number', 'id']), 255);
            $description    = sanitize(safe_get($t, 'description'), 65535);
            $hash           = compute_hash($title, $description);

            // Duplicate check
            $isDuplicate = false; $existingId = null;
            if ($sourceTenderId) {
                $dupCheckById->execute([$sourceId, $sourceTenderId]);
                $existingId  = $dupCheckById->fetchColumn();
                $isDuplicate = (bool)$existingId;
            }
            if (!$isDuplicate) {
                $dupCheckByHash->execute([$hash]);
                $existingId  = $dupCheckByHash->fetchColumn();
                $isDuplicate = (bool)$existingId;
            }
            if ($isDuplicate) {
                $results[] = ['index'=>$idx,'status'=>'duplicate','existing_id'=>(int)$existingId,'title'=>$title];
                $duplicates++;
                continue;
            }

            // Dates
            $closingRaw  = safe_get($t, ['closing_date', 'closingDate', 'deadline', 'end_date']);
            $closingDate = parse_date($closingRaw);
            $closingTime = parse_time($closingRaw) ?: parse_time(safe_get($t, ['closing_time', 'closingTime']));
            $pubDate     = parse_date(safe_get($t, ['published_date', 'publishedDate', 'posted_date', 'postedDate', 'publish_date']));

            // Briefing
            $briefingObj = safe_get($t, ['briefing', 'briefing_data']);
            if (!is_array($briefingObj)) $briefingObj = [];
            $briefingDate  = parse_date(safe_get($t, ['briefing_date', 'briefingDate']) ?: safe_get($briefingObj, 'date'));
            $briefingVenue = sanitize(safe_get($t, ['briefing_venue', 'briefingVenue']) ?: safe_get($briefingObj, 'venue'), 500) ?: null;
            $compulsoryRaw = safe_get($t, ['compulsory_briefing', 'compulsoryBriefing', 'isCompulsory']);
            if (is_array($compulsoryRaw)) $compulsoryRaw = safe_get($compulsoryRaw, 'is_compulsory');
            $compulsory = !empty($compulsoryRaw) ? 1 : 0;
            if (!$compulsory && isset($briefingObj['briefing_compulsory'])) $compulsory = $briefingObj['briefing_compulsory'] ? 1 : 0;
            if (!$compulsory && isset($briefingObj['is_compulsory']))       $compulsory = $briefingObj['is_compulsory'] ? 1 : 0;

            // Briefing structured address (flat fields or nested briefing object)
            // Briefing Address One falls back to the legacy briefing_venue value.
            $briefingAddrOne = sanitize(
                safe_get($t, ['briefing_address_one', 'briefingAddressOne', 'briefing_address_1'])
                    ?: safe_get($briefingObj, ['address_one', 'address', 'address1', 'address_line_one', 'venue']),
                500
            ) ?: null;
            if (empty($briefingAddrOne)) $briefingAddrOne = $briefingVenue;
            $briefingAddrTwo = sanitize(
                safe_get($t, ['briefing_address_two', 'briefingAddressTwo', 'briefing_address_2'])
                    ?: safe_get($briefingObj, ['address_two', 'address2', 'address_line_two']),
                500
            ) ?: null;
            $briefingAddrCity = sanitize(
                safe_get($t, ['briefing_address_city', 'briefingAddressCity', 'briefing_city', 'briefingCity'])
                    ?: safe_get($briefingObj, ['address_city', 'city']),
                255
            ) ?: null;
            $briefingAddrPostal = sanitize(
                safe_get($t, ['briefing_address_postal_code', 'briefingAddressPostalCode', 'briefing_postal_code', 'briefing_postalcode', 'briefingPostalCode'])
                    ?: safe_get($briefingObj, ['address_postal_code', 'postal_code', 'postalcode', 'zip', 'zip_code']),
                20
            ) ?: null;

            // Numeric fields
            $estValue = null;
            $estRaw   = safe_get($t, ['estimated_value', 'estimatedValue', 'value', 'budget']);
            if (is_numeric($estRaw)) {
                $estValue = round((float)$estRaw, 2);
            } elseif (is_string($estRaw) && preg_match('/[\d,]+/', $estRaw, $m)) {
                $c = str_replace(',', '', $m[0]);
                if (is_numeric($c)) $estValue = round((float)$c, 2);
            }
            $localPct = null;
            $localRaw = safe_get($t, ['local_content_pct', 'localContentPct', 'local_content']);
            if (is_numeric($localRaw)) $localPct = round((float)$localRaw, 2);
            $docPrice = 0; // 0 by default unless the scraper returns a value
            $priceRaw = safe_get($t, ['tender_document_price', 'tenderDocumentPrice', 'document_price', 'fee']);
            if (is_numeric($priceRaw)) $docPrice = round((float)$priceRaw, 2);

            // Contact
            $contactPerson = safe_get($t, ['contact_person', 'contact']);
            if (!is_array($contactPerson)) $contactPerson = [];
            $contactName  = sanitize(safe_get($t, ['contact_name', 'contactName'])  ?: safe_get($contactPerson, ['name', 'full_name']), 255) ?: null;
            $contactEmail = sanitize(safe_get($t, ['contact_email', 'contactEmail']) ?: safe_get($contactPerson, 'email'), 255) ?: null;
            $contactPhone = sanitize(safe_get($t, ['contact_phone', 'contactPhone', 'phone']) ?: safe_get($contactPerson, ['phone', 'number']), 50) ?: null;

            // ── COUNTRY + STATES/PROVINCE ─────────────────────────────────────
            $countryName = sanitize(safe_get($t, ['country', 'countryName']), 100) ?: null;
            $statesRaw   = safe_get($t, ['states', 'provinces', 'locations']);
            if (!is_array($statesRaw)) $statesRaw = [];

            // Parse nested countries[] array the bot sends
            $countriesArr = safe_get($t, 'countries');
            if (is_array($countriesArr) && !empty($countriesArr)) {
                if (!$countryName) $countryName = sanitize(safe_get($countriesArr[0], 'name'), 100) ?: null;
                foreach ($countriesArr as $cy) {
                    $cs = safe_get($cy, 'states');
                    if (is_array($cs)) foreach ($cs as $sv) {
                        $sv = trim((string)$sv);
                        if ($sv !== '' && strtolower($sv) !== 'all') $statesRaw[] = $sv;
                    }
                }
            }
            // Supplement from submission/briefing address_countries
            foreach (['submission', 'briefing'] as $block) {
                $blk = safe_get($t, $block);
                if (!is_array($blk)) continue;
                $ac = safe_get($blk, 'address_countries');
                if (!is_array($ac)) continue;
                foreach ($ac as $acc) {
                    $accStates = safe_get($acc, 'states');
                    if (is_array($accStates)) foreach ($accStates as $sv) {
                        $sv = trim((string)$sv);
                        if ($sv !== '' && strtolower($sv) !== 'all') $statesRaw[] = $sv;
                    }
                }
            }
            $statesJson = null;
            if (!empty($statesRaw)) {
                $sf = array_values(array_unique(array_filter(
                    array_map('strval', $statesRaw),
                    fn($v) => trim($v) !== ''
                )));
                if (!empty($sf)) $statesJson = json_encode($sf);
            }
            $province = sanitize(safe_get($t, 'province'), 100);
            if (empty($province) && $statesJson) {
                $sd = json_decode($statesJson, true);
                if (!empty($sd[0])) $province = sanitize($sd[0], 100);
            }
            $province = $province ?: null;
            if (empty($statesJson) && !empty($province)) $statesJson = json_encode([$province]);
            if (empty($countryName)) $countryName = 'South Africa';
            // ── END COUNTRY + STATES ──────────────────────────────────────────

            // Fields of expertise
            $expertiseRaw = safe_get($t, ['fields_of_expertise', 'expertise', 'categories', 'category', 'sectors']);
            if (is_string($expertiseRaw) && $expertiseRaw !== '') $expertiseRaw = [$expertiseRaw];
            $expertiseJson = null;
            if (is_array($expertiseRaw) && !empty($expertiseRaw)) {
                $ef = array_values(array_filter(array_map('strval', $expertiseRaw)));
                if (!empty($ef)) $expertiseJson = safe_json_encode($ef);
            }

            // Misc string fields
            $issuingOrg = sanitize(safe_get($t, ['issuing_organisation', 'issuingOrganisation', 'tender_client', 'client', 'organization']), 500) ?: null;
            $refNumber  = sanitize(safe_get($t, ['reference_number', 'referenceNumber', 'number', 'tenderNumber']), 255) ?: null;
            $tenderType = sanitize(safe_get($t, ['tender_type', 'tenderType', 'type']), 100) ?: null;
            if ($tenderType === 'RFQ') $tenderType = 'Request For Quote';
            if ($tenderType === 'RFI') $tenderType = 'Request For Information';
            if ($tenderType === 'RFP') $tenderType = 'Request For Proposal';
            $docUrl  = sanitize(safe_get($t, ['tender_document_url', 'tenderDocumentUrl', 'tender_document', 'document_url', 'pdf_url']), 1000) ?: null;
            $imgUrl  = sanitize(safe_get($t, ['tender_image_url', 'tenderImageUrl', 'tender_image', 'image_url']), 1000) ?: null;

            $submissionObj  = safe_get($t, ['submission', 'submission_details']);
            $submissionJson = is_array($submissionObj) ? safe_json_encode($submissionObj) : null;
            $submissionAddr = sanitize(
                safe_get($t, ['submission_address', 'submissionAddress', 'address'])
                    ?: (is_array($submissionObj) ? safe_get($submissionObj, ['address', 'address_one']) : null),
                500
            ) ?: null;
            // Submission Address Two / City / Postal Code (flat fields or nested object)
            $submissionAddrTwo = sanitize(
                safe_get($t, ['submission_address_two', 'submissionAddressTwo', 'submission_address_2', 'submission_address2'])
                    ?: (is_array($submissionObj) ? safe_get($submissionObj, ['address_two', 'address2', 'address_line_two']) : null),
                500
            ) ?: null;
            $submissionAddrCity = sanitize(
                safe_get($t, ['submission_address_city', 'submissionAddressCity', 'submission_city', 'submissionCity'])
                    ?: (is_array($submissionObj) ? safe_get($submissionObj, ['address_city', 'city']) : null),
                255
            ) ?: null;
            $submissionAddrPostal = sanitize(
                safe_get($t, ['submission_address_postal_code', 'submissionAddressPostalCode', 'submission_postal_code', 'submission_postalcode', 'submissionPostalCode'])
                    ?: (is_array($submissionObj) ? safe_get($submissionObj, ['address_postal_code', 'postal_code', 'postalcode', 'zip', 'zip_code']) : null),
                20
            ) ?: null;
            $briefingJson   = !empty($briefingObj) ? safe_json_encode($briefingObj) : null;

            $enquiriesEmail = sanitize(safe_get($t, ['enquiries_email', 'enquiriesEmail', 'enquiries']), 255) ?: null;
            $cidbGrade      = sanitize(safe_get($t, ['cidb_grade', 'cidbGrade', 'cidb']), 20) ?: null;
            $bbbeeReq       = sanitize(safe_get($t, ['bbbee_requirement', 'bbbeeRequirement', 'bbbee']), 50) ?: null;
            $currency       = sanitize(safe_get($t, 'currency'), 3) ?: 'ZAR';

            $htmlSnippet = safe_get($t, ['raw_html_snippet', 'htmlSnippet', 'rawHtml', 'html_content']);
            if (is_string($htmlSnippet) && strlen($htmlSnippet) > 65535) $htmlSnippet = substr($htmlSnippet, 0, 65535);
            $htmlSnippet = !empty($htmlSnippet) ? $htmlSnippet : null;

            $insertStmt->execute([
                ':job_id'                => $jobId,
                ':source_id'             => $sourceId,
                ':source_tender_id'      => $sourceTenderId ?: null,
                ':source_url'            => $sourceUrl,
                ':bot_source_name'       => $botSourceName,
                ':content_hash'          => $hash,
                ':title'                 => $title,
                ':reference_number'      => $refNumber,
                ':description'           => $description ?: null,
                ':issuing_organisation'  => $issuingOrg,
                ':country'               => $countryName,
                ':province'              => $province,
                ':states'                => $statesJson,
                ':tender_type'           => $tenderType,
                ':fields_of_expertise'   => $expertiseJson,
                ':estimated_value'       => $estValue,
                ':currency'              => $currency,
                ':closing_date'          => $closingDate,
                ':closing_time'          => $closingTime,
                ':published_date'        => $pubDate,
                ':compulsory_briefing'   => $compulsory,
                ':briefing_date'         => $briefingDate,
                ':briefing_venue'        => $briefingVenue,
                ':briefing_json'         => $briefingJson,
                ':briefing_address_one'  => $briefingAddrOne,
                ':briefing_address_two'  => $briefingAddrTwo,
                ':briefing_address_city' => $briefingAddrCity,
                ':briefing_address_postal_code' => $briefingAddrPostal,
                ':contact_name'          => $contactName,
                ':contact_email'         => $contactEmail,
                ':contact_phone'         => $contactPhone,
                ':enquiries_email'       => $enquiriesEmail,
                ':submission_address'    => $submissionAddr,
                ':submission_json'       => $submissionJson,
                ':submission_address_two' => $submissionAddrTwo,
                ':submission_address_city' => $submissionAddrCity,
                ':submission_address_postal_code' => $submissionAddrPostal,
                ':cidb_grade'            => $cidbGrade,
                ':bbbee_requirement'     => $bbbeeReq,
                ':local_content_pct'     => $localPct,
                ':tender_document_url'   => $docUrl,
                ':tender_document_price' => $docPrice,
                ':tender_image_url'      => $imgUrl,
                ':raw_html_snippet'      => $htmlSnippet,
            ]);
            $newId = (int)$db->lastInsertId();
            $inserted++;

            // Read-back: verify what actually stored (geo + source verification)
            $stored = null;
            try {
                $readBackStmt->execute([$newId]);
                $stored = $readBackStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            } catch (Throwable $rb) {
                log_error("Read-back failed", ['id' => $newId, 'error' => $rb->getMessage()]);
            }

            // PDF attachments
            $pdfUrls = safe_get($t, ['pdf_urls', 'pdfUrls', 'attachments', 'documents']);
            if (is_string($pdfUrls)) { $pdfUrls = json_decode($pdfUrls, true); if (!is_array($pdfUrls)) $pdfUrls = []; }
            if (!is_array($pdfUrls)) $pdfUrls = [];
            if (!empty($pdfUrls)) {
                $pdfStmt = $db->prepare("
                    INSERT INTO tender_files
                        (raw_tender_id, file_type, original_url, file_name, stored_path, download_status, created_at)
                    VALUES (?, 'tender_document', ?, ?, '', 'Pending', NOW())
                ");
                foreach ($pdfUrls as $pdfUrl) {
                    $pdfUrl = trim((string)$pdfUrl);
                    if (!$pdfUrl) continue;
                    $fname = basename(parse_url($pdfUrl, PHP_URL_PATH) ?: 'document.pdf');
                    $pdfStmt->execute([$newId, $pdfUrl, $fname]);
                }
            }

            $results[] = [
                'index'  => $idx,
                'status' => 'inserted',
                'id'     => $newId,
                'title'  => $title,
                'stored' => $stored,
            ];

        } catch (Throwable $e) {
            log_error("push_tender error", [
                'index' => $idx,
                'error' => $e->getMessage(),
                'line'  => $e->getLine(),
            ]);
            $results[] = ['index' => $idx, 'status' => 'error', 'reason' => $e->getMessage()];
            $errors++;
        }
    }

    if ($jobId && $inserted > 0) {
        $db->prepare("
            UPDATE scraper_jobs SET
                tenders_found = tenders_found + ?,
                tenders_new   = tenders_new   + ?
            WHERE id = ?
        ")->execute([count($tenders), $inserted, $jobId]);
    }

    ok([
        'inserted'   => $inserted,
        'duplicates' => $duplicates,
        'errors'     => $errors,
        'results'    => $results,
    ], "$inserted inserted, $duplicates duplicates, $errors errors");
}