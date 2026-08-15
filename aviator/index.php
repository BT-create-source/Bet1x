<?php
/**
 * index.php — Aviator-style crash game DEMO (virtual points / database integrated)
 */
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Aviator — bet1x</title>
<link rel="stylesheet" href="../assets/css/style.css">
<style>
  body {
    margin: 0;
    min-height: 100vh;
    display: flex;
    flex-direction: column;
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
    <li class="sub-navbar-item"><a href="../aviator.html" class="sub-navbar-link vimaan-tab active">✈️ Vimaan</a></li>
    <li class="sub-navbar-item"><a href="../win.html" class="sub-navbar-link">Sapre</a></li>
    <li class="sub-navbar-item"><a href="../win1.html" class="sub-navbar-link">Becone</a></li>
    <li class="sub-navbar-item"><a href="../win2.html" class="sub-navbar-link">Emred</a></li>
    <li class="sub-navbar-item"><a href="../win3.html" class="sub-navbar-link">VIP</a></li>
    <li class="sub-navbar-item"><a href="../teenpatti.html" class="sub-navbar-link">Teen Patti</a></li>
    <li class="sub-navbar-item"><a href="../boundarybaazi.html" class="sub-navbar-link">Boundary Baazi</a></li>
    <li class="sub-navbar-item"><a href="../youreleven.html" class="sub-navbar-link">Your Eleven</a></li>
    <li class="sub-navbar-item"><a href="../football.html" class="sub-navbar-link">Football</a></li>
    <li class="sub-navbar-item"><a href="../mining.html" class="sub-navbar-link">Mines</a></li>
  </ul>
</div>

<div class="container section-tight">
  <div id="alertBox"></div>

  <div class="aviator-layout-grid">
    <!-- Left column: Live bets feed -->
    <div class="card live-bets-card">
      <div class="panel-title">
        <span>All Bets</span>
        <span style="font-family: var(--font-mono); color: var(--green); font-size: 12px;" id="livePlayersCount">120 playing</span>
      </div>
      <div class="live-bets-list" id="liveBetsList">
        <!-- Live bets will be populated dynamically -->
      </div>
    </div>

    <!-- Right column: Main game board and bet controls -->
    <div class="aviator-wrapper">
      <!-- Top: Recent Multiplier History -->
      <div class="aviator-mult-history" id="multHistory">
        <!-- Populated dynamically -->
      </div>

      <!-- Center: Stage / Canvas Area -->
      <div class="aviator-stage">
        <canvas id="canvas" class="aviator-canvas"></canvas>
        <div id="gameOverlay" class="aviator-overlay waiting">
          <div class="main-display" id="overlayDisplay">Waiting for next round</div>
          <div class="sub-display" id="overlaySubDisplay">Next round in 8.0s</div>
        </div>
      </div>

      <!-- Bottom: Bet Controls -->
      <div class="bet-console" id="console1" style="max-width: 100%;">
        <div class="console-tabs">
          <button class="console-tab active" data-tab="bet">Bet</button>
          <button class="console-tab" data-tab="auto">Auto</button>
        </div>
        <div class="console-body">
          <div class="console-left">
            <div class="input-stepper">
              <button type="button" onclick="stepBet(-10)">−</button>
              <input type="number" id="betAmount" value="50" min="1" step="1">
              <button type="button" onclick="stepBet(10)">+</button>
            </div>
            <div class="quick-bet-grid">
              <button type="button" class="quick-bet-btn" onclick="setBetVal(10)">10</button>
              <button type="button" class="quick-bet-btn" onclick="setBetVal(50)">50</button>
              <button type="button" class="quick-bet-btn" onclick="setBetVal(100)">100</button>
              <button type="button" class="quick-bet-btn" onclick="setBetVal(500)">500</button>
            </div>
          </div>
          <div class="console-right" style="width: 220px;">
            <button class="big-bet-btn bet" id="actionBtn" onclick="handleBtnClick()">
              <span class="label">Place Bet</span>
              <span class="sub-label">₹50.00</span>
            </button>
          </div>
        </div>
        <!-- Auto cashout section (hidden by default) -->
        <div class="auto-options" id="autoOptions" style="display:none;">
          <label>
            <input type="checkbox" id="autoCashoutEnabled">
            Auto Cash Out
          </label>
          <input type="number" class="auto-cashout-val" id="autoCashoutVal" value="2.0" step="0.1" min="1.01">
        </div>
      </div>

      <!-- Provably Fair commitment -->
      <div class="provably-fair-bar">
        <span class="title">🛡️ Provably Fair commitment</span>
        <span class="seed-hash" id="seedCommitment">Round seed SHA256: loading...</span>
      </div>
    </div>
  </div>
</div>

<div class="footer">
  bet1x — Aviator crash room. All data and player logs shown are simulated for presentation purposes.
</div>

<script src="../assets/js/dummy-data.js"></script>
<script src="../assets/js/ui-common.js"></script>

<script>
// Load custom airplane image
const planeImg = new Image();
planeImg.src = '../assets/plane.png';

// Synthesized Web Audio API sound effects
let audioCtx = null;
let engineOsc = null;
let engineGain = null;

function initAudio() {
  if (!audioCtx) {
    audioCtx = new (window.AudioContext || window.webkitAudioContext)();
  }
}

function playSound(type) {
  try {
    initAudio();
    if (audioCtx.state === 'suspended') {
      audioCtx.resume();
    }
    
    if (type === 'click') {
      const osc = audioCtx.createOscillator();
      const gainNode = audioCtx.createGain();
      osc.connect(gainNode);
      gainNode.connect(audioCtx.destination);
      osc.frequency.setValueAtTime(600, audioCtx.currentTime);
      gainNode.gain.setValueAtTime(0.1, audioCtx.currentTime);
      gainNode.gain.exponentialRampToValueAtTime(0.01, audioCtx.currentTime + 0.08);
      osc.start();
      osc.stop(audioCtx.currentTime + 0.1);
    } else if (type === 'cashout') {
      const osc = audioCtx.createOscillator();
      const gainNode = audioCtx.createGain();
      osc.connect(gainNode);
      gainNode.connect(audioCtx.destination);
      osc.type = 'triangle';
      osc.frequency.setValueAtTime(523.25, audioCtx.currentTime); // C5
      osc.frequency.setValueAtTime(783.99, audioCtx.currentTime + 0.08); // G5
      gainNode.gain.setValueAtTime(0.2, audioCtx.currentTime);
      gainNode.gain.exponentialRampToValueAtTime(0.01, audioCtx.currentTime + 0.35);
      osc.start();
      osc.stop(audioCtx.currentTime + 0.4);
    } else if (type === 'crash') {
      stopEngineSound();
      const osc = audioCtx.createOscillator();
      const gainNode = audioCtx.createGain();
      osc.connect(gainNode);
      gainNode.connect(audioCtx.destination);
      osc.type = 'sawtooth';
      osc.frequency.setValueAtTime(120, audioCtx.currentTime);
      osc.frequency.linearRampToValueAtTime(20, audioCtx.currentTime + 0.45);
      gainNode.gain.setValueAtTime(0.3, audioCtx.currentTime);
      gainNode.gain.exponentialRampToValueAtTime(0.01, audioCtx.currentTime + 0.5);
      osc.start();
      osc.stop(audioCtx.currentTime + 0.5);
    }
  } catch (e) {
    console.warn("Synth failed", e);
  }
}

function startEngineSound(pitchFactor = 1.0) {
  try {
    initAudio();
    if (!engineOsc) {
      engineOsc = audioCtx.createOscillator();
      engineGain = audioCtx.createGain();
      engineOsc.connect(engineGain);
      engineGain.connect(audioCtx.destination);
      engineOsc.type = 'triangle';
      engineOsc.frequency.setValueAtTime(55, audioCtx.currentTime); // A1 base frequency
      engineGain.gain.setValueAtTime(0.0, audioCtx.currentTime);
      engineGain.gain.linearRampToValueAtTime(0.06, audioCtx.currentTime + 0.1);
      engineOsc.start();
    }
    // Scale pitch based on multiplier
    const targetFreq = 55 + Math.min(220, (pitchFactor - 1) * 35);
    engineOsc.frequency.setTargetAtTime(targetFreq, audioCtx.currentTime, 0.1);
  } catch (e) {
    console.warn("Engine audio failed", e);
  }
}

function stopEngineSound() {
  if (engineOsc) {
    try {
      engineOsc.stop();
    } catch(e){}
    engineOsc = null;
    engineGain = null;
  }
}

// Canvas Rendering variables
const canvas = document.getElementById('canvas');
const ctx = canvas.getContext('2d');
const overlay = document.getElementById('gameOverlay');
const overlayDisplay = document.getElementById('overlayDisplay');
const overlaySubDisplay = document.getElementById('overlaySubDisplay');

let width, height;
function resizeCanvas() {
  const container = canvas.parentElement;
  width = container.clientWidth;
  height = container.clientHeight;
  canvas.width = width;
  canvas.height = height;
}
window.addEventListener('resize', resizeCanvas);
resizeCanvas();

// Game State variables from server
let serverState = {
  round_id: 0,
  phase: 'waiting',
  seed_hash: '',
  server_seed: null,
  crash_point: null,
  multiplier: 1.00,
  seconds_left: null,
  balance: 0,
  bet: null
};

let lastRoundId = null;
let lastPhase = null;
let currentMultiplier = 1.00;
let clientStartTime = Date.now();
let cashoutAlertShown = false;

// Particle trails
let particles = [];
function createSmokeParticle(x, y) {
  particles.push({
    x: x,
    y: y,
    size: Math.random() * 6 + 4,
    opacity: 0.7,
    decay: Math.random() * 0.03 + 0.015,
    vx: -Math.random() * 1.5 - 0.5,
    vy: (Math.random() - 0.5) * 1.0
  });
}

// Multiplier history array
let historyLog = [1.25, 4.80, 1.05, 2.10, 15.34, 1.62, 3.40, 1.00, 1.95, 8.44];
function renderHistory() {
  const container = document.getElementById('multHistory');
  container.innerHTML = '';
  historyLog.slice(-15).reverse().forEach(val => {
    const badge = document.createElement('span');
    badge.className = 'mult-badge';
    if (val < 2.0) badge.classList.add('low');
    else if (val < 10.0) badge.classList.add('med');
    else badge.classList.add('high');
    badge.textContent = val.toFixed(2) + 'x';
    container.appendChild(badge);
  });
}

// Dynamic Mock Players
const firstNames = [];
const lastNames = [];
let mockPlayers = [];

function generateMockBets() {
  mockPlayers = [];
  document.getElementById('livePlayersCount').textContent = '0 playing';
  renderLiveBets();
}

function updateMockBets(mult, crashPt) {
  let changed = false;
  mockPlayers.forEach(p => {
    if (p.status === 'pending') {
      if (p.targetMult > 0 && mult >= p.targetMult && (crashPt === null || p.targetMult <= crashPt)) {
        p.status = 'won';
        p.cashedMultiplier = p.targetMult;
        changed = true;
      }
    }
  });
  if (changed) renderLiveBets();
}

function revealRemainingMockBets() {
  mockPlayers.forEach(p => {
    if (p.status === 'pending') {
      p.status = 'lost';
    }
  });
  renderLiveBets();
}

function renderLiveBets() {
  const container = document.getElementById('liveBetsList');
  container.innerHTML = '';
  
  mockPlayers.forEach(p => {
    const item = document.createElement('div');
    item.className = 'live-bet-item';
    
    let resultHTML = '';
    if (p.status === 'won') {
      item.classList.add('won');
      const payout = p.amount * p.cashedMultiplier;
      resultHTML = `<div class="amount-payout" style="color:var(--green)">₹${payout.toFixed(2)}<span class="payout-mult">${p.cashedMultiplier.toFixed(2)}x</span></div>`;
    } else if (p.status === 'lost') {
      item.classList.add('crashed');
      resultHTML = `<div class="amount-payout" style="color:var(--red); font-weight:normal;">Busted</div>`;
    } else {
      resultHTML = `<div class="amount-payout" style="color:var(--text-dim)">₹${p.amount.toFixed(0)}</div>`;
    }
    
    item.innerHTML = `
      <div class="user">
        <span>${p.name}</span>
        <span class="user-meta">Bet: ₹${p.amount.toFixed(0)}</span>
      </div>
      ${resultHTML}
    `;
    container.appendChild(item);
  });
}

// Stepper inputs
window.stepBet = function(delta) {
  playSound('click');
  const input = document.getElementById('betAmount');
  let val = parseFloat(input.value) + delta;
  if (isNaN(val) || val < 1) val = 1;
  input.value = Math.floor(val);
  updateBtnLabels();
};

window.setBetVal = function(val) {
  playSound('click');
  const input = document.getElementById('betAmount');
  input.value = val;
  updateBtnLabels();
};

function updateBtnLabels() {
  const input = document.getElementById('betAmount');
  const actionBtn = document.getElementById('actionBtn');
  if (!serverState.bet) {
    actionBtn.querySelector('.sub-label').textContent = '₹' + parseFloat(input.value).toFixed(2);
  }
}

// API Interactions
async function handleBtnClick() {
  const input = document.getElementById('betAmount');
  const amount = parseFloat(input.value);
  const actionBtn = document.getElementById('actionBtn');
  
  if (!serverState.bet) {
    // Place Bet
    playSound('click');
    actionBtn.disabled = true;
    try {
      const res = await fetch('api.php?action=bet', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'amount=' + encodeURIComponent(amount)
      });
      const data = await res.json();
      if (data.ok) {
        serverState = data.state;
        updateUI();
      } else {
        showAlert('danger', data.error);
        actionBtn.disabled = false;
      }
    } catch (e) {
      showAlert('danger', 'Connection error while betting.');
      actionBtn.disabled = false;
    }
  } else if (serverState.bet && !serverState.bet.cashed_out && serverState.phase === 'running') {
    // Cashout
    playSound('cashout');
    actionBtn.disabled = true;
    try {
      const res = await fetch('api.php?action=cashout', { method: 'POST' });
      const data = await res.json();
      if (data.ok) {
        serverState = data.state;
        updateUI();
        showAlert('success', `Successfully cashed out!`);
        triggerWinShower(document.querySelector('.aviator-stage'));
      } else {
        showAlert('danger', data.error);
        actionBtn.disabled = false;
      }
    } catch (e) {
      showAlert('danger', 'Connection error while cashing out.');
      actionBtn.disabled = false;
    }
  }
}

