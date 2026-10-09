<?php
/**
 * Retire Virtual Cricket when switching to a real feed (CRICKET_SOURCE=sportmonks / roanuz).
 *
 * Once the source is no longer 'mock', the simulated matches stop moving, so anything still open on
 * them would never settle. This refunds every one of those stakes in full and takes the simulated
 * fixtures out of the lobbies. Finished virtual matches and their settled bets are left as history.
 *
 *     php php-backend/tools/retire-virtual-cricket.php            dry run: counts only, changes nothing
 *     php php-backend/tools/retire-virtual-cricket.php --apply    refund and retire
 *
 * Safe to run more than once: every refund path is idempotent (an already-voided bet, round or contest
 * is skipped). On cPanel without shell access, run it once from a one-off cron line and read the log:
 *     /usr/local/bin/php /home/<account>/public_html/php-backend/tools/retire-virtual-cricket.php --apply > /home/<account>/retire-virtual.log 2>&1
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("command line only\n"); }
$root = dirname(__DIR__);
require $root . '/config.php';
foreach (['json', 'logger', 'db', 'http', 'auth', 'helpers', 'cricket-feed', 'bbb', 'exchange'] as $f) require_once "$root/lib/$f.php";
require_once "$root/lib/fantasy-feed.php";   // pulls in the rest of Your 11
$apply = in_array('--apply', array_slice($argv, 1), true);
if (!db_ready()) { fwrite(STDERR, "Database unreachable.\n"); exit(1); }
$LIKE = 'mock\_m%';
$why = 'Virtual Cricket retired';

echo ($apply ? "APPLYING" : "DRY RUN (add --apply to act)") . " — cricket source is now: " . cricket_source_mode() . "\n";
if ($apply && cricket_source_mode() === 'mock') {
    fwrite(STDERR, "The cricket source is still 'mock'. Set CRICKET_SOURCE=sportmonks (and remove CRICKET_VIRTUAL) first.\n");
    exit(1);
}

// 1. Ball by Ball: open / suspended rounds on simulated matches -> void, every stake back.
$rounds = all('SELECT "id" FROM "bbb_rounds" WHERE "match_key" LIKE ? AND "status" IN (?,?)', [$LIKE, 'OPEN', 'SUSPENDED']);
$bbbStake = (float) scalar('SELECT COALESCE(SUM(b."stake"),0) FROM "bbb_bets" b JOIN "bbb_rounds" r ON r."id" = b."round_id" WHERE r."match_key" LIKE ? AND r."status" IN (?,?)', [$LIKE, 'OPEN', 'SUSPENDED'], 0);
echo "Ball by Ball: " . count($rounds) . " open rounds, Rs " . number_format($bbbStake, 2) . " staked\n";
if ($apply) foreach ($rounds as $r) bbb_void_round((int) $r['id'], 'virtual_retired');

// 2. Match betting: pending bets on simulated matches -> void, liability back.
$bets = all('SELECT "id","liability" FROM "mx_bets" WHERE "match_key" LIKE ? AND "status" = ?', [$LIKE, 'PENDING']);
echo "Match betting: " . count($bets) . " pending bets, Rs " . number_format(array_sum(array_column($bets, 'liability')), 2) . " to refund\n";
if ($apply) foreach ($bets as $b) mx_void_bet((int) $b['id'], $why);

// 3. Your 11: contests on simulated fixtures not yet settled -> cancelled, every entry fee back.
$matches = all('SELECT "id","match_title" FROM "fantasy_matches" WHERE "feed_key" LIKE ? AND "status" NOT IN (?,?)', [$LIKE, 'SETTLED', 'CANCELLED']);
$contests = 0; $entries = 0; $refunded = 0.0;
foreach ($matches as $m) {
    foreach (all('SELECT "id","filled_spots" FROM "fantasy_contests" WHERE "match_id" = ? AND "status" NOT IN (?,?)', [(int) $m['id'], 'SETTLED', 'CANCELLED']) as $c) {
        $contests++; $entries += (int) $c['filled_spots'];
        if ($apply) { $r = fantasy_void_contest((int) $c['id'], $why); $refunded += (float) ($r['refunded'] ?? 0); }
    }
    if ($apply) q('UPDATE "fantasy_matches" SET "status" = ?, "updated_at" = ? WHERE "id" = ?', ['CANCELLED', ms_to_sql(now_ms()), (int) $m['id']]);
}
echo "Your 11: " . count($matches) . " virtual fixtures, $contests open contests, $entries entries" . ($apply ? ", Rs " . number_format($refunded, 2) . " refunded" : '') . "\n";

// 4. Lobbies: simulated fixtures not yet started disappear; one caught mid-play is marked abandoned.
$future = (int) scalar('SELECT COUNT(*) FROM "cricket_match_feed" WHERE "match_key" LIKE ? AND "status" = ?', [$LIKE, 'not_started'], 0);
$midPlay = (int) scalar('SELECT COUNT(*) FROM "cricket_match_feed" WHERE "match_key" LIKE ? AND "status" IN (?,?)', [$LIKE, 'live', 'innings_break'], 0);
echo "Lobby: $future upcoming virtual fixtures to remove, $midPlay in play to mark abandoned\n";
if ($apply) {
    q('DELETE FROM "cricket_match_feed" WHERE "match_key" LIKE ? AND "status" = ? AND NOT EXISTS (SELECT 1 FROM "mx_bets" b WHERE b."match_key" = "cricket_match_feed"."match_key") '
      . 'AND NOT EXISTS (SELECT 1 FROM "bbb_rounds" r WHERE r."match_key" = "cricket_match_feed"."match_key")', [$LIKE, 'not_started']);
    q('UPDATE "cricket_match_feed" SET "status" = ?, "status_text" = ?, "updated_at" = ? WHERE "match_key" LIKE ? AND "status" IN (?,?,?)',
      ['abandoned', $why, ms_to_sql(now_ms()), $LIKE, 'live', 'innings_break', 'not_started']);
    log_info('cricket: virtual cricket retired', ['rounds' => count($rounds), 'bets' => count($bets), 'contests' => $contests]);
    echo "Done.\n";
}
