<?php
/**
 * index.php — Mines-style grid game DEMO (virtual points only)
 * Learning build: shows how logic.php / api.php / frontend fit together.
 * No real money, wallets, or payment flows anywhere in this project.
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Mines Game — bet1x</title>
<link rel="stylesheet" href="../assets/css/style.css">
<script src="../assets/js/dummy-data.js"></script>
<script src="../assets/js/ui-common.js"></script>
<style>
  body {
    margin: 0; font-family: system-ui, sans-serif; background: #0b0e14; color: #e8e8e8;
    display: flex; flex-direction: column; align-items: center; padding: 0 0 40px;
  }
  .banner {
    background: #1c2531; border: 1px solid #2e3a4a; border-radius: 8px;
    padding: 10px 16px; font-size: 13px; color: #9fb3c8; max-width: 900px; width: 100%; box-sizing: border-box; margin-bottom: 16px;
  }
  h1 { font-size: 20px; margin: 0 0 12px; color: var(--gold); }
  .layout { display: flex; gap: 24px; max-width: 900px; width: 100%; align-items: flex-start; justify-content: center; }
  .grid {
    display: grid; grid-template-columns: repeat(5, 64px); grid-template-rows: repeat(5, 64px);
    gap: 8px; background: #151b24; padding: 16px; border-radius: 12px; border: 1px solid #263041;
  }
  .tile {
    width: 64px; height: 64px; border-radius: 8px; border: none; cursor: pointer;
    background: #22303f; font-size: 24px; display: flex; align-items: center; justify-content: center;
    transition: transform 0.1s;
  }
  .tile:hover:not(:disabled) { transform: scale(1.05); background: #2c3e52; }
  .tile:disabled { cursor: default; }
  .tile.safe { background: #1f5c3d; }
  .tile.mine { background: #6b1f1f; }
  .side {
    width: 260px; background: #151b24; border: 1px solid #263041; border-radius: 12px; padding: 16px;
    display: flex; flex-direction: column; gap: 10px;
  }
  label { font-size: 12px; color: #9fb3c8; }
  input[type=number], select {
    background: #0e1116; border: 1px solid #33404f; color: #fff; border-radius: 6px;
    padding: 8px 10px; font-size: 14px; width: 100%; box-sizing: border-box;
  }
  button.action {
    border: none; border-radius: 6px; padding: 10px; font-size: 15px; font-weight: 600; cursor: pointer;
  }
  #startBtn { background: #3d8bfd; color: white; }
  #cashoutBtn { background: #ffb020; color: #1a1200; }
  button.action:disabled { opacity: 0.4; cursor: not-allowed; }
  .stat { font-size: 14px; }
  .stat strong { color: #52d17c; }
  .msg { min-height: 20px; font-size: 13px; color: #9fb3c8; }
  .seed { font-size: 11px; color: #56637a; word-break: break-all; }
</style>
</head>
<body>

  <!-- Header -->
  <nav class="navbar exchange-header" style="width:100%; box-sizing:border-box; margin-bottom: 0;">
    <div style="display:flex; align-items:center; gap:12px;">
      <div class="exchange-logo-box">
        bet1x<span>.com</span>
      </div>
    </div>
    <div id="headerAuthArea">
      <!-- Local updateAuthHeaderUI() populated here -->
    </div>
  </nav>

  <!-- Sub Navigation Bar -->
  <div class="sub-navbar" style="width:100%; max-width: 900px; margin-bottom: 20px; border-radius: 4px; box-sizing:border-box;">
    <ul class="sub-navbar-links">
      <li class="sub-navbar-item"><a href="../index.html" class="sub-navbar-link">Home</a></li>
      <li class="sub-navbar-item"><a href="../aviator.html" class="sub-navbar-link">Vimaan</a></li>
      <li class="sub-navbar-item"><a href="../win.html" class="sub-navbar-link">Sapre</a></li>
      <li class="sub-navbar-item"><a href="../win1.html" class="sub-navbar-link">Becone</a></li>
      <li class="sub-navbar-item"><a href="../win2.html" class="sub-navbar-link">Emred</a></li>
      <li class="sub-navbar-item"><a href="../win3.html" class="sub-navbar-link">VIP</a></li>
      <li class="sub-navbar-item"><a href="../teenpatti.html" class="sub-navbar-link">Teen Patti</a></li>
      <li class="sub-navbar-item"><a href="index.php" class="sub-navbar-link active">Mines</a></li>
    </ul>
  </div>

<div class="layout" style="margin-top: 20px;">
  <div class="grid" id="grid"></div>

  <div class="side">
    <div>
      <label>Bet amount</label>
      <input type="number" id="betAmount" value="100" min="1" step="1">
    </div>
    <div>
      <label>Number of mines (1–24)</label>
      <input type="number" id="minesCount" value="3" min="1" max="24" step="1">
    </div>
    <button class="action" id="startBtn">Start Game</button>
    <button class="action" id="cashoutBtn" disabled>Cash Out</button>

    <div class="stat">Balance: <strong id="balance">--</strong></div>
    <div class="stat">Multiplier: <strong id="multiplier">1.00x</strong></div>
    <div class="stat">Potential payout: <strong id="payout">0</strong></div>
    <div class="msg" id="msg"></div>
    <div class="seed" id="seedInfo"></div>
  </div>
</div>

<script>
const gridEl      = document.getElementById('grid');
const startBtn     = document.getElementById('startBtn');
const cashoutBtn   = document.getElementById('cashoutBtn');
const balanceEl    = document.getElementById('balance');
const multiplierEl = document.getElementById('multiplier');
const payoutEl     = document.getElementById('payout');
const msgEl        = document.getElementById('msg');
const seedInfo     = document.getElementById('seedInfo');
const betAmountEl  = document.getElementById('betAmount');
const minesCountEl = document.getElementById('minesCount');

const GRID_SIZE = 25;

function buildGrid() {
  gridEl.innerHTML = '';
  for (let i = 0; i < GRID_SIZE; i++) {
    const btn = document.createElement('button');
    btn.className = 'tile';
    btn.dataset.index = i;
    btn.addEventListener('click', () => revealTile(i));
    gridEl.appendChild(btn);
  }
}
buildGrid();

async function refreshState() {
  const res = await fetch('api.php?action=state');
  const data = await res.json();
  if (data.ok) render(data.state);
}

function render(state) {
  balanceEl.textContent = '₹' + state.balance.toFixed(2);
  multiplierEl.textContent = state.multiplier.toFixed(2) + 'x';
  payoutEl.textContent = '₹' + state.potential_payout.toFixed(2);

  const tiles = gridEl.querySelectorAll('.tile');
  const revealedSet = new Set(state.revealed || []);
  const gameOver = state.status === 'busted' || state.status === 'cashed';

  tiles.forEach((tile, i) => {
    tile.className = 'tile';
    tile.textContent = '';
    tile.disabled = state.status !== 'active';

    if (revealedSet.has(i)) {
      tile.classList.add('safe');
      tile.textContent = '💎';
    }
    if (gameOver && state.mine_positions && state.mine_positions.includes(i)) {
      tile.classList.add('mine');
      tile.textContent = '💣';
    }
  });

  startBtn.disabled = state.status === 'active';
  cashoutBtn.disabled = !(state.status === 'active' && state.revealed.length > 0);

  seedInfo.textContent = state.seed_hash
    ? (state.server_seed
        ? `Seed hash: ${state.seed_hash} — revealed seed: ${state.server_seed} (verify: sha256(seed) matches)`
        : `Seed hash (commitment, shown before play): ${state.seed_hash}`)
    : '';

  if (state.status === 'busted') {
    msgEl.textContent = '💥 Hit a mine — bet lost.';
  } else if (state.status === 'cashed') {
    msgEl.textContent = `✅ Cashed out for ₹${state.potential_payout.toFixed(2)}`;
  }

  if (typeof renderWalletChips === 'function') {
    renderWalletChips();
  }
}

startBtn.addEventListener('click', async () => {
  const bet = parseFloat(betAmountEl.value);
  const mines = parseInt(minesCountEl.value, 10);
  const res = await fetch('api.php?action=start', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: `bet_amount=${encodeURIComponent(bet)}&mines_count=${encodeURIComponent(mines)}`
  });
  const data = await res.json();
  if (data.ok) {
    msgEl.textContent = 'Game started — reveal a tile!';
    render(data.state);
  } else {
    msgEl.textContent = data.error;
  }
});

async function revealTile(index) {
  const res = await fetch('api.php?action=reveal', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'index=' + index
  });
  const data = await res.json();
  if (data.ok) {
    render(data.state);
    if (data.hit_mine) msgEl.textContent = '💥 Boom — you hit a mine.';
  } else {
    msgEl.textContent = data.error;
  }
}

cashoutBtn.addEventListener('click', async () => {
  const res = await fetch('api.php?action=cashout', { method: 'POST' });
  const data = await res.json();
  if (data.ok) {
    msgEl.textContent = `🎉 Cashed out for ₹${data.payout}`;
    render(data.state);
  } else {
    msgEl.textContent = data.error;
  }
});

refreshState();
</script>
</body>
</html>