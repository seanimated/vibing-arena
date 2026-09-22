<?php
/**
 * ============================================================================
 * NTS API — AI Tender Batch Ingestion Push Handler
 * File:    api/nts_push.php
 *
 * Changes vs previous version:
 *
 *   buildSubmission() / buildBriefing() — structured address merge (NEW)
 *     Previously: submission_json / briefing_json were forwarded to NTS as-is
 *     (only address_countries was added), so the new structured address columns
 *     were silently ignored whenever a JSON blob existed — and when one didn't,
 *     only address_one was ever sent.
 *     Now: the decoded JSON blob is overlaid with the non-empty column values
 *       submission:        address_one (submission_address),
 *                          address_two, address_city, address_postal_code
 *       briefing:          address_one (briefing_address_one ?? briefing_venue),
 *                          address_two, address_city, address_postal_code,
 *                          plus briefing_date / briefing_compulsory from columns
 *     Column values win over scraped JSON so admin corrections in the
 *     operations UI take effect on the next push.
 *     buildBriefing() no longer requires briefing_date — a briefing block with
 *     only address fields is now sent (NTS may still reject it per-tender,
 *     which is visible in nts_push_results).
 *
 *   fetchPublishedTenders() — reads the new address columns
 *     submission_address_two/city/postal_code and
 *     briefing_address_one/two/city/postal_code via COALESCE(pt.x, rt.x).
 *
 *   buildTenderPayload() — tender_document_price always forwarded when present
 *     Previously gated with !empty(), which also dropped genuine '0' values.
 *     Now a returned value (including 0) is sent as tender_document_price;
 *     only absent/NULL values are omitted. estimated_value is intentionally
 *     NOT sent to NTS — left untouched.
 *
 *   buildTenderPayload() — source name resolution
 *     Previously: $t['bot_source_name'] ?? $t['source_name'] ?? $settings default
 *     Now: explicit priority chain with URL-derived fallback so international
 *     tenders never silently get labelled 'eTenders'.
 *
 *   buildTenderPayload() — tender_document URL sanitisation (NEW)
 *     Previously: $t['tender_document_url'] ?? $t['source_url'] ?? ''
 *     This let non-URL strings (e.g. a scraped physical address like
 *     "Kwacha House, Cairo Road") through to NTS as the tender_document
 *     field, causing hard rejections on tenders whose source_url column
 *     was populated incorrectly by the scraper.
 *     Now: both candidate values are passed through sanitiseUrl(), which
 *     rejects anything that isn't a valid http(s) URL and falls back to
 *     '' instead of sending garbage to NTS.
 *
 *   sanitiseUrl() — new private helper
 *     Trims the value, rejects anything not starting with http:// or
 *     https://, and validates the remainder with filter_var(). Returns
 *     null on any failure so the caller's ?? chain can fall through.
 *
 *   fetchPublishedTenders() — prefer pt columns over rt columns (NEW)
 *     Previously: bot_source_name, states, fields_of_expertise,
 *     tender_document_url, tender_image_url, submission_address,
 *     submission_json, and briefing_json were always read from
 *     raw_tenders (rt) via the LEFT JOIN.
 *     Now that published_tenders (pt) carries its own copies of these
 *     columns post-migration, each is wrapped in COALESCE(pt.x, rt.x)
 *     so a value edited/corrected on the published record takes
 *     priority, with the original raw-scrape value as fallback for
 *     rows where the published column hasn't been populated.
 *     tender_document_price, briefing_date, briefing_venue,
 *     compulsory_briefing, cidb_grade, and bbbee_requirement are left
 *     reading from rt only — published_tenders has no equivalent
 *     columns for these.
 *
 *   mapTenderType() — expanded type mapping
 *     Previously: only matched 4 lowercase single words; everything else → 'tender'.
 *     Now: also maps 'Request For Quote', 'RFQ', 'RFP', 'Request For Proposal',
 *     'RFI', 'Mining Tenders', 'Private Sector Tenders', etc.
 *
 *   resolveStates() — country-aware state resolution
 *     Previously: always returned ['All'] when states and province were both null.
 *     This meant international tenders (Thailand, Afghanistan, etc.) were sent
 *     to NTS with states: ['All'] — a South Africa-specific placeholder.
 *     Now: returns [] for non-SA countries with no explicit state data, which
 *     lets NTS treat the tender as a national-level entry for that country.
 *
 *   buildCountries() — handles empty states array gracefully
 *     Previously: always forced states: ['All'] as fallback.
 *     Now: omits the states key entirely for international tenders with no
 *     state breakdown. South Africa still defaults to ['All'].
 *
 *   pushBatch() — diagnostic logging on non-201 responses
 *     When NTS returns HTTP 4xx/5xx for a whole chunk, the raw response body
 *     is now written to the PHP error log so failures are visible without
 *     querying nts_push_batches directly.
 *
 *   deriveSourceNameFallback() — new private helper
 *     Mirrors bot.php's derive_source_name() for use when bot_source_name
 *     was not stored (e.g. rows inserted before v2.3 of bot.php). Covers
 *     all major UN portals and multilateral development banks.
 *
 *   NOTE — not fixed here:
 *     A tender with a closing_date that is today or in the past (e.g.
 *     UNDP-ZWE-01813) will still be rejected by NTS. That is a data
 *     issue, not a code path — update or remove the affected row in
 *     published_tenders/raw_tenders before re-pushing.
 * ============================================================================
 */

