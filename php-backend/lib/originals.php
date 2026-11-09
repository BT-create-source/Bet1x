<?php
/**
 * bet1x Originals — public clock-driven rounds for the ten single-step games, with interactive
 * personal rounds retained for Blackjack and Coinflip. See originals_shared.php for public draws.
 *
 * Game list and ideas from the MIT-licensed github.com/Paidguy/casino (an educational, browser-only
 * simulator). Its code was not reused as-is: there every result and the balance lived in the browser,
 * and several of its payout tables paid back more than 100%. Here every result is decided on the
 * server, every payout table is computed so it returns exactly the RTP the operator sets (each game's
 * test proves it), and the money moves like the site's other games. See ORIGINALS-LICENSE.md.
 *
 * PERSONAL GAME MONEY: each bet or action is ONE transaction, with the player's seed row locked first:
 *   lock seed row  ->  take the stake  ->  decide the result  ->  pay any win  ->  write the round and
 *   the ledger  ->  commit.
 * The lock serialises everything one player does in the originals, so double clicks and parallel
 * tabs run one after the other; anything that throws rolls the whole bet back (the stake is never
 * taken without a round, a win is never paid twice). A repeated request with the same client_ref
 * returns the round it already created instead of betting again.
 *
 * PERSONAL GAME FAIRNESS (Blackjack and Coinflip): every player has a hidden
 * server seed (its SHA-256 hash is shown before they play), a client seed they may choose, and a
 * nonce that counts their rounds. Round n's random numbers are
 *     HMAC_SHA256(key = server_seed, message = "client_seed:nonce:cursor")
 * read 4 bytes per float. Rotating the seed reveals the old one, so every past round can be re-derived
 * and checked against the hash shown beforehand. Rotation is refused while a round is still open
 * (Blackjack, Coinflip streak), because revealing the seed would reveal the rest of that round.
 *
 * Ledger rows: "<Label> Bet" (type Withdrawal) for every stake, "<Label> Win" (type Deposit) for every
 * payout — the wording classify_gameplay_transaction() / risk_total_wagered() recognise via
 * originals_classify(). Player history reads OriginalsRound directly (one row per round).
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/originals_shared.php';

const ORIGINALS_SCHEMA_VERSION = 2;

class OrigError extends RuntimeException {
    public $status;
    public $errCode;
    public function __construct($message, $status = 400, $errCode = null) {
        parent::__construct($message);
        $this->status = $status;
        $this->errCode = $errCode;
    }
}

function orig_fail($message, $status = 400, $code = null) { throw new OrigError($message, $status, $code); }

/** UTC epoch-based schedule: opening a page never starts or resets a countdown. */
function originals_clock($now = null) {
    $now = $now === null ? (int) floor(microtime(true) * 1000) : (int) $now;
    $cycle = 30000;
    $start = intdiv($now, $cycle) * $cycle;
    $close = $start + 15000;
    $betting = $now < $close;
    return ['round_id' => intdiv($start, $cycle), 'server_time' => $now,
            'phase' => $betting ? 'betting' : 'closed', 'betting_opens_at' => $start,
            'betting_closes_at' => $close, 'next_round_at' => $start + $cycle,
            'phase_ends_at' => $betting ? $close : $start + $cycle];
}

function originals_require_betting(array $in = [], $now = null) {
    $clock = originals_clock($now);
    if ($clock['phase'] !== 'betting') orig_fail('Betting is closed. Wait for the next countdown.', 409, 'betting_closed');
    if (isset($in['round_id']) && (string) $in['round_id'] !== (string) $clock['round_id']) {
        orig_fail('That betting window has ended. Wait for the current countdown.', 409, 'stale_round');
    }
    return $clock;
}

// -------------------------------------------------------------------------------------------------
// Catalogue
// -------------------------------------------------------------------------------------------------

/**
 * key => [label, file, rtp default (null = fixed by the rules), multi-step?, tagline]
 * The label is also the ledger wording ("<label> Bet" / "<label> Win"), so it must stay unique and
 * must never be changed once the game has live history.
 */
