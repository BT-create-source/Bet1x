<?php
/** Database-free boundary and stale-request checks: php php-backend/tests/originals_clock.php */
require_once __DIR__ . '/../lib/originals.php';

$checks = 0;
function clock_check($ok, $label) {
    global $checks;
    if (!$ok) throw new RuntimeException($label);
    $checks++;
}
function clock_reject($in, $now, $code) {
    try { originals_require_betting($in, $now); }
    catch (OrigError $e) { clock_check($e->errCode === $code && $e->status === 409, $code); return; }
    throw new RuntimeException('Expected ' . $code);
}

$start = 1800000000000; // Exact cycle boundary.
foreach (array_keys(originals_catalogue()) as $game) {
    $c = originals_clock($start);
    clock_check($c['phase'] === 'betting' && $c['betting_closes_at'] === $start + 15000, "$game opening");
    clock_check(originals_clock($start + 14999)['phase'] === 'betting', "$game last betting millisecond");
    clock_reject([], $start + 15000, 'betting_closed');
    clock_reject([], $start + 29999, 'betting_closed');
    $next = originals_clock($start + 30000);
    clock_check($next['phase'] === 'betting' && $next['round_id'] === $c['round_id'] + 1, "$game next round");
    clock_reject(['round_id' => $c['round_id']], $start + 30000, 'stale_round');
    clock_check(originals_require_betting(['round_id' => $next['round_id']], $start + 30000) === $next, "$game current request");
    clock_check(originals_clock($start + 5000) === originals_clock($start + 5000), "$game same time for every player");
}
clock_check(originals_clock($start + 300000000)['round_id'] === originals_clock($start)['round_id'] + 10000,
    'Idle periods advance without a connected page or cron');
echo "PASS $checks shared betting clock checks\n";