declare(strict_types=1);

define('NTS_AUTH_PATH',      '/api/v1/login');
define('NTS_REFRESH_PATH',   '/api/v1/refresh');
define('NTS_BATCH_PATH',     '/api/v1/tenders/ingest/batch');
define('NTS_TOKEN_BUFFER_S', 60);
define('NTS_MAX_BATCH_SIZE', 100);

class NtsApi
{
    public static function pushBatch(PDO $pdo, array $publishedIds, int $adminId): array
    {
        if (empty($publishedIds)) {
            return self::errorResponse('No published tender IDs supplied.');
        }

        $settings = self::loadSettings($pdo);

        if (empty($settings['nts_api_email']) || empty($settings['nts_api_password'])) {
            return self::errorResponse('NTS API credentials not configured.');
        }

        if ($settings['nts_enabled'] !== 'true') {
            return self::errorResponse('NTS push is disabled. Enable it in System Settings → NTS.');
        }

        $baseUrl = rtrim($settings['nts_api_base_url'] ?? 'https://www.nationaltenders.co.za', '/');

        $tokenResult = self::getValidToken($pdo, $baseUrl, $settings);
        if (isset($tokenResult['error'])) {
            return self::errorResponse('NTS authentication failed: ' . $tokenResult['error']);
        }
        $accessToken = $tokenResult['access_token'];

        $tenders = self::fetchPublishedTenders($pdo, $publishedIds);
        if (empty($tenders)) {
            return self::errorResponse('None of the supplied IDs matched active published tenders.');
        }

        $chunks       = array_chunk($tenders, NTS_MAX_BATCH_SIZE);
        $allResults   = [];
        $totalSummary = ['total' => 0, 'successful' => 0, 'duplicates' => 0, 'failed' => 0];
        $lastBatchRef = '';

        foreach ($chunks as $chunkIndex => $chunk) {
            $batchRef = self::generateBatchRef($chunkIndex);

            $payload = [
                'batch_reference' => $batchRef,
                'tenders'         => array_map(fn($t) => self::buildTenderPayload($t, $settings), $chunk),
            ];

            $httpResult = self::httpPost($baseUrl . NTS_BATCH_PATH, $payload, $accessToken);
            $httpCode   = $httpResult['http_code'] ?? 0;
            $body       = $httpResult['body']      ?? [];

            // Attempt a single token refresh on 401 then retry
            if ($httpCode === 401) {
                $reauth = self::doAuth($pdo, $baseUrl, $settings['nts_api_email'], $settings['nts_api_password']);
                if (!isset($reauth['error'])) {
                    $accessToken = $reauth['access_token'];
                    $httpResult  = self::httpPost($baseUrl . NTS_BATCH_PATH, $payload, $accessToken);
                    $httpCode    = $httpResult['http_code'] ?? 0;
                    $body        = $httpResult['body']      ?? [];
                }
            }

            $batchStatus  = ($httpCode === 201) ? 'Completed'
                          : (($httpCode >= 400 && $httpCode < 500) ? 'Failed' : 'PartialFail');
            $batchSummary = $body['summary'] ?? [];
            $batchResults = $body['results'] ?? [];

            // Log non-201 responses so they are visible in PHP error log without
            // requiring a direct DB query on nts_push_batches.
            if ($httpCode !== 201) {
                error_log(sprintf(
                    '[NTS] Chunk %d returned HTTP %d. Batch: %s. Countries in chunk: [%s]. Body preview: %s',
                    $chunkIndex,
                    $httpCode,
                    $batchRef,
                    implode(', ', array_unique(array_column($chunk, 'country'))),
                    json_encode(array_slice((array)$body, 0, 5))
                ));
            }

            $batchDbId = self::storeBatch(
                $pdo, $batchRef, $adminId, $batchStatus, $httpCode,
                $batchSummary, json_encode($body)
            );

            foreach ($batchResults as $res) {
                $tenderNumber = $res['number'] ?? null;
                $status       = $res['status'] ?? 'failed';
                $ntsUid       = $res['uid']    ?? null;
                $message      = $res['message'] ?? null;

                $matchedId = null;
                foreach ($chunk as $t) {
                    if (($t['reference_number'] ?? null) === $tenderNumber) {
                        $matchedId = (int) $t['published_id'];
                        break;
                    }
                }

                if ($batchDbId) {
                    self::storeResult($pdo, $batchDbId, $matchedId, $tenderNumber, $status, $ntsUid, $message);
                }

                if ($matchedId) {
                    $ntsStatus = match ($status) {
                        'success'   => 'Pushed',
                        'duplicate' => 'Duplicate',
                        default     => 'Failed',
                    };
                    self::updatePublishedNtsStatus($pdo, $matchedId, $ntsStatus, $ntsUid, $batchDbId);
                    if ($ntsStatus === 'Pushed') {
                        self::markRawPushed($pdo, $matchedId);
                    }
                }

                $allResults[] = array_merge(['number' => $tenderNumber], $res);
            }

            $totalSummary['total']      += (int) ($batchSummary['total']      ?? count($chunk));
            $totalSummary['successful'] += (int) ($batchSummary['successful'] ?? 0);
            $totalSummary['duplicates'] += (int) ($batchSummary['duplicates'] ?? 0);
            $totalSummary['failed']     += (int) ($batchSummary['failed']     ?? 0);
            $lastBatchRef = $batchRef;

            // When NTS rejects the whole batch (empty results array), mark each
            // tender as Failed individually so the UI shows a meaningful status.
            if ($httpCode !== 201 && empty($batchResults)) {
                $errMsg = $body['message'] ?? $body['error'] ?? "HTTP $httpCode from NTS API";
                foreach ($chunk as $t) {
                    $pid = (int)$t['published_id'];
                    if ($pid) self::updatePublishedNtsStatus($pdo, $pid, 'Failed', null, $batchDbId);
                    $allResults[] = [
                        'number'  => $t['reference_number'] ?? null,
                        'status'  => 'failed',
                        'message' => $errMsg,
                    ];
                    $totalSummary['failed']++;
                }
            }
        }

        return [
            'success'         => true,
            'batch_reference' => $lastBatchRef,
            'summary'         => $totalSummary,
            'results'         => $allResults,
        ];
    }

