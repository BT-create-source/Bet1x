<?php
/**
 * Minimal levelled logger. Port of backend/lib/logger.js.
 *
 * Production emits single-line JSON so the output can be shipped straight into a log aggregator;
 * development keeps the human-readable prefixed format. Everything goes to error_log(), which on
 * cPanel lands in the account's error log — there is no stdout to write to.
 */

require_once __DIR__ . '/json.php';

const BET1X_LOG_LEVELS = ['error' => 0, 'warn' => 1, 'info' => 2, 'debug' => 3];

function log_threshold() {
    static $t = null;
    if ($t === null) {
        $lvl = cfg('LOG_LEVEL', 'info');
        $t = array_key_exists($lvl, BET1X_LOG_LEVELS) ? BET1X_LOG_LEVELS[$lvl] : BET1X_LOG_LEVELS['info'];
    }
    return $t;
}

function log_emit($level, $message, $meta = null) {
    if (BET1X_LOG_LEVELS[$level] > log_threshold()) return;
    if (cfg('IS_PRODUCTION')) {
        $line = ['ts' => js_iso(), 'level' => $level, 'msg' => $message];
        if ($meta && count($meta)) $line['meta'] = $meta;
        error_log(js_json_encode($line));
    } else {
        $suffix = ($meta && count($meta)) ? ' ' . js_json_encode($meta) : '';
        error_log("[bet1x-backend] [{$level}] {$message}{$suffix}");
    }
}

function log_error($msg, $meta = null) { log_emit('error', $msg, $meta); }
function log_warn($msg, $meta = null)  { log_emit('warn',  $msg, $meta); }
function log_info($msg, $meta = null)  { log_emit('info',  $msg, $meta); }
function log_debug($msg, $meta = null) { log_emit('debug', $msg, $meta); }
