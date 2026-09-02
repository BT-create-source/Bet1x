<?php
/**
 * Request, Response and a small Express-shaped router.
 *
 * The router is hand-written rather than pulled from a framework for one specific reason: this
 * application's authorisation depends on REGISTRATION ORDER, not just on path patterns. In
 * server.js, `app.use('/api/db', requireAdmin, requireDatabase)` sits at line 912, and six routes
 * registered ABOVE it — /api/db/users/{login,signup,status,adjust-balance,reset-balance} and
 * GET /api/db/transactions — deliberately escape that gate and keep their own, looser rules. A
 * router that matched by specificity, or that applied mounts to everything regardless of order,
 * would either lock every player out of login or expose the whole admin surface. So layers are
 * walked strictly in the order they were added, exactly like Express.
 */

require_once __DIR__ . '/json.php';

// -------------------------------------------------------------------------------------------------
// Request
// -------------------------------------------------------------------------------------------------

class Req {
    public $method;
    public $path;
    public $query   = [];
    public $body    = [];
    public $headers = [];
    public $params  = [];
    public $rawBody = '';
    public $auth    = null;   // verified session payload, or null
    public $ip      = '';

    public static function capture() {
        $r = new self();
        $r->method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        // The front controller is reached through a rewrite, so REQUEST_URI still carries the path
        // the browser actually asked for. That is the one to route on.
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $qpos = strpos($uri, '?');
        $r->path = $qpos === false ? $uri : substr($uri, 0, $qpos);
        $r->path = rawurldecode($r->path);
        // Collapse duplicate slashes and drop a trailing one (except for the bare root), so
        // /api//mines/state and /api/mines/state/ reach the same handler Express would have.
        $r->path = preg_replace('#/+#', '/', $r->path);
        if (strlen($r->path) > 1) $r->path = rtrim($r->path, '/');
        if ($r->path === '') $r->path = '/';

        $r->query = $_GET ?? [];

        foreach ($_SERVER as $k => $v) {
            if (strpos($k, 'HTTP_') === 0) {
                $name = strtolower(str_replace('_', '-', substr($k, 5)));
                $r->headers[$name] = $v;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) $r->headers['content-type'] = $_SERVER['CONTENT_TYPE'];

        $r->rawBody = file_get_contents('php://input');
        $ctype = strtolower($r->headers['content-type'] ?? '');

        // express.json({limit:'256kb'}) + express.urlencoded({extended:true, limit:'256kb'})
        if (strlen($r->rawBody) > 256 * 1024) {
            $r->body = [];
        } elseif (strpos($ctype, 'application/json') !== false) {
            $decoded = json_decode($r->rawBody, true);
            $r->body = is_array($decoded) ? $decoded : [];
        } elseif (strpos($ctype, 'application/x-www-form-urlencoded') !== false) {
            $r->body = $_POST ?: [];
            if (!$r->body && $r->rawBody !== '') { parse_str($r->rawBody, $parsed); $r->body = $parsed ?: []; }
        } elseif (strpos($ctype, 'multipart/form-data') !== false) {
            $r->body = $_POST ?: [];
        } elseif ($r->rawBody !== '') {
            // No content type but a body: try JSON, as body-parser effectively does for API clients.
            $decoded = json_decode($r->rawBody, true);
            $r->body = is_array($decoded) ? $decoded : [];
        }

        $r->ip = self::clientIp();
        return $r;
    }

    /**
     * Client IP, honouring X-Forwarded-For only as far as TRUST_PROXY allows.
     *
     * Mirrors Express's `trust proxy` count: with TRUST_PROXY=n, the nth address from the RIGHT of
     * the forwarding chain is the client. Trusting the leftmost unconditionally would let anyone
     * spoof their way around the login rate limiter by sending their own header.
     */
    public static function clientIp() {
        $remote = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $trust = (int) cfg('TRUST_PROXY', 0);
        if ($trust <= 0) return $remote;
        $fwd = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if ($fwd === '') return $remote;
        $chain = array_map('trim', explode(',', $fwd));
        $chain[] = $remote;
        $idx = count($chain) - 1 - $trust;
        if ($idx < 0) $idx = 0;
        return $chain[$idx] !== '' ? $chain[$idx] : $remote;
    }

    /** Body value, or null. */
    public function b($key, $default = null) {
        return array_key_exists($key, $this->body) ? $this->body[$key] : $default;
    }

    /** Query value, or null. */
    public function q($key, $default = null) {
        return array_key_exists($key, $this->query) ? $this->query[$key] : $default;
    }

    /** Route parameter captured from a ':name' segment. */
    public function p($key, $default = null) {
        return array_key_exists($key, $this->params) ? $this->params[$key] : $default;
    }

    public function header($name, $default = null) {
        $name = strtolower($name);
        return array_key_exists($name, $this->headers) ? $this->headers[$name] : $default;
    }

    public function isSecure() {
        if (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') return true;
        if ((int) cfg('TRUST_PROXY', 0) > 0) {
            return strtolower((string)$this->header('x-forwarded-proto', '')) === 'https';
        }
        return false;
    }
}

// -------------------------------------------------------------------------------------------------
// Response
// -------------------------------------------------------------------------------------------------

class Res {
    public $sent = false;
    private $status = 200;
    private $headers = [];

    public function status($code) { $this->status = (int)$code; return $this; }

    public function setHeader($name, $value) { $this->headers[$name] = $value; return $this; }

    /** res.json(body) — encoded exactly the way JSON.stringify would. */
    public function json($body) {
        if ($this->sent) return $this;
        $this->sent = true;
        $payload = js_json_encode($body);
        if (!headers_sent()) {
            http_response_code($this->status);
            header('Content-Type: application/json; charset=utf-8');
            foreach ($this->headers as $k => $v) header("$k: $v");
        }
        echo $payload;
        return $this;
    }

    /** res.send(text) with an explicit content type. */
    public function send($text, $contentType = 'text/plain; charset=utf-8') {
        if ($this->sent) return $this;
        $this->sent = true;
        if (!headers_sent()) {
            http_response_code($this->status);
            header('Content-Type: ' . $contentType);
            foreach ($this->headers as $k => $v) header("$k: $v");
        }
        echo $text;
        return $this;
    }

    public function redirect($code, $url) {
        if ($this->sent) return $this;
        $this->sent = true;
        if (!headers_sent()) {
            http_response_code((int)$code);
            header('Location: ' . $url);
            foreach ($this->headers as $k => $v) header("$k: $v");
        }
        return $this;
    }

    /** res.sendFile — used only by the SPA-style 404 fallback. */
    public function sendFile($path, $contentType = 'text/html; charset=utf-8') {
        if ($this->sent) return $this;
        if (!is_file($path)) return $this;
        $this->sent = true;
        if (!headers_sent()) {
            http_response_code($this->status);
            header('Content-Type: ' . $contentType);
            foreach ($this->headers as $k => $v) header("$k: $v");
        }
        readfile($path);
        return $this;
    }
}

// -------------------------------------------------------------------------------------------------
// Router
// -------------------------------------------------------------------------------------------------

class Router {
    /** Layers in registration order. Order IS the authorisation model — see the file header. */
    private $layers = [];

    /** app.use([prefix,] ...middleware) */
    public function useMw($prefixOrFn, ...$rest) {
        if (is_callable($prefixOrFn)) {
            $this->layers[] = ['kind' => 'use', 'prefix' => '/', 'handlers' => array_merge([$prefixOrFn], $rest)];
        } else {
            $this->layers[] = ['kind' => 'use', 'prefix' => rtrim($prefixOrFn, '/'), 'handlers' => $rest];
        }
        return $this;
    }

    public function get($paths, ...$h)  { return $this->route('GET', $paths, $h); }
    public function post($paths, ...$h) { return $this->route('POST', $paths, $h); }
    public function put($paths, ...$h)  { return $this->route('PUT', $paths, $h); }
    public function delete($paths, ...$h) { return $this->route('DELETE', $paths, $h); }
    /** app.all(...) — every method. */
    public function all($paths, ...$h)  { return $this->route('*', $paths, $h); }

    private function route($method, $paths, $handlers) {
        $this->layers[] = [
            'kind'     => 'route',
            'method'   => $method,
            'paths'    => is_array($paths) ? $paths : [$paths],
            'handlers' => $handlers,
        ];
        return $this;
    }

    /**
     * Does a concrete request path match a registered pattern?
     * Supports ':name' segments; returns the captured params, or null for no match.
     */
    private static function matchPath($pattern, $path) {
        if (strpos($pattern, ':') === false) {
            return $pattern === $path ? [] : null;
        }
        $pSeg = explode('/', trim($pattern, '/'));
        $rSeg = explode('/', trim($path, '/'));
        if (count($pSeg) !== count($rSeg)) return null;
        $params = [];
        foreach ($pSeg as $i => $seg) {
            if (strlen($seg) > 1 && $seg[0] === ':') {
                if ($rSeg[$i] === '') return null;
                $params[substr($seg, 1)] = $rSeg[$i];
            } elseif ($seg !== $rSeg[$i]) {
                return null;
            }
        }
        return $params;
    }

    /** Is this request path inside a `use` mount point? */
    private static function underPrefix($prefix, $path) {
        if ($prefix === '' || $prefix === '/') return true;
        return $path === $prefix || strpos($path, $prefix . '/') === 0;
    }

    /**
     * Walk the layers in order. A handler that writes a response ends the walk; one that returns
     * without writing is the equivalent of Express's next().
     */
    public function dispatch(Req $req, Res $res) {
        foreach ($this->layers as $layer) {
            if ($res->sent) return true;

            if ($layer['kind'] === 'use') {
                if (!self::underPrefix($layer['prefix'], $req->path)) continue;
                foreach ($layer['handlers'] as $fn) {
                    $fn($req, $res);
                    if ($res->sent) return true;
                }
                continue;
            }

            if ($layer['method'] !== '*' && $layer['method'] !== $req->method) {
                // HEAD is served by the GET handler, as in Express.
                if (!($req->method === 'HEAD' && $layer['method'] === 'GET')) continue;
            }
            foreach ($layer['paths'] as $pattern) {
                $params = self::matchPath($pattern, $req->path);
                if ($params === null) continue;
                $req->params = $params;
                foreach ($layer['handlers'] as $fn) {
                    $fn($req, $res);
                    if ($res->sent) return true;
                }
                break;
            }
        }
        return $res->sent;
    }
}