    // ── Token management ──────────────────────────────────────────────────────

    private static function getValidToken(PDO $pdo, string $baseUrl, array $settings): array
    {
        $stored = self::loadStoredTokens($pdo);

        if ($stored && strtotime($stored['access_expires']) > (time() + NTS_TOKEN_BUFFER_S)) {
            return ['access_token' => $stored['access_token']];
        }
        if ($stored && strtotime($stored['refresh_expires']) > time()) {
            $refreshed = self::doRefresh($pdo, $baseUrl, $stored['refresh_token']);
            if (!isset($refreshed['error'])) {
                return ['access_token' => $refreshed['access_token']];
            }
        }

        return self::doAuth($pdo, $baseUrl, $settings['nts_api_email'], $settings['nts_api_password']);
    }

    private static function doAuth(PDO $pdo, string $baseUrl, string $email, string $password): array
    {
        $result = self::httpPost($baseUrl . NTS_AUTH_PATH, ['email' => $email, 'password' => $password]);
        $code   = $result['http_code'] ?? 0;
        $body   = $result['body']      ?? [];

        if (($code < 200 || $code > 201) || empty($body['access_token'])) {
            $msg = $body['message'] ?? $body['error'] ?? "HTTP $code";
            return ['error' => "Auth failed: $msg"];
        }

        self::storeTokens($pdo, $body['access_token'], $body['refresh_token'] ?? '');
        return ['access_token' => $body['access_token']];
    }

    private static function doRefresh(PDO $pdo, string $baseUrl, string $refreshToken): array
    {
        $result = self::httpPost($baseUrl . NTS_REFRESH_PATH, ['refresh_token' => $refreshToken]);
        $code   = $result['http_code'] ?? 0;
        $body   = $result['body']      ?? [];

        if (($code < 200 || $code > 201) || empty($body['access_token'])) {
            $msg = $body['message'] ?? $body['error'] ?? "HTTP $code";
            return ['error' => "Token refresh failed: $msg"];
        }

        self::storeTokens($pdo, $body['access_token'], $body['refresh_token'] ?? $refreshToken);
        return ['access_token' => $body['access_token']];
    }

    // ── Payload builder ───────────────────────────────────────────────────────

