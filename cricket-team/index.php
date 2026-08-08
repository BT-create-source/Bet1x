<!DOCTYPE html>
<html lang="hi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Fantasy Cricket</title>
<style>
  * { box-sizing: border-box; }
  body {
    margin: 0; font-family: 'Segoe UI', Arial, sans-serif;
    background: linear-gradient(180deg, #063a2e, #0a1f1a 70%);
    color: #fff; min-height: 100vh; padding: 16px; display: flex; flex-direction: column; align-items: center;
  }
  h1 { color: #ffcc00; margin: 6px 0 2px; text-shadow: 0 2px 4px #000; }
  .subtitle { opacity: .8; font-size: 13px; margin-bottom: 16px; text-align: center; }
  .wrap { width: 100%; max-width: 900px; }

  .topbar {
    display: flex; flex-wrap: wrap; gap: 10px; justify-content: space-between; align-items: center;
    background: rgba(255,255,255,0.08); border-radius: 10px; padding: 10px 16px; margin-bottom: 14px;
    position: sticky; top: 8px; z-index: 5; backdrop-filter: blur(4px);
  }
  .stat { font-size: 14px; }
  .stat b { color: #ffcc00; }

  .role-tabs { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 12px; }
  .role-tab {
    padding: 6px 14px; border-radius: 20px; background: rgba(255,255,255,0.1); cursor: pointer; font-size: 13px;
  }
  .role-tab.active { background: #ffcc00; color: #222; font-weight: bold; }

  .player-list { display: flex; flex-direction: column; gap: 8px; margin-bottom: 20px; }
  .player-row {
    display: flex; align-items: center; justify-content: space-between; gap: 10px;
    background: rgba(255,255,255,0.07); border: 2px solid transparent; border-radius: 10px; padding: 10px 14px;
  }
  .player-row.selected { border-color: #4caf50; background: rgba(76,175,80,0.15); }
  .player-info { display: flex; flex-direction: column; }
  .player-info .name { font-weight: bold; font-size: 14px; }
  .player-info .meta { font-size: 11px; opacity: .7; }
  .role-badge {
    font-size: 10px; padding: 2px 7px; border-radius: 8px; background: #2196f3; margin-right: 6px;
  }
  .credits-badge { font-size: 12px; color: #ffcc00; font-weight: bold; }
  .pick-btn {
    padding: 6px 14px; border: none; border-radius: 8px; background: #4caf50; color: #fff; cursor: pointer; font-size: 12px;
  }
  .pick-btn.remove { background: #d32f2f; }

  .cvbar { display: flex; gap: 16px; margin-top: 20px; flex-wrap: wrap; }
  .cv-box { flex: 1; min-width: 260px; background: rgba(255,255,255,0.08); border-radius: 10px; padding: 12px; }
  .cv-box h3 { margin: 0 0 8px; font-size: 14px; color: #ffcc00; }
  select {
    width: 100%; padding: 8px; border-radius: 8px; border: none; font-size: 13px;
  }

  .submit-btn {
    display: block; width: 100%; margin-top: 20px; padding: 14px; font-size: 16px; font-weight: bold;
    background: #ffcc00; color: #222; border: none; border-radius: 10px; cursor: pointer;
  }
  .submit-btn:disabled { opacity: .4; }
  .errors { background: #d32f2f33; border: 1px solid #d32f2f; padding: 10px 14px; border-radius: 8px; margin-top: 10px; font-size: 13px; }

  /* Result screen */
  .total-score { text-align: center; font-size: 40px; font-weight: bold; color: #ffcc00; margin: 10px 0; }
  .total-label { text-align: center; opacity: .8; margin-bottom: 20px; }
  .result-row {
    display: flex; justify-content: space-between; align-items: center; gap: 10px;
    background: rgba(255,255,255,0.07); border-radius: 10px; padding: 10px 14px; margin-bottom: 8px;
  }
  .result-row.cap { border-left: 4px solid #ffcc00; }
  .result-row.vc { border-left: 4px solid #4caf50; }
  .tag { font-size: 10px; padding: 2px 6px; border-radius: 6px; margin-left: 6px; }
  .tag.c { background: #ffcc00; color: #222; }
  .tag.v { background: #4caf50; }
  .points { font-weight: bold; color: #ffcc00; font-size: 16px; }
  .stat-line { font-size: 11px; opacity: .75; }

  .rules-box {
    max-width: 900px; width: 100%; margin-top: 20px; background: rgba(0,0,0,.3);
    border-radius: 10px; padding: 12px 16px; font-size: 12px;
  }
  .rules-box h4 { margin: 0 0 6px; color: #ffcc00; }
  .rules-box ul { margin: 0; padding-left: 18px; }
</style>
</head>
<body>

<h1>&#127951; FANTASY CRICKET</h1>
<div class="subtitle">Apni Dream Team banayein — 11 players, budget 100 credits, Captain 2x &amp; Vice-Captain 1.5x points!</div>

<div class="wrap" id="app"></div>

<div class="rules-box">
  <h4>Scoring Rules</h4>
  <ul id="rulesList"></ul>
</div>

<script>
let allPlayers = [];
let selected = [];        // array of player ids
let captainId = null;
let viceId = null;
let currentFilter = 'ALL';
const TOTAL_CREDITS = 100;
const SQUAD_SIZE = 11;

async function api(action, params = {}) {
  const body = new URLSearchParams({ action, ...params });
  const res = await fetch('api.php', { method: 'POST', body });
  return res.json();
}

function creditsUsed() {
  return selected.reduce((sum, id) => sum + allPlayers.find(p => p.id === id).credits, 0);
}

function roleCount(role) {
  return selected.filter(id => allPlayers.find(p => p.id === id).role === role).length;
}

function togglePlayer(id) {
  const idx = selected.indexOf(id);
  if (idx >= 0) {
    selected.splice(idx, 1);
    if (captainId === id) captainId = null;
    if (viceId === id) viceId = null;
  } else {
    if (selected.length >= SQUAD_SIZE) { alert('Aap sirf ' + SQUAD_SIZE + ' players hi choose kar sakte hain.'); return; }
    const player = allPlayers.find(p => p.id === id);
    if (creditsUsed() + player.credits > TOTAL_CREDITS) { alert('Credits limit paar ho rahi hai!'); return; }
    selected.push(id);
  }
  renderPickScreen();
}

function renderTopbar() {
  return `
    <div class="topbar">
      <div class="stat">Selected: <b>${selected.length}/${SQUAD_SIZE}</b></div>
      <div class="stat">Credits: <b>${creditsUsed().toFixed(1)}/${TOTAL_CREDITS}</b></div>
      <div class="stat">WK:<b>${roleCount('WK')}</b> BAT:<b>${roleCount('BAT')}</b> AR:<b>${roleCount('AR')}</b> BOWL:<b>${roleCount('BOWL')}</b></div>
    </div>`;
}

function renderRoleTabs() {
  const roles = [['ALL', 'Sab'], ['WK', 'Wicket-Keeper'], ['BAT', 'Batsman'], ['AR', 'All-Rounder'], ['BOWL', 'Bowler']];
  return `<div class="role-tabs">${roles.map(([key, label]) =>
    `<div class="role-tab ${currentFilter === key ? 'active' : ''}" onclick="setFilter('${key}')">${label}</div>`
  ).join('')}</div>`;
}

function setFilter(role) { currentFilter = role; renderPickScreen(); }

function renderPlayerList() {
  const list = allPlayers.filter(p => currentFilter === 'ALL' || p.role === currentFilter);
  return `<div class="player-list">${list.map(p => {
    const isSel = selected.includes(p.id);
    return `
      <div class="player-row ${isSel ? 'selected' : ''}">
        <div class="player-info">
          <div class="name"><span class="role-badge">${p.role}</span>${p.name}</div>
          <div class="meta">${p.team}</div>
        </div>
        <div style="display:flex;align-items:center;gap:10px;">
          <div class="credits-badge">${p.credits.toFixed(1)} Cr</div>
          <button class="pick-btn ${isSel ? 'remove' : ''}" onclick="togglePlayer(${p.id})">${isSel ? 'Remove' : 'Pick'}</button>
        </div>
      </div>`;
  }).join('')}</div>`;
}

function renderCaptainSelectors() {
  if (selected.length !== SQUAD_SIZE) return '';
  const opts = id => allPlayers.filter(p => selected.includes(p.id))
    .map(p => `<option value="${p.id}" ${id === p.id ? 'selected' : ''}>${p.name}</option>`).join('');
  return `
    <div class="cvbar">
      <div class="cv-box">
        <h3>Captain (2x points)</h3>
        <select onchange="captainId=parseInt(this.value); renderPickScreen()">
          <option value="">-- Chunein --</option>${opts(captainId)}
        </select>
      </div>
      <div class="cv-box">
        <h3>Vice-Captain (1.5x points)</h3>
        <select onchange="viceId=parseInt(this.value); renderPickScreen()">
          <option value="">-- Chunein --</option>${opts(viceId)}
        </select>
      </div>
    </div>`;
}

function renderPickScreen(errors = []) {
  const canSubmit = selected.length === SQUAD_SIZE && captainId && viceId && captainId !== viceId;
  document.getElementById('app').innerHTML = `
    ${renderTopbar()}
    ${renderRoleTabs()}
    ${renderPlayerList()}
    ${renderCaptainSelectors()}
    ${errors.length ? `<div class="errors">${errors.map(e => '&bull; ' + e).join('<br>')}</div>` : ''}
    <button class="submit-btn" ${canSubmit ? '' : 'disabled'} onclick="submitTeam()">Match Simulate Karein &amp; Points Dekhein</button>
  `;
}

async function submitTeam() {
  const data = await api('submit_team', {
    player_ids: selected.join(','), captain_id: captainId, vice_id: viceId,
  });
  if (!data.success) { renderPickScreen(data.errors || ['Kuch error aaya.']); return; }
  renderResultScreen(data.breakdown, data.team_total);
}

function renderResultScreen(breakdown, total) {
  document.getElementById('app').innerHTML = `
    <div class="total-score">${total.toFixed(1)}</div>
    <div class="total-label">Aapki Team ka Total Fantasy Score</div>
    ${breakdown.map(p => `
      <div class="result-row ${p.is_captain ? 'cap' : ''} ${p.is_vice ? 'vc' : ''}">
        <div>
          <div><span class="role-badge">${p.role}</span>${p.name}
            ${p.is_captain ? '<span class="tag c">C</span>' : ''}${p.is_vice ? '<span class="tag v">VC</span>' : ''}
          </div>
          <div class="stat-line">Runs: ${p.stats.runs} (4s:${p.stats.fours} 6s:${p.stats.sixes}) | Wkts: ${p.stats.wickets} | Catches: ${p.stats.catches}</div>
        </div>
        <div class="points">${p.final_points.toFixed(1)}</div>
      </div>
    `).join('')}
    <button class="submit-btn" onclick="startNewMatch()">Naya Match Khelein</button>
  `;
}

async function startNewMatch() {
  await api('new_match');
  selected = []; captainId = null; viceId = null; currentFilter = 'ALL';
  renderPickScreen();
}

async function init() {
  const playersData = await api('players');
  allPlayers = playersData.players;
  document.getElementById('rulesList').innerHTML =
    (window.SCORING_RULES || []).map(r => `<li>${r}</li>`).join('');

  const state = await api('state');
  if (state.exists && state.status === 'result') {
    renderResultScreen(state.breakdown, state.team_total);
  } else {
    renderPickScreen();
  }
}

window.SCORING_RULES = [
  "1 Run = 1 point", "Boundary (4) = +1 bonus", "Six = +2 bonus",
  "30+ runs = +4, Half-century = +8, Century = +16",
  "Duck (0 runs, batting role) = -2",
  "Wicket = 25 points (3-wkt haul = +4, 5-wkt haul = +8)",
  "Maiden over = +4", "Catch = +8", "Stumping = +12", "Run-out = +6",
  "Captain = 2x points, Vice-Captain = 1.5x points"
];

init();
</script>

</body>
</html>