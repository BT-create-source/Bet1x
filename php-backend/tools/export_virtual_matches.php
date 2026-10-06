<?php
/**
 * Export simulated virtual-league matches in Cricsheet's JSON format, so tools/build_cricket_model.py
 * can learn the match-betting model from the simulator itself.
 *
 *     php -d extension=zip php-backend/tools/export_virtual_matches.php [count=6000] [out=virtual-matches.zip]
 *     python php-backend/tools/build_cricket_model.py --virtual virtual-matches.zip
 *
 * Why: real-money Virtual Cricket must be priced from the same process that produces the results. The
 * simulator scores differently from real T20 (higher, and far less spread out), so pricing it with the
 * Cricsheet model would hand sharp players a steady edge on every innings-total line. Re-run both
 * commands whenever lib/cricket-mock.php's simulation changes; the secret seed does not matter here
 * (it changes WHICH matches happen, not how matches behave), so the export uses a throwaway one.
 */
if (PHP_SAPI !== 'cli') exit(1);
$count = max(500, (int) ($argv[1] ?? 6000));
$out = $argv[2] ?? 'virtual-matches.zip';
putenv('CRICKET_MOCK_ABANDON_EVERY=0');
putenv('CRICKET_VIRTUAL_SECRET=export-' . bin2hex(random_bytes(8)));
$root = dirname(__DIR__);
require $root . '/config.php';
foreach (['json', 'logger', 'db', 'http', 'auth', 'helpers', 'cricket-feed', 'cricket-mock'] as $f) require_once "$root/lib/$f.php";

$z = new ZipArchive();
if ($z->open($out, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) { fwrite(STDERR, "cannot write $out\n"); exit(1); }
$base = 2000000;
for ($i = 0; $i < $count; $i++) {
    $slot = $base + $i;
    $sim = cricket_mock_simulate($slot);
    $meta = cricket_mock_match_meta($slot);
    $name = ['a' => $meta['team_a']['name'], 'b' => $meta['team_b']['name']];
    $innings = []; $totals = [];
    foreach (array_slice($sim['innings_order'], 0, 2) as $k => $innKey) {
        $side = substr($innKey, 0, 1);
        $overs = []; $tot = 0;
        foreach ($sim['balls'] as $b) {
            if ($b['innings'] !== $innKey) continue;
            $o = (int) $b['overs'][0];
            $type = $b['ball_type'];
            $extrasKey = ['wide' => 'wides', 'no_ball' => 'noballs', 'bye' => 'byes', 'leg_bye' => 'legbyes'][$type] ?? null;
            $total = (int) $b['team_score']['runs'];
            $bat = (int) $b['batsman']['runs'];
            $d = ['batter' => $b['batsman']['player_key'], 'bowler' => $b['bowler']['player_key'],
                  'non_striker' => $b['non_striker']['player_key'],
                  'runs' => ['batter' => $bat, 'extras' => $total - $bat, 'total' => $total]];
            if ($extrasKey) $d['extras'] = [$extrasKey => max(1, $total - $bat)];
            if (!empty($b['wicket'])) $d['wickets'] = [['kind' => str_replace('_', ' ', $b['wicket']['kind']), 'player_out' => $b['wicket']['player_key']]];
            $overs[$o]['over'] = $o;
            $overs[$o]['deliveries'][] = $d;
            $tot += $total;
        }
        $innings[] = ['team' => $name[$side], 'overs' => array_values($overs)];
        $totals[] = [$name[$side], $tot];
    }
    if (count($innings) < 2) continue;
    $outcome = $totals[0][1] === $totals[1][1] ? ['result' => 'tie'] : ['winner' => $totals[0][1] > $totals[1][1] ? $totals[0][0] : $totals[1][0]];
    $z->addFromString("v$slot.json", json_encode(['info' => ['overs' => 20, 'teams' => array_values($name), 'outcome' => $outcome], 'innings' => $innings]));
    if (($i + 1) % 1000 === 0) fwrite(STDERR, ($i + 1) . " matches\n");
}
$z->close();
fwrite(STDERR, "wrote $out ($count simulated matches)\n");