    private static function buildTenderPayload(array $t, array $settings): array
    {
        // ── Source name resolution ────────────────────────────────────────────
        // Priority 1: bot_source_name stored at scrape time by bot.php v2.3+
        // Priority 2: source_name from scraper_sources (via JOIN in fetchPublishedTenders)
        // Priority 3: re-derived from source_url (covers rows scraped before v2.3)
        // Priority 4: system settings default (last resort)
        //
        // This chain ensures international tenders never fall back to 'eTenders'
        // (the SA-specific default) when their actual source is UNDP, UNGM, etc.
        $sourceName = null;
        if (!empty($t['bot_source_name'])) {
            $sourceName = $t['bot_source_name'];
        } elseif (!empty($t['source_name'])) {
            $sourceName = $t['source_name'];
        } elseif (!empty($t['source_url'])) {
            $sourceName = self::deriveSourceNameFallback($t['source_url']);
        }
        if (empty($sourceName)) {
            $sourceName = $settings['nts_default_source'] ?? 'eTenders';
        }
        // ── End source name resolution ────────────────────────────────────────

        $country = !empty($t['country']) ? $t['country'] : 'South Africa';
        $states  = self::resolveStates($t);

        $tender = [
            'number'          => $t['reference_number'] ?? ('REF-' . $t['published_id']),
            'type'            => self::mapTenderType($t['tender_type'] ?? ''),
            'title'           => $t['title'] ?? '',
            'description'     => $t['description'] ?? $t['title'] ?? '',
            'source'          => $sourceName,
            'posted_date'     => self::toIso8601($t['published_date'] ?? $t['approved_at'] ?? null),
            'closing_date'    => self::toIso8601($t['closing_date'] ?? null, $t['closing_time'] ?? '11:00:00'),
            'countries'       => self::buildCountries($country, $states),
            // Sanitised: reject non-URL values (e.g. a scraped physical address
            // stored in source_url) instead of forwarding them to NTS, which
            // causes a hard validation failure on that tender.
            'tender_document' => self::sanitiseUrl($t['tender_document_url'] ?? null)
                              ?? self::sanitiseUrl($t['source_url'] ?? null)
                              ?? '',
        ];

        if (!empty($t['issuing_organisation'])) {
            $tender['tender_client'] = $t['issuing_organisation'];
        }
        if (!empty($t['fields_of_expertise'])) {
            $tender['fields_of_expertise'] = self::resolveExpertise($t);
        }
        if (!empty($t['contact_name']) || !empty($t['contact_email']) || !empty($t['contact_phone'])) {
            $tender['contact_person'] = [
                'name'   => $t['contact_name']  ?? '',
                'email'  => $t['contact_email'] ?? '',
                'number' => $t['contact_phone'] ?? '',
            ];
        }
        if ($t['tender_document_price'] !== null && $t['tender_document_price'] !== '') {
            // Forward the returned value as-is (0 = free / default). Only skip
            // when truly absent - PHP empty() would also drop a genuine '0'.
            $tender['tender_document_price'] = (float) $t['tender_document_price'];
        }
        if (!empty($t['tender_image_url'])) {
            $tender['tender_image'] = $t['tender_image_url'];
        }

        $submission = self::buildSubmission($t, $country, $states);
        if ($submission) $tender['submission'] = $submission;

        $briefing = self::buildBriefing($t, $country, $states);
        if ($briefing) $tender['briefing'] = $briefing;

        return $tender;
    }

    /**
     * Reject anything that isn't a well-formed http(s) URL.
     *
     * Used for tender_document_url / source_url, both of which have been
     * observed containing scraped physical addresses (e.g.
     * "Kwacha House, Cairo Road") rather than links. Sending such a value
     * to NTS as tender_document causes the whole tender to be rejected if
     * NTS validates the field as a URL.
     *
     * Returns null (not '') on failure so callers can chain with ?? and
     * fall through to the next candidate value, defaulting to '' only at
     * the end of the chain.
     */
    private static function sanitiseUrl(?string $url): ?string
    {
        if (empty($url)) return null;
        $url = trim($url);
        // Reject anything that doesn't start with http:// or https://
        if (!preg_match('/^https?:\/\//i', $url)) return null;
        return filter_var($url, FILTER_VALIDATE_URL) ? $url : null;
    }

    /**
     * Build the countries array for an NTS tender payload.
     * NTS requires the states key to always be present — country-level
     * tenders (any country, including international) must use ['All'].
     */
    private static function buildCountries(string $country, array $states): array
    {
        return [['name' => $country, 'states' => !empty($states) ? $states : ['All']]];
    }