// Auto Cashout checks
function checkAutoCashout(mult) {
  const isAuto = document.getElementById('autoCashoutEnabled').checked;
  const target = parseFloat(document.getElementById('autoCashoutVal').value);
  if (isAuto && !isNaN(target) && mult >= target) {
    if (serverState.bet && !serverState.bet.cashed_out && serverState.phase === 'running') {
      handleBtnClick();
    }
  }
}

function updateUI() {
  // Sync Wallet Chip
  document.querySelectorAll('[data-wallet-chip]').forEach(el => {
    el.textContent = '₹ ' + serverState.balance.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  });

  const actionBtn = document.getElementById('actionBtn');
  const input = document.getElementById('betAmount');
  const amount = parseFloat(input.value);

  // Setup seedcommitment
  if (serverState.phase === 'crashed') {
    document.getElementById('seedCommitment').textContent = `Round #${serverState.round_id} server seed: ${serverState.server_seed} (hash: ${serverState.seed_hash})`;
  } else {
    document.getElementById('seedCommitment').textContent = `Round #${serverState.round_id} seed hash commitment: ${serverState.seed_hash}`;
  }

  // State actions based on phase
  if (serverState.phase === 'waiting') {
    cashoutAlertShown = false;
    actionBtn.disabled = false;
    
    if (serverState.bet) {
      actionBtn.className = 'big-bet-btn cancel';
      actionBtn.disabled = true; // Wait for round
      actionBtn.innerHTML = `
        <span class="label">Bet Placed</span>
        <span class="sub-label">Waiting...</span>
      `;
    } else {
      actionBtn.className = 'big-bet-btn bet';
      actionBtn.innerHTML = `
        <span class="label">Place Bet</span>
        <span class="sub-label">₹${amount.toFixed(2)}</span>
      `;
    }
  } else if (serverState.phase === 'running') {
    if (serverState.bet) {
      if (serverState.bet.cashed_out) {
        actionBtn.className = 'big-bet-btn bet';
        actionBtn.disabled = true;
        actionBtn.innerHTML = `
          <span class="label">Cashed Out</span>
          <span class="sub-label" style="color:var(--green)">+₹${(serverState.bet.amount * serverState.bet.cashout_multiplier).toFixed(2)}</span>
        `;
      } else {
        actionBtn.disabled = false;
        actionBtn.className = 'big-bet-btn cashout';
        actionBtn.innerHTML = `
          <span class="label">Cash Out</span>
          <span class="sub-label" id="cashoutVal">₹${(serverState.bet.amount * currentMultiplier).toFixed(2)}</span>
        `;
      }
    } else {
      actionBtn.className = 'big-bet-btn bet';
      actionBtn.disabled = true;
      actionBtn.innerHTML = `
        <span class="label">Place Bet</span>
        <span class="sub-label">In Flight</span>
      `;
    }
  } else if (serverState.phase === 'crashed') {
    actionBtn.className = 'big-bet-btn bet';
    actionBtn.disabled = true;
    
    if (serverState.bet) {
      if (serverState.bet.cashed_out) {
        actionBtn.innerHTML = `
          <span class="label">Won</span>
          <span class="sub-label" style="color:var(--green)">+₹${(serverState.bet.amount * serverState.bet.cashout_multiplier).toFixed(2)}</span>
        `;
      } else {
        actionBtn.innerHTML = `
          <span class="label">Busted</span>
          <span class="sub-label" style="color:var(--red)">Lost ₹${serverState.bet.amount.toFixed(2)}</span>
        `;
      }
    } else {
      actionBtn.innerHTML = `
        <span class="label">Busted</span>
        <span class="sub-label">Waiting next round</span>
      `;
    }
  }
}

