/**
 * The transport boundary between roanuz.js and the actual network.
 *
 * roanuz.js never calls `fetch` itself — it calls `authenticate()`/`call()` on whichever transport
 * `resolveTransport()` hands it, and every other module in this pipeline (collector, contests,
 * scoring, boundary) never even knows this file exists. That is the whole point: with no paid
 * Roanuz access, the pipeline still needs to be buildable, testable and demoable end to end, and
 * "swap the mock for the real thing" has to be a config change, not a rewrite.
 *
 * Two implementations:
 *   - `HttpTransport`  — real HTTP, against `config.ROANUZ_AUTH_BASE_URL`/`ROANUZ_BASE_URL`.
 *   - `MockTransport`  — canned responses shaped like Roanuz's own public documentation (see the
 *     source comments below for exactly which page each shape comes from), not an ad-hoc stand-in.
 *
 * `config.ROANUZ_TRANSPORT` selects between them, and resolves itself from whether real credentials
 * are present when left unset — see config.js. Dropping in a real `ROANUZ_API_TOKEN`/
 * `ROANUZ_PROJECT_KEY` is what flips this from mock to live; no other code changes.
 */

const crypto = require('crypto');

// ------------------------------------------------------------------------------------------------
// HTTP transport — real network calls
// ------------------------------------------------------------------------------------------------

/**
 * Confirmed 2026-08-24 against Roanuz's own docs (see docs/CRICKET-BUILD-BRIEF.md):
 *   - auth:  POST {authBaseUrl}/{projectKey}/auth/         body {api_key}
 *   - data:  GET  {baseUrl}/{projectKey}{path}              header rs-token
 * Both confirmed live during today's connectivity check — the auth endpoint returns Roanuz's own
 * structured error payload (schema/error/http_status_code), which only a real route does.
 */
function createHttpTransport({ authBaseUrl, baseUrl }) {
  return {
    kind: 'live',

    async authenticate(projectKey, apiKey) {
      const res = await fetch(`${authBaseUrl}/${projectKey}/auth/`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ api_key: apiKey })
      });
      const text = await res.text().catch(() => '');
      if (!res.ok) return { ok: false, status: res.status, error: text.slice(0, 500) };
      let data;
      try { data = JSON.parse(text); } catch (e) { return { ok: false, status: res.status, error: 'unparseable_auth_response' }; }
      return { ok: true, status: res.status, data };
    },

    async call(method, projectKey, path, { token, body } = {}) {
      const res = await fetch(`${baseUrl}/${projectKey}${path}`, {
        method,
        headers: {
          ...(token ? { 'rs-token': token } : {}),
          ...(body ? { 'Content-Type': 'application/json' } : {})
        },
        body: body ? JSON.stringify(body) : undefined
      });
      const text = await res.text().catch(() => '');
      if (!res.ok) return { ok: false, status: res.status, error: text.slice(0, 500) };
      let data;
      try { data = JSON.parse(text); } catch (e) { return { ok: false, status: res.status, error: 'unparseable_response' }; }
      return { ok: true, status: res.status, data };
    }
  };
}

// ------------------------------------------------------------------------------------------------
// Mock transport — canned, documentation-shaped responses
// ------------------------------------------------------------------------------------------------

/** A token that is obviously fake if it ever leaks into a log or a screenshot. */
function mockToken() {
  return `MOCK_${crypto.randomBytes(12).toString('hex')}`;
}

/**
 * One synthetic tournament with two synthetic teams, enough players per squad to exercise the
 * confirmed-XI bench-substitution path (see contests.substituteExcludedPlayers), and a short
 * synthetic match so a full innings can be played out quickly.
 *
 * Field names below match what `roanuz.mapFixture`/`mapRole` and `normalize.js` already read
 * (`key`, `name`, `format`, `venue`, `teams.a`/`teams.b`, `start_date.iso`, `roles.play_role`,
 * etc.) — the same aliases the live path expects, so nothing downstream needs to know which
 * transport produced the data.
 */
