<!DOCTYPE html>
<html lang="hi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Live Cricket Predictor</title>
<style>
  * { box-sizing: border-box; }
  body {
    margin: 0; font-family: 'Segoe UI', Arial, sans-serif;
    background: linear-gradient(180deg, #1a0a2e, #0d0518 70%);
    color: #fff; min-height: 100vh; padding: 16px; display: flex; flex-direction: column; align-items: center;
  }
  h1 { color: #ff9800; margin: 6px 0 2px; text-shadow: 0 2px 4px #000; }
  .subtitle { opacity: .75; font-size: 12px; margin-bottom: 14px; text-align: center; max-width: 600px; }
  .wrap { width: 100%; max-width: 820px; }

  .topbar {
    display: flex; justify-content: space-between; align-items: center; background: rgba(255,255,255,0.08);
    border-radius: 10px; padding: 10px 16px; margin-bottom: 14px; position: sticky; top: 8px; z-index: 5;
  }
  .coin-badge { font-size: 16px; font-weight: bold; color: #ffd700; }

  .section-title { font-size: 14px; color: #ff9800; margin: 16px 0 8px; font-weight: bold; }
  .market-row {
    display: flex; justify-content: space-between; align-items: center; gap: 10px;
    background: rgba(255,255,255,0.07); border-radius: 10px; padding: 10px 14px; margin-bottom: 6px;
  }
  .market-row.has-bet { border-left: 4px solid #4caf50; }
  .market-label { font-size: 13px; flex: 1; }
  .odds-tag { font-size: 12px; color: #ff9800; font-weight: bold; margin-right: 8px; }
  .stake-input {
    width: 70px; padding: 6px; border-radius: 6px; border: none; font-size: 13px; text-align: center;
  }
  .bet-btn, .cancel-btn {
    padding: 6px 12px; border: none; border-radius: 8px; cursor: pointer; font-size: 12px; font-weight: bold;
  }
  .bet-btn { background: #4caf50; color: #fff; }
  .cancel-btn { background: #d32f2f; color: #fff; }

  .my-bets { background: rgba(255,255,255,0.05); border-radius: 10px; padding: 10px 14px; margin-top: 14px; font-size: 13px; }
  .my-bets .row { display: flex; justify-content: space-between; padding: 3px 0; }

  .lock-btn, .action-btn {
    display: block; width: 100%; margin-top: 16px; padding: 14px; font-size: 16px; font-weight: bold;
    background: #ff9800; color: #222; border: none; border-radius: 10px; cursor: pointer;
  }
  .action-btn.secondary { background: #2196f3; color: #fff; margin-top: 8px; }
  .errors { background: #d32f2f33; border: 1px solid #d32f2f; padding: 10px 14px; border-radius: 8px; margin-top: 10px; font-size: 13px; }

  /* Live screen */
  .scoreboard { background: rgba(255,255,255,0.08); border-radius: 12px; padding: 16px; text-align: center; margin-bottom: 14px; }
  .team-batting { font-size: 15px; opacity: .85; }
  .score-big { font-size: 36px; font-weight: bold; color: #ffd700; margin: 4px 0; }
  .overs-line { font-size: 13px; opacity: .8; }
  .target-line { font-size: 13px; color: #ff9800; margin-top: 4px; }
  .players-line { display: flex; justify-content: space-around; margin-top: 12px; font-size: 12px; flex-wrap: wrap; gap: 8px; }
  .player-chip { background: rgba(0,0,0,.3); border-radius: 8px; padding: 6px 10px; }

  .commentary-box {
    background: rgba(0,0,0,.35); border-radius: 10px; padding: 10px 14px; font-size: 13px;
    max-height: 220px; overflow-y: auto; margin-bottom: 10px;
  }
  .commentary-box div { padding: 3px 0; opacity: .9; }

  /* Finished screen */
  .result-banner { text-align: center; font-size: 22px; font-weight: bold; color: #ffd700; margin: 14px 0; }
  .bet-result-row {
    display: flex; justify-content: space-between; align-items: center; background: rgba(255,255,255,0.07);
    border-radius: 10px; padding: 10px 14px; margin-bottom: 6px; font-size: 13px;
  }
  .bet-result-row.won { border-left: 4px solid #4caf50; }
  .bet-result-row.lost { border-left: 4px solid #d32f2f; opacity: .7; }
  .bet-result-row.push { border-left: 4px solid #999; }
  .result-tag { font-weight: bold; }
  .result-tag.won { color: #4caf50; }
  .result-tag.lost { color: #d32f2f; }
</style>
</head>
<body>

<h1>&#127920; LIVE CRICKET PREDICTOR</h1>
<div class="subtitle">Live match predictor — predict match winner, player milestones, and bowling performance to win stakes.</div>

<div class="wrap" id="app"></div>

<script>
let state = null;
let autoPlayTimer = null;

async function api(action, params = {}) {
  const body = new URLSearchParams({ action, ...params });
  const res = await fetch('api.php', { method: 'POST', body });
  return res.json();
}

function topbar() {
  return `<div class="topbar"><div>🏏 Exhibition Match</div><div class="coin-badge">🪙 ${state.coins} coins</div></div>`;
}

function groupMarkets() {
  const winner = state.markets.filter(m => m.type === 'winner');
  const byPlayer = {};
  state.markets.filter(m => m.type !== 'winner').forEach(m => {
    byPlayer[m.player] = byPlayer[m.player] || [];
    byPlayer[m.player].push(m);
  });
  return { winner, byPlayer };
}

function marketRow(m, errors) {
  const existingBet = state.bets.find(b => b.market_key === m.key);
  if (existingBet) {
    return `<div class="market-row has-bet">
      <div class="market-label">${m.label} <span class="odds-tag">${m.odds}x</span></div>
      <div>Staked: ${existingBet.stake} 🪙</div>
      <button class="cancel-btn" onclick="cancelBet('${m.key}')">Cancel</button>
    </div>`;
  }
  return `<div class="market-row">
    <div class="market-label">${m.label} <span class="odds-tag">${m.odds}x</span></div>
    <input type="number" min="10" step="10" placeholder="Stake" class="stake-input" id="stake_${m.key}">
    <button class="bet-btn" onclick="placeBet('${m.key}')">Bet</button>
  </div>`;
}

function renderBetting(errors = []) {
  const { winner, byPlayer } = groupMarkets();
  let html = topbar();
  html += `<div class="section-title">🏆 Match Winner</div>`;
  winner.forEach(m => html += marketRow(m));

  Object.keys(byPlayer).forEach(player => {
    html += `<div class="section-title">👤 ${player}</div>`;
    byPlayer[player].forEach(m => html += marketRow(m));
  });

  const totalStaked = state.bets.reduce((s, b) => s + b.stake, 0);
  html += `<div class="my-bets"><b>Aapke Bets (${state.bets.length}):</b> Total staked: ${totalStaked} 🪙</div>`;
  if (errors.length) html += `<div class="errors">${errors.map(e => '&bull; ' + e).join('<br>')}</div>`;
  html += `<button class="lock-btn" onclick="lockAndStart()">🔒 Bets Lock Karein &amp; Match Shuru Karein</button>`;
  document.getElementById('app').innerHTML = html;
}

async function placeBet(marketKey) {
  const input = document.getElementById('stake_' + marketKey);
  const stake = parseFloat(input.value);
  if (!stake || stake < 10) { renderBetting(['Minimum stake 10 coins hai.']); return; }
  const data = await api('place_bet', { market_key: marketKey, stake });
  if (data.error) { state = { ...state, ...data }; renderBetting([data.error]); return; }
  state = data;
  renderBetting();
}

async function cancelBet(marketKey) {
  const data = await api('cancel_bet', { market_key: marketKey });
  state = data;
  renderBetting();
}

async function lockAndStart() {
  const data = await api('lock_and_start');
  state = data;
  renderLive();
}

function playerChip(p, label) {
  if (!p) return '';
  return `<div class="player-chip">${label}: <b>${p.name}</b> ${p.stats.runs ?? ''}${p.stats.balls !== undefined ? '(' + p.stats.balls + 'b, ' + (p.stats.fours||0) + 'x4 ' + (p.stats.sixes||0) + 'x6)' : ''}</div>`;
}

function renderLive() {
  const cur = state.cur;
  let html = topbar();
  html += `<div class="scoreboard">
    <div class="team-batting">${cur.batting_team} batting — Innings ${state.innings}</div>
    <div class="score-big">${cur.score}/${cur.wickets}</div>
    <div class="overs-line">Overs: ${cur.overs}.0 / ${cur.overs_total}</div>
    ${state.target ? `<div class="target-line">Target: ${state.target}</div>` : ''}
    <div class="players-line">
      ${playerChip(cur.striker, '🏏 Striker')}
      ${cur.non_striker ? `<div class="player-chip">Non-striker: <b>${cur.non_striker.name}</b> ${cur.non_striker.stats.runs}(${cur.non_striker.stats.balls}b)</div>` : ''}
      <div class="player-chip">🎯 Bowler: <b>${cur.bowler.name}</b> ${cur.bowler.stats.overs}ov, ${cur.bowler.stats.runs}r, ${cur.bowler.stats.wickets}w</div>
    </div>
  </div>`;

  if (state.innings1_summary) {
    html += `<div class="section-title">Innings 1: ${state.innings1_summary.team} — ${state.innings1_summary.score}/${state.innings1_summary.wickets}</div>`;
  }

  html += `<div class="commentary-box" id="commentaryBox">${state.commentary.map(c => `<div>${c}</div>`).join('')}</div>`;
  html += `<button class="action-btn" onclick="simOver()">▶ Next Over Simulate Karein</button>`;
  html += `<button class="action-btn secondary" id="autoBtn" onclick="toggleAutoPlay()">${autoPlayTimer ? '⏸ Auto-Play Rokein' : '⏩ Auto-Play Shuru Karein'}</button>`;

  document.getElementById('app').innerHTML = html;
  const box = document.getElementById('commentaryBox');
  box.scrollTop = box.scrollHeight;
}

async function simOver() {
  const data = await api('simulate_over');
  state = data;
  if (state.status === 'finished') {
    stopAutoPlay();
    renderFinished();
  } else {
    renderLive();
  }
}

function toggleAutoPlay() {
  if (autoPlayTimer) { stopAutoPlay(); renderLive(); return; }
  autoPlayTimer = setInterval(simOver, 1400);
  renderLive();
}
function stopAutoPlay() { if (autoPlayTimer) { clearInterval(autoPlayTimer); autoPlayTimer = null; } }

function renderFinished() {
  let html = topbar();
  html += `<div class="result-banner">${state.winner === 'tie' ? '🤝 Match TIE ho gaya!' : '🏆 ' + state.winner + ' jeet gayi!'}</div>`;
  if (state.innings1_summary) html += `<div class="section-title">Final Scores</div>`;

  html += `<div class="section-title">Aapke Bets ka Result</div>`;
  if (state.bets.length === 0) {
    html += `<div class="my-bets">Aapne is match par koi bet nahi lagayi thi.</div>`;
  }
  state.bets.forEach(b => {
    html += `<div class="bet-result-row ${b.result}">
      <div>${b.label} <br><span style="opacity:.7">Stake: ${b.stake} 🪙 @ ${b.odds}x</span></div>
      <div class="result-tag ${b.result}">${b.result === 'won' ? '✅ WON +' + b.payout : b.result === 'push' ? '↩ PUSH (refunded)' : '❌ LOST'}</div>
    </div>`;
  });

  html += `<button class="action-btn" onclick="newMatch()">🆕 Naya Match Khelein</button>`;
  document.getElementById('app').innerHTML = html;
}

async function newMatch() {
  stopAutoPlay();
  const data = await api('new_match');
  state = data;
  renderBetting();
}

async function init() {
  const data = await api('state');
  if (!data.exists) {
    state = data;
    await newMatch();
    return;
  }
  state = data;
  if (state.status === 'betting') renderBetting();
  else if (state.status === 'live') renderLive();
  else renderFinished();
}

init();
</script>

</body>
</html>