// Polling Loop
async function pollState() {
  try {
    const res = await fetch('api.php?action=state');
    const data = await res.json();
    if (data.ok) {
      const oldState = serverState;
      serverState = data.state;
      
      // Check round change
      if (serverState.round_id !== lastRoundId) {
        lastRoundId = serverState.round_id;
        generateMockBets();
        lastCurvePoints = [];
        particles = [];
        if (oldState.crash_point) {
          historyLog.push(oldState.crash_point);
          if (historyLog.length > 20) historyLog.shift();
          renderHistory();
        }
      }
      
      // Shift in phase sound effects
      if (serverState.phase !== lastPhase) {
        lastPhase = serverState.phase;
        if (serverState.phase === 'crashed') {
          playSound('crash');
          revealRemainingMockBets();
        } else if (serverState.phase === 'running') {
          clientStartTime = Date.now();
          playSound('click');
        }
      }
      
      updateUI();
    }
  } catch (e) {
    console.warn("API state polling connection issue", e);
  }
  setTimeout(pollState, 150);
}

// Alert notifications
function showAlert(type, message) {
  const box = document.getElementById('alertBox');
  const alert = document.createElement('div');
  alert.className = `alert alert-${type === 'success' ? 'success' : 'error'}`;
  alert.textContent = message;
  box.appendChild(alert);
  setTimeout(() => {
    alert.style.opacity = '0';
    alert.style.transform = 'translateY(-10px)';
    alert.style.transition = 'opacity 0.4s, transform 0.4s';
    setTimeout(() => alert.remove(), 400);
  }, 3000);
}