function originals_catalogue() {
    return [
        'dice'       => ['label' => 'Dice',        'rtp' => 0.99,  'multi' => false, 'fixed_rtp' => null,  'tag' => 'Roll over or under'],
        'coinflip'   => ['label' => 'Coinflip',    'rtp' => 0.99,  'multi' => true,  'fixed_rtp' => null,  'tag' => 'Double up, streak to 1000x'],
        'wheel'      => ['label' => 'Wheel',       'rtp' => 0.97,  'multi' => false, 'fixed_rtp' => null,  'tag' => 'Spin the fortune wheel'],
        'plinko'     => ['label' => 'Plinko',      'rtp' => 0.99,  'multi' => false, 'fixed_rtp' => null,  'tag' => 'Drop the ball, up to 1000x'],
        'keno'       => ['label' => 'Keno',        'rtp' => 0.98,  'multi' => false, 'fixed_rtp' => null,  'tag' => 'Pick up to 10 numbers'],
        'roulette'   => ['label' => 'Roulette',    'rtp' => null,  'multi' => false, 'fixed_rtp' => 36 / 37, 'tag' => 'European single zero'],
        'baccarat'   => ['label' => 'Baccarat',    'rtp' => null,  'multi' => false, 'fixed_rtp' => 0.9894, 'tag' => 'Player, Banker or Tie'],
        'dragontiger'=> ['label' => 'Dragon Tiger','rtp' => null, 'multi' => false, 'fixed_rtp' => 25 / 26, 'tag' => 'Dragon, Tiger or Tie'],
        'andarbahar' => ['label' => 'Andar Bahar', 'rtp' => 0.97,  'multi' => false, 'fixed_rtp' => null,  'tag' => 'Andar or Bahar?'],
        'slots'      => ['label' => 'Slots',       'rtp' => 0.96,  'multi' => false, 'fixed_rtp' => null,  'tag' => '5 reels, 10 lines, free spins'],
        'blackjack'  => ['label' => 'Blackjack',   'rtp' => null,  'multi' => true,  'fixed_rtp' => 0.995, 'tag' => 'Beat the dealer to 21'],
        'matka'      => ['label' => 'Matka',       'rtp' => 0.95,  'multi' => false, 'fixed_rtp' => null,  'tag' => 'Single, Jodi, Panna'],
    ];
}

function originals_game($game) {
    $cat = originals_catalogue();
    if (!is_string($game) || !isset($cat[$game])) orig_fail('Unknown game.', 404);
    static $loaded = [];
    if (!isset($loaded[$game])) {
        require_once __DIR__ . '/../games/originals/' . $game . '.php';
        $loaded[$game] = true;
    }
    return $cat[$game];
}

/** Ledger wording -> game, for the operator dashboard, risk checks and anything else reading the ledger. */
function originals_classify($details) {
    if (!is_string($details) || $details === '') return null;
    foreach (originals_catalogue() as $key => $g) {
        if (strpos($details, $g['label'] . ' Bet') === 0) return ['game' => $key, 'kind' => 'wager', 'label' => $g['label']];
        if (strpos($details, $g['label'] . ' Win') === 0) return ['game' => $key, 'kind' => 'win', 'label' => $g['label']];
    }
    return null;
}

/** Stake prefixes, for risk_total_wagered(). */
function originals_bet_prefixes() {
    return array_map(function ($g) { return $g['label'] . ' Bet'; }, array_values(originals_catalogue()));
}

// -------------------------------------------------------------------------------------------------
// Schema (applied on first use, on PostgreSQL and MySQL alike)
// -------------------------------------------------------------------------------------------------

function originals_ensure() {
    static $done = null;
    if ($done !== null) return $done;
    try {
        if (!db_ready()) return $done = false;
        if ((int) state_get('originals_schema_version') >= ORIGINALS_SCHEMA_VERSION) return $done = true;
        if (db_is_mysql()) {
            db_or_throw()->exec('CREATE TABLE IF NOT EXISTS `OriginalsSeed` (
                `username` VARCHAR(191) NOT NULL, `server_seed` VARCHAR(128) NOT NULL, `server_seed_hash` VARCHAR(128) NOT NULL,
                `client_seed` VARCHAR(64) NOT NULL, `nonce` BIGINT NOT NULL DEFAULT 0,
                `prev_server_seed` VARCHAR(128) NULL, `prev_server_seed_hash` VARCHAR(128) NULL, `prev_client_seed` VARCHAR(64) NULL, `prev_nonce` BIGINT NULL,
                `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                PRIMARY KEY (`username`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin');
            db_or_throw()->exec('CREATE TABLE IF NOT EXISTS `OriginalsRound` (
                `id` BIGINT NOT NULL AUTO_INCREMENT, `round_ref` VARCHAR(40) NOT NULL, `username` VARCHAR(191) NOT NULL, `game` VARCHAR(32) NOT NULL,
                `client_ref` VARCHAR(80) NOT NULL, `bet_amount` DOUBLE NOT NULL, `payout` DOUBLE NOT NULL DEFAULT 0, `multiplier` DOUBLE NOT NULL DEFAULT 0,
                `status` VARCHAR(16) NOT NULL, `params` MEDIUMTEXT NULL, `state` MEDIUMTEXT NULL, `result` MEDIUMTEXT NULL,
                `server_seed` VARCHAR(128) NOT NULL, `server_seed_hash` VARCHAR(128) NOT NULL, `client_seed` VARCHAR(64) NOT NULL, `nonce` BIGINT NOT NULL,
                `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3), `settled_at` DATETIME(3) NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `OriginalsRound_ref_key` (`round_ref`),
                UNIQUE KEY `OriginalsRound_user_ref_key` (`username`, `client_ref`),
                KEY `OriginalsRound_user_idx` (`username`, `id`),
                KEY `OriginalsRound_open_idx` (`username`, `game`, `status`),
                KEY `OriginalsRound_created_idx` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin');
        } else {
            $sql = @file_get_contents(__DIR__ . '/../sql/migration-019-originals-postgres.sql');
            if (!is_string($sql) || $sql === '') throw new RuntimeException('migration-019 file missing');
            db_or_throw()->exec($sql);
        }
        $indexed = with_named_lock('originals_public_index', 10, function () {
            if (db_is_mysql()) {
                $exists = scalar('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?', ['OriginalsRound', 'OriginalsRound_shared_idx'], 0);
                if (!(int) $exists) db_or_throw()->exec('CREATE INDEX `OriginalsRound_shared_idx` ON `OriginalsRound` (`game`, `status`, `username`, `id`)');
            } else {
                db_or_throw()->exec('CREATE INDEX IF NOT EXISTS "OriginalsRound_shared_idx" ON "OriginalsRound" ("game", "status", "username", "id")');
            }
            return true;
        });
        if (!$indexed) return $done = false;
        state_set('originals_schema_version', ORIGINALS_SCHEMA_VERSION);
        return $done = true;
    } catch (Throwable $e) {
        log_error('originals schema could not be applied', ['message' => $e->getMessage()]);
        return $done = false;
    }
}

