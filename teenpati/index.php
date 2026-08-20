<?php
// Sirf page load karta hai; asli game logic api.php (AJAX) se chalti hai.
require_once __DIR__ . '/game_logic.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Teen Patti — bet1x</title>
<link rel="stylesheet" href="../assets/css/style.css">
<style>
  body {
    margin: 0;
    min-height: 100vh;
    display: flex;
    flex-direction: column;
  }
  .game-container {
    max-width: var(--max-width);
    margin: 0 auto;
    padding: 32px 24px;
    width: 100%;
    display: flex;
    flex-direction: column;
    align-items: center;
  }
  h1 {
    font-family: var(--font-display);
    font-size: 32px;
    color: var(--gold);
    margin: 10px 0 5px;
    text-align: center;
    letter-spacing: -0.02em;
  }
  .subtitle {
    color: var(--text-dim);
    margin-bottom: 24px;
    font-size: 14px;
    text-align: center;
  }
  .table {
    background: radial-gradient(circle at center, #0e5c34 30%, #062315 90%);
    border: 8px solid #4a2c14;
    border-radius: var(--radius-lg);
    width: 100%;
    max-width: 800px;
    padding: 32px 24px;
    box-shadow: inset 0 0 60px rgba(0,0,0,0.6), var(--shadow-card);
    margin-bottom: 20px;
  }
  .pot-row {
    text-align: center;
    margin-bottom: 24px;
  }
  .pot-badge {
    display: inline-block;
    background: var(--gold-soft);
    color: var(--gold);
    border: 1px solid rgba(255, 197, 61, 0.35);
    font-weight: 700;
    padding: 10px 28px;
    border-radius: 30px;
    font-size: 20px;
    font-family: var(--font-mono);
    box-shadow: 0 4px 15px rgba(0,0,0,0.25);
  }
  .players {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
  }
  .player-card {
    background: rgba(11, 14, 29, 0.6);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 16px;
    text-align: center;
    transition: all 0.2s;
    backdrop-filter: blur(5px);
  }
  .player-card.turn {
    border-color: var(--gold);
    box-shadow: 0 0 16px rgba(255, 197, 61, 0.25);
    background: rgba(26, 32, 64, 0.6);
  }
  .player-card.folded {
    opacity: 0.45;
  }
  .player-card.winner {
    border-color: var(--green);
    box-shadow: 0 0 16px rgba(46, 212, 122, 0.25);
    background: rgba(46, 212, 122, 0.05);
  }
  .player-name {
    font-weight: 600;
    font-size: 15px;
    margin-bottom: 6px;
    font-family: var(--font-display);
  }
  .player-balance {
    font-size: 12px;
    color: var(--text-dim);
    margin-bottom: 12px;
    font-family: var(--font-mono);
  }
  .cards-row {
    display: flex;
    justify-content: center;
    gap: 6px;
    min-height: 72px;
  }
  .card {
    width: 46px;
    height: 68px;
    background: #ffffff;
    color: #1a1d2e;
    border-radius: var(--radius-sm);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: space-between;
    padding: 6px 4px;
    font-size: 14px;
    font-weight: 700;
    box-shadow: 0 4px 8px rgba(0,0,0,0.3);
    font-family: var(--font-mono);
    border: 1px solid rgba(0,0,0,0.1);
    position: relative;
  }
  .card span {
    font-size: 15px;
    line-height: 1;
  }
  .card.red {
    color: var(--red);
  }
  .card.back {
    background: repeating-linear-gradient(45deg, #1f2544, #1f2544 4px, #12162b 4px, #12162b 8px);
    border: 2px solid var(--gold);
    color: transparent;
  }
  .card.back::after {
    content: "♠";
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    color: rgba(255, 197, 61, 0.4);
    font-size: 20px;
  }
  .hand-label {
    font-size: 12px;
    margin-top: 10px;
    color: var(--gold);
    font-weight: 500;
    min-height: 18px;
  }
  .controls {
    display: flex;
    justify-content: center;
    gap: 12px;
    flex-wrap: wrap;
    margin-top: 10px;
  }
  .log-box {
    max-width: 800px;
    width: 100%;
    margin-top: 20px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 16px 20px;
    font-size: 13px;
    max-height: 140px;
    overflow-y: auto;
    box-shadow: var(--shadow-card);
    font-family: var(--font-mono);
    color: var(--text-dim);
  }
  .log-box div {
    padding: 6px 0;
    border-bottom: 1px solid rgba(255,255,255,0.03);
    opacity: 0.9;
  }
  .log-box div:last-child {
    border-bottom: none;
  }
  .status-msg {
    text-align: center;
    font-size: 15px;
    margin: 12px 0;
    min-height: 22px;
    color: var(--gold);
    font-weight: 600;
  }
</style>
</head>
<body>

<nav class="navbar exchange-header">
  <div class="exchange-logo-box">
    bet1x<span>.com</span>
  </div>
  <div id="headerAuthArea">
    <!-- Loaded dynamically by ui-common.js -->
  </div>
</nav>

<div class="sub-navbar">
  <ul class="sub-navbar-links">
    <li class="sub-navbar-item"><a href="../index.html" class="sub-navbar-link">Home</a></li>
    <li class="sub-navbar-item"><a href="../aviator.html" class="sub-navbar-link vimaan-tab">✈️ Vimaan</a></li>
    <li class="sub-navbar-item"><a href="../win.html" class="sub-navbar-link">Sapre</a></li>
    <li class="sub-navbar-item"><a href="../win1.html" class="sub-navbar-link">Becone</a></li>
    <li class="sub-navbar-item"><a href="../win2.html" class="sub-navbar-link">Emred</a></li>
    <li class="sub-navbar-item"><a href="../win3.html" class="sub-navbar-link">VIP</a></li>
    <li class="sub-navbar-item"><a href="index.php" class="sub-navbar-link active">Teen Patti</a></li>
    <li class="sub-navbar-item"><a href="../mining.html" class="sub-navbar-link">Mines</a></li>
  </ul>
</div>

<div class="game-container">
  <h1>♠ TEEN PATTI ♦</h1>
  <div class="subtitle">Boot: ₹<?php echo BOOT_AMOUNT; ?> | Play against Raju, Vikram, and Sana</div>

  <div class="table">
    <div class="pot-row"><span class="pot-badge" id="potBadge">Pot: ₹0</span></div>
    <div class="players" id="playersArea"></div>
    <div class="status-msg" id="statusMsg"></div>
    <div class="controls" id="controls"></div>
  </div>

  <div class="log-box" id="logBox"></div>
</div>

<div class="footer">
  bet1x — Teen Patti. All players, cards, and results shown are simulated for presentation purposes.
</div>

<script src="../assets/js/dummy-data.js"></script>
<script src="../assets/js/ui-common.js"></script>
<script>
async function callApi(action, extraParams = '') {
  let body = 'action=' + encodeURIComponent(action);
  if (extraParams) {
    body += '&' + extraParams;
  }
  const res = await fetch('api.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: body
  });
  return res.json();
}

function cardHtml(card) {
  if (!card) return '<div class="card back"></div>';
  return `<div class="card ${card.red ? 'red' : ''}">${card.label}<span>${card.symbol}</span></div>`;
}

let localCountdownInterval = null;
let syncInterval = null;

function render(data) {
  // Clear any existing countdown ticker
  if (localCountdownInterval) {
    clearInterval(localCountdownInterval);
    localCountdownInterval = null;
  }

  if (data.error) {
    document.getElementById('statusMsg').textContent = data.error;
    return;
  }
  if (!data.exists) {
    document.getElementById('playersArea').innerHTML = '';
    document.getElementById('controls').innerHTML =
      '<button class="btn btn-primary" onclick="newGame()">Naya Game Shuru Karein</button>';
    document.getElementById('statusMsg').textContent = 'Khelna shuru karne ke liye button dabayein.';
    
    if (syncInterval) {
      clearInterval(syncInterval);
      syncInterval = null;
    }
    return;
  }

  // Sync wallet balance to client side
  if (data.players && data.players.human) {
    setWallet(data.players.human.balance);
  }

  document.getElementById('potBadge').textContent = 'Pot: ₹' + data.pot.toLocaleString('en-IN');

  let html = '';
  data.order.forEach(key => {
    const p = data.players[key];
    const isTurn = data.turn === key && data.status === 'playing';
    const isWinner = data.status === 'finished' && data.winner === key;
    let cardsHtml = '';
    if (p.cards) {
      cardsHtml = p.cards.map(cardHtml).join('');
    } else {
      cardsHtml = cardHtml(null) + cardHtml(null) + cardHtml(null);
    }
    
    let timerHtml = '';
    if (isTurn) {
      if (key === 'human') {
        timerHtml = `<div class="turn-timer" style="color:var(--gold);font-weight:bold;margin-top:6px;font-size:12px;">Baari Samapt: <span id="turnCountdown">${data.time_left}</span>s</div>`;
      } else {
        timerHtml = `<div class="turn-timer" style="color:var(--text-dim);font-style:italic;margin-top:6px;font-size:12px;">Faisla le raha hai...</div>`;
      }
    }

    html += `
      <div class="player-card ${isTurn ? 'turn' : ''} ${p.folded ? 'folded' : ''} ${isWinner ? 'winner' : ''}">
        <div class="player-name">${p.name} ${p.is_bot ? '(Bot)' : ''}</div>
        <div class="player-balance">Balance: ₹${p.balance.toLocaleString('en-IN')}</div>
        <div class="cards-row">${cardsHtml}</div>
        <div class="hand-label">${p.hand_label ?? (p.folded ? 'Pack' : '')}</div>
        ${timerHtml}
      </div>`;
  });
  document.getElementById('playersArea').innerHTML = html;

  document.getElementById('logBox').innerHTML = data.log.map(l => `<div>&bull; ${l}</div>`).join('');
  document.getElementById('logBox').scrollTop = document.getElementById('logBox').scrollHeight;

  const controls = document.getElementById('controls');
  const statusMsg = document.getElementById('statusMsg');

  // Start client side tick-down if it is human's turn
  if (data.status === 'playing' && data.turn === 'human') {
    let sec = data.time_left;
    localCountdownInterval = setInterval(() => {
      sec = Math.max(0, sec - 1);
      const el = document.getElementById('turnCountdown');
      if (el) el.textContent = sec;
      if (sec <= 0) {
        clearInterval(localCountdownInterval);
      }
    }, 1000);
  }

  // Setup server polling loop during active game play
  if (data.status === 'playing') {
    if (!syncInterval) {
      syncInterval = setInterval(async () => {
        const d = await callApi('state');
        render(d);
      }, 1500);
    }
  } else {
    if (syncInterval) {
      clearInterval(syncInterval);
      syncInterval = null;
    }
  }

  if (data.status === 'finished') {
    statusMsg.innerHTML = `<span style="color:var(--green)">${data.players[data.winner].name} JEET GAYA! 🎉</span>`;
    controls.innerHTML = '<button class="btn btn-gold" onclick="newGame()">Agla Game Khelein</button>';
    return;
  }

  const activeCount = data.order.filter(k => !data.players[k].folded).length;
  if (data.turn === 'human') {
    statusMsg.textContent = 'Aapki baari hai — Stake: ₹' + data.current_stake;
    controls.innerHTML = `
      <button class="btn btn-primary" onclick="doAction('chaal')">Chaal (₹${data.current_stake})</button>
      <button class="btn btn-ghost" style="color:var(--red);border-color:rgba(255,75,110,0.4)" onclick="doAction('fold')">Pack (Fold)</button>
      ${activeCount === 2 ? '<button class="btn btn-gold" onclick="doAction(\'show\')">Show</button>' : ''}
    `;
  } else {
    statusMsg.textContent = `${data.players[data.turn].name} ki baari chal rahi hai...`;
    controls.innerHTML = '';
  }
}

async function newGame() {
  const balance = getWallet();
  const data = await callApi('new_game', 'balance=' + encodeURIComponent(balance));
  render(data);
}

async function doAction(action) {
  document.getElementById('controls').innerHTML = '';
  document.getElementById('statusMsg').textContent = 'Processing...';
  const data = await callApi(action);
  render(data);
}

async function loadState() {
  const data = await callApi('state');
  render(data);
}

loadState();
</script>

</body>
</html>