// Setup console tabs for auto options
const tabs = document.querySelectorAll('.console-tab');
const autoOptions = document.getElementById('autoOptions');
tabs.forEach(tab => {
  tab.addEventListener('click', () => {
    tabs.forEach(t => t.classList.remove('active'));
    tab.classList.add('active');
    playSound('click');
    if (tab.dataset.tab === 'auto') {
      autoOptions.style.display = 'flex';
    } else {
      autoOptions.style.display = 'none';
    }
  });
});

// Game loop / Tick engine for Smooth client rendering
const growthRate = 0.06;

function gameTick() {
  const now = Date.now();

  if (serverState.phase === 'waiting') {
    const elapsed = now - clientStartTime; // Estimation
    overlay.className = 'aviator-overlay waiting';
    overlayDisplay.textContent = '✈️ TAKING OFF SOON';
    
    let secondsLeft = serverState.seconds_left !== null ? serverState.seconds_left : 8;
    overlaySubDisplay.textContent = `Next round in ${secondsLeft.toFixed(1)}s`;
    
    // Smooth countdown progress bar
    drawWaitingCanvas(secondsLeft / 8.0);
  } else if (serverState.phase === 'running') {
    // Interpolate multiplier locally for silky smooth 60fps rendering
    const runningElapsed = (now - clientStartTime) / 1000;
    // Don't get ahead of server multiplier by too much
    const estimatedMult = Math.exp(growthRate * runningElapsed);
    currentMultiplier = Math.min(estimatedMult, serverState.multiplier + 0.15);
    
    overlay.className = 'aviator-overlay running';
    overlayDisplay.textContent = currentMultiplier.toFixed(2) + 'x';
    overlaySubDisplay.textContent = '';
    
    // Draw dynamic curve
    drawFlightCanvas(currentMultiplier);
    
    // Update live bet console values
    const cashoutVal = document.getElementById('cashoutVal');
    if (cashoutVal && serverState.bet && !serverState.bet.cashed_out) {
      cashoutVal.textContent = '₹' + (serverState.bet.amount * currentMultiplier).toFixed(2);
    }
    
    // Auto cashout checks
    checkAutoCashout(currentMultiplier);
    updateMockBets(currentMultiplier, null);
    
    // Engine sound pitch
    startEngineSound(currentMultiplier);
  } else if (serverState.phase === 'crashed') {
    const crashVal = serverState.crash_point !== null ? serverState.crash_point : currentMultiplier;
    overlay.className = 'aviator-overlay crashed';
    overlayDisplay.textContent = 'FLEW AWAY';
    overlaySubDisplay.textContent = `@ ${crashVal.toFixed(2)}x`;
    
    // Draw crash state
    drawCrashedCanvas(crashVal);
  }
  
  requestAnimationFrame(gameTick);
}

