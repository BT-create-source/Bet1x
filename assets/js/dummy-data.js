/**
 * bet1x — PITCH DEMO dummy data layer (DISABLED / ZEROED)
 * ---------------------------------------------------------
 * Simulated data generators have been disabled. 
 * Real backend database values are used across the app.
 * ---------------------------------------------------------
 */

const ROOMS = {
  sapre:  { label: 'Sapre',  duration: 30,  page: 'win.html',  accent: 'green'  },
  becone: { label: 'Becone', duration: 60,  page: 'win1.html', accent: 'red'    },
  emred:  { label: 'Emred',  duration: 180, page: 'win2.html', accent: 'violet' },
  vip:    { label: 'VIP',    duration: 300, page: 'win3.html', accent: 'gold'   },
};

const FAKE_NAMES = [];

function randomChoice(arr) { return ''; }
function randomInt(min, max) { return 0; }
function maskName(name) { return name; }

function resolveNumber(num) {
  return { color: '', dotClass: '', size: '' };
}

function generateFakeResults(count) {
  return [];
}

function formatRoundId(date, seq) {
  return '';
}

function generateFakeBetHistory(count) {
  return [];
}

function generateFakeKPIs() {
  return {
    activePlayers: 0,
    betsToday: 0,
    totalVolume: 0,
    payoutRate: '0.0',
  };
}

function generateFakeActivityEvent() {
  return {
    user: '',
    room: '',
    amount: 0,
    category: '',
    value: '',
    cls: ''
  };
}

function generateFakeDistribution() {
  return {
    colors: [
      { label: 'Green',  cls: 'green',  bets: 0, amount: 0 },
      { label: 'Red',    cls: 'red',    bets: 0, amount: 0 },
      { label: 'Violet', cls: 'violet', bets: 0, amount: 0 },
    ],
    numbers: Array.from({ length: 10 }, (_, i) => ({ n: i, bets: 0, amount: 0 })),
    sizes: [
      { label: 'Big',   bets: 0, amount: 0 },
      { label: 'Small', bets: 0, amount: 0 },
    ]
  };
}

const playerPool = [
  // India (IND)
  { id: 1,  name: 'Rishabh Pant',     team: 'India', role: 'WK',   credits: 9.0 },
  { id: 2,  name: 'KL Rahul',         team: 'India', role: 'WK',   credits: 8.5 },
  { id: 3,  name: 'Rohit Sharma',     team: 'India', role: 'BAT',  credits: 10.0 },
  { id: 4,  name: 'Virat Kohli',      team: 'India', role: 'BAT',  credits: 10.5 },
  { id: 5,  name: 'Yashasvi Jaiswal', team: 'India', role: 'BAT',  credits: 9.5 },
  { id: 6,  name: 'Ravindra Jadeja',  team: 'India', role: 'AR',   credits: 9.5 },
  { id: 7,  name: 'Hardik Pandya',    team: 'India', role: 'AR',   credits: 9.0 },
  { id: 8,  name: 'Jasprit Bumrah',   team: 'India', role: 'BOWL', credits: 10.0 },
  { id: 9,  name: 'Mohammed Siraj',   team: 'India', role: 'BOWL', credits: 8.5 },
  { id: 10, name: 'Kuldeep Yadav',    team: 'India', role: 'BOWL', credits: 8.0 },
  { id: 11, name: 'Arshdeep Singh',   team: 'India', role: 'BOWL', credits: 8.0 },

  // Australia (AUS)
  { id: 12, name: 'Josh Inglis',      team: 'Australia', role: 'WK',   credits: 8.5 },
  { id: 13, name: 'Alex Carey',       team: 'Australia', role: 'WK',   credits: 8.0 },
  { id: 14, name: 'Travis Head',      team: 'Australia', role: 'BAT',  credits: 10.0 },
  { id: 15, name: 'Steve Smith',      team: 'Australia', role: 'BAT',  credits: 9.5 },
  { id: 16, name: 'Mitchell Marsh',   team: 'Australia', role: 'BAT',  credits: 9.0 },
  { id: 17, name: 'Glenn Maxwell',    team: 'Australia', role: 'AR',   credits: 9.5 },
  { id: 18, name: 'Marcus Stoinis',   team: 'Australia', role: 'AR',   credits: 9.0 },
  { id: 19, name: 'Pat Cummins',      team: 'Australia', role: 'BOWL', credits: 9.5 },
  { id: 20, name: 'Mitchell Starc',   team: 'Australia', role: 'BOWL', credits: 9.5 },
  { id: 21, name: 'Josh Hazlewood',   team: 'Australia', role: 'BOWL', credits: 9.0 },
  { id: 22, name: 'Adam Zampa',       team: 'Australia', role: 'BOWL', credits: 8.5 }
];
