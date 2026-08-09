const express = require('express');
const axios = require('axios');
require('dotenv').config();

const app = express();
const PORT = process.env.PORT || 3000;

// Enable CORS for development
app.use((req, res, next) => {
  res.setHeader('Access-Control-Allow-Origin', '*');
  res.setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type');
  if (req.method === 'OPTIONS') return res.sendStatus(200);
  next();
});

// Parse JSON request bodies
app.use(express.json());

// Serve static files from the 'public' folder
app.use(express.static('public'));

// ─── In-Memory Data Store ───────────────────────────────────────────
let cachedMatches = [];
let cachedCricketMatches = [];

// Wallets: { username: number }
const wallets = {};

// Bets: { username: [ betObject, ... ] }
const bets = {};

// Transactions: { username: [ txnObject, ... ] }
const transactions = {};

const DEFAULT_BALANCE = 1000;

function getWallet(username) {
  if (wallets[username] === undefined) wallets[username] = DEFAULT_BALANCE;
  return wallets[username];
}

function setWallet(username, amount) {
  wallets[username] = parseFloat(amount.toFixed(2));
}

// ─── Seeded Odds Generator (mirrors frontend) ───────────────────────
function generateOddsForMatch(matchId) {
  let seed = matchId || 1;
  const rand = (min, max) => {
    const x = Math.sin(seed++) * 10000;
    return min + (x - Math.floor(x)) * (max - min);
  };
  return {
    home: parseFloat(rand(1.3, 3.8).toFixed(2)),
    draw: parseFloat(rand(2.8, 3.9).toFixed(2)),
    away: parseFloat(rand(1.5, 4.2).toFixed(2)),
    over25: parseFloat(rand(1.5, 2.4).toFixed(2)),
    under25: parseFloat(rand(1.5, 2.4).toFixed(2)),
    btts_yes: parseFloat(rand(1.4, 2.1).toFixed(2)),
    btts_no: parseFloat(rand(1.6, 2.3).toFixed(2)),
  };
}

// ─── Football Data Fetcher ──────────────────────────────────────────
async function fetchFootballData() {
  try {
    const today = new Date();
    const dateFrom = new Date(today);
    dateFrom.setDate(today.getDate() - 3);
    const dateTo = new Date(today);
    dateTo.setDate(today.getDate() + 6);

    const formatDate = (d) => d.toISOString().split('T')[0];
    const comps = 'PL,PD,SA,BL1,FL1,CL,ELC,DED,PPL,BSA,CLI';
    const url = `https://api.football-data.org/v4/matches?dateFrom=${formatDate(dateFrom)}&dateTo=${formatDate(dateTo)}&competitions=${comps}`;

    const response = await axios.get(url, {
      headers: { 'X-Auth-Token': process.env.FOOTBALL_API_KEY }
    });

    cachedMatches = response.data.matches || [];
    console.log(`[${new Date().toLocaleTimeString()}] Updated match data successfully! Total matches: ${cachedMatches.length}`);

    // Auto-settle bets after every data refresh
    settleAllBets();
  } catch (error) {
    console.error('API Request Error:', error.response ? error.response.data : error.message);
  }
}

async function fetchCricketData() {
  try {
    if (!process.env.CRICKET_API_KEY) {
      console.warn("CRICKET_API_KEY is missing from environment. Skipping cricket API fetch.");
      return;
    }
    const url = `https://api.cricapi.com/v1/currentMatches?apikey=${process.env.CRICKET_API_KEY}`;
    const response = await axios.get(url);
    if (response.data && response.data.data) {
      cachedCricketMatches = response.data.data;
      console.log(`[${new Date().toLocaleTimeString()}] Updated cricket match data successfully! Total matches: ${cachedCricketMatches.length}`);
    } else {
      console.warn("CricAPI returned no matches or error status:", response.data);
    }
  } catch (error) {
    console.error('Cricket API Request Error:', error.message);
  }
}