    private static function buildSubmission(array $t, string $country, array $states): ?array
    {
        $sub = [];
        if (!empty($t['submission_json'])) {
            $decoded = json_decode($t['submission_json'], true);
            if (is_array($decoded)) $sub = $decoded;
        }

        // Structured address columns overlay the scraped JSON blob — values
        // captured by bot.php v2.4+ (or corrected in the operations UI) take
        // priority; JSON keys without a column counterpart are kept as-is.
        $addrMap = [
            'address_one'         => $t['submission_address']              ?? null,
            'address_two'         => $t['submission_address_two']          ?? null,
            'address_city'        => $t['submission_address_city']         ?? null,
            'address_postal_code' => $t['submission_address_postal_code']  ?? null,
        ];
        foreach ($addrMap as $key => $val) {
            if ($val !== null && trim((string)$val) !== '') $sub[$key] = $val;
        }

        if (!self::hasAddressContent($sub)) return null;

        if (empty($sub['address_countries'])) {
            $sub['address_countries'] = self::buildCountries($country, $states);
        }
        return $sub;
    }

    private static function buildBriefing(array $t, string $country, array $states): ?array
    {
        $br = [];
        if (!empty($t['briefing_json'])) {
            $decoded = json_decode($t['briefing_json'], true);
            if (is_array($decoded)) $br = $decoded;
        }

        if (!empty($t['briefing_date'])) {
            $br['briefing_date'] = self::toIso8601($t['briefing_date']);
        }
        if (isset($t['compulsory_briefing']) && $t['compulsory_briefing'] !== null && $t['compulsory_briefing'] !== '') {
            $br['briefing_compulsory'] = (bool) $t['compulsory_briefing'];
        }

        // Briefing Address One falls back to the legacy briefing_venue value.
        $addrMap = [
            'address_one'         => $t['briefing_address_one']         ?? null,
            'address_two'         => $t['briefing_address_two']         ?? null,
            'address_city'        => $t['briefing_address_city']        ?? null,
            'address_postal_code' => $t['briefing_address_postal_code'] ?? null,
        ];
        if (empty(trim((string)($addrMap['address_one'] ?? '')))) {
            $addrMap['address_one'] = $t['briefing_venue'] ?? null;
        }
        foreach ($addrMap as $key => $val) {
            if ($val !== null && trim((string)$val) !== '') $br[$key] = $val;
        }

        if (!self::hasAddressContent($br)) return null;

        if (empty($br['address_countries'])) {
            $br['address_countries'] = self::buildCountries($country, $states);
        }
        return $br;
    }

    /**
     * True when a submission/briefing object carries any meaningful content
     * beyond the address_countries wrapper (and the briefing_compulsory flag,
     * which on its own says nothing about where/when a briefing happens).
     */
    private static function hasAddressContent(array $obj): bool
    {
        foreach ($obj as $key => $val) {
            if ($key === 'address_countries' || $key === 'briefing_compulsory') continue;
            if (is_array($val)) { if (!empty($val)) return true; continue; }
            if (trim((string)$val) !== '') return true;
        }
        return false;
    }

    // ── DB helpers ────────────────────────────────────────────────────────────

    private static function loadSettings(PDO $pdo): array
    {
        $defaults = [
            'nts_enabled'        => 'true',
            'nts_api_base_url'   => 'https://www.nationaltenders.co.za',
            'nts_api_email'      => 'aibot@epicdev.co',
            'nts_api_password'   => 'hzoi4sExKR0k=naAXB04',
            'nts_default_source' => 'eTenders',
            'nts_batch_size'     => '100',
        ];

        try {
            $rows = $pdo->query("
                SELECT setting_key, setting_value
                FROM system_settings
                WHERE group_name = 'nts'
            ")->fetchAll(PDO::FETCH_KEY_PAIR);

            $dbValues = array_filter($rows ?: [], fn($v) => $v !== null && $v !== '');
            return array_merge($defaults, $dbValues);
        } catch (Throwable) {
            return $defaults;
        }
    }

    private static function loadStoredTokens(PDO $pdo): ?array
    {
        $stmt = $pdo->query("SELECT * FROM nts_auth_tokens ORDER BY updated_at DESC LIMIT 1");
        $row  = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private static function storeTokens(PDO $pdo, string $access, string $refresh): void
    {
        $accessExpires  = date('Y-m-d H:i:s', time() + 1200);
        $refreshExpires = date('Y-m-d H:i:s', time() + 2592000);

        $existing = $pdo->query("SELECT id FROM nts_auth_tokens LIMIT 1")->fetchColumn();
        if ($existing) {
            $pdo->prepare("
                UPDATE nts_auth_tokens
                SET access_token = :at, refresh_token = :rt,
                    access_expires = :ae, refresh_expires = :re,
                    updated_at = NOW()
                WHERE id = :id
            ")->execute([':at' => $access, ':rt' => $refresh,
                         ':ae' => $accessExpires, ':re' => $refreshExpires, ':id' => $existing]);
        } else {
            $pdo->prepare("
                INSERT INTO nts_auth_tokens (access_token, refresh_token, access_expires, refresh_expires)
                VALUES (:at, :rt, :ae, :re)
            ")->execute([':at' => $access, ':rt' => $refresh,
                         ':ae' => $accessExpires, ':re' => $refreshExpires]);
        }
    }

