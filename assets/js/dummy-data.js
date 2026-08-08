/**
 * bet1x — PITCH DEMO dummy data layer
 * ---------------------------------------------------------
 * Everything here is fake/simulated for presentation purposes.
 * No network calls, no real backend, no real money.
 * ---------------------------------------------------------
 */

const ROOMS = {
  sapre:  { label: 'Sapre',  duration: 30,  page: 'win.html',  accent: 'green'  },
  becone: { label: 'Becone', duration: 60,  page: 'win1.html', accent: 'red'    },
  emred:  { label: 'Emred',  duration: 180, page: 'win2.html', accent: 'violet' },
  vip:    { label: 'VIP',    duration: 300, page: 'win3.html', accent: 'gold'   },
};

const FAKE_NAMES = [
  'Rahul K.', 'Priya S.', 'Aman***', 'Neha R.', 'Vikram P.', 'Sneha***',
  'Rohit M.', 'Ananya***', 'Karan D.', 'Isha***', 'Suresh T.', 'Divya***',
  'Manoj***', 'Pooja V.', 'Arjun***', 'Kavya S.'
];

function randomChoice(arr) { return arr[Math.floor(Math.random() * arr.length)]; }
function randomInt(min, max) { return Math.floor(Math.random() * (max - min + 1)) + min; }
function maskName(name) { return name; }

function resolveNumber(num) {
  const size = num >= 5 ? 'Big' : 'Small';
  if (num === 0) return { color: 'Violet-Red', dotClass: 'violet', size };
  if (num === 5) return { color: 'Violet-Green', dotClass: 'violet', size };
  const greens = [1, 3, 7, 9];
  const isGreen = greens.includes(num);
  return { color: isGreen ? 'Green' : 'Red', dotClass: isGreen ? 'green' : 'red', size };
}

/** Generate N fake past results for the pulse strip / history table */
function generateFakeResults(count) {
  const out = [];
  let d = new Date();
  for (let i = 0; i < count; i++) {
    const num = randomInt(0, 9);
    const resolved = resolveNumber(num);
    d = new Date(d.getTime() - randomInt(30, 300) * 1000);
    out.push({
      roundNumber: formatRoundId(d, count - i),
      number: num,
      color: resolved.color,
      dotClass: resolved.dotClass,
      size: resolved.size,
      time: d,
    });
  }
  return out.reverse();
}

function formatRoundId(date, seq) {
  const y = date.getFullYear();
  const m = String(date.getMonth() + 1).padStart(2, '0');
  const day = String(date.getDate()).padStart(2, '0');
  return `${y}${m}${day}-${String(seq).padStart(5, '0')}`;
}

/** Fake bet history for the logged-in "demo" user across all rooms */
function generateFakeBetHistory(count) {
  const cats = ['color', 'number', 'size'];
  const colorVals = ['Green', 'Red', 'Violet'];
  const sizeVals = ['Big', 'Small'];
  const roomKeys = Object.keys(ROOMS);
  const out = [];
  for (let i = 0; i < count; i++) {
    const cat = randomChoice(cats);
    let val;
    if (cat === 'color') val = randomChoice(colorVals);
    else if (cat === 'size') val = randomChoice(sizeVals);
    else val = String(randomInt(0, 9));

    const amount = randomChoice([10, 20, 50, 100, 200, 500]);
    const won = Math.random() < 0.42;
    const room = ROOMS[randomChoice(roomKeys)];
    const num = randomInt(0, 9);
    const resolved = resolveNumber(num);

    out.push({
      room: room.label,
      roundNumber: formatRoundId(new Date(Date.now() - i * 60000), 9000 - i),
      category: cat,
      value: val,
      amount,
      status: won ? 'won' : 'lost',
      payout: won ? (amount * (cat === 'number' ? 9 : cat === 'size' ? 2 : 2)).toFixed(2) : 0,
      resultNumber: num,
      resultColor: resolved.color,
      resultSize: resolved.size,
    });
  }
  return out;
}

/** Live-looking KPI numbers for the hero/dashboard stat row */
function generateFakeKPIs() {
  return {
    activePlayers: randomInt(1800, 2600),
    betsToday: randomInt(48000, 62000),
    totalVolume: randomInt(2100000, 3400000),
    payoutRate: (randomInt(940, 980) / 10).toFixed(1),
  };
}

/** One fake "someone just bet" activity event */
function generateFakeActivityEvent() {
  const roomKeys = Object.keys(ROOMS);
  const room = ROOMS[randomChoice(roomKeys)];
  const colorVals = ['Green', 'Red', 'Violet'];
  const cat = randomChoice(['color', 'number', 'size']);
  let val, cls;
  if (cat === 'color') { val = randomChoice(colorVals); cls = val.toLowerCase(); }
  else if (cat === 'size') { val = randomChoice(['Big', 'Small']); cls = ''; }
  else { val = randomInt(0, 9); cls = ''; }

  return {
    user: randomChoice(FAKE_NAMES),
    room: room.label,
    amount: randomChoice([10, 20, 50, 100, 200, 500, 1000]),
    category: cat,
    value: val,
    cls,
  };
}

/** Fake pending-bet distribution for the admin (parity) dashboard */
function generateFakeDistribution() {
  const colors = [
    { label: 'Green',  cls: 'green',  bets: randomInt(180, 420) },
    { label: 'Red',    cls: 'red',    bets: randomInt(180, 420) },
    { label: 'Violet', cls: 'violet', bets: randomInt(60, 160) },
  ];
  const totalColorAmt = colors.map(c => ({ ...c, amount: c.bets * randomInt(35, 90) }));

  const numbers = [];
  for (let n = 0; n <= 9; n++) {
    numbers.push({ n, bets: randomInt(10, 90), amount: randomInt(500, 6000) });
  }

  const sizes = [
    { label: 'Big',   bets: randomInt(200, 500), amount: randomInt(15000, 40000) },
    { label: 'Small', bets: randomInt(200, 500), amount: randomInt(15000, 40000) },
  ];

  return { colors: totalColorAmt, numbers, sizes };
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