// -------------------------------------------------------------------------------------------------
// Operator settings
// -------------------------------------------------------------------------------------------------

function originals_config_default($game) {
    $g = originals_catalogue()[$game];
    return [
        'enabled' => true,
        'rtp'     => $g['rtp'] !== null ? (float) $g['rtp'] : (float) $g['fixed_rtp'],
        'min_bet' => max(1.0, (float) cfg('MIN_BET')),
        'max_bet' => min((float) cfg('MAX_BET'), 10000.0),
        'max_win' => 1000000.0,
    ];
}

function originals_config_all() {
    $stored = state_get('originals_config');
    $stored = is_array($stored) ? $stored : [];
    $out = [];
    foreach (originals_catalogue() as $key => $g) {
        $c = array_merge(originals_config_default($key), is_array($stored[$key] ?? null) ? $stored[$key] : []);
        if ($g['rtp'] === null) $c['rtp'] = (float) $g['fixed_rtp'];   // fixed by the rules, never configurable
        $c['enabled'] = (bool) $c['enabled'];
        foreach (['rtp', 'min_bet', 'max_bet', 'max_win'] as $k) $c[$k] = (float) $c[$k];
        $out[$key] = $c;
    }
    return $out;
}

function originals_config($game) { return originals_config_all()[$game]; }

/** Apply an operator's patch to one game. Returns ['ok'=>bool, 'config'|'error']. */
function originals_config_update($game, array $patch) {
    $cat = originals_catalogue();
    if (!isset($cat[$game])) return ['ok' => false, 'error' => 'Unknown game.'];
    $all = originals_config_all();
    $c = $all[$game];
    if (array_key_exists('enabled', $patch)) $c['enabled'] = js_truthy($patch['enabled']);
    foreach (['rtp', 'min_bet', 'max_bet', 'max_win'] as $k) {
        if (!array_key_exists($k, $patch) || $patch[$k] === null || $patch[$k] === '') continue;
        if ($k === 'rtp' && $cat[$game]['rtp'] === null) continue;   // fixed-rule game
        $v = js_parse_float($patch[$k]);
        if (!js_is_finite($v)) return ['ok' => false, 'error' => "Invalid value for $k."];
        $c[$k] = (float) $v;
    }
    if ($c['rtp'] > 1.5) $c['rtp'] = $c['rtp'] / 100;   // accept 97 as well as 0.97
    if ($cat[$game]['rtp'] !== null && ($c['rtp'] < 0.80 || $c['rtp'] > 0.99)) return ['ok' => false, 'error' => 'RTP must be between 80% and 99%.'];
    if ($c['min_bet'] <= 0) return ['ok' => false, 'error' => 'Minimum bet must be positive.'];
    if ($c['max_bet'] < $c['min_bet']) return ['ok' => false, 'error' => 'Maximum bet must be at least the minimum bet.'];
    if ($c['max_win'] < $c['max_bet']) return ['ok' => false, 'error' => 'Maximum win must be at least the maximum bet.'];
    $c['rtp'] = round($c['rtp'], 4);
    $stored = state_get('originals_config');
    $stored = is_array($stored) ? $stored : [];
    $stored[$game] = $c;
    state_set('originals_config', $stored);
    return ['ok' => true, 'config' => originals_config($game)];
}

