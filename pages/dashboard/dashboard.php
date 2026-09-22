<?php
declare(strict_types=1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/api/database.php';

// ── CONFIG ────────────────────────────────────────────────────────────────────
define('PORTAL_NAME', 'SA Tender Portal');

// ── HEADERS ───────────────────────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// ── HELPERS ───────────────────────────────────────────────────────────────────
function json_error(int $code, string $msg): never {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

function scalar(PDO $pdo, string $sql, array $params = []): mixed {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_NUM);
    return $row ? $row[0] : null;
}

function rows(PDO $pdo, string $sql, array $params = []): array {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ── DB CONNECTION ─────────────────────────────────────────────────────────────
try {
    $pdo = Database::getConnection();
} catch (PDOException $e) {
    json_error(503, 'Database connection failed');
}

// ── DASHBOARD DATA ────────────────────────────────────────────────────────────
try {

    // ── PRIMARY KPIs (from v_dashboard_stats view) ────────────────────────────
    $dashRow = rows($pdo, "SELECT * FROM v_dashboard_stats LIMIT 1");
    $dash    = $dashRow[0] ?? [];

    $pendingTenders        = (int)($dash['pending_tenders']         ?? 0);
    $underReview           = (int)($dash['under_review']            ?? 0);
    $approvedToday         = (int)($dash['approved_today']          ?? 0);
    $liveTenders           = (int)($dash['live_tenders']            ?? 0);
    $expiredNotClosed      = (int)($dash['expired_not_closed']      ?? 0);
    $scrapersRunning       = (int)($dash['scrapers_running']        ?? 0);
    $scraperFailuresToday  = (int)($dash['scraper_failures_today']  ?? 0);
    $tendersScrapedToday   = (int)($dash['tenders_scraped_today']   ?? 0);
    $applicationsToday     = (int)($dash['applications_today']      ?? 0);
    $totalApplicants       = (int)($dash['total_applicants']        ?? 0);
    $pendingAccessRequests = (int)($dash['pending_access_requests'] ?? 0);
    $duplicatesToday       = (int)($dash['duplicates_today']        ?? 0);

    // ── TOTAL SCRAPED (all time, for approval rate donut) ────────────────────
    $totalScraped = (int) scalar($pdo, "SELECT COUNT(*) FROM raw_tenders");

    // ── SCRAPER SOURCE HEALTH ─────────────────────────────────────────────────
    // Use the v_scraper_health view, limit to active sources
    $sourceHealth = rows($pdo, "
        SELECT
            id,
            name,
            is_active,
            is_verified,
            last_job_status,
            last_job_started,
            last_job_finished,
            last_job_tenders_new,
            last_job_errors,
            pending_review_count,
            minutes_since_last_scrape
        FROM v_scraper_health
        WHERE is_active = 1
        ORDER BY is_active DESC, name ASC
        LIMIT 8
    ");

    // ── MONTHLY TENDERS SCRAPED (last 12 months) ──────────────────────────────
    $monthly = rows($pdo, "
        SELECT
            DATE_FORMAT(scraped_at, '%b') AS month,
            COUNT(*) AS count
        FROM raw_tenders
        WHERE scraped_at >= DATE_SUB(CURDATE(), INTERVAL 11 MONTH)
        GROUP BY YEAR(scraped_at), MONTH(scraped_at)
        ORDER BY YEAR(scraped_at), MONTH(scraped_at)
    ");

    // ── PUBLISHED TENDERS BY CATEGORY ────────────────────────────────────────
    $categories = rows($pdo, "
        SELECT
            tc.name,
            tc.color,
            COUNT(pt.id) AS count
        FROM tender_categories tc
        LEFT JOIN published_tenders pt ON pt.category_id = tc.id
            AND pt.status = 'Active'
        WHERE tc.is_active = 1
        GROUP BY tc.id
        HAVING count > 0
        ORDER BY count DESC
        LIMIT 8
    ");

    // ── RAW TENDERS BY STATUS (for inbox breakdown) ───────────────────────────
    $statusBreakdown = [];
    foreach (rows($pdo, "
        SELECT admin_status, COUNT(*) AS cnt
        FROM raw_tenders
        GROUP BY admin_status
        ORDER BY cnt DESC
    ") as $r) {
        $statusBreakdown[$r['admin_status']] = (int)$r['cnt'];
    }

    // ── PUBLISHED TENDERS BY PROVINCE ────────────────────────────────────────
    $byProvince = rows($pdo, "
        SELECT
            COALESCE(province, 'Unspecified') AS province,
            COUNT(*) AS count
        FROM published_tenders
        WHERE status = 'Active'
        GROUP BY province
        ORDER BY count DESC
        LIMIT 9
    ");

    // ── APPLICATIONS BY STATUS ────────────────────────────────────────────────
    $applicationsByStatus = [];
    foreach (rows($pdo, "
        SELECT status, COUNT(*) AS cnt
        FROM tender_applications
        GROUP BY status
        ORDER BY cnt DESC
    ") as $r) {
        $applicationsByStatus[$r['status']] = (int)$r['cnt'];
    }

    // ── RECENT ACTIVITY (from audit_logs via view) ────────────────────────────
    $recentActivity = rows($pdo, "
        SELECT
            al.action,
            al.entity_type,
            al.entity_label,
            al.result,
            al.created_at AS time,
            COALESCE(
                CASE WHEN al.actor_type = 'admin'
                     THEN CONCAT(a.first_name, ' ', a.last_name)
                     ELSE al.actor_email
                END,
                'System'
            ) AS actor
        FROM audit_logs al
        LEFT JOIN admins a ON al.actor_type = 'admin' AND al.actor_id = a.id
        ORDER BY al.created_at DESC
        LIMIT 10
    ");

    // ── SCRAPER JOB HISTORY (last 7 days, by day) ────────────────────────────
    $jobHistory = rows($pdo, "
        SELECT
            DATE(started_at) AS job_date,
            COUNT(*) AS total_jobs,
            SUM(IF(status = 'Completed', 1, 0)) AS completed,
            SUM(IF(status = 'Failed', 1, 0))    AS failed,
            SUM(tenders_new) AS new_tenders
        FROM scraper_jobs
        WHERE started_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
        GROUP BY DATE(started_at)
        ORDER BY job_date ASC
    ");

    // ── PENDING TENDERS: CLOSING SOON ────────────────────────────────────────
    $closingSoon = rows($pdo, "
        SELECT
            rt.title,
            rt.issuing_organisation,
            rt.closing_date,
            rt.admin_status,
            ss.name AS source_name,
            DATEDIFF(rt.closing_date, CURDATE()) AS days_left
        FROM raw_tenders rt
        JOIN scraper_sources ss ON rt.source_id = ss.id
        WHERE rt.admin_status IN ('Pending', 'Under Review')
          AND rt.closing_date IS NOT NULL
          AND rt.closing_date >= CURDATE()
          AND rt.closing_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
        ORDER BY rt.closing_date ASC
        LIMIT 5
    ");

    // ── TOP SCRAPER SOURCES BY TENDERS FOUND ─────────────────────────────────
    $topSources = rows($pdo, "
        SELECT
            ss.name,
            COUNT(rt.id) AS total_tenders,
            SUM(IF(rt.admin_status = 'Pending', 1, 0))   AS pending,
            SUM(IF(rt.admin_status = 'Approved', 1, 0))  AS approved
        FROM scraper_sources ss
        LEFT JOIN raw_tenders rt ON rt.source_id = ss.id
        GROUP BY ss.id
        ORDER BY total_tenders DESC
        LIMIT 5
    ");

    // ── ASSEMBLE RESPONSE ─────────────────────────────────────────────────────
    echo json_encode([
        'success'   => true,
        'timestamp' => date('c'),

        // Flat KPI stats object (used by Alpine x-text bindings)
        'stats' => [
            'pending_tenders'         => $pendingTenders,
            'under_review'            => $underReview,
            'approved_today'          => $approvedToday,
            'live_tenders'            => $liveTenders,
            'expired_not_closed'      => $expiredNotClosed,
            'scrapers_running'        => $scrapersRunning,
            'scraper_failures_today'  => $scraperFailuresToday,
            'tenders_scraped_today'   => $tendersScrapedToday,
            'applications_today'      => $applicationsToday,
            'total_applicants'        => $totalApplicants,
            'pending_access_requests' => $pendingAccessRequests,
            'duplicates_today'        => $duplicatesToday,
            'total_scraped'           => $totalScraped,
        ],

        // Chart & panel data
        'monthly'           => $monthly,          // [{month, count}]
        'source_health'     => $sourceHealth,     // [{name, last_job_status, ...}]
        'categories'        => $categories,       // [{name, color, count}]
        'status_breakdown'  => $statusBreakdown,  // {Pending: N, Approved: N, ...}
        'by_province'       => $byProvince,       // [{province, count}]
        'applications_by_status' => $applicationsByStatus,
        'recent_activity'   => $recentActivity,   // [{action, entity_type, actor, time}]
        'job_history'       => $jobHistory,       // [{job_date, completed, failed, new_tenders}]
        'closing_soon'      => $closingSoon,       // tenders about to close still unreviewed
        'top_sources'       => $topSources,       // [{name, total_tenders, pending, approved}]
    ]);

} catch (PDOException $e) {
    json_error(500, 'Query failed: ' . $e->getMessage());
}