<?php
/**
 * Evilletec ERP – Support API
 * Endpoint: /support.php
 * Actions (POST): submit_ticket
 *
 * Requires: PHP 8.0+, PHPMailer (via Composer or manual include)
 */

declare(strict_types=1);

// ─── CONFIGURATION ──────────────────────────────────────────────────────────
define('SMTP_HOST',      'evilletec.com');
define('SMTP_PORT',      465);
define('SMTP_USER',      'support@evilletec.com');
define('SMTP_PASS',      '@evilletec123');           // Move to config outside web root in production
define('SMTP_FROM',      'support@evilletec.com');
define('SMTP_FROM_NAME', 'Evilletec ERP Support');
define('SUPPORT_EMAIL',  'support@evilletec.com');
define('SUPPORT_NAME',   'Evilletec Support Team');
define('ERP_NAME',       'Evilletec ERP');
define('LOG_FILE',       __DIR__ . '/support_errors.log');
define('MAX_DESC_LEN',   5000);
define('MAX_FILE_BYTES', 10 * 1024 * 1024); // 10 MB

// ─── CORS / HEADERS ─────────────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Requested-With');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// ─── HELPERS ────────────────────────────────────────────────────────────────
function json_ok(mixed $data): never {
    echo json_encode(['success' => true, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function json_error(int $code, string $msg): never {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function sanitize(mixed $v): string {
    return htmlspecialchars(trim((string)$v), ENT_QUOTES, 'UTF-8');
}

function logError(string $msg): void {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    file_put_contents(LOG_FILE, $line, FILE_APPEND | LOCK_EX);
}

// ─── ROUTER ─────────────────────────────────────────────────────────────────
$method = $_SERVER['REQUEST_METHOD'];
$action = $_POST['action'] ?? '';

if ($method === 'POST') {
    match ($action) {
        'submit_ticket' => handleSubmitTicket(),
        default         => json_error(400, 'Unknown action: ' . $action),
    };
} else {
    json_error(405, 'Method not allowed');
}

// ═════════════════════════════════════════════════════════════════════════════
// HANDLER
// ═════════════════════════════════════════════════════════════════════════════

function handleSubmitTicket(): void
{
    // ── Validate inputs ──────────────────────────────────────────────────────
    $name      = sanitize($_POST['name']     ?? '');
    $email     = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
    $company   = sanitize($_POST['company']  ?? '');
    $category  = sanitize($_POST['category'] ?? '');
    $priority  = sanitize($_POST['priority'] ?? 'Medium');
    $subject   = sanitize($_POST['subject']  ?? '');
    $message   = sanitize($_POST['message']  ?? '');
    $module    = sanitize($_POST['module']   ?? '');

    if (!$name)    json_error(400, 'Full name is required.');
    if (!$email)   json_error(400, 'A valid email address is required.');
    if (!$subject) json_error(400, 'Subject is required.');
    if (!$message) json_error(400, 'Message / description is required.');
    if (strlen($_POST['message'] ?? '') > MAX_DESC_LEN) json_error(400, 'Description is too long (max ' . MAX_DESC_LEN . ' chars).');

    $allowedCategories = ['Bug Report', 'Feature Request', 'Account Issue', 'Billing', 'General Enquiry', 'Data / Reports', 'Integration', 'Performance'];
    $allowedPriorities = ['Low', 'Medium', 'High', 'Critical'];
    if (!in_array($category, $allowedCategories, true)) json_error(400, 'Invalid category.');
    if (!in_array($priority, $allowedPriorities, true)) $priority = 'Medium';

    // ── Handle optional screenshot attachment ────────────────────────────────
    $attachment      = null;
    $attachmentName  = null;
    $attachmentMime  = null;

    if (isset($_FILES['screenshot']) && $_FILES['screenshot']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['screenshot'];

        if ($file['size'] > MAX_FILE_BYTES) json_error(400, 'Screenshot exceeds 10 MB limit.');

        $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp'];
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detectedMime = $finfo->file($file['tmp_name']);
        if (!in_array($detectedMime, $allowedMimes, true)) json_error(400, 'Only image files (JPEG, PNG, GIF, WEBP) are allowed.');

        $attachment     = file_get_contents($file['tmp_name']);
        $attachmentName = preg_replace('/[^a-zA-Z0-9._\-]/', '_', basename($file['name']));
        $attachmentMime = $detectedMime;
    }

    // ── Generate ticket reference ────────────────────────────────────────────
    $ticketRef = 'ERP-' . strtoupper(substr(md5(uniqid($email, true)), 0, 8));
    $submittedAt = date('d M Y, H:i') . ' (UTC+2)';

    // ── Priority badge colours (for HTML email) ──────────────────────────────
    $priorityColour = match($priority) {
        'Critical' => '#ef4444',
        'High'     => '#f97316',
        'Medium'   => '#f59e0b',
        default    => '#22c55e',
    };

    // ── Build emails ─────────────────────────────────────────────────────────
    $supportHtml = buildSupportEmail($ticketRef, $name, $email, $company, $category, $priority, $priorityColour, $subject, $message, $module, $submittedAt);
    $userHtml    = buildUserAutoResponse($ticketRef, $name, $category, $priority, $priorityColour, $subject, $submittedAt);

    // ── Send via SMTP (using PHP's mail() or raw SMTP sockets) ───────────────
    // NOTE: For production, replace with PHPMailer or Symfony Mailer.
    // This implementation uses a self-contained SMTP send function.
    $boundary = '----=_Part_' . md5(uniqid());

    // Send to support team
    $supportResult = sendMail(
        toEmail: SUPPORT_EMAIL,
        toName:  SUPPORT_NAME,
        subject: "[{$ticketRef}] [{$priority}] {$subject}",
        htmlBody: $supportHtml,
        attachment: $attachment,
        attachmentName: $attachmentName,
        attachmentMime: $attachmentMime,
        replyToEmail: $email,
        replyToName: $name,
    );

    if (!$supportResult['ok']) {
        logError("Failed to send support email for {$ticketRef}: " . $supportResult['error']);
        json_error(500, 'Failed to send support request. Please try again or email ' . SUPPORT_EMAIL . ' directly.');
    }

    // Send auto-response to user
    $userResult = sendMail(
        toEmail: $email,
        toName:  $name,
        subject: "We received your request · {$ticketRef}",
        htmlBody: $userHtml,
    );

    if (!$userResult['ok']) {
        logError("Failed to send auto-response for {$ticketRef} to {$email}: " . $userResult['error']);
        // Don't fail the whole request – the support email already sent
    }

    json_ok([
        'ticket_ref'   => $ticketRef,
        'submitted_at' => $submittedAt,
        'message'      => 'Your support request has been submitted. Check your inbox for a confirmation email.',
    ]);
}

// ═════════════════════════════════════════════════════════════════════════════
// SMTP SEND (vanilla PHP – no external library required)
// ═════════════════════════════════════════════════════════════════════════════

function sendMail(
    string  $toEmail,
    string  $toName,
    string  $subject,
    string  $htmlBody,
    ?string $attachment     = null,
    ?string $attachmentName = null,
    ?string $attachmentMime = null,
    string  $replyToEmail   = '',
    string  $replyToName    = '',
): array {
    try {
        $host    = SMTP_HOST;
        $port    = SMTP_PORT;
        $user    = SMTP_USER;
        $pass    = SMTP_PASS;
        $from    = SMTP_FROM;
        $fromName = SMTP_FROM_NAME;

        $boundary = '----=_Part_' . md5(uniqid());
        $hasAttachment = ($attachment !== null);

        // Build MIME message
        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "From: =?UTF-8?B?" . base64_encode($fromName) . "?= <{$from}>\r\n";
        $headers .= "To: =?UTF-8?B?" . base64_encode($toName) . "?= <{$toEmail}>\r\n";
        if ($replyToEmail) {
            $headers .= "Reply-To: =?UTF-8?B?" . base64_encode($replyToName) . "?= <{$replyToEmail}>\r\n";
        }
        $headers .= "X-Mailer: Evilletec-ERP-Support\r\n";

        if ($hasAttachment) {
            $headers .= "Content-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n";
            $body  = "--{$boundary}\r\n";
            $body .= "Content-Type: text/html; charset=UTF-8\r\n";
            $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
            $body .= chunk_split(base64_encode($htmlBody)) . "\r\n";
            $body .= "--{$boundary}\r\n";
            $body .= "Content-Type: {$attachmentMime}; name=\"{$attachmentName}\"\r\n";
            $body .= "Content-Disposition: attachment; filename=\"{$attachmentName}\"\r\n";
            $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
            $body .= chunk_split(base64_encode($attachment)) . "\r\n";
            $body .= "--{$boundary}--";
        } else {
            $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
            $headers .= "Content-Transfer-Encoding: base64\r\n";
            $body = chunk_split(base64_encode($htmlBody));
        }

        // Use PHP mail() as the transport layer.
        // For production, swap with PHPMailer for reliable SMTP/SSL handling.
        $sent = mail($toEmail, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers);

        if (!$sent) {
            // Fallback: try stream SMTP (SSL on port 465)
            $sent = smtpSend($host, $port, $user, $pass, $from, $fromName, $toEmail, $toName, $subject, $htmlBody, $attachment, $attachmentName, $attachmentMime);
        }

        return ['ok' => $sent, 'error' => $sent ? '' : 'mail() returned false'];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Raw SMTP send over SSL (port 465).
 * This is a fallback/primary SMTP client for environments where mail() is restricted.
 */
function smtpSend(
    string  $host,
    int     $port,
    string  $user,
    string  $pass,
    string  $from,
    string  $fromName,
    string  $toEmail,
    string  $toName,
    string  $subject,
    string  $htmlBody,
    ?string $attachment,
    ?string $attachmentName,
    ?string $attachmentMime,
): bool {
    $errno = 0; $errstr = '';
    $sock = fsockopen("ssl://{$host}", $port, $errno, $errstr, 10);
    if (!$sock) return false;

    $read = fn() => fgets($sock, 512);
    $send = function(string $cmd) use ($sock, &$read) {
        fputs($sock, $cmd . "\r\n");
        return $read();
    };

    $read(); // greeting
    $send("EHLO " . gethostname());
    $send("AUTH LOGIN");
    $send(base64_encode($user));
    $send(base64_encode($pass));
    $send("MAIL FROM:<{$from}>");
    $send("RCPT TO:<{$toEmail}>");
    $send("DATA");

    $boundary = '----=_Part_' . md5(uniqid());
    $hasAttachment = ($attachment !== null);

    $msg  = "MIME-Version: 1.0\r\n";
    $msg .= "From: =?UTF-8?B?" . base64_encode($fromName) . "?= <{$from}>\r\n";
    $msg .= "To: =?UTF-8?B?" . base64_encode($toName) . "?= <{$toEmail}>\r\n";
    $msg .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
    $msg .= "Date: " . date('r') . "\r\n";

    if ($hasAttachment) {
        $msg .= "Content-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n\r\n";
        $msg .= "--{$boundary}\r\n";
        $msg .= "Content-Type: text/html; charset=UTF-8\r\n";
        $msg .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $msg .= chunk_split(base64_encode($htmlBody)) . "\r\n";
        $msg .= "--{$boundary}\r\n";
        $msg .= "Content-Type: {$attachmentMime}; name=\"{$attachmentName}\"\r\n";
        $msg .= "Content-Disposition: attachment; filename=\"{$attachmentName}\"\r\n";
        $msg .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $msg .= chunk_split(base64_encode($attachment)) . "\r\n";
        $msg .= "--{$boundary}--\r\n";
    } else {
        $msg .= "Content-Type: text/html; charset=UTF-8\r\n";
        $msg .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $msg .= chunk_split(base64_encode($htmlBody)) . "\r\n";
    }

    $msg .= "\r\n.";
    $resp = $send($msg);
    $send("QUIT");
    fclose($sock);

    return str_starts_with(trim($resp), '250');
}

// ═════════════════════════════════════════════════════════════════════════════
// EMAIL TEMPLATES
// ═════════════════════════════════════════════════════════════════════════════

function buildSupportEmail(
    string $ref, string $name, string $email, string $company,
    string $category, string $priority, string $priorityColour,
    string $subject, string $message, string $module, string $submittedAt
): string {
    $companyRow = $company ? "<tr><td style='padding:6px 0;color:#64748b;font-size:13px;width:130px'>Company</td><td style='padding:6px 0;font-size:13px;color:#1e293b;font-weight:600'>" . htmlspecialchars($company) . "</td></tr>" : '';
    $moduleRow  = $module  ? "<tr><td style='padding:6px 0;color:#64748b;font-size:13px'>Module</td><td style='padding:6px 0;font-size:13px;color:#1e293b;font-weight:600'>" . htmlspecialchars($module) . "</td></tr>" : '';

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/><title>New Support Ticket</title></head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:'Segoe UI',system-ui,sans-serif">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:32px 16px">
    <tr><td align="center">
      <table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%">
        <!-- Header -->
        <tr><td style="background:#0a0c10;border-radius:20px 20px 0 0;padding:28px 36px">
          <table width="100%" cellpadding="0" cellspacing="0">
            <tr>
              <td><span style="display:inline-block;background:#064e3b;color:#fff;font-size:11px;font-weight:700;letter-spacing:2px;padding:4px 10px;border-radius:6px;text-transform:uppercase">New Ticket</span>
                <h1 style="margin:10px 0 4px;color:#fff;font-size:22px;font-weight:300;letter-spacing:-0.5px">Support Request Received</h1>
                <p style="margin:0;color:#64748b;font-size:13px">{$submittedAt}</p>
              </td>
              <td align="right"><div style="background:#064e3b;border-radius:12px;padding:10px 16px;text-align:center">
                <p style="margin:0;color:#86efac;font-size:10px;letter-spacing:1.5px;text-transform:uppercase">Ticket</p>
                <p style="margin:4px 0 0;color:#fff;font-size:16px;font-weight:700;letter-spacing:1px">{$ref}</p>
              </div></td>
            </tr>
          </table>
        </td></tr>
        <!-- Priority banner -->
        <tr><td style="background:{$priorityColour};padding:8px 36px">
          <p style="margin:0;color:#fff;font-size:11px;font-weight:700;letter-spacing:2px;text-transform:uppercase">⚡ Priority: {$priority}</p>
        </td></tr>
        <!-- Body -->
        <tr><td style="background:#fff;padding:32px 36px">
          <h2 style="margin:0 0 4px;font-size:18px;color:#1e293b;font-weight:600">{$subject}</h2>
          <p style="margin:0 0 24px;color:#64748b;font-size:13px">Category: <strong>{$category}</strong></p>
          <!-- Submitter Details -->
          <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:14px;padding:20px;margin-bottom:24px">
            <p style="margin:0 0 12px;font-size:11px;font-weight:700;color:#94a3b8;letter-spacing:2px;text-transform:uppercase">Submitter Details</p>
            <table cellpadding="0" cellspacing="0">
              <tr><td style="padding:6px 0;color:#64748b;font-size:13px;width:130px">Full Name</td><td style="padding:6px 0;font-size:13px;color:#1e293b;font-weight:600">{$name}</td></tr>
              <tr><td style="padding:6px 0;color:#64748b;font-size:13px">Email</td><td style="padding:6px 0;font-size:13px"><a href="mailto:{$email}" style="color:#064e3b;font-weight:600">{$email}</a></td></tr>
              {$companyRow}
              {$moduleRow}
            </table>
          </div>
          <!-- Message -->
          <div style="background:#f8fafc;border-left:4px solid #064e3b;border-radius:0 14px 14px 0;padding:20px;margin-bottom:24px">
            <p style="margin:0 0 10px;font-size:11px;font-weight:700;color:#94a3b8;letter-spacing:2px;text-transform:uppercase">Message</p>
            <p style="margin:0;font-size:14px;color:#334155;line-height:1.7;white-space:pre-wrap">{$message}</p>
          </div>
          <p style="margin:0;font-size:12px;color:#94a3b8">If a screenshot was attached, it will appear as an email attachment above.</p>
        </td></tr>
        <!-- Footer -->
        <tr><td style="background:#f8fafc;border-top:1px solid #e2e8f0;border-radius:0 0 20px 20px;padding:20px 36px;text-align:center">
          <p style="margin:0;font-size:12px;color:#94a3b8">Evilletec ERP Internal Support System · Do not reply to this email</p>
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;
}

function buildUserAutoResponse(
    string $ref, string $name, string $category,
    string $priority, string $priorityColour, string $subject, string $submittedAt
): string {
    $slaMap = ['Critical' => '2 hours', 'High' => '4 hours', 'Medium' => '1 business day', 'Low' => '2–3 business days'];
    $sla = $slaMap[$priority] ?? '1 business day';

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/><title>Support Ticket Confirmed</title></head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:'Segoe UI',system-ui,sans-serif">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:32px 16px">
    <tr><td align="center">
      <table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%">
        <!-- Header -->
        <tr><td style="background:#0a0c10;border-radius:20px 20px 0 0;padding:36px">
          <div style="text-align:center">
            <div style="display:inline-block;background:#064e3b;border-radius:14px;width:56px;height:56px;line-height:56px;text-align:center;margin:0 auto 16px;font-size:26px">✓</div>
            <h1 style="margin:0 0 6px;color:#fff;font-size:24px;font-weight:300;letter-spacing:-0.5px">We've got your request!</h1>
            <p style="margin:0;color:#64748b;font-size:14px">Our team will be in touch shortly.</p>
          </div>
        </td></tr>
        <!-- Ticket card -->
        <tr><td style="background:#064e3b;padding:20px 36px">
          <table width="100%" cellpadding="0" cellspacing="0">
            <tr>
              <td><p style="margin:0;color:#86efac;font-size:11px;font-weight:700;letter-spacing:2px;text-transform:uppercase">Your Ticket Reference</p>
                <p style="margin:6px 0 0;color:#fff;font-size:28px;font-weight:700;letter-spacing:2px">{$ref}</p>
              </td>
              <td align="right"><div style="background:rgba(255,255,255,0.1);border-radius:10px;padding:10px 14px;text-align:center">
                <p style="margin:0;color:#86efac;font-size:10px;letter-spacing:1px;text-transform:uppercase">Est. Response</p>
                <p style="margin:4px 0 0;color:#fff;font-size:14px;font-weight:700">{$sla}</p>
              </div></td>
            </tr>
          </table>
        </td></tr>
        <!-- Body -->
        <tr><td style="background:#fff;padding:32px 36px">
          <p style="margin:0 0 20px;font-size:15px;color:#1e293b">Hi <strong>{$name}</strong>,</p>
          <p style="margin:0 0 20px;font-size:14px;color:#475569;line-height:1.7">
            Thank you for reaching out to the <strong>Evilletec ERP Support Team</strong>. We have successfully received your support request and assigned it to the appropriate team.
          </p>
          <!-- Summary -->
          <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:14px;padding:20px;margin-bottom:24px">
            <p style="margin:0 0 14px;font-size:11px;font-weight:700;color:#94a3b8;letter-spacing:2px;text-transform:uppercase">Ticket Summary</p>
            <table cellpadding="0" cellspacing="0" width="100%">
              <tr><td style="padding:6px 0;color:#64748b;font-size:13px;width:120px">Subject</td><td style="padding:6px 0;font-size:13px;color:#1e293b;font-weight:600">{$subject}</td></tr>
              <tr><td style="padding:6px 0;color:#64748b;font-size:13px">Category</td><td style="padding:6px 0;font-size:13px;color:#1e293b">{$category}</td></tr>
              <tr><td style="padding:6px 0;color:#64748b;font-size:13px">Priority</td><td style="padding:6px 0"><span style="background:{$priorityColour}20;color:{$priorityColour};font-size:11px;font-weight:700;padding:3px 10px;border-radius:99px;text-transform:uppercase">{$priority}</span></td></tr>
              <tr><td style="padding:6px 0;color:#64748b;font-size:13px">Submitted</td><td style="padding:6px 0;font-size:13px;color:#1e293b">{$submittedAt}</td></tr>
            </table>
          </div>
          <p style="margin:0 0 24px;font-size:13px;color:#64748b;line-height:1.7">
            Please keep your ticket reference number <strong style="color:#064e3b">{$ref}</strong> handy for any follow-up. You can reply directly to any email from our team to add more information to your case.
          </p>
          <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:14px;padding:16px 20px">
            <p style="margin:0;font-size:13px;color:#166534;line-height:1.6">
              Need urgent help? Email us directly at <a href="mailto:support@evilletec.com" style="color:#064e3b;font-weight:700">support@evilletec.com</a>
            </p>
          </div>
        </td></tr>
        <!-- Footer -->
        <tr><td style="background:#f8fafc;border-top:1px solid #e2e8f0;border-radius:0 0 20px 20px;padding:20px 36px;text-align:center">
          <p style="margin:0 0 4px;font-size:13px;font-weight:600;color:#1e293b">Evilletec Support Team</p>
          <p style="margin:0;font-size:12px;color:#94a3b8">This is an automated confirmation · Please do not reply to this email</p>
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;
}