    private static function fetchPublishedTenders(PDO $pdo, array $ids): array
    {
        $ph   = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("
            SELECT
                pt.id                    AS published_id,
                pt.title,
                pt.reference_number,
                pt.description,
                pt.issuing_organisation,
                pt.country,
                pt.province,
                pt.tender_type,
                pt.closing_date,
                pt.closing_time,
                pt.published_date,
                pt.approved_at,
                pt.contact_name,
                pt.contact_email,
                pt.contact_phone,
                pt.source_url,
                pt.nts_push_status,
                ss.name                  AS source_name,
                ss.base_url              AS source_base_url,
                COALESCE(pt.bot_source_name,    rt.bot_source_name)    AS bot_source_name,
                COALESCE(pt.states,             rt.states)             AS states,
                COALESCE(pt.fields_of_expertise, rt.fields_of_expertise) AS fields_of_expertise,
                COALESCE(pt.tender_document_url, rt.tender_document_url) AS tender_document_url,
                rt.tender_document_price,
                COALESCE(pt.tender_image_url,   rt.tender_image_url)   AS tender_image_url,
                COALESCE(pt.submission_address, rt.submission_address) AS submission_address,
                COALESCE(pt.submission_address_two,         rt.submission_address_two)         AS submission_address_two,
                COALESCE(pt.submission_address_city,        rt.submission_address_city)        AS submission_address_city,
                COALESCE(pt.submission_address_postal_code, rt.submission_address_postal_code) AS submission_address_postal_code,
                COALESCE(pt.submission_json,    rt.submission_json)    AS submission_json,
                rt.briefing_date,
                rt.briefing_venue,
                COALESCE(pt.briefing_address_one,         rt.briefing_address_one)         AS briefing_address_one,
                COALESCE(pt.briefing_address_two,         rt.briefing_address_two)         AS briefing_address_two,
                COALESCE(pt.briefing_address_city,        rt.briefing_address_city)        AS briefing_address_city,
                COALESCE(pt.briefing_address_postal_code, rt.briefing_address_postal_code) AS briefing_address_postal_code,
                COALESCE(pt.briefing_json,      rt.briefing_json)      AS briefing_json,
                rt.compulsory_briefing,
                rt.cidb_grade,
                rt.bbbee_requirement
            FROM published_tenders pt
            LEFT JOIN raw_tenders rt     ON rt.id  = pt.raw_tender_id
            LEFT JOIN scraper_sources ss ON ss.id  = rt.source_id
            WHERE pt.id IN ($ph)
              AND pt.status = 'Active'
            ORDER BY pt.id ASC
        ");
        $stmt->execute(array_values($ids));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function storeBatch(
        PDO $pdo, string $ref, int $adminId, string $status, int $httpCode,
        array $summary, string $rawResponse
    ): ?int {
        try {
            $pdo->prepare("
                INSERT INTO nts_push_batches
                    (batch_reference, pushed_by, status, total, successful, duplicates, failed,
                     http_status_code, raw_response, pushed_at)
                VALUES
                    (:ref, :admin, :status, :total, :succ, :dupe, :fail, :http, :raw, NOW())
                ON DUPLICATE KEY UPDATE
                    status = VALUES(status), total = VALUES(total),
                    successful = VALUES(successful), duplicates = VALUES(duplicates),
                    failed = VALUES(failed), http_status_code = VALUES(http_status_code),
                    raw_response = VALUES(raw_response)
            ")->execute([
                ':ref'    => $ref,
                ':admin'  => $adminId,
                ':status' => $status,
                ':total'  => (int)($summary['total']      ?? 0),
                ':succ'   => (int)($summary['successful'] ?? 0),
                ':dupe'   => (int)($summary['duplicates'] ?? 0),
                ':fail'   => (int)($summary['failed']     ?? 0),
                ':http'   => $httpCode,
                ':raw'    => mb_substr($rawResponse, 0, 65535),
            ]);
            return (int)$pdo->lastInsertId() ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function storeResult(
        PDO $pdo, int $batchId, ?int $publishedId, ?string $number,
        string $status, ?string $ntsUid, ?string $message
    ): void {
        try {
            $pdo->prepare("
                INSERT INTO nts_push_results
                    (batch_id, published_tender_id, tender_number, status, nts_uid, message)
                VALUES (:bid, :pid, :num, :status, :uid, :msg)
            ")->execute([
                ':bid'    => $batchId,
                ':pid'    => $publishedId,
                ':num'    => $number,
                ':status' => $status,
                ':uid'    => $ntsUid,
                ':msg'    => $message,
            ]);
        } catch (Throwable) {}
    }

    private static function updatePublishedNtsStatus(
        PDO $pdo, int $publishedId, string $ntsStatus, ?string $ntsUid, ?int $batchId
    ): void {
        try {
            $pdo->prepare("
                UPDATE published_tenders
                SET nts_push_status = :status,
                    nts_uid         = COALESCE(:uid, nts_uid),
                    nts_pushed_at   = NOW(),
                    nts_batch_id    = COALESCE(:bid, nts_batch_id)
                WHERE id = :id
            ")->execute([
                ':status' => $ntsStatus,
                ':uid'    => $ntsUid,
                ':bid'    => $batchId,
                ':id'     => $publishedId,
            ]);
        } catch (Throwable) {}
    }

    private static function markRawPushed(PDO $pdo, int $publishedId): void
    {
        try {
            $pdo->prepare("
                UPDATE raw_tenders rt
                JOIN published_tenders pt ON pt.raw_tender_id = rt.id
                SET rt.admin_status = 'Pushed'
                WHERE pt.id = :pid AND rt.admin_status = 'Approved'
            ")->execute([':pid' => $publishedId]);
        } catch (Throwable) {}
    }

    // ── HTTP helper ───────────────────────────────────────────────────────────

    private static function httpPost(string $url, array $payload, ?string $bearerToken = null): array
    {
        $json    = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'Content-Length: ' . strlen($json),
        ];
        if ($bearerToken) {
            $headers[] = 'Authorization: Bearer ' . $bearerToken;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'TenderPortalBot/1.0',
        ]);

        $raw      = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            return ['http_code' => 0, 'body' => ['error' => $curlErr]];
        }

