<?php
// Run with php -n: stub transport so tests cannot send real email.
if (extension_loaded('curl')) {
    fwrite(STDERR, "Run this isolated transport test with php -n.\n");
    exit(1);
}
foreach (['CURLOPT_RETURNTRANSFER','CURLOPT_POST','CURLOPT_POSTFIELDS','CURLOPT_HTTPHEADER',
    'CURLOPT_HTTPAUTH','CURLAUTH_BASIC','CURLOPT_USERPWD','CURLOPT_CONNECTTIMEOUT',
    'CURLOPT_TIMEOUT','CURLOPT_SSL_VERIFYPEER','CURLOPT_SSL_VERIFYHOST',
    'CURLOPT_FOLLOWLOCATION','CURLINFO_HTTP_CODE'] as $i => $name) define($name, $i + 1);
$settings = ['SMTP_HOST'=>'in-v3.mailjet.com','SMTP_USER'=>'test-key',
    'SMTP_PASSWORD'=>'test-secret','SMTP_SENDER_EMAIL'=>'noreply@example.com','LOG_LEVEL'=>'error'];
function cfg($key, $default = null) { global $settings; return $settings[$key] ?? $default; }
if (!extension_loaded('curl')) {
function curl_init($url) { $GLOBALS['url'] = $url; return 1; }
function curl_setopt_array($ch, $options) { $GLOBALS['options'] = $options; return true; }
function curl_exec($ch) { return $GLOBALS['raw']; }
function curl_errno($ch) { return $GLOBALS['errno']; }
function curl_getinfo($ch, $key) { return $GLOBALS['status']; }
function curl_close($ch) {}
}
require __DIR__ . '/../lib/mailer.php';
function check($ok, $message) { if (!$ok) throw new Exception($message); }
$cases = [
    [200, 0, '{"Messages":[{"Status":"success"}]}', true],
    [200, 0, '{"Messages":[{"Status":"error"}]}', false],
    [401, 0, '{"ErrorMessage":"unauthorized"}', false],
    [429, 0, '{}', false],
    [500, 0, '<html>failure</html>', false],
    [200, 0, '{}', false],
    [0, 28, false, false],
];
foreach ($cases as [$status, $errno, $raw, $expected]) {
    $result = mailer_send_otp('recipient@example.com', '123456');
    check($result['ok'] === $expected, 'Unexpected send result');
    check($url === 'https://api.mailjet.com/v3.1/send', 'Wrong endpoint');
    check($options[CURLOPT_SSL_VERIFYPEER] && $options[CURLOPT_SSL_VERIFYHOST] === 2, 'TLS verification missing');
    check(!$options[CURLOPT_FOLLOWLOCATION], 'Credentials must not follow redirects');
    $message = json_decode($options[CURLOPT_POSTFIELDS], true)['Messages'][0];
    check($message['From']['Email'] === 'noreply@example.com', 'Wrong sender');
    check($message['To'][0]['Email'] === 'recipient@example.com', 'Wrong recipient');
    check(strpos($message['TextPart'], '123456') !== false, 'Missing OTP');
    check(strpos(json_encode($result), 'test-secret') === false, 'Secret exposed');
}
echo "Passed 7 Mailjet HTTPS transport cases. No email sent.\n";
