<?php
/**
 * Security middleware: HTTPS enforcement, helmet-equivalent response headers, and CORS.
 *
 * Every header below is the one helmet 8 actually emits for the configuration in server.js, not an
 * approximation. Where helmet's default differs from what server.js asked for, the override is
 * reproduced — the script-src-attr line in particular, because helmet defaults it to 'none' and
 * these pages wire up roughly 150 buttons with onclick=/onsubmit= attributes that the default
 * silently kills in the browser while the server looks perfectly healthy.
 */

require_once __DIR__ . '/http.php';

/**
 * Terminate plaintext HTTP at the edge.
 *
 * Relies on the reverse proxy setting X-Forwarded-Proto, which is why TRUST_PROXY must be
 * configured alongside FORCE_HTTPS.
 */
function force_https(Req $req, Res $res) {
    if (!cfg('FORCE_HTTPS')) return;
    if ($req->isSecure()) return;

    // Reaching here on a site that IS served over TLS means the app cannot see it — almost always
    // FORCE_HTTPS=true with TRUST_PROXY=0 behind a proxy that terminates TLS, which answers 403 to
    // every login, signup, bet, deposit and withdrawal while the static pages keep loading fine.
    // That combination is close to invisible from the outside, so say so loudly in the log rather
    // than leaving the next person to infer it from "the forms do nothing".
    log_warn('force_https: request not recognised as secure — every non-GET will be refused', [
        'method'            => $req->method,
        'path'              => $req->path,
        'trust_proxy'       => (int) cfg('TRUST_PROXY', 0),
        'server_port'       => $_SERVER['SERVER_PORT'] ?? null,
        'x_forwarded_proto' => $req->header('x-forwarded-proto', null),
        'hint'              => 'If the site is behind a proxy/CDN, set TRUST_PROXY=1 in php-backend/.env',
    ]);

    if ($req->method !== 'GET' && $req->method !== 'HEAD') {
        $res->status(403)->json(['error' => 'HTTPS is required.']);
        return;
    }
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $uri  = $_SERVER['REQUEST_URI'] ?? '/';
    $res->redirect(308, 'https://' . $host . $uri);
}

/** helmet(...) — the exact header set for the options server.js passes. */
function security_headers(Req $req, Res $res) {
    if (headers_sent()) return;

    $corsOrigins = cfg('CORS_ORIGINS', []);
    $connectSrc = array_merge(["'self'"], $corsOrigins);

    // Directive order matches the object literal in server.js, so the emitted header is identical.
    $directives = [
        "default-src"     => ["'self'"],
        // The pages are hand-written HTML with inline <script>/<style> blocks throughout, so inline
        // execution has to stay allowed. The value of this policy is that it still pins every
        // EXTERNAL origin: no third-party script host can be injected, and 'unsafe-eval' is absent.
        "script-src"      => ["'self'", "'unsafe-inline'"],
        // Must be set explicitly: 'unsafe-inline' in script-src does NOT cover inline event-handler
        // attributes, and helmet's default of 'none' would block every onclick= on the site.
        "script-src-attr" => ["'unsafe-inline'"],
        "style-src"       => ["'self'", "'unsafe-inline'", 'https://fonts.googleapis.com'],
        "font-src"        => ["'self'", 'https://fonts.gstatic.com', 'data:'],
        "img-src"         => ["'self'", 'data:', 'blob:'],
        "connect-src"     => $connectSrc,
        "object-src"      => ["'none'"],
        "base-uri"        => ["'self'"],
        "form-action"     => ["'self'"],
        "frame-ancestors" => ["'none'"],
    ];

    $parts = [];
    foreach ($directives as $name => $values) {
        $parts[] = $name . ' ' . implode(' ', $values);
    }
    // `upgradeInsecureRequests: config.FORCE_HTTPS ? [] : null` — an empty array emits the
    // directive with no value; null removes it entirely.
    if (cfg('FORCE_HTTPS')) $parts[] = 'upgrade-insecure-requests';

    header('Content-Security-Policy: ' . implode(';', $parts));

    // helmet's remaining defaults, with crossOriginEmbedderPolicy disabled (so no COEP header) and
    // crossOriginResourcePolicy set to same-site.
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Cross-Origin-Resource-Policy: same-site');
    header('Origin-Agent-Cluster: ?1');
    header('Referrer-Policy: no-referrer');
    header('X-Content-Type-Options: nosniff');
    header('X-DNS-Prefetch-Control: off');
    header('X-Download-Options: noopen');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Permitted-Cross-Domain-Policies: none');
    header('X-XSS-Protection: 0');
    header_remove('X-Powered-By');   // app.disable('x-powered-by')

    if (cfg('FORCE_HTTPS')) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

/**
 * CORS.
 *
 * Same-origin deployments need no CORS at all, which is the default here. When CORS_ORIGINS is
 * configured the allowlist is EXACT — the pre-hardening callback approved every origin it was
 * handed, which combined with `credentials: true` let any website on the internet drive a
 * logged-in user's session.
 */
function cors_middleware(Req $req, Res $res) {
    $origin = $req->header('origin');
    $allowed = cfg('CORS_ORIGINS', []);

    $ok = false;
    if ($origin === null || $origin === '') {
        $ok = true;                                   // same-origin, curl, server-to-server
    } elseif (in_array($origin, $allowed, true)) {
        $ok = true;
    } elseif (!cfg('IS_PRODUCTION') && count($allowed) === 0) {
        $ok = true;
    }

    if (!headers_sent()) {
        header('Vary: Origin', false);
        if ($ok && $origin !== null && $origin !== '') {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Access-Control-Allow-Credentials: true');
        }
    }

    // Preflight: cors answers 204 and does not run the route.
    if ($req->method === 'OPTIONS') {
        if (!headers_sent()) {
            header('Access-Control-Allow-Methods: GET,HEAD,PUT,PATCH,POST,DELETE');
            $reqHeaders = $req->header('access-control-request-headers');
            if ($reqHeaders) header('Access-Control-Allow-Headers: ' . $reqHeaders);
            header('Content-Length: 0');
        }
        $res->status(204)->send('');
    }
}
