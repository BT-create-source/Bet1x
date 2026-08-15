<?php
/**
 * index.php — Football Sportsbook
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Football Sportsbook — bet1x</title>
<link rel="stylesheet" href="../assets/css/style.css">
<script src="../assets/js/dummy-data.js"></script>
<script src="../assets/js/ui-common.js"></script>
<style>
  :root {
    --fb-bg: #090c10;
    --fb-card: #131922;
    --fb-card-hover: #18202c;
    --fb-border: #1f2937;
    --fb-green: #10b981;
    --fb-gold: #f59e0b;
    --fb-blue: #3b82f6;
    --fb-red: #ef4444;
    --fb-purple: #8b5cf6;
    --fb-text: #f3f4f6;
    --fb-text-dim: #9ca3af;
  }

  body {
    margin: 0;
    font-family: 'Inter', system-ui, -apple-system, sans-serif;
    background: var(--fb-bg);
    color: var(--fb-text);
    display: flex;
    flex-direction: column;
    align-items: center;
    min-height: 100vh;
    padding-bottom: 50px;
  }

  /* Page Wrapper */
  .fb-container {
    max-width: 1140px;
    width: 100%;
    padding: 0 16px;
    box-sizing: border-box;
  }

  /* Featured Live Arena Hero Banner */
  .fb-hero {
    background: linear-gradient(135deg, rgba(16, 185, 129, 0.12) 0%, rgba(15, 23, 42, 0.95) 40%, rgba(30, 41, 59, 0.95) 100%), url('../assets/football_bg.png') center/cover;
    border: 1px solid var(--fb-border);
    border-radius: 14px;
    padding: 20px;
    margin-top: 16px;
    margin-bottom: 20px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.4);
    position: relative;
    overflow: hidden;
  }

  .fb-hero::before {
    content: '';
    position: absolute;
    top: 0; right: 0; bottom: 0; left: 0;
    background: radial-gradient(circle at 80% 20%, rgba(16, 185, 129, 0.15) 0%, transparent 60%);
    pointer-events: none;
  }

  .fb-hero-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;
  }

  .fb-league-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: rgba(245, 158, 11, 0.15);
    border: 1px solid rgba(245, 158, 11, 0.3);
    color: var(--fb-gold);
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 11.5px;
    font-weight: 700;
    letter-spacing: 0.5px;
    text-transform: uppercase;
  }

  .fb-hero-match {
    display: grid;
    grid-template-columns: 1fr auto 1fr;
    align-items: center;
    gap: 20px;
    text-align: center;
  }

  .fb-team-box {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
  }

  .fb-crest {
    width: 64px;
    height: 64px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 900;
    font-size: 22px;
    box-shadow: 0 6px 16px rgba(0,0,0,0.5);
    border: 2px solid rgba(255,255,255,0.15);
  }

  .fb-team-name {
    font-size: 16px;
    font-weight: 800;
    color: #fff;
  }

  .fb-team-form {
    display: flex;
    gap: 4px;
  }

  .fb-form-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    display: inline-block;
  }
  .fb-form-w { background: var(--fb-green); }
  .fb-form-d { background: var(--fb-gold); }
  .fb-form-l { background: var(--fb-red); }

  .fb-vs-box {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 6px;
  }

  .fb-score-pill {
    background: #090c10;
    border: 1px solid var(--fb-green);
    color: var(--fb-green);
    font-family: var(--font-mono);
    font-size: 24px;
    font-weight: 900;
    padding: 6px 20px;
    border-radius: 30px;
    box-shadow: 0 0 15px rgba(16, 185, 129, 0.3);
    letter-spacing: 2px;
  }

  .fb-live-tag {
    background: rgba(239, 68, 68, 0.2);
    border: 1px solid var(--fb-red);
    color: var(--fb-red);
    font-size: 11px;
    font-weight: 800;
    padding: 2px 8px;
    border-radius: 10px;
    animation: pulse 1.5s infinite;
  }

  @keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
  }

  /* Main Layout */
  .fb-layout {
    display: grid;
    grid-template-columns: 1fr 320px;
    gap: 20px;
    align-items: start;
  }

  @media (max-width: 900px) {
    .fb-layout {
      grid-template-columns: 1fr;
    }
  }

  /* League Filter Tabs */
  .fb-filters {
    display: flex;
    gap: 8px;
    margin-bottom: 16px;
    overflow-x: auto;
    padding-bottom: 4px;
  }

  .fb-filter-btn {
    background: var(--fb-card);
    border: 1px solid var(--fb-border);
    color: var(--fb-text-dim);
    padding: 8px 14px;
    border-radius: 8px;
    font-size: 12.5px;
    font-weight: 600;
    cursor: pointer;
    white-space: nowrap;
    transition: all 0.2s ease;
  }

  .fb-filter-btn:hover, .fb-filter-btn.active {
    background: rgba(16, 185, 129, 0.15);
    border-color: var(--fb-green);
    color: #fff;
  }

  /* Match Card */
  .fb-match-card {
    background: var(--fb-card);
    border: 1px solid var(--fb-border);
    border-radius: 12px;
    padding: 16px;
    margin-bottom: 14px;
    transition: transform 0.2s ease, border-color 0.2s ease;
  }

  .fb-match-card:hover {
    border-color: #374151;
  }

  .fb-card-top {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 14px;
    font-size: 12px;
    color: var(--fb-text-dim);
  }

  .fb-card-teams {
    display: grid;
    grid-template-columns: 1fr auto 1fr;
    align-items: center;
    gap: 12px;
    margin-bottom: 16px;
  }

  .fb-team-row {
    display: flex;
    align-items: center;
    gap: 10px;
  }
  .fb-team-row.away {
    justify-content: flex-end;
  }

  .fb-team-mini-crest {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    font-weight: 900;
    color: #fff;
    flex-shrink: 0;
  }

  .fb-match-time {
    font-family: var(--font-mono);
    font-size: 13px;
    font-weight: 700;
    background: rgba(255,255,255,0.05);
    padding: 4px 10px;
    border-radius: 6px;
    color: var(--fb-gold);
  }

  /* Betting Markets Grid */
  .fb-market-label {
    font-size: 11px;
    color: var(--fb-text-dim);
    text-transform: uppercase;
    font-weight: 700;
    letter-spacing: 0.5px;
    margin-bottom: 6px;
    display: block;
  }

  .fb-odds-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(80px, 1fr));
    gap: 6px;
    margin-bottom: 10px;
  }

  .fb-odds-btn {
    background: #0d1117;
    border: 1px solid #1f2937;
    color: var(--fb-text);
    border-radius: 6px;
    padding: 8px 6px;
    font-size: 12px;
    cursor: pointer;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 2px;
    transition: all 0.15s ease;
  }

  .fb-odds-btn:hover {
    border-color: var(--fb-green);
    background: rgba(16, 185, 129, 0.08);
  }

  .fb-odds-btn.selected {
    background: var(--fb-green) !important;
    border-color: var(--fb-green) !important;
    color: #000 !important;
    font-weight: 800;
    box-shadow: 0 0 10px rgba(16, 185, 129, 0.4);
  }

  .fb-odds-btn.selected .fb-odds-val {
    color: #000 !important;
  }

  .fb-odds-name {
    font-size: 10.5px;
    color: var(--fb-text-dim);
  }

  .fb-odds-val {
    font-family: var(--font-mono);
    font-weight: 800;
    font-size: 13px;
    color: var(--fb-gold);
  }

  .fb-action-bar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 12px;
    padding-top: 12px;
    border-top: 1px solid rgba(255,255,255,0.05);
  }

  .fb-sim-trigger {
    background: linear-gradient(135deg, var(--fb-gold), #d97706);
    color: #000;
    border: none;
    padding: 7px 14px;
    border-radius: 6px;
    font-weight: 800;
    font-size: 12px;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 6px;
    transition: transform 0.15s ease;
  }
  .fb-sim-trigger:hover {
    transform: translateY(-1px);
  }
  .fb-sim-trigger:disabled {
    opacity: 0.4;
    cursor: not-allowed;
    transform: none;
  }

  /* Bet Slip Sidebar */
  .fb-slip-card {
    background: var(--fb-card);
    border: 1px solid var(--fb-border);
    border-radius: 12px;
    padding: 16px;
    position: sticky;
    top: 20px;
    box-shadow: 0 8px 24px rgba(0,0,0,0.3);
  }

  .fb-slip-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
    padding-bottom: 8px;
    border-bottom: 1px solid var(--fb-border);
  }

  .fb-slip-title {
    font-size: 15px;
    font-weight: 800;
    color: #fff;
    display: flex;
    align-items: center;
    gap: 6px;
  }

  .fb-slip-tabs {
    display: flex;
    gap: 4px;
    background: #0d1117;
    padding: 3px;
    border-radius: 6px;
    margin-bottom: 12px;
  }

  .fb-slip-tab {
    flex: 1;
    text-align: center;
    padding: 6px;
    font-size: 11px;
    font-weight: 700;
    color: var(--fb-text-dim);
    border-radius: 4px;
    cursor: pointer;
  }
  .fb-slip-tab.active {
    background: var(--fb-card);
    color: #fff;
  }

  .fb-leg-item {
    background: #0d1117;
    border: 1px solid #1f2937;
    border-radius: 8px;
    padding: 10px;
    margin-bottom: 8px;
    position: relative;
  }

  .fb-leg-match {
    font-size: 11px;
    color: var(--fb-text-dim);
    margin-bottom: 4px;
  }

  .fb-leg-pick {
    font-size: 13px;
    font-weight: 800;
    color: #fff;
    display: flex;
    justify-content: space-between;
  }

  .fb-leg-odds {
    color: var(--fb-gold);
    font-family: var(--font-mono);
  }

  .fb-leg-remove {
    position: absolute;
    top: 6px; right: 6px;
    background: none;
    border: none;
    color: var(--fb-red);
    cursor: pointer;
    font-weight: bold;
    font-size: 14px;
  }

  .fb-stake-presets {
    display: flex;
    gap: 4px;
    margin-bottom: 10px;
  }

  .fb-preset-btn {
    flex: 1;
    background: #0d1117;
    border: 1px solid var(--fb-border);
    color: var(--fb-text-dim);
    padding: 4px;
    border-radius: 4px;
    font-size: 10.5px;
    font-weight: 700;
    cursor: pointer;
  }
  .fb-preset-btn:hover {
    border-color: var(--fb-green);
    color: #fff;
  }

  .fb-stake-input {
    width: 100%;
    background: #0d1117;
    border: 1px solid var(--fb-border);
    color: #fff;
    border-radius: 8px;
    padding: 10px;
    font-family: var(--font-mono);
    font-size: 15px;
    font-weight: 700;
    box-sizing: border-box;
    margin-bottom: 12px;
  }

  .fb-slip-summary {
    background: rgba(255,255,255,0.02);
    border-radius: 8px;
    padding: 10px;
    margin-bottom: 14px;
    font-size: 12.5px;
  }

  .fb-summary-row {
    display: flex;
    justify-content: space-between;
    margin-bottom: 6px;
  }

  .fb-summary-row.total {
    font-weight: 800;
    color: #fff;
    font-size: 14px;
    border-top: 1px solid var(--fb-border);
    padding-top: 6px;
    margin-bottom: 0;
  }

  .fb-place-btn {
    width: 100%;
    background: linear-gradient(135deg, var(--fb-green), #059669);
    color: #000;
    border: none;
    padding: 12px;
    border-radius: 8px;
    font-size: 15px;
    font-weight: 900;
    cursor: pointer;
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
    transition: transform 0.15s ease;
  }
  .fb-place-btn:hover {
    transform: translateY(-1px);
  }
  .fb-place-btn:disabled {
    opacity: 0.4;
    cursor: not-allowed;
    transform: none;
  }

  /* Live Commentary Modal */
  .fb-modal-overlay {
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(0,0,0,0.8);
    backdrop-filter: blur(4px);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 9999;
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.3s ease;
  }
  .fb-modal-overlay.active {
    opacity: 1;
    pointer-events: auto;
  }

  .fb-modal-card {
    background: #111827;
    border: 1px solid #374151;
    border-radius: 16px;
    max-width: 540px;
    width: 100%;
    padding: 20px;
    box-shadow: 0 20px 50px rgba(0,0,0,0.6);
  }

  .fb-commentary-box {
    background: #0d1117;
    border-radius: 8px;
    padding: 10px;
    height: 140px;
    overflow-y: auto;
    font-size: 12px;
    line-height: 1.5;
    margin-top: 12px;
  }

  .fb-comm-item {
    margin-bottom: 6px;
    padding-bottom: 6px;
    border-bottom: 1px solid rgba(255,255,255,0.05);
  }
  .fb-comm-item.goal {
    color: var(--fb-green);
    font-weight: 800;
  }
</style>
</head>
<body>

  <!-- Top Global Header Navigation -->
  <nav class="navbar exchange-header" style="width:100%; box-sizing:border-box; margin-bottom: 0;">
    <div style="display:flex; align-items:center; gap:12px;">
      <div class="exchange-logo-box">
        bet1x<span>.com</span>
      </div>
    </div>
    <div id="headerAuthArea"></div>
  </nav>

  <!-- Sub Navigation Bar -->
  <div class="sub-navbar" style="width:100%; max-width: 1140px; margin-bottom: 16px; border-radius: 4px; box-sizing:border-box;">
    <ul class="sub-navbar-links">
      <li class="sub-navbar-item"><a href="../index.html" class="sub-navbar-link">Home</a></li>
      <li class="sub-navbar-item"><a href="../aviator.html" class="sub-navbar-link">Vimaan</a></li>
      <li class="sub-navbar-item"><a href="../win.html" class="sub-navbar-link">Sapre</a></li>
      <li class="sub-navbar-item"><a href="../win1.html" class="sub-navbar-link">Becone</a></li>
      <li class="sub-navbar-item"><a href="../win2.html" class="sub-navbar-link">Emred</a></li>
      <li class="sub-navbar-item"><a href="../win3.html" class="sub-navbar-link">VIP</a></li>
      <li class="sub-navbar-item"><a href="../teenpatti.html" class="sub-navbar-link">Teen Patti</a></li>
      <li class="sub-navbar-item"><a href="../boundarybaazi.html" class="sub-navbar-link">Boundary Baazi</a></li>
      <li class="sub-navbar-item"><a href="../youreleven.html" class="sub-navbar-link">Your Eleven</a></li>
      <li class="sub-navbar-item"><a href="index.php" class="sub-navbar-link active">Football</a></li>
      <li class="sub-navbar-item"><a href="../mining.html" class="sub-navbar-link">Mines</a></li>
    </ul>
  </div>

  <div class="fb-container">
    
    <!-- Hero Banner: Match of the Day -->
    <div class="fb-hero">
      <div class="fb-hero-header">
        <div class="fb-league-badge">
          <span>🏆 UEFA Champions League — Final</span>
        </div>
        <div class="fb-live-tag">🔴 FEATURED MATCH</div>
      </div>

      <div class="fb-hero-match">
        <!-- Home Team -->
        <div class="fb-team-box">
          <div class="fb-crest" style="background: linear-gradient(135deg, #1e3a8a, #f59e0b);">RM</div>
          <div class="fb-team-name">Real Madrid</div>
          <div class="fb-team-form">
            <span class="fb-form-dot fb-form-w"></span>
            <span class="fb-form-dot fb-form-w"></span>
            <span class="fb-form-dot fb-form-d"></span>
            <span class="fb-form-dot fb-form-w"></span>
            <span class="fb-form-dot fb-form-w"></span>
          </div>
        </div>

        <!-- Center Score / VS -->
        <div class="fb-vs-box">
          <div class="fb-score-pill">2 - 1</div>
          <div style="font-size:11px; color:var(--fb-text-dim); text-transform:uppercase; letter-spacing:1px; font-weight:700;">Santiago Bernabéu</div>
        </div>

        <!-- Away Team -->
        <div class="fb-team-box">
          <div class="fb-crest" style="background: linear-gradient(135deg, #a855f7, #ef4444);">FCB</div>
          <div class="fb-team-name">FC Barcelona</div>
          <div class="fb-team-form">
            <span class="fb-form-dot fb-form-w"></span>
            <span class="fb-form-dot fb-form-l"></span>
            <span class="fb-form-dot fb-form-w"></span>
            <span class="fb-form-dot fb-form-w"></span>
            <span class="fb-form-dot fb-form-d"></span>
          </div>
        </div>
      </div>
    </div>

    <!-- League Filters -->
    <div class="fb-filters">
      <button class="fb-filter-btn active" onclick="filterLeague('all', this)">🔥 All Live Matches</button>
      <button class="fb-filter-btn" onclick="filterLeague('ucl', this)">🏆 Champions League</button>
      <button class="fb-filter-btn" onclick="filterLeague('epl', this)">🏴󠁧󠁢󠁥󠁮󠁧󠁿 Premier League</button>
      <button class="fb-filter-btn" onclick="filterLeague('laliga', this)">🇪🇸 La Liga</button>
      <button class="fb-filter-btn" onclick="filterLeague('seriea', this)">🇮🇹 Serie A</button>
    </div>

    <!-- Main Content Layout -->
    <div class="fb-layout">
      <!-- Left Column: Matches List -->
      <div>
        <div id="fbMatchesList">
          <!-- Rendered dynamically -->
        </div>

        <!-- Bet History & Settled Wagers Section -->
        <div style="margin-top:24px; background:var(--fb-card); border:1px solid var(--fb-border); border-radius:12px; padding:16px;">
          <h3 style="margin:0 0 12px; font-size:15px; color:#fff;">Bet History & Settled Wagers</h3>
          <div id="fbBetHistory"></div>
        </div>
      </div>

      <!-- Right Column: Interactive Bet Slip -->
      <div class="fb-slip-card">
        <div class="fb-slip-header">
          <div class="fb-slip-title">🎟️ Bet Slip</div>
          <div id="fbSlipCountBadge" style="background:var(--fb-green); color:#000; font-size:10.5px; font-weight:900; padding:2px 7px; border-radius:10px;">0 PICKS</div>
        </div>

        <div class="fb-slip-tabs">
          <div class="fb-slip-tab active" id="fbSlipTabSingle" onclick="switchSlipTab('single')">Single Wagers</div>
          <div class="fb-slip-tab" id="fbSlipTabAccumulator" onclick="switchSlipTab('accumulator')">Accumulator Parlay</div>
        </div>

        <div id="fbSlipLegsList">
          <!-- Dynamic Legs -->
        </div>

        <div id="fbEmptySlipMsg" style="text-align:center; padding:20px 0; color:var(--fb-text-dim); font-size:12.5px;">
          Click any odds multiplier button to add match picks to your slip.
        </div>

        <!-- Stake Input & Presets -->
        <div style="margin-top:12px;">
          <label style="font-size:11px; color:var(--fb-text-dim); font-weight:700; text-transform:uppercase; display:block; margin-bottom:4px;">Stake Amount (₹)</label>
          <div class="fb-stake-presets">
            <button class="fb-preset-btn" onclick="setStakePreset(100)">+100</button>
            <button class="fb-preset-btn" onclick="setStakePreset(500)">+500</button>
            <button class="fb-preset-btn" onclick="setStakePreset(1000)">+1000</button>
            <button class="fb-preset-btn" onclick="setStakePreset(5000)">+5000</button>
          </div>
          <input type="number" id="fbStakeInput" class="fb-stake-input" value="100" min="1" step="1">
        </div>

        <!-- Payout Summary -->
        <div class="fb-slip-summary">
          <div class="fb-summary-row">
            <span style="color:var(--fb-text-dim);">Total Odds:</span>
            <strong id="fbTotalOddsVal" style="color:var(--fb-gold); font-family:var(--font-mono);">1.00x</strong>
          </div>
          <div class="fb-summary-row total">
            <span>Potential Payout:</span>
            <strong id="fbPotentialPayoutVal" style="color:var(--fb-green); font-family:var(--font-mono);">₹0.00</strong>
          </div>
        </div>

        <button id="fbPlaceBetBtn" class="fb-place-btn" onclick="placeFootballBet()" disabled>PLACE BET</button>
        <div id="fbBetMsg" style="font-size:12px; margin-top:8px; text-align:center; min-height:16px;"></div>
      </div>
    </div>
  </div>

  <!-- Live Commentary & Kickoff Simulation Modal -->
  <div id="fbSimModal" class="fb-modal-overlay">
    <div class="fb-modal-card">
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
        <div style="font-size:14px; font-weight:800; color:var(--fb-gold);">LIVE MATCH ARENA SIMULATION</div>
        <button onclick="closeSimModal()" style="background:none; border:none; color:#fff; font-size:18px; cursor:pointer;">✕</button>
      </div>

      <div style="text-align:center; background:#0d1117; padding:16px; border-radius:10px; margin-bottom:12px;">
        <div id="fbSimMatchTitle" style="font-size:16px; font-weight:800; color:#fff; margin-bottom:8px;">Match Kickoff</div>
        <div id="fbSimMatchScore" style="font-family:var(--font-mono); font-size:28px; font-weight:900; color:var(--fb-green);">0 - 0</div>
        <div id="fbSimMatchStatus" style="font-size:11.5px; color:var(--fb-gold); margin-top:4px; font-weight:700;">First Half 1'</div>
      </div>

      <div class="fb-commentary-box" id="fbSimCommentaryFeed">
        <!-- Live Play-by-play events -->
      </div>
    </div>
  </div>

<script>
// --- REALISTIC PREMIER FOOTBALL MATCHES DUMMY DATA ---
let footballMatches = [
  {
    id: 1,
    league: 'ucl',
    league_label: '🏆 UEFA Champions League',
    home: 'Real Madrid',
    away: 'FC Barcelona',
    home_bg: 'linear-gradient(135deg, #1e3a8a, #f59e0b)',
    away_bg: 'linear-gradient(135deg, #a855f7, #ef4444)',
    home_code: 'RM',
    away_code: 'FCB',
    status: 'scheduled',
    time_label: '21:00 UTC',
    score: null,
    odds: {
      home: 1.95, draw: 3.50, away: 3.70,
      over25: 1.68, under25: 2.10,
      btts_yes: 1.60, btts_no: 2.20,
      double_chance: { '1X': 1.25, '12': 1.28, 'X2': 1.80 },
      correct_score: { '1-0': 7.00, '2-0': 9.50, '2-1': 8.00, '1-1': 6.50, '2-2': 11.00, '0-1': 11.00, '1-2': 12.00, 'other': 4.50 }
    }
  },
  {
    id: 2,
    league: 'epl',
    league_label: '🏴󠁧󠁢󠁥󠁮󠁧󠁿 Premier League',
    home: 'Manchester City',
    away: 'Arsenal FC',
    home_bg: 'linear-gradient(135deg, #38bdf8, #0284c7)',
    away_bg: 'linear-gradient(135deg, #dc2626, #ffffff)',
    home_code: 'MCI',
    away_code: 'ARS',
    status: 'scheduled',
    time_label: '19:30 UTC',
    score: null,
    odds: {
      home: 1.80, draw: 3.60, away: 4.20,
      over25: 1.75, under25: 2.00,
      btts_yes: 1.70, btts_no: 2.05,
      double_chance: { '1X': 1.20, '12': 1.26, 'X2': 1.95 },
      correct_score: { '1-0': 6.50, '2-0': 8.50, '2-1': 7.50, '1-1': 7.00, '2-2': 12.00, '0-1': 12.00, '1-2': 13.00, 'other': 5.00 }
    }
  },
  {
    id: 3,
    league: 'bundesliga',
    league_label: '🇩🇪 Bundesliga',
    home: 'Bayern Munich',
    away: 'Borussia Dortmund',
    home_bg: 'linear-gradient(135deg, #b91c1c, #2563eb)',
    away_bg: 'linear-gradient(135deg, #eab308, #000000)',
    home_code: 'BAY',
    away_code: 'BVB',
    status: 'scheduled',
    time_label: '17:30 UTC',
    score: null,
    odds: {
      home: 1.52, draw: 4.50, away: 5.50,
      over25: 1.45, under25: 2.60,
      btts_yes: 1.55, btts_no: 2.30,
      double_chance: { '1X': 1.12, '12': 1.18, 'X2': 2.45 },
      correct_score: { '1-0': 8.50, '2-0': 7.50, '2-1': 7.00, '3-1': 10.00, '1-1': 9.00, '0-1': 16.00, 'other': 3.20 }
    }
  },
  {
    id: 4,
    league: 'ucl',
    league_label: '🏆 UEFA Champions League',
    home: 'Paris Saint-Germain',
    away: 'Liverpool FC',
    home_bg: 'linear-gradient(135deg, #1d4ed8, #dc2626)',
    away_bg: 'linear-gradient(135deg, #991b1b, #ffffff)',
    home_code: 'PSG',
    away_code: 'LIV',
    status: 'scheduled',
    time_label: '20:00 UTC',
    score: null,
    odds: {
      home: 2.30, draw: 3.40, away: 2.90,
      over25: 1.85, under25: 1.90,
      btts_yes: 1.65, btts_no: 2.15,
      double_chance: { '1X': 1.38, '12': 1.29, 'X2': 1.58 },
      correct_score: { '1-0': 9.00, '2-1': 9.00, '1-1': 6.20, '2-2': 11.00, '0-1': 10.00, '1-2': 10.50, 'other': 5.50 }
    }
  },
  {
    id: 5,
    league: 'seriea',
    league_label: '🇮🇹 Serie A',
    home: 'Inter Milan',
    away: 'Juventus',
    home_bg: 'linear-gradient(135deg, #1e40af, #000000)',
    away_bg: 'linear-gradient(135deg, #ffffff, #000000)',
    home_code: 'INT',
    away_code: 'JUV',
    status: 'scheduled',
    time_label: '19:45 UTC',
    score: null,
    odds: {
      home: 2.05, draw: 3.20, away: 3.80,
      over25: 2.15, under25: 1.65,
      btts_yes: 1.90, btts_no: 1.85,
      double_chance: { '1X': 1.25, '12': 1.32, 'X2': 1.72 },
      correct_score: { '1-0': 6.00, '2-0': 8.50, '1-1': 5.80, '0-0': 8.00, '0-1': 9.50, 'other': 6.00 }
    }
  }
];

let slipPicks = [];
let currentLeagueFilter = 'all';
let activeSlipTab = 'single';

function switchSlipTab(tabType) {
  activeSlipTab = tabType;
  const singleTab = document.getElementById('fbSlipTabSingle');
  const accTab = document.getElementById('fbSlipTabAccumulator');
  if (singleTab && accTab) {
    if (tabType === 'single') {
      singleTab.classList.add('active');
      accTab.classList.remove('active');
    } else {
      singleTab.classList.remove('active');
      accTab.classList.add('active');
    }
  }
  renderSlip();
}

document.addEventListener('DOMContentLoaded', () => {
  const storedMatches = localStorage.getItem('bet1x_football_matches_v2');
  if (storedMatches) {
    try { footballMatches = JSON.parse(storedMatches); } catch(e){}
  }
  
  renderMatchesList();
  renderBetHistory();
  renderSlip();
});

function filterLeague(league, btnEl) {
  currentLeagueFilter = league;
  document.querySelectorAll('.fb-filter-btn').forEach(b => b.classList.remove('active'));
  if (btnEl) btnEl.classList.add('active');
  renderMatchesList();
}

function renderMatchesList() {
  const container = document.getElementById('fbMatchesList');
  container.innerHTML = '';

  const filtered = footballMatches.filter(m => currentLeagueFilter === 'all' || m.league === currentLeagueFilter);

  if (filtered.length === 0) {
    container.innerHTML = '<div style="text-align:center; padding:30px; color:var(--fb-text-dim);">No active fixtures found for this league.</div>';
    return;
  }

  filtered.forEach(m => {
    const isFinished = m.status === 'finished';
    const card = document.createElement('div');
    card.className = 'fb-match-card';

    const isSelected = (bType, sel) => slipPicks.some(p => p.match_id === m.id && p.bet_type === bType && p.selection === sel);

    card.innerHTML = `
      <div class="fb-card-top">
        <span>${m.league_label}</span>
        <span class="fb-match-time">${isFinished ? 'FULL TIME' : m.time_label}</span>
      </div>

      <div class="fb-card-teams">
        <div class="fb-team-row">
          <div class="fb-team-mini-crest" style="background:${m.home_bg};">${m.home_code}</div>
          <div style="font-weight:700; color:#fff; font-size:15px;">${m.home}</div>
        </div>

        <div style="text-align:center;">
          ${isFinished
            ? `<span style="font-family:var(--font-mono); font-size:20px; font-weight:900; color:var(--fb-green);">${m.score[0]} - ${m.score[1]}</span>`
            : `<span style="font-size:12px; font-weight:800; color:var(--fb-text-dim);">VS</span>`}
        </div>

        <div class="fb-team-row away">
          <div style="font-weight:700; color:#fff; font-size:15px;">${m.away}</div>
          <div class="fb-team-mini-crest" style="background:${m.away_bg};">${m.away_code}</div>
        </div>
      </div>

      <span class="fb-market-label">Full Time Result (1X2)</span>
      <div class="fb-odds-row">
        <button class="fb-odds-btn ${isSelected('match_winner', 'home') ? 'selected' : ''}" ${isFinished ? 'disabled' : ''} onclick="togglePick(${m.id}, 'match_winner', 'home', '${m.home} Win', ${m.odds.home})">
          <span class="fb-odds-name">1 (${m.home})</span>
          <span class="fb-odds-val">${m.odds.home.toFixed(2)}x</span>
        </button>

        <button class="fb-odds-btn ${isSelected('match_winner', 'draw') ? 'selected' : ''}" ${isFinished ? 'disabled' : ''} onclick="togglePick(${m.id}, 'match_winner', 'draw', 'Draw', ${m.odds.draw})">
          <span class="fb-odds-name">X (Draw)</span>
          <span class="fb-odds-val">${m.odds.draw.toFixed(2)}x</span>
        </button>

        <button class="fb-odds-btn ${isSelected('match_winner', 'away') ? 'selected' : ''}" ${isFinished ? 'disabled' : ''} onclick="togglePick(${m.id}, 'match_winner', 'away', '${m.away} Win', ${m.odds.away})">
          <span class="fb-odds-name">2 (${m.away})</span>
          <span class="fb-odds-val">${m.odds.away.toFixed(2)}x</span>
        </button>
      </div>

      <span class="fb-market-label">Goals & Both Teams To Score</span>
      <div class="fb-odds-row">
        <button class="fb-odds-btn ${isSelected('over_under', 'over25') ? 'selected' : ''}" ${isFinished ? 'disabled' : ''} onclick="togglePick(${m.id}, 'over_under', 'over25', 'Over 2.5 Goals', ${m.odds.over25})">
          <span class="fb-odds-name">Over 2.5</span>
          <span class="fb-odds-val">${m.odds.over25.toFixed(2)}x</span>
        </button>

        <button class="fb-odds-btn ${isSelected('over_under', 'under25') ? 'selected' : ''}" ${isFinished ? 'disabled' : ''} onclick="togglePick(${m.id}, 'over_under', 'under25', 'Under 2.5 Goals', ${m.odds.under25})">
          <span class="fb-odds-name">Under 2.5</span>
          <span class="fb-odds-val">${m.odds.under25.toFixed(2)}x</span>
        </button>

        <button class="fb-odds-btn ${isSelected('btts', 'btts_yes') ? 'selected' : ''}" ${isFinished ? 'disabled' : ''} onclick="togglePick(${m.id}, 'btts', 'btts_yes', 'BTTS Yes', ${m.odds.btts_yes})">
          <span class="fb-odds-name">BTTS Yes</span>
          <span class="fb-odds-val">${m.odds.btts_yes.toFixed(2)}x</span>
        </button>
      </div>

      <div class="fb-action-bar">
        <div style="font-size:11.5px; color:var(--fb-text-dim);">
          ${isFinished ? '⚡ Result Settled & Verified' : '🔒 Verified Fair Simulation'}
        </div>
        ${!isFinished
          ? `<button class="fb-sim-trigger" onclick="openSimulateArena(${m.id})">Kickoff Simulation</button>`
          : `<span style="color:var(--fb-green); font-weight:800; font-size:12px;">✅ SETTLED</span>`}
      </div>
    `;

    container.appendChild(card);
  });
}

function togglePick(matchId, betType, selection, label, odds) {
  const match = footballMatches.find(m => m.id === matchId);
  const matchLabel = match ? `${match.home} vs ${match.away}` : `Match #${matchId}`;
  const leagueLabel = match ? match.league_label : '';
  const timeLabel = match ? match.time_label : '';
  
  const existingIndex = slipPicks.findIndex(p => p.match_id === matchId && p.bet_type === betType);
  if (existingIndex >= 0 && slipPicks[existingIndex].selection === selection) {
    slipPicks.splice(existingIndex, 1);
  } else if (existingIndex >= 0) {
    slipPicks[existingIndex] = { match_id: matchId, match_label: matchLabel, league_label: leagueLabel, time_label: timeLabel, bet_type: betType, selection, label, odds };
  } else {
    slipPicks.push({ match_id: matchId, match_label: matchLabel, league_label: leagueLabel, time_label: timeLabel, bet_type: betType, selection, label, odds });
  }

  renderSlip();
  renderMatchesList();
}

function renderSlip() {
  const container = document.getElementById('fbSlipLegsList');
  const emptyMsg = document.getElementById('fbEmptySlipMsg');
  const countBadge = document.getElementById('fbSlipCountBadge');
  const totalOddsEl = document.getElementById('fbTotalOddsVal');
  const potentialPayoutEl = document.getElementById('fbPotentialPayoutVal');
  const placeBtn = document.getElementById('fbPlaceBetBtn');

  container.innerHTML = '';
  countBadge.textContent = `${slipPicks.length} PICKS`;

  if (slipPicks.length === 0) {
    emptyMsg.style.display = 'block';
    totalOddsEl.textContent = '1.00x';
    potentialPayoutEl.textContent = '₹0.00';
    placeBtn.textContent = 'PLACE BET';
    placeBtn.disabled = true;
    return;
  }

  emptyMsg.style.display = 'none';

  let totalOdds = 1.0;
  let totalSinglePayout = 0;
  const stake = parseFloat(document.getElementById('fbStakeInput').value) || 0;

  slipPicks.forEach((pick, index) => {
    totalOdds *= pick.odds;
    totalSinglePayout += stake * pick.odds;
    const item = document.createElement('div');
    item.className = 'fb-leg-item';
    item.innerHTML = `
      <button class="fb-leg-remove" onclick="removePick(${index})">✕</button>
      <div style="font-size:9.5px; color:var(--fb-gold); font-weight:700; margin-bottom:2px; display:flex; justify-content:space-between;">
        <span>${pick.league_label || 'Match'}</span>
        <span>${pick.time_label || ''}</span>
      </div>
      <div class="fb-leg-match" style="font-weight:700; color:#fff; font-size:12.5px; margin-bottom:4px;">${pick.match_label}</div>
      <div class="fb-leg-pick">
        <span>${pick.label}</span>
        <span class="fb-leg-odds" style="color:var(--fb-gold); font-weight:800;">${pick.odds.toFixed(2)}x</span>
      </div>
    `;
    container.appendChild(item);
  });

  if (activeSlipTab === 'single') {
    totalOddsEl.textContent = 'N/A';
    potentialPayoutEl.textContent = '₹' + totalSinglePayout.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    placeBtn.textContent = `PLACE ${slipPicks.length} SINGLE BETS (Total: ₹${(stake * slipPicks.length).toFixed(2)})`;
    placeBtn.disabled = (stake <= 0 || (stake * slipPicks.length) > getWallet());
  } else {
    const payout = stake * totalOdds;
    totalOddsEl.textContent = totalOdds.toFixed(2) + 'x';
    potentialPayoutEl.textContent = '₹' + payout.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    placeBtn.textContent = 'PLACE ACCUMULATOR BET';
    placeBtn.disabled = (stake <= 0 || stake > getWallet());
  }
}

function removePick(index) {
  slipPicks.splice(index, 1);
  renderSlip();
  renderMatchesList();
}

function setStakePreset(amt) {
  document.getElementById('fbStakeInput').value = amt;
  renderSlip();
}

document.getElementById('fbStakeInput').addEventListener('input', renderSlip);

function placeFootballBet() {
  const stake = parseFloat(document.getElementById('fbStakeInput').value);
  const msgEl = document.getElementById('fbBetMsg');
  const currentBal = getWallet();

  if (stake <= 0 || isNaN(stake)) {
    msgEl.style.color = 'var(--fb-red)';
    msgEl.textContent = 'Please enter a valid stake.';
    return;
  }

  const userBets = JSON.parse(localStorage.getItem('bet1x_football_bets_v2') || '[]');

  if (activeSlipTab === 'single') {
    const totalCost = stake * slipPicks.length;
    if (totalCost > currentBal) {
      msgEl.style.color = 'var(--fb-red)';
      msgEl.textContent = 'Insufficient virtual coin balance!';
      return;
    }

    adjustWallet(-totalCost, `Football Single Wagers (${slipPicks.length} bets)`);

    slipPicks.forEach(pick => {
      const newBet = {
        id: 'FB' + Math.floor(1000 + Math.random() * 9000),
        timestamp: new Date().toLocaleTimeString(),
        stake: stake,
        total_odds: pick.odds,
        potential_payout: stake * pick.odds,
        status: 'pending',
        legs: [{ ...pick, result: 'pending' }]
      };
      userBets.unshift(newBet);
    });
  } else {
    // Accumulator Parlay
    if (stake > currentBal) {
      msgEl.style.color = 'var(--fb-red)';
      msgEl.textContent = 'Insufficient virtual coin balance!';
      return;
    }

    adjustWallet(-stake, 'Football Accumulator Parlay Wager');

    const totalOdds = slipPicks.reduce((acc, p) => acc * p.odds, 1);
    const newBet = {
      id: 'FB' + Math.floor(1000 + Math.random() * 9000),
      timestamp: new Date().toLocaleTimeString(),
      stake: stake,
      total_odds: totalOdds,
      potential_payout: stake * totalOdds,
      status: 'pending',
      legs: slipPicks.map(p => ({ ...p, result: 'pending' }))
    };
    userBets.unshift(newBet);
  }

  localStorage.setItem('bet1x_football_bets_v2', JSON.stringify(userBets));

  slipPicks = [];
  renderSlip();
  renderMatchesList();
  renderBetHistory();

  msgEl.style.color = 'var(--fb-green)';
  msgEl.textContent = '✓ Football bet placed successfully!';
  setTimeout(() => { msgEl.textContent = ''; }, 3000);
}

let simInterval = null;

function openSimulateArena(matchId) {
  const match = footballMatches.find(m => m.id === matchId);
  if (!match || match.status === 'finished') return;

  const overlay = document.getElementById('fbSimModal');
  const title = document.getElementById('fbSimMatchTitle');
  const score = document.getElementById('fbSimMatchScore');
  const status = document.getElementById('fbSimMatchStatus');
  const feed = document.getElementById('fbSimCommentaryFeed');

  title.textContent = `${match.home} vs ${match.away}`;
  score.textContent = '0 - 0';
  status.textContent = "First Half 1'";
  feed.innerHTML = '<div class="fb-comm-item">⏱️ Referee blows kickoff whistle! Match is underway.</div>';

  overlay.classList.add('active');

  let h = Math.floor(Math.random() * 3);
  let a = Math.floor(Math.random() * 3);

  if (Math.random() > 0.6) h += 1;
  if (Math.random() > 0.8) a += 1;

  let currentMin = 1;
  let curH = 0;
  let curA = 0;

  if (simInterval) clearInterval(simInterval);

  simInterval = setInterval(() => {
    currentMin += 15;

    if (currentMin === 45) {
      status.textContent = 'Half Time 45+2';
      feed.insertAdjacentHTML('afterbegin', '<div class="fb-comm-item">⏸️ Half Time whistle. Players head to tunnels.</div>');
    } else if (currentMin < 90) {
      status.textContent = `Second Half ${currentMin}'`;
      if (curH < h && Math.random() < 0.6) {
        curH++;
        feed.insertAdjacentHTML('afterbegin', `<div class="fb-comm-item goal">GOAL (${currentMin}')! ${match.home} score a brilliant team goal!</div>`);
      } else if (curA < a && Math.random() < 0.6) {
        curA++;
        feed.insertAdjacentHTML('afterbegin', `<div class="fb-comm-item goal">GOAL (${currentMin}')! ${match.away} equalize on the counter attack!</div>`);
      } else {
        feed.insertAdjacentHTML('afterbegin', `<div class="fb-comm-item">📌 (${currentMin}') Tactical battle in midfield. Possession back and forth.</div>`);
      }
      score.textContent = `${curH} - ${curA}`;
    } else {
      clearInterval(simInterval);
      curH = h;
      curA = a;
      score.textContent = `${h} - ${a}`;
      status.textContent = 'FULL TIME 90+4';
      feed.insertAdjacentHTML('afterbegin', `<div class="fb-comm-item goal">🏆 FULL TIME! Final Score: ${match.home} ${h} - ${a} ${match.away}</div>`);

      settleMatchOutcome(matchId, h, a);
    }
  }, 700);
}

function closeSimModal() {
  document.getElementById('fbSimModal').classList.remove('active');
  if (simInterval) clearInterval(simInterval);
}

function settleMatchOutcome(matchId, h, a) {
  const match = footballMatches.find(m => m.id === matchId);
  if (!match) return;

  match.status = 'finished';
  match.score = [h, a];
  localStorage.setItem('bet1x_football_matches_v2', JSON.stringify(footballMatches));

  const userBets = JSON.parse(localStorage.getItem('bet1x_football_bets_v2') || '[]');

  userBets.forEach(bet => {
    let allWon = true;
    let anyLost = false;

    bet.legs.forEach(leg => {
      if (leg.match_id === matchId && leg.result === 'pending') {
        let legWon = false;
        if (leg.bet_type === 'match_winner') {
          const actual = h > a ? 'home' : (h === a ? 'draw' : 'away');
          legWon = leg.selection === actual;
        } else if (leg.bet_type === 'over_under') {
          const isOver = (h + a) >= 3;
          legWon = leg.selection === (isOver ? 'over25' : 'under25');
        } else if (leg.bet_type === 'btts') {
          const isBtts = (h >= 1 && a >= 1);
          legWon = leg.selection === (isBtts ? 'btts_yes' : 'btts_no');
        }
        leg.result = legWon ? 'won' : 'lost';
      }

      if (leg.result === 'lost') anyLost = true;
      if (leg.result !== 'won') allWon = false;
    });

    if (bet.status === 'pending') {
      if (anyLost) {
        bet.status = 'lost';
      } else if (allWon) {
        bet.status = 'won';
        adjustWallet(bet.potential_payout, `Football Bet Payout #${bet.id}`);
      }
    }
  });

  localStorage.setItem('bet1x_football_bets_v2', JSON.stringify(userBets));

  renderMatchesList();
  renderBetHistory();
}

function renderBetHistory() {
  const container = document.getElementById('fbBetHistory');
  const bets = JSON.parse(localStorage.getItem('bet1x_football_bets_v2') || '[]');

  if (bets.length === 0) {
    container.innerHTML = '<div style="text-align:center; padding:16px; color:var(--fb-text-dim); font-size:12.5px;">No football bets placed yet.</div>';
    return;
  }

  container.innerHTML = bets.map(b => {
    let statusBadge = '<span style="background:#374151; color:#9ca3af; padding:2px 8px; border-radius:4px; font-weight:800; font-size:10px;">PENDING</span>';
    let borderStyle = 'border-left: 4px solid #374151;';

    if (b.status === 'won') {
      statusBadge = `<span style="background:rgba(16, 185, 129, 0.2); color:var(--fb-green); border:1px solid var(--fb-green); padding:2px 8px; border-radius:4px; font-weight:800; font-size:10px;">WON ₹${b.potential_payout.toFixed(2)}</span>`;
      borderStyle = 'border-left: 4px solid var(--fb-green);';
    } else if (b.status === 'lost') {
      statusBadge = '<span style="background:rgba(239, 68, 68, 0.2); color:var(--fb-red); border:1px solid var(--fb-red); padding:2px 8px; border-radius:4px; font-weight:800; font-size:10px;">LOST</span>';
      borderStyle = 'border-left: 4px solid var(--fb-red);';
    }

    const legsHtml = b.legs.map(l => `
      <div style="font-size:11.5px; color:var(--fb-text-dim); margin-top:4px;">
        • ${l.match_label} — <b>${l.label}</b> @ ${l.odds.toFixed(2)}x
      </div>
    `).join('');

    return `
      <div style="background:#0d1117; border:1px solid var(--fb-border); ${borderStyle} border-radius:8px; padding:12px; margin-bottom:8px;">
        <div style="display:flex; justify-content:space-between; align-items:center;">
          <span style="font-family:var(--font-mono); font-size:12px; font-weight:800; color:var(--fb-gold);">${b.id}</span>
          ${statusBadge}
        </div>
        ${legsHtml}
        <div style="display:flex; justify-content:space-between; margin-top:8px; font-size:11.5px; border-top:1px solid rgba(255,255,255,0.05); padding-top:6px;">
          <span>Stake: <b>₹${b.stake}</b></span>
          <span>Total Odds: <b>${b.total_odds.toFixed(2)}x</b></span>
        </div>
      </div>
    `;
  }).join('');
}
</script>
</body>
</html>