// -------------------------------------------------------------------------------------------------
// Provably fair random numbers
// -------------------------------------------------------------------------------------------------

/** The i-th float of a round: 4 bytes of HMAC_SHA256(server_seed, "client:nonce:cursor"), 8 per hash. */
function orig_float_at($serverSeed, $clientSeed, $nonce, $i) {
    $h = hash_hmac('sha256', $clientSeed . ':' . $nonce . ':' . intdiv($i, 8), $serverSeed, true);
    $o = ($i % 8) * 4;
    $b = unpack('C4', substr($h, $o, 4));
    return $b[1] / 256 + $b[2] / 65536 + $b[3] / 16777216 + $b[4] / 4294967296;
}

/** A cursor over one round's floats. Multi-step games persist ->index() and resume from it. */
final class OrigRng {
    private $server, $client, $nonce, $i;
    public function __construct($server, $client, $nonce, $start = 0) {
        $this->server = $server; $this->client = $client; $this->nonce = (int) $nonce; $this->i = (int) $start;
    }
    public function float() { return orig_float_at($this->server, $this->client, $this->nonce, $this->i++); }
    /** Uniform integer in [0, n). */
    public function int($n) { return min($n - 1, (int) floor($this->float() * $n)); }
    public function index() { return $this->i; }
    /** Fisher-Yates over a copy of $items. */
    public function shuffle(array $items) {
        for ($k = count($items) - 1; $k > 0; $k--) {
            $j = $this->int($k + 1);
            $t = $items[$k]; $items[$k] = $items[$j]; $items[$j] = $t;
        }
        return $items;
    }
}

function orig_new_server_seed() { return bin2hex(random_bytes(32)); }
function orig_new_client_seed() { return bin2hex(random_bytes(6)); }

function orig_valid_client_seed($s) {
    return is_string($s) && preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $s);
}

/** The player's seed row, locked for the rest of the transaction; created on first use. MUST be inside tx(). */
function orig_seed_lock($username) {
    $row = one('SELECT * FROM "OriginalsSeed" WHERE "username" = ? FOR UPDATE', [$username]);
    if ($row) return $row;
    $seed = orig_new_server_seed();
    q('INSERT INTO "OriginalsSeed" ("username","server_seed","server_seed_hash","client_seed","nonce","updated_at") VALUES (?,?,?,?,0,?)
       ON CONFLICT ("username") DO NOTHING',
      [$username, $seed, hash('sha256', $seed), orig_new_client_seed(), ms_to_sql()]);
    return one('SELECT * FROM "OriginalsSeed" WHERE "username" = ? FOR UPDATE', [$username]);
}

function orig_seed_view($row) {
    if (!$row) return null;
    return [
        'server_seed_hash' => $row['server_seed_hash'], 'client_seed' => $row['client_seed'], 'nonce' => (int) $row['nonce'],
        'previous' => $row['prev_server_seed'] ? [
            'server_seed' => $row['prev_server_seed'], 'server_seed_hash' => $row['prev_server_seed_hash'],
            'client_seed' => $row['prev_client_seed'], 'last_nonce' => (int) $row['prev_nonce'],
        ] : null,
    ];
}

/** Reveal the current server seed and start a new pair. Refused while a round is open. */
function originals_rotate_seed($username, $clientSeed = null) {
    if ($clientSeed !== null && $clientSeed !== '' && !orig_valid_client_seed($clientSeed)) {
        orig_fail('Client seed must be 1-64 letters, digits, _ or -.');
    }
    return tx(function () use ($username, $clientSeed) {
        $s = orig_seed_lock($username);
        if (one('SELECT 1 FROM "OriginalsRound" WHERE "username" = ? AND "status" = \'open\' LIMIT 1', [$username])) {
            orig_fail('Finish your open round before changing seeds.', 409, 'round_open');
        }
        $new = orig_new_server_seed();
        $client = ($clientSeed !== null && $clientSeed !== '') ? $clientSeed : orig_new_client_seed();
        q('UPDATE "OriginalsSeed" SET "prev_server_seed" = "server_seed", "prev_server_seed_hash" = "server_seed_hash",
             "prev_client_seed" = "client_seed", "prev_nonce" = "nonce", "server_seed" = ?, "server_seed_hash" = ?,
             "client_seed" = ?, "nonce" = 0, "updated_at" = ? WHERE "username" = ?',
          [$new, hash('sha256', $new), $client, ms_to_sql(), $username]);
        return orig_seed_view(one('SELECT * FROM "OriginalsSeed" WHERE "username" = ?', [$username]));
    });
}

function originals_seed_info($username) {
    return tx(function () use ($username) { return orig_seed_view(orig_seed_lock($username)); });
}

