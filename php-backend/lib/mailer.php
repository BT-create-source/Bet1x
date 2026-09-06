<?php
/**
 * Email delivery (Brevo transactional email API).
 *
 * The entire provider integration lives in this one file on purpose, mirroring lib/sms.php.
 * Everything above it talks to mailer_send_otp() and knows nothing about Brevo, so swapping
 * provider later is one file, not a hunt through the routes.
 *
 * WHAT THIS DELIBERATELY DOES NOT DO
 * ---------------------------------
 * It never logs the code. A verification code in the error log is a code an operator, a log
 * shipper or anyone with server access can read and use, which defeats the point of hashing it in
 * the database. Failures log the recipient address and the provider's own message, never the digits.
 */

require_once __DIR__ . '/logger.php';

/** Brevo's transactional email endpoint. Override only for a sandbox or proxy. */
function mailer_endpoint() {
    return (string) env_get('BREVO_API_URL', 'https://api.brevo.com/v3/smtp/email');
}

/**
 * Send a verification code by email.
 *
 * Returns ['ok' => true] or ['ok' => false, 'error' => '<safe message>'].
 * The caller shows that message to the user, so it never carries provider internals.
 */
function mailer_send_otp($email, $code) {
    $apiKey = (string) cfg('BREVO_API_KEY', '');
    if ($apiKey === '') {
        log_error('mailer: no BREVO_API_KEY configured; cannot send');
        return ['ok' => false, 'error' => 'Email delivery is not configured on this deployment.'];
    }

    $senderEmail = (string) cfg('BREVO_SENDER_EMAIL', '');
    $senderName  = (string) cfg('BREVO_SENDER_NAME', 'bet1x');
    if ($senderEmail === '') {
        log_error('mailer: no BREVO_SENDER_EMAIL configured; cannot send');
        return ['ok' => false, 'error' => 'Email delivery is not configured on this deployment.'];
    }

    $ttlMinutes = max(1, (int) round(((int) cfg('OTP_TTL_SECONDS', 300)) / 60));
    $safeCode = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');

    $payload = [
        'sender'      => ['name' => $senderName, 'email' => $senderEmail],
        'to'          => [['email' => $email]],
        'subject'     => 'Your bet1x verification code',
        'htmlContent' => '<p>Your bet1x verification code is <strong>' . $safeCode . '</strong>.</p>'
                        . '<p>It expires in ' . $ttlMinutes . ' minute' . ($ttlMinutes === 1 ? '' : 's')
                        . '. Do not share it with anyone.</p>',
        'textContent' => 'Your bet1x verification code is ' . $code . '. It expires in '
                        . $ttlMinutes . ' minute' . ($ttlMinutes === 1 ? '' : 's') . '. Do not share it with anyone.',
    ];

    $ch = curl_init(mailer_endpoint());
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'api-key: ' . $apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        // A hung mail provider must not hang the signup request behind it.
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 12,
    ]);
    $raw   = curl_exec($ch);
    $errNo = curl_errno($ch);
    $errSt = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errNo !== 0) {
        log_error('mailer: transport failure', ['email' => $email, 'curl' => $errSt]);
        return ['ok' => false, 'error' => 'Could not reach the email service. Please try again.'];
    }

    // Brevo answers 201 with {"messageId": "..."} on success.
    if ($status >= 200 && $status < 300) {
        log_info('mailer: otp sent', ['email' => $email]);
        return ['ok' => true];
    }

    log_error('mailer: provider rejected the send', [
        'email'    => $email,
        'http'     => $status,
        'response' => is_string($raw) ? substr($raw, 0, 300) : null,
    ]);
    return ['ok' => false, 'error' => 'Could not send the code right now. Please try again shortly.'];
}
