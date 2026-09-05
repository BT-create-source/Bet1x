<?php
/**
 * SMS delivery (Fast2SMS).
 *
 * The entire provider integration lives in this one file on purpose. Everything above it talks to
 * sms_send_otp() and knows nothing about Fast2SMS, so swapping provider later is one file, not a
 * hunt through the routes.
 *
 * WHAT THIS DELIBERATELY DOES NOT DO
 * ---------------------------------
 * It never logs the code. A verification code in the error log is a code an operator, a log shipper
 * or anyone with server access can read and use, which defeats the point of hashing it in the
 * database. Failures log the phone number and the provider's own message, never the digits.
 */

require_once __DIR__ . '/logger.php';

/** Fast2SMS's bulk endpoint. Override only for a sandbox or proxy. */
function sms_endpoint() {
    return (string) env_get('FAST2SMS_BASE_URL', 'https://www.fast2sms.com/dev/bulkV2');
}

/**
 * Normalise an Indian mobile number to the bare 10 digits Fast2SMS expects.
 *
 * Accepts what people actually type — +91 98765 43210, 0098..., 0-prefixed, spaces, dashes — and
 * returns 9876543210, or null if it is not a plausible Indian mobile. Validating here means the
 * rest of the app can assume one canonical form, which is also what makes "one account per phone"
 * enforceable: without it, +919876543210 and 9876543210 would be two different rows.
 */
function sms_normalise_indian_mobile($raw) {
    $digits = preg_replace('/\D+/', '', (string) $raw);
    if ($digits === '') return null;

    // Strip the country code / trunk prefixes, longest first.
    foreach (['0091', '91', '0'] as $prefix) {
        $len = strlen($prefix);
        if (strlen($digits) > 10 && substr($digits, 0, $len) === $prefix) {
            $digits = substr($digits, $len);
            break;
        }
    }

    // Indian mobiles are 10 digits and start 6-9. Anything else is a typo or a landline.
    if (!preg_match('/^[6-9]\d{9}$/', $digits)) return null;
    return $digits;
}

/**
 * Send a verification code.
 *
 * Returns ['ok' => true] or ['ok' => false, 'error' => '<safe message>'].
 * The caller shows that message to the user, so it never carries provider internals.
 */
function sms_send_otp($phone, $code) {
    $apiKey = (string) cfg('FAST2SMS_API_KEY', '');
    if ($apiKey === '') {
        log_error('sms: no FAST2SMS_API_KEY configured; cannot send');
        return ['ok' => false, 'error' => 'SMS is not configured on this deployment.'];
    }

    $route = strtolower((string) cfg('FAST2SMS_ROUTE', 'otp'));
    $body  = ['numbers' => $phone];

    if ($route === 'otp') {
        // OTP route: Fast2SMS composes the message itself around the code.
        $body['route']            = 'otp';
        $body['variables_values'] = $code;
    } elseif ($route === 'dlt') {
        // DLT route: a pre-approved template, identified by message id, with the code substituted in.
        $body['route']            = 'dlt';
        $body['sender_id']        = (string) cfg('FAST2SMS_SENDER_ID', '');
        $body['message']          = (string) cfg('FAST2SMS_MESSAGE_ID', '');
        $body['variables_values'] = $code;
    } else {
        // Quick route: we supply the full text.
        $body['route']   = 'q';
        $body['message'] = 'Your bet1x verification code is ' . $code
                         . '. It expires in ' . max(1, (int) round(((int) cfg('OTP_TTL_SECONDS', 300)) / 60))
                         . ' minutes. Do not share it with anyone.';
        $senderId = (string) cfg('FAST2SMS_SENDER_ID', '');
        if ($senderId !== '') $body['sender_id'] = $senderId;
    }

    $ch = curl_init(sms_endpoint());
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($body),
        CURLOPT_HTTPHEADER     => [
            'authorization: ' . $apiKey,
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ],
        // A hung SMS provider must not hang the signup request behind it.
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 12,
    ]);
    $raw   = curl_exec($ch);
    $errNo = curl_errno($ch);
    $errSt = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errNo !== 0) {
        log_error('sms: transport failure', ['phone' => $phone, 'curl' => $errSt]);
        return ['ok' => false, 'error' => 'Could not reach the SMS service. Please try again.'];
    }

    $decoded = json_decode((string) $raw, true);

    // Fast2SMS answers {"return":true,...} on success. Treat anything else as a failure and log the
    // provider's own words, which is what makes a misconfigured route or an exhausted balance
    // diagnosable instead of just "it didn't work".
    if ($status === 200 && is_array($decoded) && !empty($decoded['return'])) {
        log_info('sms: otp sent', ['phone' => $phone, 'route' => $route]);
        return ['ok' => true];
    }

    log_error('sms: provider rejected the send', [
        'phone'    => $phone,
        'route'    => $route,
        'http'     => $status,
        'response' => is_string($raw) ? substr($raw, 0, 300) : null,
    ]);
    return ['ok' => false, 'error' => 'Could not send the code right now. Please try again shortly.'];
}