// -------------------------------------------------------------------------------------------------
// Rounds
// -------------------------------------------------------------------------------------------------

/** Money down to the paisa, never up: a payout is never rounded in the player's (or house's) favour by more than a paisa. */
function orig_money($x) { return floor((float) $x * 100 + 1e-7) / 100; }

function orig_json($v) { return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }

function orig_round_view(array $r, $activeHash = null) {
    $settled = $r['status'] === 'settled';
    $state = json_decode((string) $r['state'], true);
    $out = [
        'round_ref' => $r['round_ref'], 'game' => $r['game'], 'status' => $r['status'],
        'bet' => (float) $r['bet_amount'], 'payout' => (float) $r['payout'], 'multiplier' => (float) $r['multiplier'],
        'params' => json_decode((string) $r['params'], true), 'result' => json_decode((string) $r['result'], true),
        'server_seed_hash' => $r['server_seed_hash'], 'client_seed' => $r['client_seed'], 'nonce' => (int) $r['nonce'],
        'created_at' => js_iso($r['created_at']),
    ];
    if (isset($state['shared_round_id'])) $out['shared_round_id'] = (int) $state['shared_round_id'];
    if ($r['status'] === 'queued') {
        $out['result'] = null;
        $out['payout'] = 0;
        $out['multiplier'] = 0;
        $out['closes_at'] = (int) $state['closes_at'];
    }
    if (!$settled) $out['step'] = (int) (json_decode((string) $r['state'], true)['step'] ?? 0);
    // The seed is shown once it is no longer the player's active one (they rotated), never before.
    if ($settled && $activeHash !== null && $r['server_seed_hash'] !== $activeHash) $out['server_seed'] = $r['server_seed'];
    return $out;
}

function orig_client_ref($v) {
    if (!is_string($v) || !preg_match('/^[A-Za-z0-9_\-]{8,80}$/', $v)) orig_fail('Missing request reference - reload the page and try again.');
    return $v;
}

/** The ledger rows for a stake / a payout. */
function orig_ledger_bet($username, $label, $amount, $note = '') {
    insert_transaction(new_record_id('ORIG'), $username, 'Withdrawal', $amount, $label . ' Bet' . ($note !== '' ? ' ' . $note : ''), 'Completed');
}
function orig_ledger_win($username, $label, $amount, $multiplier, $roundRef = '') {
    $m = rtrim(rtrim(number_format((float) $multiplier, 4, '.', ''), '0'), '.');
    insert_transaction(new_record_id('ORIG_WIN'), $username, 'Deposit', $amount, $label . ' Win x' . ($m === '' ? '0' : $m) . ($roundRef !== '' ? ' Round ' . $roundRef : ''), 'Completed');
}

/** Cap a payout at the game's maximum win, then floor it to the paisa. */
function orig_cap($payout, array $cfg) { return orig_money(min((float) $payout, (float) $cfg['max_win'])); }

/**
 * Play a round: validates, then in ONE transaction takes the stake, decides the result, pays and records.
 * $in is the request body; the game's prepare() reads its own fields from it.
 */