// ─── Server-Side Bet Settlement ─────────────────────────────────────
function settleAllBets() {
  for (const username in bets) {
    const userBets = bets[username];
    if (!userBets || userBets.length === 0) continue;

    userBets.forEach(bet => {
      if (bet.status !== 'pending') return;

      let allLegsFinished = true;
      let anyLegLost = false;

      bet.legs.forEach(leg => {
        if (leg.result !== 'pending') {
          if (leg.result === 'lost') anyLegLost = true;
          if (leg.result !== 'won') allLegsFinished = false;
          return;
        }

        const liveMatch = cachedMatches.find(m => m.id === leg.match_id);
        if (!liveMatch) { allLegsFinished = false; return; }

        const status = liveMatch.status?.toUpperCase();
        if (status === 'FINISHED') {
          const h = liveMatch.score?.fullTime?.home ?? 0;
          const a = liveMatch.score?.fullTime?.away ?? 0;
          let legWon = false;

          if (leg.bet_type === 'match_winner') {
            const actual = h > a ? 'home' : (h === a ? 'draw' : 'away');
            legWon = leg.selection === actual;
          } else if (leg.bet_type === 'over_under') {
            legWon = leg.selection === ((h + a) >= 3 ? 'over25' : 'under25');
          } else if (leg.bet_type === 'btts') {
            legWon = leg.selection === ((h >= 1 && a >= 1) ? 'btts_yes' : 'btts_no');
          }

          leg.result = legWon ? 'won' : 'lost';
          leg.final_score = { home: h, away: a };
        } else {
          allLegsFinished = false;
        }
      });

      if (anyLegLost || bet.legs.some(l => l.result === 'lost')) {
        bet.status = 'lost';
        bet.settled_at = new Date().toISOString();
      } else if (allLegsFinished) {
        bet.status = 'won';
        bet.settled_at = new Date().toISOString();
        const payout = bet.potential_payout;

        // Credit wallet
        const current = getWallet(username);
        setWallet(username, current + payout);

        // Log payout transaction
        if (!transactions[username]) transactions[username] = [];
        transactions[username].unshift({
          id: 'FB_PAY_' + Math.floor(100000 + Math.random() * 900000),
          type: 'payout',
          amount: payout,
          details: `Football Bet Payout #${bet.id}`,
          timestamp: new Date().toISOString()
        });

        console.log(`[Settlement] User "${username}" won bet ${bet.id} — payout: ${payout.toFixed(2)}`);
      }
    });
  }
}

// ─── API Routes ─────────────────────────────────────────────────────

// GET /api/matches — Return cached live match data
app.get('/api/matches', (req, res) => {
  res.json({
    totalMatches: cachedMatches.length,
    matches: cachedMatches
  });
});

// GET /api/cricket/matches — Return cached live cricket match data
app.get('/api/cricket/matches', (req, res) => {
  res.json({
    totalMatches: cachedCricketMatches.length,
    matches: cachedCricketMatches
  });
});

// GET /api/wallet?user=<username> — Get wallet balance
app.get('/api/wallet', (req, res) => {
  const user = req.query.user || 'default';
  res.json({ user, balance: getWallet(user) });
});

// POST /api/wallet/adjust — Adjust wallet balance
app.post('/api/wallet/adjust', (req, res) => {
  const { user, delta, reason } = req.body;
  if (!user) return res.status(400).json({ error: 'Missing user' });
  if (typeof delta !== 'number') return res.status(400).json({ error: 'Missing or invalid delta' });

  const current = getWallet(user);
  const newBal = current + delta;
  if (newBal < 0) return res.status(400).json({ error: 'Insufficient balance' });

  setWallet(user, newBal);

  if (!transactions[user]) transactions[user] = [];
  transactions[user].unshift({
    id: 'TX_' + Math.floor(100000 + Math.random() * 900000),
    type: delta >= 0 ? 'deposit' : 'withdrawal',
    amount: Math.abs(delta),
    details: reason || 'Wallet adjustment',
    timestamp: new Date().toISOString()
  });

  res.json({ success: true, new_balance: getWallet(user) });
});

