<?php
/**
 * "Your Eleven" — public read endpoints (lobby + squad).
 *
 * Phase 1 of the port. These two routes are read-only: they touch no wallet, create nothing, and
 * settle nothing, so mounting them cannot affect any existing game. The money paths (team save,
 * contest join, settlement) arrive in later phases.
 *
 * The whole file is only ever required and registered when FANTASY_ENABLED is true — see the guard
 * in index.php — so with the flag off these URLs 404 exactly like any unknown path, rather than
 * existing and refusing.
 *
 * Both endpoints are deliberately unauthenticated. They return nothing but public cricket fixture
 * and squad information, the same data any visitor could read off the source site, and keeping them
 * open means the lobby renders for a visitor who has not signed in yet. Everything that involves a
 * team, an entry or money will require auth.
 */

require_once __DIR__ . '/../lib/http.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/fantasy.php';

function register_fantasy_routes(Router $app) {

    /**
     * GET /api/fantasy/matches?status=UPCOMING
     *
     * Fixtures for the lobby, already grouped by tournament so the client does not have to. Groups
     * come back in order of their soonest match, which puts whatever starts next at the top of the
     * page rather than ordering series alphabetically.
     */
    $app->get('/api/fantasy/matches', function (Req $req, Res $res) {
        try {
            $status = $req->q('status', 'UPCOMING');
            $rows = fantasy_list_matches($status);

            // Group by series, preserving the soonest-first order the query already produced: the
            // first time a series is seen fixes that group's position.
            $groups = [];
            $index  = [];
            foreach ($rows as $row) {
                $match = fantasy_public_match($row);
                $key   = $match['series_name'] !== '' ? $match['series_name'] : 'Other Matches';
                if (!isset($index[$key])) {
                    $index[$key] = count($groups);
                    $groups[] = ['series_name' => $key, 'matches' => []];
                }
                $groups[$index[$key]]['matches'][] = $match;
            }

            $res->json([
                'success' => true,
                'status'  => strtoupper((string) $status),
                'count'   => count($rows),
                'groups'  => $groups,
                // The client counts down from its own clock but corrects against this, so a device
                // with a wrong time does not show a locked match as still joinable.
                'server_time_ms' => now_ms(),
            ]);
        } catch (Throwable $err) {
            fail500($res, $err, 'fantasy_matches');
        }
    });

    /**
     * GET /api/fantasy/matches/:id/players
     *
     * The squad for one fixture, grouped WK / BAT / ALL / BOWL, plus the rules the team builder has
     * to enforce, so the screen shows the same numbers the server will validate against instead of
     * hardcoding its own copy of them.
     */
    $app->get('/api/fantasy/matches/:id/players', function (Req $req, Res $res) {
        try {
            $match = fantasy_find_match($req->p('id'));
            if (!$match) {
                $res->status(404)->json(['error' => 'Match not found.']);
                return;
            }

            $public = fantasy_public_match($match);
            $res->json([
                'success' => true,
                'match'   => $public,
                'rules'   => fantasy_rules(),
                // The two real teams, for the "max 7 from one team" counter in the builder.
                'teams'   => [
                    ['name' => $public['team_a'], 'short' => $public['team_a_short']],
                    ['name' => $public['team_b'], 'short' => $public['team_b_short']],
                ],
                'players' => fantasy_players_grouped($public['id']),
                'server_time_ms' => now_ms(),
            ]);
        } catch (Throwable $err) {
            fail500($res, $err, 'fantasy_players');
        }
    });
}