function originals_play(array $user, $game, array $in) {
    $meta = originals_game($game);
    $cfg = originals_config($game);
    if (!$cfg['enabled']) orig_fail($meta['label'] . ' is currently unavailable.', 403, 'disabled');
    $clientRef = orig_client_ref($in['client_ref'] ?? null);
    $prep = call_user_func('orig_' . $game . '_prepare', $in, $cfg);
    $stake = orig_money($prep['stake']);
    if ($stake < $cfg['min_bet'] - 1e-9) orig_fail('Minimum bet is ₹' . js_num_str($cfg['min_bet']) . '.');
    if ($stake > $cfg['max_bet'] + 1e-9) orig_fail('Maximum bet is ₹' . js_num_str($cfg['max_bet']) . '.');
    if (originals_is_shared($game)) return originals_shared_queue($user, $game, $clientRef, $in, $prep);
    $username = $user['username'];

    return tx(function () use ($user, $username, $game, $meta, $cfg, $clientRef, $prep, $stake, $in) {
        $seed = orig_seed_lock($username);
        $existing = one('SELECT * FROM "OriginalsRound" WHERE "username" = ? AND "client_ref" = ?', [$username, $clientRef]);
        if ($existing) {
            if ($existing['game'] !== $game) orig_fail('That request reference belongs to another game.', 409, 'ref_conflict');
            return ['round' => orig_round_view($existing, $seed['server_seed_hash']), 'balance' => orig_balance($user['id']), 'replayed' => true];
        }
        // Check after acquiring the lock: a request queued before close can arrive too late.
        originals_require_betting($in);
        if ($meta['multi'] && one('SELECT 1 FROM "OriginalsRound" WHERE "username" = ? AND "game" = ? AND "status" = \'open\' LIMIT 1', [$username, $game])) {
            orig_fail('Finish your current round first.', 409, 'round_open');
        }
        if (debit_wallet($user['id'], $stake) === null) orig_fail('Insufficient balance.', 400, 'insufficient_balance');
        $nonce = (int) $seed['nonce'];
        q('UPDATE "OriginalsSeed" SET "nonce" = "nonce" + 1, "updated_at" = ? WHERE "username" = ?', [ms_to_sql(), $username]);
        $rng = new OrigRng($seed['server_seed'], $seed['client_seed'], $nonce);
        $out = call_user_func('orig_' . $game . '_play', $stake, $prep['params'], $rng, $cfg);

        $open = ($out['status'] ?? 'settled') === 'open';
        $payout = $open ? 0.0 : orig_cap($out['payout'] ?? 0, $cfg);
        $state = $out['state'] ?? null;
        if (is_array($state)) $state['config'] = $cfg;
        if (is_array($state)) $state['cursor'] = $rng->index();
        $ref = 'OR' . strtoupper(bin2hex(random_bytes(7)));
        $now = ms_to_sql();
        q('INSERT INTO "OriginalsRound" ("round_ref","username","game","client_ref","bet_amount","payout","multiplier","status","params","state","result",
             "server_seed","server_seed_hash","client_seed","nonce","created_at","settled_at") VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
          [$ref, $username, $game, $clientRef, $stake, $payout, $stake > 0 ? round($payout / $stake, 6) : 0, $open ? 'open' : 'settled',
           orig_json($prep['params']), $state === null ? null : orig_json($state), orig_json($out['result'] ?? null),
           $seed['server_seed'], $seed['server_seed_hash'], $seed['client_seed'], $nonce, $now, $open ? null : $now]);
        orig_ledger_bet($username, $meta['label'], $stake, 'Round ' . $ref);
        if ($payout > 0) {
            credit_wallet($user['id'], $payout);
            orig_ledger_win($username, $meta['label'], $payout, $payout / $stake, $ref);
        }
        $row = one('SELECT * FROM "OriginalsRound" WHERE "round_ref" = ?', [$ref]);
        return ['round' => orig_round_view($row, $seed['server_seed_hash']), 'balance' => orig_balance($user['id']), 'replayed' => false];
    });
}

/**
 * The next step of an open multi-step round (Blackjack hit/stand/double/split/insurance, Coinflip
 * flip/cashout). The game's action() may ask for an extra stake (double, split, insurance), which is
 * taken in the same transaction. $in carries round_ref, action and the game's own fields.
 */
function originals_action(array $user, $game, array $in) {
    $meta = originals_game($game);
    if (!$meta['multi']) orig_fail('This game has no actions.', 400);
    $cfg = originals_config($game);
    $roundRef = is_string($in['round_ref'] ?? null) ? $in['round_ref'] : '';
    $action = is_string($in['action'] ?? null) ? strtolower($in['action']) : '';
    $step = isset($in['step']) ? (int) $in['step'] : -1;
    $username = $user['username'];

    return tx(function () use ($user, $username, $game, $meta, $cfg, $roundRef, $action, $step, $in) {
        $seed = orig_seed_lock($username);
        $r = one('SELECT * FROM "OriginalsRound" WHERE "round_ref" = ? AND "username" = ? FOR UPDATE', [$roundRef, $username]);
        if (!$r || $r['game'] !== $game) orig_fail('Round not found.', 404);
        $state = json_decode((string) $r['state'], true);
        if ($r['status'] !== 'open' || !is_array($state)) orig_fail('This round is already finished.', 409, 'round_closed');
        // Every action names the step it was made at, so a double-click is not applied twice.
        if ($step !== (int) ($state['step'] ?? 0)) orig_fail('That round already moved on. Refreshing…', 409, 'stale');
        $cfg = $state['config'] ?? $cfg;

        $rng = new OrigRng($r['server_seed'], $r['client_seed'], (int) $r['nonce'], (int) ($state['cursor'] ?? 0));
        $round = ['bet' => (float) $r['bet_amount'], 'params' => json_decode((string) $r['params'], true), 'state' => $state];
        $out = call_user_func('orig_' . $game . '_action', $round, $action, $in, $rng, $cfg);

        $extra = orig_money($out['extra_stake'] ?? 0);
        if ($extra > 0) {
            originals_require_betting($in);
            if (debit_wallet($user['id'], $extra) === null) orig_fail('Insufficient balance for that.', 400, 'insufficient_balance');
            orig_ledger_bet($username, $meta['label'], $extra, 'Round ' . $roundRef . ' (' . $action . ')');
        }
        $totalStake = orig_money((float) $r['bet_amount'] + $extra);
        $open = ($out['status'] ?? 'settled') === 'open';
        $payout = $open ? 0.0 : orig_cap($out['payout'] ?? 0, $cfg);
        $newState = $out['state'] ?? $state;
        $newState['config'] = $cfg;
        $newState['cursor'] = $rng->index();
        $newState['step'] = (int) ($state['step'] ?? 0) + 1;
        $now = ms_to_sql();
        q('UPDATE "OriginalsRound" SET "bet_amount" = ?, "payout" = ?, "multiplier" = ?, "status" = ?, "state" = ?, "result" = ?, "settled_at" = ?
           WHERE "round_ref" = ?',
          [$totalStake, $payout, $totalStake > 0 ? round($payout / $totalStake, 6) : 0, $open ? 'open' : 'settled',
           orig_json($newState), orig_json($out['result'] ?? null), $open ? null : $now, $roundRef]);
        if ($payout > 0) {
            credit_wallet($user['id'], $payout);
            orig_ledger_win($username, $meta['label'], $payout, $payout / $totalStake, $roundRef);
        }
        $row = one('SELECT * FROM "OriginalsRound" WHERE "round_ref" = ?', [$roundRef]);
        return ['round' => orig_round_view($row, $seed['server_seed_hash']), 'balance' => orig_balance($user['id'])];
    });
}