        $decoded = json_decode($raw ?: '{}', true);
        return ['http_code' => $httpCode, 'body' => is_array($decoded) ? $decoded : []];
    }

    // ── Mapping helpers ───────────────────────────────────────────────────────

    /**
     * Map the tender_type string stored in the DB to a type value the NTS API
     * accepts. The previous version only matched 4 lowercase single-word types,
     * so every international tender type ('Request For Quote', 'Tender', etc.)
     * fell through to the 'tender' default — which is still correct but lossy.
     *
     * The NTS-accepted values are: tender, goods, services, works, consultancy.
     */
    private static function mapTenderType(string $type): string
    {
        return match (strtolower(trim($type))) {
            'goods'                                          => 'goods',
            'services'                                       => 'services',
            'works'                                          => 'works',
            'consultancy'                                    => 'consultancy',
            'request for quote', 'rfq'                      => 'services',
            'request for proposal', 'rfp'                   => 'services',
            'request for information', 'rfi'                => 'services',
            'awarded tenders', 'award', 'awarded'           => 'tender',
            'mining tenders', 'mining'                      => 'works',
            'private sector tenders', 'private sector'      => 'tender',
            default                                         => 'tender',
        };
    }

    /**
     * Resolve the states array for a tender record.
     * Falls back to ['All'] for any country when no specific state data exists.
     */
    private static function resolveStates(array $t): array
    {
        if (!empty($t['states'])) {
            $decoded = json_decode($t['states'], true);
            if (is_array($decoded) && $decoded) {
                $filtered = array_values(array_filter(
                    $decoded,
                    fn($v) => strtolower(trim((string)$v)) !== 'all'
                ));
                if ($filtered) return $filtered;
            }
        }

        if (!empty($t['province'])) {
            return [$t['province']];
        }

        return ['All'];
    }

    private static function resolveExpertise(array $t): array
    {
        if (!empty($t['fields_of_expertise'])) {
            $decoded = json_decode($t['fields_of_expertise'], true);
            if (is_array($decoded) && $decoded) return array_values($decoded);
        }
        $type = $t['tender_type'] ?? '';
        if ($type) return [$type];
        return ['General'];
    }

    private static function toIso8601(?string $date, string $time = '08:00:00'): string
    {
        if (!$date) return date('Y-m-d\TH:i:s\Z');
        $time = $time ?: '08:00:00';
        $ts   = strtotime("$date $time");
        return $ts ? date('Y-m-d\TH:i:s\Z', $ts) : date('Y-m-d\TH:i:s\Z');
    }

    private static function generateBatchRef(int $chunkIndex = 0): string
    {
        $date  = date('Ymd');
        $seq   = str_pad((string)($chunkIndex + 1), 3, '0', STR_PAD_LEFT);
        $micro = substr((string)microtime(true), -3);
        return "batch-{$date}-{$seq}-{$micro}";
    }

    /**
     * Derive a human-readable source name from a URL.
     *
     * This is a fallback used only when bot_source_name is NULL in raw_tenders,
     * meaning the row was scraped before bot.php v2.3. Once all rows have been
     * backfilled (see backfill SQL in bot.php header), this function will rarely
     * be called.
     *
     * Mirrors bot.php::derive_source_name() — keep both in sync when adding
     * new portals.
     */
    private static function deriveSourceNameFallback(string $url): string
    {
        if (!$url) return '';

        $host = strtolower(parse_url($url, PHP_URL_HOST) ?: '');
        if (!$host) return '';
        $host = preg_replace('/^(www\.|m\.|portal\.|procurement\.)/', '', $host);

        $map = [
            // South African government portals
            'etenders.gov.za'                 => 'eTenders',
            'peims.treasury.gov.za'           => 'National Treasury',
            'treasury.gov.za'                 => 'National Treasury',
            'cidb.org.za'                     => 'CIDB',
            'tender.gauteng.gov.za'           => 'Gauteng Tenders',
            'tenders.durban.gov.za'           => 'eThekwini Tenders',
            'tenders.capetown.gov.za'         => 'City of Cape Town',
            'scm.tshwane.gov.za'              => 'City of Tshwane',
            'bids.johannesburg.gov.za'        => 'City of Joburg',
            // South African SOEs and agencies
            'eskom.co.za'                     => 'Eskom',
            'transnet.net'                    => 'Transnet',
            'sanral.co.za'                    => 'SANRAL',
            'prasa.com'                        => 'PRASA',
            'sita.co.za'                       => 'SITA',
            'sars.gov.za'                      => 'SARS',
            'dbsa.org'                         => 'DBSA',
            // UN system
            'notices.undp.org'                => 'UNDP Procurement Notices',
            'procurement-notices.undp.org'    => 'UNDP Procurement Notices',
            'undp.org'                         => 'UNDP',
            'ungm.org'                         => 'UNGM',
            'unops.org'                        => 'UNOPS',
            'ifc.org'                          => 'IFC',
            // Multilateral development banks
            'worldbank.org'                   => 'World Bank',
            'projects.worldbank.org'          => 'World Bank',
            'afdb.org'                         => 'African Development Bank',
            'iadb.org'                         => 'Inter-American Development Bank',
            'adb.org'                          => 'Asian Development Bank',
            'isdb.org'                         => 'Islamic Development Bank',
            'ebrd.com'                         => 'EBRD',
            'eib.org'                          => 'European Investment Bank',
            // Other UN agencies
            'unhcr.org'                        => 'UNHCR',
            'unicef.org'                       => 'UNICEF',
            'wfp.org'                          => 'WFP',
            'who.int'                          => 'WHO',
            'ilo.org'                          => 'ILO',
            'fao.org'                          => 'FAO',
            // Aggregators
            'devex.com'                        => 'Devex',
            'reliefweb.int'                    => 'ReliefWeb',
            'tendersinfo.com'                 => 'TendersInfo',
            'africatenders.com'               => 'Africa Tenders',
            'dgmarket.com'                     => 'dgMarket',
        ];

        foreach ($map as $pattern => $label) {
            if ($host === $pattern || str_ends_with($host, '.' . $pattern)) {
                return $label;
            }
        }

        return '';
    }

    private static function errorResponse(string $msg): array
    {
        return [
            'success' => false,
            'error'   => $msg,
            'summary' => ['total' => 0, 'successful' => 0, 'duplicates' => 0, 'failed' => 0],
            'results' => [],
        ];
    }
}

// ── Standalone CLI / cron mode ────────────────────────────────────────────────

if (php_sapi_name() === 'cli' && realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    $dry = in_array('--dry-run', $argv ?? [], true);

    require_once __DIR__ . '/database.php';
    $pdo = Database::getConnection();

    $rows = $pdo->query("
        SELECT id FROM published_tenders
        WHERE nts_push_status = 'Pending' AND status = 'Active'
        LIMIT 500
    ")->fetchAll(PDO::FETCH_COLUMN);

    if (!$rows) {
        echo "[NTS] No pending tenders to push.\n";
        exit(0);
    }

    echo "[NTS] Found " . count($rows) . " tender(s) to push" . ($dry ? " (dry-run)" : "") . ".\n";

    if ($dry) {
        echo "[NTS] Dry-run mode — no changes made.\n";
        exit(0);
    }

    $result = NtsApi::pushBatch($pdo, $rows, 1);

    if (isset($result['error'])) {
        echo "[NTS] ERROR: " . $result['error'] . "\n";
        exit(1);
    }

    $s = $result['summary'];
    echo "[NTS] Done. Total: {$s['total']} | Pushed: {$s['successful']} | Duplicates: {$s['duplicates']} | Failed: {$s['failed']}\n";
    exit(0);
}

