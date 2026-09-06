<?php
/**
 * Email delivery — direct Gmail SMTP, hand-rolled.
 *
 * There is no Composer/vendor setup in this codebase (see the note at the top of lib/sms.php for
 * why everything here talks to providers directly rather than through a library), so this speaks
 * the SMTP protocol itself over a TLS socket rather than pulling in PHPMailer. It is a minimal
 * client — EHLO, AUTH LOGIN, MAIL FROM/RCPT TO/DATA, QUIT — sized for "send one OTP email", not a
 * general mail library.
 *
 * WHAT THIS DELIBERATELY DOES NOT DO
 * -----------------------------------
 * It never logs the code. A verification code in the error log is a code an operator, a log
 * shipper or anyone with server access can read and use, which defeats the point of hashing it in
 * the database. Failures log the recipient address and the SMTP server's own reply, never the
 * digits. The app password is read from config only — never logged, never echoed to the client.
 */

require_once __DIR__ . '/logger.php';

/**
 * Read one SMTP reply, following multi-line continuations (the fourth character is '-' on every
 * line except the last, e.g. "250-SIZE 35882000" then "250 HELP").
 * Returns ['code' => int, 'text' => string] or null on a read failure/timeout.
 */
function smtp_read_reply($sock) {
    $lines = [];
    while (true) {
        $line = fgets($sock, 1024);
        if ($line === false) return null;
        $lines[] = $line;
        // A well-formed line is "NNN(-| )rest". Stop once we see the non-continuation form.
        if (strlen($line) < 4 || $line[3] !== '-') break;
    }
    $last = end($lines);
    $code = (int) substr($last, 0, 3);
    return ['code' => $code, 'text' => implode('', $lines)];
}

/** Send one command and return its reply, or null if the socket write/read failed. */
function smtp_command($sock, $cmd) {
    if (fwrite($sock, $cmd . "\r\n") === false) return null;
    return smtp_read_reply($sock);
}

/**
 * Send a verification code by email over Gmail SMTP (smtp.gmail.com:465, implicit TLS).
 *
 * Returns ['ok' => true] or ['ok' => false, 'error' => '<safe message>'].
 * The caller shows that message to the user, so it never carries provider internals.
 */