function orig_balance($userId) {
    $u = find_user_by_id($userId);
    return $u ? (float) $u['wallet_balance'] : null;
}

/** A game's page state: settings, the player's open round (if any), seeds and recent rounds. */
function originals_state($user, $game) {
    $meta = originals_game($game);
    $cfg = originals_config($game);
    $public = originals_shared_public($game);
    $out = ['ok' => true, 'clock' => originals_clock(), 'game' => $game, 'label' => $meta['label'], 'config' => originals_public_config($game, $cfg),
            'signed_in' => (bool) $user, 'public_rounds' => $public, 'pending' => [], 'open_round' => null, 'recent' => [], 'seed' => null, 'balance' => null];
    if (function_exists('orig_' . $game . '_info')) $out['info'] = call_user_func('orig_' . $game . '_info', $cfg);
    if (!$user) return $out;
    $username = $user['username'];
    $out['seed'] = originals_seed_info($username);
    $active = $out['seed']['server_seed_hash'];
    $out['balance'] = orig_balance($user['id']);
    $out['pending'] = array_map(function ($r) { return orig_round_view($r); }, all('SELECT * FROM "OriginalsRound" WHERE "username" = ? AND "game" = ? AND "status" = \'queued\' ORDER BY "id"', [$username, $game]));
    $open = one('SELECT * FROM "OriginalsRound" WHERE "username" = ? AND "game" = ? AND "status" = \'open\' ORDER BY "id" DESC LIMIT 1', [$username, $game]);
    if ($open) $out['open_round'] = orig_round_view($open, $active) + ['step' => (int) (json_decode((string) $open['state'], true)['step'] ?? 0)];
    $out['recent'] = array_map(function ($r) use ($active) { return orig_round_view($r, $active); },
        all('SELECT * FROM "OriginalsRound" WHERE "username" = ? AND "game" = ? AND "status" = \'settled\' ORDER BY "id" DESC LIMIT 20', [$username, $game]));
    return $out;
}

function originals_public_config($game, array $cfg) {
    $meta = originals_catalogue()[$game];
    return ['enabled' => $cfg['enabled'], 'rtp' => $cfg['rtp'], 'rtp_fixed' => $meta['rtp'] === null,
            'min_bet' => $cfg['min_bet'], 'max_bet' => $cfg['max_bet'], 'max_win' => $cfg['max_win']];
}