// POST /api/bets — Place a new bet
app.post('/api/bets', (req, res) => {
  const { user, bet_type, stake, legs } = req.body;

  // Validate required fields
  if (!user) return res.status(400).json({ error: 'Missing user' });
  if (!stake || stake <= 0) return res.status(400).json({ error: 'Invalid stake amount' });
  if (!legs || !Array.isArray(legs) || legs.length === 0) return res.status(400).json({ error: 'No bet legs provided' });

  // Validate each leg against actual matches and recalculate odds server-side
  const validatedLegs = [];
  for (const leg of legs) {
    const match = cachedMatches.find(m => m.id === leg.match_id);
    if (!match) return res.status(400).json({ error: `Match ${leg.match_id} not found` });

    const matchStatus = match.status?.toUpperCase();
    if (matchStatus === 'FINISHED') return res.status(400).json({ error: `Match ${leg.match_id} is already finished` });

    // Regenerate odds server-side to prevent client-side manipulation
    const serverOdds = generateOddsForMatch(match.id);
    const oddsKey = leg.selection; // e.g. 'home', 'draw', 'away', 'over25', etc.
    const actualOdds = serverOdds[oddsKey];
    if (!actualOdds) return res.status(400).json({ error: `Invalid selection "${leg.selection}" for match ${leg.match_id}` });

    validatedLegs.push({
      match_id: leg.match_id,
      match_label: `${match.homeTeam?.name || 'Home'} vs ${match.awayTeam?.name || 'Away'}`,
      competition: match.competition?.name || 'Unknown',
      bet_type: leg.bet_type,
      selection: leg.selection,
      label: leg.label || leg.selection,
      odds: actualOdds,
      result: 'pending'
    });
  }

  // Calculate total cost
  const isAccumulator = bet_type === 'accumulator';
  const totalCost = isAccumulator ? stake : stake * validatedLegs.length;

  // Check wallet balance
  const currentBal = getWallet(user);
  if (totalCost > currentBal) {
    return res.status(400).json({ error: 'Insufficient balance', balance: currentBal, required: totalCost });
  }

  // Deduct stake from wallet
  setWallet(user, currentBal - totalCost);

  // Create bet record(s)
  if (!bets[user]) bets[user] = [];
  if (!transactions[user]) transactions[user] = [];

  const placedBets = [];

  if (isAccumulator) {
    const totalOdds = validatedLegs.reduce((acc, l) => acc * l.odds, 1);
    const bet = {
      id: 'FB' + Date.now().toString(36).toUpperCase() + Math.floor(Math.random() * 1000),
      type: 'accumulator',
      timestamp: new Date().toISOString(),
      stake,
      total_odds: parseFloat(totalOdds.toFixed(2)),
      potential_payout: parseFloat((stake * totalOdds).toFixed(2)),
      status: 'pending',
      legs: validatedLegs
    };
    bets[user].unshift(bet);
    placedBets.push(bet);
  } else {
    // Single bets — one bet per leg
    validatedLegs.forEach(leg => {
      const bet = {
        id: 'FB' + Date.now().toString(36).toUpperCase() + Math.floor(Math.random() * 1000),
        type: 'single',
        timestamp: new Date().toISOString(),
        stake,
        total_odds: leg.odds,
        potential_payout: parseFloat((stake * leg.odds).toFixed(2)),
        status: 'pending',
        legs: [leg]
      };
      bets[user].unshift(bet);
      placedBets.push(bet);
    });
  }

  // Log wager transaction
  transactions[user].unshift({
    id: 'FB_BET_' + Math.floor(100000 + Math.random() * 900000),
    type: 'wager',
    amount: totalCost,
    details: isAccumulator
      ? `Football Accumulator (${validatedLegs.length} legs)`
      : `Football Single Wager${validatedLegs.length > 1 ? 's (' + validatedLegs.length + ')' : ''}`,
    timestamp: new Date().toISOString()
  });

  res.json({
    success: true,
    new_balance: getWallet(user),
    bets_placed: placedBets.length,
    bets: placedBets
  });
});

// GET /api/bets?user=<username> — Get all bets for a user
app.get('/api/bets', (req, res) => {
  const user = req.query.user || 'default';
  const userBets = bets[user] || [];
  res.json({
    user,
    total: userBets.length,
    pending: userBets.filter(b => b.status === 'pending').length,
    won: userBets.filter(b => b.status === 'won').length,
    lost: userBets.filter(b => b.status === 'lost').length,
    bets: userBets
  });
});

// GET /api/transactions?user=<username> — Get transaction history
app.get('/api/transactions', (req, res) => {
  const user = req.query.user || 'default';
  res.json({
    user,
    transactions: transactions[user] || []
  });
});

// GET /api/bets/all — Admin endpoint: get all bets across all users
app.get('/api/bets/all', (req, res) => {
  const allBets = [];
  for (const username in bets) {
    bets[username].forEach(b => allBets.push({ ...b, user: username }));
  }
  allBets.sort((a, b) => new Date(b.timestamp) - new Date(a.timestamp));
  res.json({ total: allBets.length, bets: allBets });
});

// ─── Start Server ───────────────────────────────────────────────────
fetchFootballData();
setInterval(fetchFootballData, 60000);

fetchCricketData();
setInterval(fetchCricketData, 60000);

app.listen(PORT, () => {
  console.log(`Server running on http://localhost:${PORT}`);
  console.log(`Endpoints:`);
  console.log(`  GET  /api/matches`);
  console.log(`  GET  /api/wallet?user=<name>`);
  console.log(`  POST /api/wallet/adjust  { user, delta, reason }`);
  console.log(`  POST /api/bets           { user, bet_type, stake, legs }`);
  console.log(`  GET  /api/bets?user=<name>`);
  console.log(`  GET  /api/bets/all`);
  console.log(`  GET  /api/transactions?user=<name>`);
});