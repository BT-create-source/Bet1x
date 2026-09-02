<?php
/**
 * JSON encoding that matches JavaScript's JSON.stringify byte for byte.
 *
 * This file exists because "identical response shapes" is a stricter requirement than it sounds,
 * and PHP's json_encode differs from JSON.stringify in four ways that would each show up in a
 * response body:
 *
 *   1. WHOLE FLOATS.  Every number in JavaScript is a double, and JSON.stringify prints 2000.0 as
 *      `2000`. Depending on the build, PHP prints either `2000` or `2000.0`, and a wallet balance
 *      rendering as `2000.0` where the Node build sent `2000` is exactly the kind of difference
 *      this port is not allowed to introduce. js_json_encode() converts every float with an
 *      integral value to an int before encoding, so the output is `2000` on any PHP build.
 *
 *   2. SLASHES.  PHP escapes `/` as `\/` by default; JavaScript does not. A details string like
 *      "Mines Cash Out — 2.5x" is unaffected, but a URL or an ISO timestamp is not.
 *
 *   3. UNICODE.  PHP escapes non-ASCII to \uXXXX by default; JavaScript emits it literally. The
 *      rupee sign in "Need at least ₹10 to join" is the case that matters here.
 *
 *   4. undefined vs null.  JSON.stringify DROPS a key whose value is `undefined` but KEEPS one
 *      whose value is `null`. server.js relies on this: the operator-only fields on the colour
 *      state response are `isOperator ? optimal : undefined`, and a player must receive a body with
 *      no such key at all — not one with a null in it. PHP has no `undefined`, so UNDEF is a
 *      sentinel that js_json_encode() strips.
 *
 * NAN and INF are emitted as `null`, which is what JSON.stringify does and what PHP's own
 * json_encode refuses to do.
 */

/** Sentinel for JavaScript's `undefined`: a key holding this is omitted from the output entirely. */
final class JsUndefined {
    private static $instance = null;
    public static function get() {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }
    private function __construct() {}
}

/** Shorthand for the sentinel above. Use where server.js wrote `undefined`. */
function UNDEF() { return JsUndefined::get(); }

/**
 * Force a value to encode as a JSON object rather than an array.
 *
 * PHP cannot tell an empty list from an empty map — both are `[]` — so `{}` in the original would
 * come out as `[]` here. Every place server.js sends a possibly-empty map (colour-room overrides,
 * the per-option Boundary pool, the rig-audit per-instance breakdown) goes through this.
 */
function js_object($value) {
    if ($value === null) return new stdClass();
    if (is_object($value)) return $value;
    if (is_array($value) && count($value) === 0) return new stdClass();
    return (object) $value;
}

/**
 * Recursively normalise a value into something json_encode will render the way JSON.stringify does.
 * Strips UNDEF keys, flattens whole floats to ints, and neutralises NAN/INF.
 */
function js_normalize($value) {
    if ($value instanceof JsUndefined) return null; // callers strip these before recursing

    if (is_float($value)) {
        if (is_nan($value) || is_infinite($value)) return null;
        // Only collapse to int when the value is genuinely whole AND fits, so a large double is
        // never silently truncated.
        if ($value == floor($value) && abs($value) < 9.2233720368547758e18) {
            return (int) $value;
        }
        return $value;
    }

    if (is_array($value)) {
        // An EMPTY array is a list, and must encode as [] — JSON.stringify([]) is "[]".
        //
        // This case has to be handled before the list test below, because that test is
        // `array_keys($v) === range(0, count($v) - 1)` and PHP's range(0, -1) returns [0, -1],
        // not []. An empty array therefore fails the test, falls through to the object branch and
        // renders as {}. Every empty collection in every response was affected: an empty bet book,
        // an empty round history, an empty seat list, an empty ledger — all of which the frontend
        // calls .length and .map() on.
        if (count($value) === 0) return [];

        $isList = array_keys($value) === range(0, count($value) - 1);
        if ($isList) {
            $out = [];
            foreach ($value as $item) {
                // An undefined inside an ARRAY becomes null in JSON.stringify, it is not dropped.
                $out[] = ($item instanceof JsUndefined) ? null : js_normalize($item);
            }
            return $out;
        }
        $out = [];
        foreach ($value as $k => $v) {
            if ($v instanceof JsUndefined) continue; // key omitted, exactly like JSON.stringify
            $out[(string)$k] = js_normalize($v);
        }
        // An associative array that ends up empty must still be an object, not [].
        return count($out) === 0 ? new stdClass() : $out;
    }

    if ($value instanceof DateTimeInterface) {
        return js_iso($value);
    }

    if (is_object($value)) {
        $out = new stdClass();
        foreach (get_object_vars($value) as $k => $v) {
            if ($v instanceof JsUndefined) continue;
            $out->{$k} = js_normalize($v);
        }
        return $out;
    }

    return $value;
}

