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
require_once __DIR__ . '/../lib/fantasy-scoring.php';
require_once __DIR__ . '/../lib/fantasy-live.php';
require_once __DIR__ . '/../lib/fantasy-settle.php';
require_once __DIR__ . '/../lib/fantasy-feed.php';

/**
 * Keep the feed-backed data fresh without depending on a cron being installed: in mock mode, advance
 * the simulated matches; in either mode, re-pull the schedule at most every five minutes. Both are
 * throttled through state keys, so a busy lobby costs a couple of indexed reads per request.
 * Failures are logged, never surfaced: a lobby read must not fail because a refresh did.
 */
function fantasy_lazy_feed_refresh() {
    if (!fantasy_uses_feed()) return;
    try {
        cricket_mock_pump();
        $last = state_get('fantasy_fixture_sync_at');
        $gap = cricket_source_mode() === 'mock' ? 120000 : 300000;
        if (!is_array($last) || now_ms() - (int) ($last['ms'] ?? 0) > $gap) {
            state_set('fantasy_fixture_sync_at', ['ms' => now_ms()]);
            fantasy_feed_sync_fixtures();
        }
    } catch (Throwable $e) {
        log_warn('fantasy: lazy feed refresh failed', ['message' => $e->getMessage()]);
    }
}

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
            fantasy_lazy_feed_refresh();
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
                // Simulated league (no Roanuz keys yet): the page labels it Virtual Cricket.
                'virtual' => function_exists('cricket_source_mode') && fantasy_uses_feed() && cricket_source_mode() === 'mock',
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
            $grouped = fantasy_players_grouped($public['id']);
            // "Sel by" / "C by" / "VC by": the share of all teams on this match picking each player.
            $sel = fantasy_selection_stats($public['id']);
            foreach ($grouped as $role => &$list) {
                foreach ($list as &$pl) {
                    $st = $sel['players'][$pl['id']] ?? [];
                    $pl['selected_by'] = $st['sel'] ?? 0;
                    $pl['captain_by'] = $st['c'] ?? 0;
                    $pl['vice_captain_by'] = $st['vc'] ?? 0;
                }
                unset($pl);
            }
            unset($list);
            $res->json([
                'success' => true,
                'match'   => $public,
                'rules'   => fantasy_rules(),
                'scoring_rules' => fantasy_scoring_rules($public['format']),
                'selection_teams' => $sel['teams'],
                // The two real teams, for the "max 7 from one team" counter in the builder.
                'teams'   => [
                    ['name' => $public['team_a'], 'short' => $public['team_a_short']],
                    ['name' => $public['team_b'], 'short' => $public['team_b_short']],
                ],
                'players' => $grouped,
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
                'min_entries' => $req->b('min_entries', null),
                'max_entries_per_user' => $req->b('max_entries_per_user', 1),
                'contest_type' => $req->b('contest_type', 'custom'),
                'is_guaranteed' => $req->b('is_guaranteed', false),
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

    // ---------------------------------------------------------------------------------------------
    // Live scoring
    // ---------------------------------------------------------------------------------------------

    /**
     * GET /api/fantasy/matches/:id/scoreboard
     *
     * Every player's figures and points for a match, highest first. Public: this is match data, the
     * same information the scorecard it came from shows.
     *
     * The scoring rules are sent alongside so the screen can explain a total rather than only display
     * it — a player who cannot see why they scored what they did has no way to tell a low score from a
     * bug.
     */
    $app->get('/api/fantasy/matches/:id/scoreboard', function (Req $req, Res $res) {
        try {
            $match = fantasy_find_match($req->p('id'));
            if (!$match) {
                $res->status(404)->json(['error' => 'Match not found.']);
                return;
            }
            $res->json([
                'success'       => true,
                'match'         => fantasy_public_match($match),
                // What the SOURCE says, kept distinct from our own status: "the game is over" is not
                // the same statement as "the contests have been settled".
                'source_state'  => $match['source_state'] ?? null,
                'source_status' => $match['source_status_text'] ?? null,
                'last_synced_at' => $match['last_synced_at'] ?? null,
                'scoring_rules' => fantasy_scoring_rules($match['format']),
                'players'       => fantasy_live_scoreboard((int) $match['id']),
                'server_time_ms' => now_ms(),
            ]);
        } catch (Throwable $err) {
            fail500($res, $err, 'fantasy_scoreboard');
        }
    });

    /**
     * GET /api/fantasy/teams/:id/points — the caller's own XI, scored player by player.
     *
     * Returns the same breakdown the engine produced, including each player's multiplier, so a total
     * can be checked line by line instead of taken on trust.
     */
    $app->get('/api/fantasy/teams/:id/points', 'require_auth', function (Req $req, Res $res) {
        try {
            $user = get_or_create_user(acting_username($req));
            if (!$user) {
                $res->status(404)->json(['error' => 'Account not found.']);
                return;
            }
            $team = fantasy_team_with_players((int) $req->p('id'), (int) $user['id']);
            if (!$team) {
                $res->status(404)->json(['error' => 'Team not found.']);
                return;
            }
            $stats = fantasy_stored_stats($team['match_id']);
            $scored = fantasy_score_team($team['players'], $stats, fantasy_rules_for_match($team['match_id']));
            $res->json([
                'success' => true,
                'team_id' => $team['id'],
                'team_name' => $team['team_name'],
                'total'   => $scored['total'],
                'players' => $scored['players'],
                'server_time_ms' => now_ms(),
            ]);
        } catch (Throwable $err) {
            fail500($res, $err, 'fantasy_team_points');
        }
    });

    /**
     * POST /api/admin/fantasy/matches/:id/override-stats
     *
     * The escape hatch for the scraper being wrong: a source that changes shape mid-match, a name that
     * could not be mapped to a squad player, or a scorecard correction the source has not published.
     *
     * An operator corrects the FACTS — runs, wickets, catches — and the engine recomputes the points.
     * Points are deliberately not settable directly, so the scoring rules stay the only authority on
     * what a performance is worth, and an override is auditable as a change to figures rather than as
     * an unexplained number.
     *
     * Only the fields sent are changed; the rest keep their stored values, so fixing one figure does
     * not require restating the other fourteen and cannot accidentally zero them.
     *
     * Body: {"player_id":123,"runs":62,"fours":6,"sixes":3,"is_out":1,"did_bat":1}
     */
    $app->post('/api/admin/fantasy/matches/:id/override-stats', 'require_admin',
        function (Req $req, Res $res) {
            try {
                $playerId = (int) $req->b('player_id', 0);
                if ($playerId <= 0) {
                    $res->status(422)->json(['error' => 'player_id is required.']);
                    return;
                }
                $fields = [];
                foreach (fantasy_live_stat_fields() as $f) {
                    if ($req->b($f, null) !== null) $fields[$f] = $req->b($f);
                }
                if (!$fields) {
                    $res->status(422)->json([
                        'error' => 'Send at least one stat field.',
                        'allowed' => fantasy_live_stat_fields(),
                    ]);
                    return;
                }

                $result = fantasy_override_player_stats((int) $req->p('id'), $playerId, $fields);
                if (!$result['ok']) {
                    $res->status((int) ($result['status'] ?? 422))->json(['error' => $result['error']]);
                    return;
                }
                $res->json([
                    'success' => true,
                    'player'  => $result['player'],
                    'stats'   => $result['stats'],
                    'points'  => $result['points'],
                    'teams_rescored' => $result['teams_scored'],
                ]);
            } catch (Throwable $err) {
                fail500($res, $err, 'fantasy_override_stats');
            }
        });

    // ---------------------------------------------------------------------------------------------
    // Leaderboard and settlement
    // ---------------------------------------------------------------------------------------------

    /**
     * GET /api/fantasy/contests/:id/leaderboard
     *
     * Public. Before settlement the standings are provisional and computed live from each entry's
     * points; afterwards the stored rank and prize are returned as they were paid, so the leaderboard
     * can never disagree with the money. The response says which of the two it is via `provisional`,
     * because showing a live projection as if it were final is how disputes start.
     */
    $app->get('/api/fantasy/contests/:id/leaderboard', function (Req $req, Res $res) {
        try {
            $board = fantasy_contest_leaderboard($req->p('id'), $req->q('limit', 100));
            if (!$board) {
                $res->status(404)->json(['error' => 'Contest not found.']);
                return;
            }
            $board['success'] = true;
            $board['server_time_ms'] = now_ms();
            $res->json($board);
        } catch (Throwable $err) {
            fail500($res, $err, 'fantasy_leaderboard');
        }
    });

    /**
     * POST /api/admin/fantasy/contests/:id/settle — rank, allocate and pay out one contest.
     *
     * Refuses unless the source has reported the match finished, which is evidence rather than a
     * clock. {"force":1} overrides that, and a forced settle is recorded in the ledger detail so it
     * can be told apart from a normal one afterwards.
     *
     * Safe to call twice: a contest already settled reports already_settled and pays nothing.
     */
    $app->post('/api/admin/fantasy/contests/:id/settle', 'require_admin', function (Req $req, Res $res) {
        try {
            $force = (bool) $req->b('force', false);
            $result = fantasy_settle_contest((int) $req->p('id'), $force);
            if (!$result['ok']) {
                $res->status((int) ($result['status'] ?? 409))->json(['error' => $result['error']]);
                return;
            }
            $result['success'] = true;
            $res->json($result);
        } catch (Throwable $err) {
            fail500($res, $err, 'fantasy_settle_contest');
        }
    });

    /**
     * POST /api/admin/fantasy/matches/:id/settle — settle every open contest on a fixture.
     *
     * The fixture is only marked SETTLED when every one of its contests is settled or cancelled, so a
     * failure leaves it open for a retry instead of being papered over.
     */
    $app->post('/api/admin/fantasy/matches/:id/settle', 'require_admin', function (Req $req, Res $res) {
        try {
            $result = fantasy_settle_match((int) $req->p('id'), (bool) $req->b('force', false));
            if (!$result['ok']) {
                $res->status((int) ($result['status'] ?? 409))->json(['error' => $result['error']]);
                return;
            }
            $result['success'] = true;
            $res->json($result);
        } catch (Throwable $err) {
            fail500($res, $err, 'fantasy_settle_match');
        }
    });

    /**
     * POST /api/admin/fantasy/contests/:id/void — refund every entry and cancel the contest.
     *
     * The right answer for a contest that never filled, or a match that was abandoned: give the money
     * back rather than pay a prize table quoted against a full house. Refuses on an already-settled
     * contest, because prizes cannot be unpaid.
     */
    $app->post('/api/admin/fantasy/contests/:id/void', 'require_admin', function (Req $req, Res $res) {
        try {
            $result = fantasy_void_contest((int) $req->p('id'), (string) $req->b('reason', ''));
            if (!$result['ok']) {
                $res->status((int) ($result['status'] ?? 409))->json(['error' => $result['error']]);
                return;
            }
            $result['success'] = true;
            $res->json($result);
        } catch (Throwable $err) {
            fail500($res, $err, 'fantasy_void_contest');
        }
    });

    // ---------------------------------------------------------------------------------------------
    // Parity endpoints: My Matches, switch team, rival teams after the deadline, credits
    // ---------------------------------------------------------------------------------------------

    /** GET /api/fantasy/my-matches — the caller's fixtures, Upcoming / Live / Completed. */
    $app->get('/api/fantasy/my-matches', 'require_auth', function (Req $req, Res $res) {
        try {
            fantasy_lazy_feed_refresh();
            $user = get_or_create_user(acting_username($req));
            if (!$user) { $res->status(404)->json(['error' => 'Account not found.']); return; }
            $res->json(['success' => true] + fantasy_my_matches((int) $user['id']) + ['server_time_ms' => now_ms()]);
        } catch (Throwable $err) {
            fail500($res, $err, 'fantasy_my_matches');
        }
    });

    /** POST /api/fantasy/entries/:id/switch {team_id} — swap the team on an entry before the deadline. */
    $app->post('/api/fantasy/entries/:id/switch', 'require_auth', function (Req $req, Res $res) {
        try {
            $user = get_or_create_user(acting_username($req));
            if (!$user) { $res->status(404)->json(['error' => 'Account not found.']); return; }
            $r = fantasy_switch_entry_team((int) $user['id'], (int) $req->p('id'), (int) $req->b('team_id', 0));
            if (!$r['ok']) { $res->status((int) ($r['status'] ?? 409))->json(['error' => $r['error']]); return; }
            $res->json(['success' => true, 'unchanged' => !empty($r['unchanged'])]);
        } catch (Throwable $err) {
            fail500($res, $err, 'fantasy_switch_team');
        }
    });

    /** GET /api/fantasy/entries/:id/team — any entry's XI and points, once the match has started. */
    $app->get('/api/fantasy/entries/:id/team', function (Req $req, Res $res) {
        try {
            $r = fantasy_entry_team_public((int) $req->p('id'));
            if (!$r['ok']) { $res->status((int) $r['status'])->json(['error' => $r['error']]); return; }
            unset($r['ok']);
            $res->json(['success' => true] + $r);
        } catch (Throwable $err) {
            fail500($res, $err, 'fantasy_entry_team');
        }
    });

    /**
     * POST /api/admin/fantasy/matches/:id/credits {credits: {player_id: value, ...}}
     *
     * Operator pricing before the deadline. Refused once the match locks, because changing what a
     * player costs after teams are fixed would make some saved XIs illegal retroactively.
     */
    $app->post('/api/admin/fantasy/matches/:id/credits', 'require_admin', function (Req $req, Res $res) {
        try {
            $match = fantasy_find_match($req->p('id'));
            if (!$match) { $res->status(404)->json(['error' => 'Match not found.']); return; }
            if (now_ms() >= (int) sql_to_ms($match['lock_time'])) {
                $res->status(409)->json(['error' => 'Credits are fixed once the match deadline has passed.']); return;
            }
            $changed = 0;
            foreach ((array) $req->b('credits', []) as $pid => $val) {
                if (!is_numeric($val)) continue;
                $changed += affected('UPDATE "fantasy_players" SET "credits" = ?, "credits_locked" = 1 WHERE "id" = ? AND "match_id" = ?',
                                     [fantasy_clamp_credits($val), (int) $pid, (int) $match['id']]);
            }
            $res->json(['success' => true, 'updated' => $changed]);
        } catch (Throwable $err) {
            fail500($res, $err, 'fantasy_admin_credits');
        }
    });

    /** POST /api/admin/fantasy/sync — pull fixtures and squads now, and lay out the contest set. */
    $app->post('/api/admin/fantasy/sync', 'require_admin', function (Req $req, Res $res) {
        try {
            $res->json(['success' => true, 'summary' => fantasy_feed_sync_fixtures()]);
        } catch (Throwable $err) {
            fail500($res, $err, 'fantasy_admin_sync');
        }
    });

    /**
     * POST /api/admin/fantasy/matches/:id/calibrate {official: {player_id: points, ...}, apply: bool}
     *
     * Paste Dream11's official base points (no C/VC multiplier) for a few players of a finished match;
     * the engine reports which variant of the two open rule details reproduces them exactly, and with
     * apply=true adopts it and re-scores the match.
     */
    $app->post('/api/admin/fantasy/matches/:id/calibrate', 'require_admin', function (Req $req, Res $res) {
        try {
            $official = (array) $req->b('official', []);
            if (!$official) { $res->status(422)->json(['error' => 'Send official: {player_id: points}.']); return; }
            $r = fantasy_calibrate((int) $req->p('id'), $official, (bool) $req->b('apply', false));
            if (!$r['ok']) { $res->status((int) $r['status'])->json(['error' => $r['error']]); return; }
            $res->json(['success' => true] + $r);
        } catch (Throwable $err) {
            fail500($res, $err, 'fantasy_calibrate');
        }
    });

    /**
     * POST /api/admin/fantasy/matches/:id/points-system {format}
     *
     * Which Dream11 table scores this match: T20, ODI, TEST, T10, HUNDRED, or the warm-up tables
     * OTHER_T20 / OTHER_ODI / OTHER_TEST (practice matches where more than 11 players may play).
     */
    $app->post('/api/admin/fantasy/matches/:id/points-system', 'require_admin', function (Req $req, Res $res) {
        try {
            $fmt = strtoupper(trim((string) $req->b('format', '')));
            $allowed = ['T20', 'ODI', 'TEST', 'T10', 'HUNDRED', 'OTHER_T20', 'OTHER_ODI', 'OTHER_TEST'];
            if (!in_array($fmt, $allowed, true)) { $res->status(422)->json(['error' => 'format must be one of ' . implode(', ', $allowed) . '.']); return; }
            $match = fantasy_find_match($req->p('id'));
            if (!$match) { $res->status(404)->json(['error' => 'Match not found.']); return; }
            q('UPDATE "fantasy_matches" SET "format" = ?, "updated_at" = ? WHERE "id" = ?', [$fmt, ms_to_sql(now_ms()), (int) $match['id']]);
            $GLOBALS['BET1X_RULES_EPOCH'] = ($GLOBALS['BET1X_RULES_EPOCH'] ?? 0) + 1;
            $res->json(['success' => true, 'format' => $fmt, 'rescored' => fantasy_rescore_match((int) $match['id'])]);
        } catch (Throwable $err) {
            fail500($res, $err, 'fantasy_points_system');
        }
    });
}
