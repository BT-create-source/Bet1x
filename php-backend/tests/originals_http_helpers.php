<?php
/** Compatibility for legacy money suites that assert final wallets; public tests inspect raw queued responses. */
function originals_test_wait_window($path) {
    if (!preg_match('~^/api/originals/[^/]+/play$~', $path)) return;
    while (($clock = originals_clock())['phase'] !== 'betting' || $clock['betting_closes_at'] - $clock['server_time'] < 2000) usleep(100000);
}

function originals_test_resolve($path, $token, array $response, $port = 5141) {
    if (!empty($GLOBALS['ORIGINALS_TEST_DEFER'])) return $response;
    if (($response[1]['round']['status'] ?? null) !== 'queued' || !preg_match('~^/api/originals/([^/]+)/play$~', $path, $match)) return $response;
    $ref = $response[1]['round']['round_ref'];
    $deadline = microtime(true) + 35;
    do {
        usleep(200000);
        [$code, $state] = http('GET', '/api/originals/' . $match[1] . '/state', $token, null, $port);
        if ($code !== 200) return [$code, $state, json_encode($state)];
        foreach ($state['recent'] ?? [] as $round) if ($round['round_ref'] === $ref) {
            $response[1]['round'] = $round;
            $response[1]['balance'] = $state['balance'];
            $response[2] = json_encode($response[1]);
            return $response;
        }
    } while (microtime(true) < $deadline);
    throw new RuntimeException('Public round did not settle: ' . $ref);
}