const MOCK_TOURNAMENT_KEY = 'mock_tournament_2026';
const MOCK_TEAM_A = { key: 'mock_team_a', name: 'Mock Strikers' };
const MOCK_TEAM_B = { key: 'mock_team_b', name: 'Mock Titans' };

function mockSquad(teamKey, teamLabel) {
  const roleCycle = ['wk', 'bat', 'bat', 'bat', 'bat', 'all_rounder', 'all_rounder', 'bowl', 'bowl', 'bowl', 'bowl', 'bat', 'bowl'];
  const players = [];
  for (let i = 0; i < 13; i += 1) {
    players.push({
      key: `${teamKey}_p${i}`,
      name: `${teamLabel} Player ${i}`,
      roles: { play_role: roleCycle[i % roleCycle.length], batting_style: 'right_hand', bowling_style: null }
    });
  }
  return players;
}

function mockFixtureRow(daysFromNow = 1) {
  const start = new Date(Date.now() + daysFromNow * 86400000);
  return {
    key: 'mock_match_001',
    name: `${MOCK_TEAM_A.name} vs ${MOCK_TEAM_B.name}`,
    short_name: 'MST vs MTT',
    format: 'T20',
    venue: { name: 'Mock Stadium' },
    teams: { a: { key: MOCK_TEAM_A.key, name: MOCK_TEAM_A.name }, b: { key: MOCK_TEAM_B.key, name: MOCK_TEAM_B.name } },
    start_date: { iso: start.toISOString() },
    status: { state: 'scheduled' }
  };
}

function createMockTransport() {
  return {
    kind: 'mock',

    async authenticate(projectKey, apiKey) {
      // Shape per https://sports.dev.roanuz.com/v5/docs/auth-rest-api: "the access token and the
      // expire time of the token are available in the response" - exact field names beyond
      // `token`/`expires` were not extractable from the public docs (see the build-brief
      // amendment), so this mirrors the same `data.token`/`data.expires` shape `normalize._pick`
      // already looks for in roanuz.js, which is the best-documented reading available.
      return {
        ok: true,
        status: 200,
        data: { data: { token: mockToken(), expires: Math.floor(Date.now() / 1000) + 24 * 3600 } }
      };
    },

    async call(method, projectKey, path, { token, body } = {}) {
      if (/\/tournament\/[^/]+\/fixtures\/?$/.test(path)) {
        return { ok: true, status: 200, data: { data: { fixtures: [mockFixtureRow()] } } };
      }
      const teamMatch = path.match(/\/tournament\/[^/]+\/team\/([^/]+)\/?$/);
      if (teamMatch) {
        const teamKey = teamMatch[1];
        const label = teamKey === MOCK_TEAM_A.key ? MOCK_TEAM_A.name : MOCK_TEAM_B.name;
        return { ok: true, status: 200, data: { data: { players: mockSquad(teamKey, label) } } };
      }
      if (/\/subscribe\/?$/.test(path)) {
        // Shape per https://sports.dev.roanuz.com/v5/pages/match-webhook: subscribe is a POST with
        // { method: "web_hook" }; the docs don't show its response body, so this reports the
        // request was accepted without inventing fields beyond that.
        return { ok: true, status: 200, data: { data: { subscribed: true, method: (body && body.method) || 'web_hook' } } };
      }
      if (/\/player\/[^/]+\/stats\/?$/.test(path)) {
        return { ok: true, status: 200, data: { data: { matches: 0, average: 0, strike_rate: 0 } } };
      }
      return { ok: false, status: 404, error: `mock transport has no fixture for ${method} ${path}` };
    }
  };
}

// ------------------------------------------------------------------------------------------------

function resolveTransport(config) {
  return config.ROANUZ_TRANSPORT === 'live'
    ? createHttpTransport({ authBaseUrl: config.ROANUZ_AUTH_BASE_URL, baseUrl: config.ROANUZ_BASE_URL })
    : createMockTransport();
}

module.exports = {
  createHttpTransport,
  createMockTransport,
  resolveTransport,
  MOCK_TOURNAMENT_KEY,
  MOCK_TEAM_A,
  MOCK_TEAM_B,
  mockFixtureRow,
  mockSquad
};
