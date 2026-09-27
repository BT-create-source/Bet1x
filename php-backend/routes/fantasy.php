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
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/fantasy.php';
require_once __DIR__ . '/../lib/fantasy-teams.php';
require_once __DIR__ . '/../lib/fantasy-contests.php';

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

    // ---------------------------------------------------------------------------------------------
    // Teams. Authenticated: a team belongs to an account, and every read is scoped to the caller so
    // one player cannot fetch another's XI by guessing an id.
    //
    // No money moves in any of these. Entry fees arrive with contests in the next phase; a saved
    // team on its own costs nothing, which is why a player may keep several and edit them freely
    // until the deadline.
    // ---------------------------------------------------------------------------------------------

    /**
     * Shared by create and replace.
     *
     * Only player IDS are read from the request. Credits, roles and team names all come from the
     * stored squad inside fantasy_save_team(), so a forged credit value in the body cannot buy a
     * team that would not otherwise be legal.
     */
    $saveTeam = function (Req $req, Res $res, $teamId) {
        $username = acting_username($req);
        $user = get_or_create_user($username);
        if (!$user) {
            $res->status(404)->json(['error' => 'Account not found.']);
            return;
        }

        $matchId = (int) $req->b('match_id', 0);
        if ($matchId <= 0) {
            $res->status(422)->json(['error' => 'match_id is required.']);
            return;
        }

        $lineup = [
            'players'      => (array) $req->b('players', []),
            'captain'      => $req->b('captain_player_id', 0),
            'vice_captain' => $req->b('vice_captain_player_id', 0),
            'team_name'    => $req->b('team_name', ''),
        ];

        $result = fantasy_save_team((int) $user['id'], $matchId, $lineup, $teamId);
        if (!$result['ok']) {
            $res->status((int) ($result['status'] ?? 422))->json(['error' => $result['error']]);
            return;
        }

        $res->json([
            'success'      => true,
            'team'         => fantasy_team_with_players($result['team_id'], (int) $user['id']),
            'credits_used' => $result['credits_used'],
            'role_counts'  => $result['role_counts'],
            'team_counts'  => $result['team_counts'],
        ]);
    };

    /** POST /api/fantasy/teams — save a new XI. */
    $app->post('/api/fantasy/teams', 'require_auth', function (Req $req, Res $res) use ($saveTeam) {
        try {
            $saveTeam($req, $res, null);
        } catch (Throwable $err) {
            fail500($res, $err, 'fantasy_team_create');
        }
    });

    /**
     * POST /api/fantasy/teams/:id — replace one of the caller's existing XIs.
     *
     * POST rather than PUT because the Router in lib/http.php only routes GET and POST, matching the
     * rest of this backend.
     */
    $app->post('/api/fantasy/teams/:id', 'require_auth', function (Req $req, Res $res) use ($saveTeam) {
        try {
            $saveTeam($req, $res, (int) $req->p('id'));
        } catch (Throwable $err) {
            fail500($res, $err, 'fantasy_team_update');
        }
    });

    /** GET /api/fantasy/my-teams[?match_id=N] — the caller's saved XIs. */
    $app->get('/api/fantasy/my-teams', 'require_auth', function (Req $req, Res $res) {
        try {
            $username = acting_username($req);
            $user = get_or_create_user($username);
            if (!$user) {
                $res->status(404)->json(['error' => 'Account not found.']);
                return;
            }
            $matchId = $req->q('match_id', null);
            $teams = fantasy_list_user_teams((int) $user['id'], $matchId === null ? null : (int) $matchId);
            $res->json([
                'success' => true,
                'count'   => count($teams),
                'teams'   => $teams,
                'rules'   => fantasy_rules(),
                'server_time_ms' => now_ms(),
            ]);
        } catch (Throwable $err) {
            fail500($res, $err, 'fantasy_my_teams');
        }
    });

    /** GET /api/fantasy/teams/:id — one of the caller's XIs, with its eleven players. */
    $app->get('/api/fantasy/teams/:id', 'require_auth', function (Req $req, Res $res) {
        try {
            $username = acting_username($req);
            $user = get_or_create_user($username);
            if (!$user) {
                $res->status(404)->json(['error' => 'Account not found.']);
                return;
            }
            $team = fantasy_team_with_players((int) $req->p('id'), (int) $user['id']);
            if (!$team) {
                // Deliberately the same 404 whether the team does not exist or belongs to someone
                // else, so ids cannot be probed for existence.
                $res->status(404)->json(['error' => 'Team not found.']);
                return;
            }
            $match = fantasy_find_match($team['match_id']);
            $res->json([
                'success' => true,
                'team'    => $team,
                'match'   => $match ? fantasy_public_match($match) : null,
                'rules'   => fantasy_rules(),
                'server_time_ms' => now_ms(),
            ]);
        } catch (Throwable $err) {
            fail500($res, $err, 'fantasy_team_get');
        }
    });

    // ---------------------------------------------------------------------------------------------
    // Contests
    // ---------------------------------------------------------------------------------------------

    /**
     * GET /api/fantasy/matches/:id/contests
     *
     * Public, so a visitor who has not signed in can still see what is on offer. When a token IS
     * present each contest is flagged with whether that account has already entered — attach_session()
     * in index.php populates $req->auth for every request without rejecting anonymous ones, so this
     * needs no middleware to tell the two cases apart.
     */
    $app->get('/api/fantasy/matches/:id/contests', function (Req $req, Res $res) {
        try {
            $match = fantasy_find_match($req->p('id'));
            if (!$match) {
                $res->status(404)->json(['error' => 'Match not found.']);
                return;
            }
            $userId = null;
            if ($req->auth) {
                $user = get_or_create_user(acting_username($req));
                if ($user) $userId = (int) $user['id'];
            }
            $res->json([
                'success'   => true,
                'match'     => fantasy_public_match($match),
                'contests'  => fantasy_list_contests((int) $match['id'], $userId),
                'server_time_ms' => now_ms(),
            ]);
        } catch (Throwable $err) {
            fail500($res, $err, 'fantasy_contests');
        }
    });

    /**
     * POST /api/fantasy/contests/:id/join — pay the entry fee and enter a team.
     *
     * The only route in this module that moves money. Everything about how it does so lives in
     * fantasy_join_contest(); this is just the HTTP shell, including mapping its status code.
     */
    $app->post('/api/fantasy/contests/:id/join', 'require_auth', function (Req $req, Res $res) {
        try {
            $username = acting_username($req);
            $user = get_or_create_user($username);
            if (!$user) {
                $res->status(404)->json(['error' => 'Account not found.']);
                return;
            }

            $teamId = (int) $req->b('team_id', 0);
            if ($teamId <= 0) {
                $res->status(422)->json(['error' => 'team_id is required.']);
                return;
            }

            $result = fantasy_join_contest((int) $user['id'], $user['username'],
                                           (int) $req->p('id'), $teamId);
            if (!$result['ok']) {
                $res->status((int) ($result['status'] ?? 409))->json(['error' => $result['error']]);
                return;
            }

            $res->json([
                'success'     => true,
                'entry_id'    => $result['entry_id'],
                'entry_fee'   => $result['entry_fee'],
                'new_balance' => $result['new_balance'],
            ]);
        } catch (Throwable $err) {
            fail500($res, $err, 'fantasy_contest_join');
        }
    });

    /** GET /api/fantasy/my-entries[?match_id=N] — the caller's contest entries. */
    $app->get('/api/fantasy/my-entries', 'require_auth', function (Req $req, Res $res) {
        try {
            $user = get_or_create_user(acting_username($req));
            if (!$user) {
                $res->status(404)->json(['error' => 'Account not found.']);
                return;
            }
            $matchId = $req->q('match_id', null);
            $entries = fantasy_my_entries((int) $user['id'], $matchId === null ? null : (int) $matchId);
            $res->json([
                'success' => true,
                'count'   => count($entries),
                'entries' => $entries,
                'server_time_ms' => now_ms(),
            ]);
        } catch (Throwable $err) {
            fail500($res, $err, 'fantasy_my_entries');
        }
    });

    // ---------------------------------------------------------------------------------------------
    // Operator
    // ---------------------------------------------------------------------------------------------

    /**
     * POST /api/admin/fantasy/contests — create a contest on a match.
     *
     * Under /api/admin deliberately: routes/admin.php registers useMw('/api/admin', 'require_admin'),
     * so this path already carries the operator gate, and 'require_admin' is named here as well so
     * the requirement is visible at the route rather than only implied by its prefix.
     *
     * Contest creation is an operator action, never automatic. A contest carries a real entry fee and
     * a rake, so which ones exist on a fixture should not be something a scraper's cron invents.
     *
     * Example body:
     *   {"match_id":1,"title":"Mega Contest","entry_fee":49,"total_spots":1000,"rake_pct":15,
     *    "prize_rules":[{"from":1,"to":1,"pct":20},{"from":2,"to":2,"pct":10},
     *                   {"from":3,"to":10,"pct":5},{"from":11,"to":60,"pct":0.6}]}
     */
    $app->post('/api/admin/fantasy/contests', 'require_admin', function (Req $req, Res $res) {
        try {
            $matchId = (int) $req->b('match_id', 0);
            if ($matchId <= 0) {
                $res->status(422)->json(['error' => 'match_id is required.']);
                return;
            }
            $result = fantasy_create_contest($matchId, [
                'title'       => $req->b('title', ''),
                'entry_fee'   => $req->b('entry_fee', 0),
                'total_spots' => $req->b('total_spots', 0),
                'rake_pct'    => $req->b('rake_pct', 0),
                'prize_pool'  => $req->b('prize_pool', 0),
                'prize_rules' => $req->b('prize_rules', null),
            ]);
            if (!$result['ok']) {
                $res->status((int) ($result['status'] ?? 422))->json(['error' => $result['error']]);
                return;
            }
            $contest = one('SELECT * FROM "fantasy_contests" WHERE "id" = ?', [$result['contest_id']]);
            $res->json(['success' => true, 'contest' => fantasy_contest_public($contest)]);
        } catch (Throwable $err) {
            fail500($res, $err, 'fantasy_contest_create');
        }
    });
}