/** Encode exactly as JSON.stringify would. */
function js_json_encode($value) {
    $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    $encoded = json_encode(js_normalize($value), $flags);
    return $encoded === false ? 'null' : $encoded;
}

/**
 * A Date rendered the way Prisma + JSON.stringify render one: ISO 8601, UTC, milliseconds, `Z`.
 * e.g. 2026-08-29T14:03:11.482Z
 *
 * Accepts a DateTimeInterface, a millisecond epoch, or a 'Y-m-d H:i:s.v'-shaped string as the
 * database driver returns it.
 */
function js_iso($value = null) {
    if ($value === null) {
        $dt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    } elseif ($value instanceof DateTimeInterface) {
        $dt = ms_to_datetime(((float)$value->format('U.u')) * 1000.0);
        if ($dt === null) return null;
    } elseif (is_numeric($value)) {
        $dt = ms_to_datetime((float) $value);
        if ($dt === null) return null;
    } else {
        try {
            $dt = new DateTimeImmutable((string)$value, new DateTimeZone('UTC'));
        } catch (Exception $e) {
            return null;
        }
    }
    return $dt->format('Y-m-d\TH:i:s.v\Z');
}

/**
 * Millisecond epoch — the PHP spelling of Date.now().
 *
 * Every timestamp comparison, TTL and deadline in the games is in milliseconds, so this is used
 * throughout rather than time().
 */
function now_ms() {
    return (int) round(microtime(true) * 1000);
}

/** Parse a DATETIME/TIMESTAMP(3) string (UTC) into a millisecond epoch. */
function sql_to_ms($value) {
    if ($value === null || $value === '') return null;
    try {
        $dt = new DateTimeImmutable((string)$value, new DateTimeZone('UTC'));
    } catch (Exception $e) {
        return null;
    }
    return (int) round(((float)$dt->format('U.u')) * 1000);
}

/** Format a millisecond epoch as a TIMESTAMP(3) string in UTC. */
function ms_to_sql($ms = null) {
    if ($ms === null) $ms = now_ms();
    $dt = ms_to_datetime((float)$ms);
    if ($dt === null) $dt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    return $dt->format('Y-m-d H:i:s.v');
}

/**
 * Millisecond epoch -> a UTC DateTimeImmutable, or null.
 *
 * createFromFormat returns FALSE for anything it cannot parse, and chaining ->setTimezone() onto a
 * false is a fatal error rather than an exception — so the result is always checked here rather
 * than at four separate call sites.
 */
function ms_to_datetime($ms) {
    $dt = DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $ms / 1000.0));
    if ($dt === false) return null;
    return $dt->setTimezone(new DateTimeZone('UTC'));
}

/**
 * Node's `new Date(x).toLocaleTimeString('en-US', { hour12: false })` — a bare HH:MM:SS in the
 * SERVER's local timezone, with no date and no zone.
 *
 * Reproduced rather than tidied because the colour-prediction history and the recent-results
 * endpoint both send this exact shape and the frontend renders it directly. Note the mixed time
 * bases this creates in one payload — round ids are computed in UTC while these are local — which
 * is faithful to the original and is why php-backend sets its own timezone explicitly.
 *
 * en-US with hour12:false renders midnight as "24:00:00" rather than "00:00:00"; that quirk is
 * reproduced here for the same reason.
 */
function js_locale_time($ms = null) {
    if ($ms === null) $ms = now_ms();
    $dt = ms_to_datetime((float)$ms);
    if ($dt === null) return null;
    $dt = $dt->setTimezone(new DateTimeZone(date_default_timezone_get()));
    $h = (int) $dt->format('G');
    $hh = ($h === 0) ? '24' : str_pad((string)$h, 2, '0', STR_PAD_LEFT);
    return $hh . ':' . $dt->format('i:s');
}