// Canvas rendering helper functions
let lastCurvePoints = [];

function drawWaitingCanvas(progress) {
  ctx.clearRect(0, 0, width, height);
  drawGrid(0);
  
  // Draw loading bar in center
  const barW = Math.min(300, width - 80);
  const barH = 12;
  const barX = (width - barW) / 2;
  const barY = height / 2 + 40;
  
  ctx.fillStyle = 'rgba(255,255,255,0.06)';
  ctx.beginPath();
  ctx.roundRect(barX, barY, barW, barH, 6);
  ctx.fill();
  
  ctx.fillStyle = 'var(--gold)';
  ctx.beginPath();
  ctx.roundRect(barX, barY, barW * (1 - progress), barH, 6); // Ramps up to full bar
  ctx.fill();
  
  particles = [];
  lastCurvePoints = [];
}

function drawGrid(scrollOffset) {
  ctx.strokeStyle = 'rgba(38, 43, 74, 0.35)';
  ctx.lineWidth = 1;
  
  // Vertical lines scrolling left
  const spacingX = 80;
  const startX = -(scrollOffset % spacingX);
  for (let x = startX; x < width; x += spacingX) {
    ctx.beginPath();
    ctx.moveTo(x, 0);
    ctx.lineTo(x, height - 30);
    ctx.stroke();
  }
  
  // Horizontal lines scrolling down
  const spacingY = 60;
  const startY = (scrollOffset * 0.4) % spacingY;
  for (let y = startY; y < height - 30; y += spacingY) {
    ctx.beginPath();
    ctx.moveTo(0, y);
    ctx.lineTo(width, y);
    ctx.stroke();
  }
  
  // Axes
  ctx.strokeStyle = '#262b4a';
  ctx.lineWidth = 2;
  ctx.beginPath();
  ctx.moveTo(0, height - 30);
  ctx.lineTo(width, height - 30);
  ctx.stroke();
}

