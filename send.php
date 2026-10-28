<?php
/**
 * UniBridge form handler (replaces Netlify Forms).
 * Receives the website forms, checks them, and emails info@unibridge.ae.
 * Edit the two lines below if the address ever changes.
 */
$TO   = 'info@unibridge.ae';          // where enquiries arrive
$FROM = 'website@unibridge.ae';       // must be a real mailbox on unibridge.ae (create it in cPanel)

header('X-Robots-Tag: noindex');
$wantsJson = isset($_SERVER['HTTP_ACCEPT']) && stripos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false;

function done($ok, $wantsJson, $msg = '') {
    if ($wantsJson) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($ok ? 200 : 400);
        echo json_encode(['ok' => $ok, 'message' => $msg]);
    } else {
        header('Location: ' . ($ok ? 'thanks.html' : 'contact.html?sent=error'));
    }
    exit;
}
function clean($v, $max = 2000) {
    $v = is_string($v) ? trim($v) : '';
    $v = str_replace(["\r", "\0"], '', $v);
    return mb_substr($v, 0, $max);
}
function oneLine($v, $max = 200) {          // blocks email header injection
    return preg_replace('/[\r\n]+/', ' ', clean($v, $max));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { done(false, $wantsJson, 'Invalid request.'); }

// --- Spam protection 1: hidden honeypot field (real people never fill it) ---
if (!empty($_POST['bot-field'])) { done(true, $wantsJson, 'ok'); }   // pretend success, send nothing

// --- Spam protection 2: form must have been open for at least 3 seconds ---
$ts = isset($_POST['ts']) ? (int)$_POST['ts'] : 0;
if ($ts > 0 && (time() - intdiv($ts, 1000)) < 3) { done(true, $wantsJson, 'ok'); }

// --- Spam protection 3: max 5 sends per visitor per hour ---
$ip   = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$dir  = __DIR__ . '/form-log';
if (!is_dir($dir)) { @mkdir($dir, 0750, true); }
$rate = $dir . '/rate-' . md5($ip) . '.txt';
$now  = time();
$hits = [];
if (is_file($rate)) { $hits = array_filter(array_map('intval', explode("\n", (string)@file_get_contents($rate))), function ($t) use ($now) { return $now - $t < 3600; }); }
if (count($hits) >= 5) { done(false, $wantsJson, 'Too many messages. Please try again later or email info@unibridge.ae.'); }
$hits[] = $now; @file_put_contents($rate, implode("\n", $hits));

// --- Read and check the fields ---
$formName = oneLine($_POST['form-name'] ?? 'enquiry', 40);
$name     = oneLine($_POST['name'] ?? '', 120);
$email    = oneLine($_POST['email'] ?? '', 160);
$phone    = oneLine($_POST['phone'] ?? '', 40);
$audience = oneLine($_POST['audience'] ?? '', 60);
$inst     = oneLine($_POST['institution'] ?? '', 160);
$country  = oneLine($_POST['country'] ?? '', 80);
$message  = clean($_POST['message'] ?? '', 4000);

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { done(false, $wantsJson, 'Please enter a valid email address.'); }
if ($formName !== 'notify' && $name === '') { done(false, $wantsJson, 'Please enter your name.'); }
// Spam protection 4: too many links in the message = spam
if (preg_match_all('#https?://#i', $message) > 2) { done(true, $wantsJson, 'ok'); }

$labels = ['inquiry' => 'Website enquiry', 'partner' => 'University partner enquiry', 'notify' => 'School day alert sign-up'];
$subject = ($labels[$formName] ?? 'Website enquiry') . ($name !== '' ? ' from ' . $name : '');

$lines = [
    'Form: ' . $formName,
    'Name: ' . $name,
    'Email: ' . $email,
    'Phone: ' . $phone,
    'I am a: ' . $audience,
    'Institution: ' . $inst,
    'Country: ' . $country,
    'Consent given: ' . (!empty($_POST['consent']) ? 'yes' : 'no'),
    '',
    'Message:',
    $message,
    '',
    '---',
    'Sent from unibridge.ae on ' . date('d M Y, H:i') . ' (server time). Visitor IP: ' . $ip,
];
$body = implode("\n", array_filter($lines, function ($l) { return !preg_match('/^(Phone|I am a|Institution|Country): $/', $l); }));

$headers  = "From: UniBridge Website <{$FROM}>\r\n";
$headers .= "Reply-To: {$email}\r\n";
$headers .= "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n";

$sent = @mail($TO, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers, '-f' . $FROM);

// Safety copy: if mail ever fails, the enquiry is still saved in form-log/ (blocked from the web by .htaccess)
if (!$sent) {
    @file_put_contents($dir . '/failed-' . date('Ymd-His') . '-' . substr(md5($email . $now), 0, 6) . '.txt', $subject . "\n\n" . $body);
    done(false, $wantsJson, 'Sorry, that did not send. Please email info@unibridge.ae.');
}
done(true, $wantsJson, 'Thank you. We have your details and will be in touch soon.');