/** Player history (profile page): one row per settled round, newest first. */
function originals_history_rows($username, $limit = 200) {
    if (!originals_ensure()) return [];
    try {
        $rows = all('SELECT "game","bet_amount","payout","created_at" FROM "OriginalsRound" WHERE "username" = ? AND "status" = \'settled\'
                     ORDER BY "id" DESC LIMIT ' . (int) $limit, [$username]);
    } catch (Throwable $e) {
        return [];
    }
    $cat = originals_catalogue();
    return array_map(function ($r) use ($cat) {
        $won = (float) $r['payout'] > 0;
        return [
            'game'      => $cat[$r['game']]['label'] ?? $r['game'],
            'result'    => $won ? 'won' : 'lost',
            'amount'    => round($won ? (float) $r['payout'] : (float) $r['bet_amount'], 2),
            'stake'     => round((float) $r['bet_amount'], 2),
            'timestamp' => $r['created_at'],
        ];
    }, $rows);
}

// -------------------------------------------------------------------------------------------------
// Shared maths helpers for the games
// -------------------------------------------------------------------------------------------------

/**
 * Scale a payout shape so its expected return is as close to $rtp as possible WITHOUT exceeding it.
 * $probs[i] is outcome i's probability, $shape[i] its relative size (0 = loses). Every payout is
 * floored to $dp decimals and capped at $cap. A binary search on the scale factor handles the cap and
 * the flooring together. Returns the list of multipliers.
 */
function orig_scale_to_rtp(array $probs, array $shape, $rtp, $cap = 1000.0, $dp = 2) {
    $f = pow(10, $dp);
    $build = function ($k) use ($shape, $cap, $f) {
        $out = [];
        foreach ($shape as $i => $s) $out[$i] = $s <= 0 ? 0.0 : min($cap, floor($s * $k * $f + 1e-9) / $f);
        return $out;
    };
    $ev = function ($m) use ($probs) { $e = 0.0; foreach ($m as $i => $v) $e += $probs[$i] * $v; return $e; };
    $lo = 0.0; $hi = 1.0;
    while ($ev($build($hi)) < $rtp && $hi < 1e12) $hi *= 2;
    for ($it = 0; $it < 200; $it++) {
        $mid = ($lo + $hi) / 2;
        if ($ev($build($mid)) <= $rtp) $lo = $mid; else $hi = $mid;
    }
    $out = $build($lo);
    // Flooring every payout leaves up to a cent per outcome unpaid, which on small tables is close to
    // a whole percent. Give it back: raise outcomes by one step, most likely first, while the table
    // stays at or under the RTP. Outcomes with the same shape value (e.g. Plinko's mirrored buckets)
    // move together, so a symmetric table stays symmetric.
    $groups = [];
    foreach ($shape as $i => $s) if ($s > 0) $groups[(string) round($s, 9)][] = $i;
    $step = 1 / $f;
    uasort($groups, function ($a, $b) use ($probs) {
        $pa = 0; foreach ($a as $i) $pa += $probs[$i];
        $pb = 0; foreach ($b as $i) $pb += $probs[$i];
        return $pb <=> $pa;
    });
    $cur = $ev($out);
    for ($round = 0; $round < 1000; $round++) {
        $moved = false;
        foreach ($groups as $idx) {
            $gp = 0; foreach ($idx as $i) $gp += $probs[$i];
            if ($out[$idx[0]] + $step > $cap + 1e-9) continue;
            if ($cur + $gp * $step <= $rtp + 1e-12) {
                foreach ($idx as $i) $out[$i] = round($out[$i] + $step, $dp);
                $cur += $gp * $step;
                $moved = true;
            }
        }
        if (!$moved) break;
    }
    return $out;
}

function orig_expected(array $probs, array $mults) { $e = 0.0; foreach ($mults as $i => $v) $e += $probs[$i] * $v; return $e; }

function orig_choose($n, $k) {
    if ($k < 0 || $k > $n) return 0.0;
    $r = 1.0;
    for ($i = 1; $i <= $k; $i++) $r = $r * ($n - $k + $i) / $i;
    return $r;
}

/** A card 0..51 -> ['r' => 1..13 (A=1), 's' => 0..3, 'c' => short code like "QH"]. */
function orig_card($idx) {
    $r = intdiv($idx, 4) + 1;
    $s = $idx % 4;
    $ranks = [1 => 'A', 2 => '2', 3 => '3', 4 => '4', 5 => '5', 6 => '6', 7 => '7', 8 => '8', 9 => '9', 10 => '10', 11 => 'J', 12 => 'Q', 13 => 'K'];
    return ['r' => $r, 's' => $s, 'c' => $ranks[$r] . 'SHDC'[$s]];
}

/** Read the list of {type, value, amount} chips a multi-bet game sends. */
function orig_read_chips($raw, $maxChips = 60) {
    if (!is_array($raw) || !$raw) orig_fail('Place at least one bet.');
    if (count($raw) > $maxChips) orig_fail('Too many bets in one round.');
    $out = []; $sum = 0.0;
    foreach ($raw as $b) {
        if (!is_array($b)) orig_fail('Invalid bet.');
        $amt = js_parse_float($b['amount'] ?? null);
        if (!js_is_finite($amt) || $amt <= 0) orig_fail('Invalid bet amount.');
        $amt = orig_money($amt);
        if ($amt <= 0) orig_fail('Invalid bet amount.');
        $type = is_string($b['type'] ?? null) ? strtolower(trim($b['type'])) : '';
        $value = isset($b['value']) ? (is_scalar($b['value']) ? (string) $b['value'] : '') : '';
        $out[] = ['type' => $type, 'value' => $value, 'amount' => $amt];
        $sum += $amt;
    }
    return [$out, orig_money($sum)];
}

function orig_bet_amount($in) {
    $v = js_parse_float($in['bet_amount'] ?? null);
    if (!js_is_finite($v) || $v <= 0) orig_fail('Invalid bet amount.');
    return orig_money($v);
}