function drawFlightCanvas(mult) {
  ctx.clearRect(0, 0, width, height);
  
  // Background Grid scrolling proportional to time
  const scrollOffset = (Date.now() - clientStartTime) * 0.08;
  drawGrid(scrollOffset);
  
  // Path calculations
  const startX = 50;
  const startY = height - 30;
  const endLimitX = width - 100;
  const endLimitY = 50;
  
  const maxSeconds = Math.log(10) / growthRate;
  const currentSeconds = (Date.now() - clientStartTime) / 1000;
  const timeRatio = Math.min(1.0, currentSeconds / maxSeconds);
  
  const currentX = startX + (endLimitX - startX) * timeRatio;
  const heightRatio = Math.min(1.0, (mult - 1) / 9);
  const currentY = startY - (startY - endLimitY) * heightRatio;

  // Calculate flight slope angle
  let slopeAngle = -0.2;
  if (lastCurvePoints.length > 3) {
    const p1 = lastCurvePoints[lastCurvePoints.length - 3];
    const p2 = lastCurvePoints[lastCurvePoints.length - 1];
    slopeAngle = Math.atan2(p2.y - p1.y, p2.x - p1.x);
  }

  // Calculate tail coordinates (about 48px behind the plane center along the flight angle)
  const tailX = currentX - Math.cos(slopeAngle) * 48;
  const tailY = currentY - Math.sin(slopeAngle) * 48;
  
  // Push particle trail at the tail
  if (Math.random() < 0.4) {
    createSmokeParticle(tailX, tailY);
  }
  
  // Update and draw trail particles
  ctx.fillStyle = 'rgba(239, 68, 68, 0.4)';
  particles.forEach((p, idx) => {
    p.x += p.vx;
    p.y += p.vy;
    p.opacity -= p.decay;
    if (p.opacity <= 0) {
      particles.splice(idx, 1);
      return;
    }
    ctx.fillStyle = `rgba(239, 68, 68, ${p.opacity})`;
    ctx.beginPath();
    ctx.arc(p.x, p.y, p.size, 0, Math.PI * 2);
    ctx.fill();
  });
  
  // Save curve coordinates for line drawing (history tracks the center)
  lastCurvePoints.push({ x: currentX, y: currentY });
  if (lastCurvePoints.length > 500) lastCurvePoints.shift();
  
  // Draw glow line path
  ctx.shadowColor = '#ef4444';
  ctx.shadowBlur = 15;
  ctx.strokeStyle = '#ef4444';
  ctx.lineWidth = 5;
  ctx.lineCap = 'round';
  ctx.lineJoin = 'round';
  
  ctx.beginPath();
  ctx.moveTo(startX, startY);
  
  // Smooth line interpolation - draw up to the plane's tail point
  if (lastCurvePoints.length > 2) {
    for (let i = 1; i < lastCurvePoints.length - 1; i++) {
      ctx.lineTo(lastCurvePoints[i].x, lastCurvePoints[i].y);
    }
    ctx.lineTo(tailX, tailY);
  } else {
    ctx.lineTo(tailX, tailY);
  }
  ctx.stroke();
  
  // Reset shadow effects
  ctx.shadowBlur = 0;
  
  // Draw flight airplane image rotated to align its pre-tilted nose with the slope
  ctx.save();
  ctx.translate(currentX, currentY);
  ctx.rotate(slopeAngle + 0.38);
  ctx.drawImage(planeImg, -48, -48, 96, 96);
  ctx.restore();
}

function drawCrashedCanvas(crashVal) {
  ctx.clearRect(0, 0, width, height);
  drawGrid(0);
  
  // Draw trail with faded color
  ctx.strokeStyle = 'rgba(239, 68, 68, 0.2)';
  ctx.lineWidth = 4;
  ctx.beginPath();
  const startX = 50;
  const startY = height - 30;
  ctx.moveTo(startX, startY);
  
  if (lastCurvePoints.length > 0) {
    lastCurvePoints.forEach(p => {
      ctx.lineTo(p.x, p.y);
    });
    ctx.stroke();
    
    // Draw a small explosion burst at the tip of the crashed curve
    const lastPt = lastCurvePoints[lastCurvePoints.length - 1];
    ctx.fillStyle = 'var(--red)';
    ctx.beginPath();
    ctx.arc(lastPt.x, lastPt.y, 8, 0, Math.PI * 2);
    ctx.fill();
    
    ctx.font = '14px var(--font-mono)';
    ctx.fillStyle = 'var(--red)';
    ctx.fillText('💥 BUSTED', lastPt.x + 12, lastPt.y + 4);
  }
}

// Initial Boot
pollState();
renderHistory();
gameTick();

</script>
</body>
</html>