function mailer_send_otp($email, $code) {
    $user = (string) cfg('GMAIL_SMTP_USER', '');
    $pass = (string) cfg('GMAIL_SMTP_APP_PASSWORD', '');
    if ($user === '' || $pass === '') {
        log_error('mailer: GMAIL_SMTP_USER/GMAIL_SMTP_APP_PASSWORD not configured; cannot send');
        return ['ok' => false, 'error' => 'Email delivery is not configured on this deployment.'];
    }

    $senderName = (string) cfg('GMAIL_SENDER_NAME', 'bet1x');
    $ttlMinutes = max(1, (int) round(((int) cfg('OTP_TTL_SECONDS', 300)) / 60));

    $host = (string) cfg('GMAIL_SMTP_HOST', 'smtp.gmail.com');
    $port = (int) cfg('GMAIL_SMTP_PORT', 465);
    // Port 465 is IMPLICIT TLS — the whole connection is encrypted from the first byte. Any other
    // port (587, the modern "submission" port; 25, almost always blocked outbound on shared
    // hosting) is assumed to want STARTTLS instead: connect in plaintext, then upgrade partway
    // through the conversation. Mixing the two modes up is the single most common reason a
    // hand-rolled SMTP client hangs against Gmail. Some hosts block 465 outbound while leaving 587
    // open (or vice versa) — GMAIL_SMTP_PORT is what decides which mode this function speaks, so
    // switching is a config change, not a code change.
    $useStartTls = ($port !== 465);

    $sslOpts = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);

    $errno = 0;
    $errstr = '';
    $sock = @stream_socket_client(
        ($useStartTls ? 'tcp' : 'ssl') . "://{$host}:{$port}",
        $errno,
        $errstr,
        10,
        STREAM_CLIENT_CONNECT,
        $sslOpts
    );
    if ($sock === false) {
        log_error('mailer: SMTP connect failed', ['host' => $host, 'port' => $port, 'error' => $errstr]);
        return ['ok' => false, 'error' => 'Could not reach the email service. Please try again.'];
    }
    stream_set_timeout($sock, 10);

    // A single place to bail out partway through the conversation: log the step and SMTP's own
    // reply text (never the OTP, which never appears in any SMTP command until the DATA body,
    // and even there only inside the payload this function already controls).
    $fail = function ($step, $reply) use ($email, $sock) {
        log_error('mailer: SMTP step failed', [
            'email' => $email,
            'step'  => $step,
            'reply' => $reply ? trim($reply['text']) : '(no reply / timeout)',
        ]);
        fclose($sock);
        return ['ok' => false, 'error' => 'Could not send the code right now. Please try again shortly.'];
    };

    $greeting = smtp_read_reply($sock);
    if (!$greeting || $greeting['code'] !== 220) return $fail('greeting', $greeting);

    // The EHLO hostname is cosmetic (Gmail does not verify it against anything), but has to be
    // some syntactically valid token.
    $reply = smtp_command($sock, 'EHLO bet1x.biz');
    if (!$reply || $reply['code'] !== 250) return $fail('EHLO', $reply);

    if ($useStartTls) {
        $reply = smtp_command($sock, 'STARTTLS');
        if (!$reply || $reply['code'] !== 220) return $fail('STARTTLS', $reply);

        if (!@stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            return $fail('TLS handshake', null);
        }

        // EHLO must be repeated after STARTTLS: the server's feature list (specifically whether
        // AUTH is offered at all) is only trustworthy once the connection is encrypted, and Gmail
        // will otherwise reject AUTH LOGIN as coming from a plaintext session.
        $reply = smtp_command($sock, 'EHLO bet1x.biz');
        if (!$reply || $reply['code'] !== 250) return $fail('EHLO after STARTTLS', $reply);
    }

    $reply = smtp_command($sock, 'AUTH LOGIN');
    if (!$reply || $reply['code'] !== 334) return $fail('AUTH LOGIN', $reply);

    $reply = smtp_command($sock, base64_encode($user));
    if (!$reply || $reply['code'] !== 334) return $fail('AUTH username', $reply);

    $reply = smtp_command($sock, base64_encode($pass));
    if (!$reply || $reply['code'] !== 235) {
        // Gmail's own message for a wrong/revoked app password is genuinely useful here (it says
        // so explicitly), which is why the reply text is logged in $fail rather than swallowed.
        return $fail('AUTH password', $reply);
    }

    $reply = smtp_command($sock, "MAIL FROM:<{$user}>");
    if (!$reply || $reply['code'] !== 250) return $fail('MAIL FROM', $reply);

    $reply = smtp_command($sock, "RCPT TO:<{$email}>");
    if (!$reply || $reply['code'] !== 250) return $fail('RCPT TO', $reply);

    $reply = smtp_command($sock, 'DATA');
    if (!$reply || $reply['code'] !== 354) return $fail('DATA', $reply);

    $safeCode = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
    $htmlBody = '<p>Your bet1x verification code is <strong>' . $safeCode . '</strong>.</p>'
              . '<p>It expires in ' . $ttlMinutes . ' minute' . ($ttlMinutes === 1 ? '' : 's')
              . '. Do not share it with anyone.</p>';

    // A multipart/alternative body: a plain-text part for clients that want it, an HTML part for
    // everyone else, both carrying the same code. Every line the OTP itself does NOT appear on is
    // boilerplate; the two lines that do are the only sensitive content in the whole message.
    $boundary = 'bet1x_' . bin2hex(random_bytes(8));
    $date = gmdate('D, d M Y H:i:s O');
    $messageId = '<' . bin2hex(random_bytes(16)) . '@bet1x.biz>';

    // SMTP DATA requires lines to end \r\n, and a leading '.' on a line must be doubled — neither
    // of which arises in this fixed template, but is worth stating since it is a classic footgun
    // for anyone extending this to include free-text content later.
    $data = "From: {$senderName} <{$user}>\r\n"
          . "To: <{$email}>\r\n"
          . "Subject: Your bet1x verification code\r\n"
          . "Date: {$date}\r\n"
          . "Message-ID: {$messageId}\r\n"
          . "MIME-Version: 1.0\r\n"
          . "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n"
          . "\r\n"
          . "--{$boundary}\r\n"
          . "Content-Type: text/plain; charset=UTF-8\r\n"
          . "\r\n"
          . "Your bet1x verification code is {$code}. It expires in {$ttlMinutes} minute"
          . ($ttlMinutes === 1 ? '' : 's') . ". Do not share it with anyone.\r\n"
          . "\r\n"
          . "--{$boundary}\r\n"
          . "Content-Type: text/html; charset=UTF-8\r\n"
          . "\r\n"
          . "{$htmlBody}\r\n"
          . "\r\n"
          . "--{$boundary}--\r\n"
          . ".\r\n";

    if (fwrite($sock, $data) === false) return $fail('DATA body write', null);
    $reply = smtp_read_reply($sock);
    if (!$reply || $reply['code'] !== 250) return $fail('DATA body', $reply);

    smtp_command($sock, 'QUIT'); // best-effort; the send already succeeded above
    fclose($sock);

    log_info('mailer: otp sent', ['email' => $email]);
    return ['ok' => true];
}
