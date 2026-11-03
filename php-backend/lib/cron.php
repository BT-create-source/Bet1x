<?php
/**
 * The once-a-minute background tick, shared by its two triggers:
 *
 *   - cron/tick.php            — the cPanel cron job (command line), the normal way, and
 *   - GET/POST /api/cron/tick  — routes/cron.php, for hosts whose cron does not run (some GoDaddy
 *                                plans): an outside scheduler such as cron-job.org opens that URL
 *                                every minute with the secret CRON_HTTP_KEY.
 *
 * Both run exactly this function, so the work, the overlap lock and the heartbeat that /api/ready
 * reads are identical whichever one fires. Expects the game libraries to be loaded already.
 */

/**
 * Advance every game that may be sitting idle, then record the heartbeat.
 * Returns ['ran' => false] when another tick already holds the lock (nothing done, not an error).
 */
function cron_main_tick() {
    $startedAt = microtime(true);
    $ran = false;

    with_named_lock('cron_tick', 5, function () use (&$ran) {
        $ran = true;
        // 1. Aviator — advance the phase machine (waiting -> running -> crashed -> waiting).
        try { aviator_tick(); }
        catch (Throwable $e) { log_error('cron: aviator tick failed', ['message' => $e->getMessage()]); }

        // 2. Astronaut — settle a round nobody is watching, so auto cash-outs and losses never sit open.
        try { astro_tick(); }
        catch (Throwable $e) { log_error('cron: astronaut tick failed', ['message' => $e->getMessage()]); }

        // 3. Colour Prediction — settle any round that ended while nobody was polling.
        try { color_advance_all_rooms(); }
        catch (Throwable $e) { log_error('cron: colour settlement failed', ['message' => $e->getMessage()]); }

        // 4. Teen Patti — turn timeouts, stuck tables, presence eviction, filler turns, post-hand
        //    resets, and the organic traffic engine that keeps idle rooms looking alive.
        try { tp_sweep(); }
        catch (Throwable $e) { log_error('cron: teen patti sweep failed', ['message' => $e->getMessage()]); }

        // 5. Housekeeping for the tables that replaced in-memory state.
        try { live_presence_sweep(); }  catch (Throwable $e) {}
        try { rate_limit_sweep(); }     catch (Throwable $e) {}
        try { rig_trim(); }             catch (Throwable $e) {}
    });

    $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
    if (!$ran) return ['ran' => false, 'ms' => $durationMs];

    // Heartbeat. Written only on a run that reached the end, so it records "the tick actually
    // completed", not merely "the trigger fired". /api/ready compares it with now and reports the
    // deployment not-ready once it is older than CRON_STALE_SECONDS — the signal an uptime monitor
    // alerts on, since a stopped tick is otherwise silent (idle rounds simply stop settling).
    try {
        state_set('cron_last_run', ['at' => now_ms(), 'duration_ms' => $durationMs]);
    } catch (Throwable $e) {
        log_error('cron: could not record heartbeat', ['message' => $e->getMessage()]);
    }
    log_debug('cron: tick complete', ['ms' => $durationMs]);
    return ['ran' => true, 'ms' => $durationMs];
}
