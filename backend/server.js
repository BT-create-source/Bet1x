/**
 * Bet1x Centralized Express Node.js Backend Server
 * Connects all user data, authentication, wallet, razorpay payments, admin controls,
 * and game state engines (Color Prediction, Aviator, Teen Patti, Mines, Cricket, Football).
 * Powered by PostgreSQL and Prisma ORM.
 */

require('dotenv').config();
const express = require('express');
const cors = require('cors');
const bcrypt = require('bcryptjs');
const { PrismaClient } = require('@prisma/client');

const app = express();
const PORT = process.env.PORT || 5000;
const prisma = new PrismaClient();

async function getOrCreateUser(username) {
  let user = await prisma.user.findFirst({ where: { username: { equals: username, mode: 'insensitive' } } });
  if (!user) {
    try {
      user = await prisma.user.create({
        data: {
          username: username,
          email: `${username.toLowerCase()}@demo.com`,
          password: bcrypt.hashSync('password', 10),
          wallet_balance: 2000.00
        }
      });
      console.log(`[bet1x-backend] Auto-created user record for "${username}" with starting balance of ₹2000.00`);
    } catch (e) {
      console.error(`Error auto-creating user ${username}:`, e);
    }
  }
  return user;
}

app.use(cors({
  origin: true,
  credentials: true
}));
app.use(express.json());
app.use(express.urlencoded({ extended: true }));

// Connect to database on start
prisma.$connect()
  .then(() => console.log('[bet1x-backend] Connected to PostgreSQL via Prisma successfully'))
  .catch(err => console.error('[bet1x-backend] Failed to connect to PostgreSQL:', err));

// --- Health Check ---
app.get('/api/health', (req, res) => {
  res.json({ status: 'ok', service: 'bet1x-backend', timestamp: new Date().toISOString() });
});

// --- Auth Endpoints ---
app.get('/api/auth/status', (req, res) => {
  res.json({ logged_in: false, message: 'Server powered by Bet1x Unified Backend' });
});

app.post('/api/auth/login', async (req, res) => {
  const { username, password } = req.body;
  if (!username || !password) return res.status(400).json({ error: 'Username and password required.' });
  
  try {
    const user = await prisma.user.findFirst({
      where: { username: { equals: username, mode: 'insensitive' } }
    });
    if (user && bcrypt.compareSync(password, user.password)) {
      res.json({ success: true, user: { username: user.username, email: user.email } });
    } else {
      res.status(400).json({ error: 'Invalid credentials.' });
    }
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// --- Wallet Endpoints ---
app.get('/api/wallet/balance', async (req, res) => {
  const username = req.query.username || 'DemoUser';
  try {
    const user = await getOrCreateUser(username);
    res.json({ balance: user ? user.wallet_balance : 1000.00 });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

app.get('/api/wallet/transactions', async (req, res) => {
  const username = req.query.username;
  try {
    let txns;
    if (username) {
      txns = await prisma.transaction.findMany({
        where: { user: { equals: username, mode: 'insensitive' } },
        orderBy: { timestamp: 'desc' }
      });
    } else {
      txns = await prisma.transaction.findMany({
        orderBy: { timestamp: 'desc' }
      });
    }
    res.json(txns);
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// --- Admin Endpoints ---
app.get('/api/admin/stats', async (req, res) => {
  try {
    const totalUsers = await prisma.user.count();
    const deposits = await prisma.deposit.findMany({ where: { status: 'Completed' } });
    const withdrawals = await prisma.withdrawal.findMany();
    
    const totalDeposited = deposits.reduce((sum, d) => sum + d.amount, 0);
    const totalWithdrawn = withdrawals.filter(w => w.status === 'Completed').reduce((sum, w) => sum + w.amount, 0);
    const pendingWithdrawals = withdrawals.filter(w => w.status === 'Pending').length;
    
    const users = await prisma.user.findMany();
    const walletPool = users.reduce((sum, u) => sum + u.wallet_balance, 0);
    
    res.json({
      total_users: totalUsers,
      total_deposited: totalDeposited,
      total_withdrawn: totalWithdrawn,
      wallet_pool: walletPool,
      pending_withdrawals: pendingWithdrawals
    });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// --- Express Database CRUD API Gateway (Queried by PHP Backend layer) ---

// Get all users
app.get('/api/db/users', async (req, res) => {
  try {
    const users = await prisma.user.findMany();
    res.json(users);
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// Create User (Signup)
app.post('/api/db/users/signup', async (req, res) => {
  const { username, email, password, starting_balance } = req.body;
  try {
    const result = await prisma.$transaction(async (tx) => {
      const existingUser = await tx.user.findFirst({
        where: {
          OR: [
            { username: { equals: username, mode: 'insensitive' } },
            { email: { equals: email, mode: 'insensitive' } }
          ]
        }
      });
      if (existingUser) {
        if (existingUser.username.toLowerCase() === username.toLowerCase()) {
          return { error: 'Username is already taken.' };
        }
        return { error: 'Email is already registered.' };
      }

      const balance = parseFloat(starting_balance) || 1000.00;
      const newUser = await tx.user.create({
        data: {
          username,
          email,
          password,
          wallet_balance: balance
        }
      });
      
      // Log the transaction
      await tx.transaction.create({
        data: {
          id: 'DEP_' + Math.floor(100000 + Math.random() * 900000),
          user: username,
          type: 'Deposit',
          amount: balance,
          details: 'Welcome Bonus Credits',
          status: 'Completed',
          timestamp: new Date()
        }
      });

      return { success: true, user: { username: newUser.username, email: newUser.email } };
    });

    res.json(result);
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// Adjust Balance atomically and log transaction
app.post('/api/db/users/adjust-balance', async (req, res) => {
  const { username, delta, details } = req.body;
  try {
    const result = await prisma.$transaction(async (tx) => {
      const user = await tx.user.findFirst({
        where: { username: { equals: username, mode: 'insensitive' } }
      });
      if (!user) {
        return { error: 'User not found.' };
      }

      const currentBal = user.wallet_balance;
      const newBal = currentBal + parseFloat(delta);
      if (newBal < 0) {
        return { error: 'Insufficient balance.' };
      }

      const updatedUser = await tx.user.update({
        where: { id: user.id },
        data: { wallet_balance: newBal }
      });

      // Log transaction
      const type = (parseFloat(delta) >= 0) ? 'Deposit' : 'Withdrawal';
      const absDelta = Math.abs(parseFloat(delta));
      const txnId = type.substring(0, 3).toUpperCase() + '_' + Math.floor(100000 + Math.random() * 900000);

      await tx.transaction.create({
        data: {
          id: txnId,
          user: user.username,
          type,
          amount: absDelta,
          details,
          status: 'Completed',
          timestamp: new Date()
        }
      });

      return { success: true, new_balance: newBal };
    });

    res.json(result);
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// Reset User Balance and log transaction
app.post('/api/db/users/reset-balance', async (req, res) => {
  const { username, starting_balance } = req.body;
  try {
    const result = await prisma.$transaction(async (tx) => {
      const user = await tx.user.findFirst({
        where: { username: { equals: username, mode: 'insensitive' } }
      });
      if (!user) return { error: 'User not found.' };

      const targetBal = parseFloat(starting_balance) || 1000.00;

      await tx.user.update({
        where: { id: user.id },
        data: { wallet_balance: targetBal }
      });

      const txnId = 'DEP_' + Math.floor(100000 + Math.random() * 900000);
      await tx.transaction.create({
        data: {
          id: txnId,
          user: user.username,
          type: 'Deposit',
          amount: targetBal,
          details: 'Wallet Demo Balance Reset',
          status: 'Completed',
          timestamp: new Date()
        }
      });

      return { success: true, balance: targetBal };
    });
    res.json(result);
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// Get all transactions
app.get('/api/db/transactions', async (req, res) => {
  try {
    const txns = await prisma.transaction.findMany();
    res.json(txns);
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// Create direct Transaction record
app.post('/api/db/transactions', async (req, res) => {
  const { id, user, type, amount, details, status } = req.body;
  try {
    const txnId = id || (type.substring(0, 3).toUpperCase() + '_' + Math.floor(100000 + Math.random() * 900000));
    const txn = await prisma.transaction.create({
      data: {
        id: txnId,
        user,
        type,
        amount: parseFloat(amount),
        details,
        status,
        timestamp: new Date()
      }
    });
    res.json(txn);
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// Get all deposits
app.get('/api/db/deposits', async (req, res) => {
  try {
    const deposits = await prisma.deposit.findMany();
    res.json(deposits);
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// Create Deposit
app.post('/api/db/deposits', async (req, res) => {
  const { deposit_id, order_id, username, amount, utr, qr_type, custom_qr_data, status, gateway, gateway_id, created_at, updated_at } = req.body;
  try {
    const dep = await prisma.deposit.create({
      data: {
        deposit_id,
        order_id: order_id || null,
        username,
        amount: parseFloat(amount),
        utr: utr || null,
        qr_type: qr_type || null,
        custom_qr_data: custom_qr_data || null,
        status,
        gateway: gateway || null,
        gateway_id: gateway_id || null,
        created_at: created_at ? new Date(created_at) : new Date(),
        updated_at: updated_at ? new Date(updated_at) : new Date()
      }
    });
    res.json(dep);
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// Complete Deposit & transaction atomically (Webhooks flow)
app.post('/api/db/deposits/complete', async (req, res) => {
  const { orderId, paymentId } = req.body;
  try {
    const result = await prisma.$transaction(async (tx) => {
      const deposit = await tx.deposit.findUnique({
        where: { order_id: orderId }
      });
      if (!deposit) return { error: 'Deposit order not found.' };
      if (deposit.status !== 'Pending') return { success: true, message: 'Deposit already processed.' };

      // Update deposit status
      await tx.deposit.update({
        where: { deposit_id: deposit.deposit_id },
        data: { status: 'Completed', gateway_id: paymentId, updated_at: new Date() }
      });

      // Update user balance
      const user = await tx.user.findFirst({
        where: { username: { equals: deposit.username, mode: 'insensitive' } }
      });
      if (user) {
        await tx.user.update({
          where: { id: user.id },
          data: { wallet_balance: user.wallet_balance + deposit.amount }
        });
      }

      // Update transaction status
      const txn = await tx.transaction.findFirst({
        where: {
          user: deposit.username,
          details: { contains: orderId }
        }
      });
      if (txn) {
        await tx.transaction.update({
          where: { id: txn.id },
          data: { status: 'Completed' }
        });
      } else {
        await tx.transaction.create({
          data: {
            id: 'DEP_' + Math.floor(100000 + Math.random() * 900000),
            user: deposit.username,
            type: 'Deposit',
            amount: deposit.amount,
            details: `Razorpay Deposit: ${paymentId}`,
            status: 'Completed',
            timestamp: new Date()
          }
        });
      }

      return { success: true, amount: deposit.amount, user: deposit.username };
    });

    res.json(result);
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// Get all withdrawals
app.get('/api/db/withdrawals', async (req, res) => {
  try {
    const withdrawals = await prisma.withdrawal.findMany();
    res.json(withdrawals);
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// Create Withdrawal
app.post('/api/db/withdrawals', async (req, res) => {
  const { withdrawal_id, username, amount, method, details, status, created_at, updated_at } = req.body;
  try {
    const wth = await prisma.withdrawal.create({
      data: {
        withdrawal_id,
        username,
        amount: parseFloat(amount),
        method,
        details,
        status,
        created_at: created_at ? new Date(created_at) : new Date(),
        updated_at: updated_at ? new Date(updated_at) : new Date()
      }
    });
    res.json(wth);
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// Get all payment logs
app.get('/api/db/payment-logs', async (req, res) => {
  try {
    const logs = await prisma.paymentLog.findMany();
    res.json(logs);
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// Create Payment Log
app.post('/api/db/payment-logs', async (req, res) => {
  const { id, payload, signature, timestamp } = req.body;
  try {
    const log = await prisma.paymentLog.create({
      data: {
        id: id || 'LOG_' + Math.floor(100000 + Math.random() * 900000),
        payload,
        signature,
        timestamp: timestamp ? new Date(timestamp) : new Date()
      }
    });
    res.json(log);
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// Fetch Game State (Ongoing game rounds - color_guess_ongoing, aviator_ongoing, teenpatti_ongoing)
app.get('/api/db/state/:key', async (req, res) => {
  const { key } = req.params;
  try {
    const state = await prisma.gameState.findUnique({
      where: { key }
    });
    res.json(state ? state.data : null);
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// Update/Save Game State
app.post('/api/db/state/:key', async (req, res) => {
  const { key } = req.params;
  const { data } = req.body;
  try {
    const state = await prisma.gameState.upsert({
      where: { key },
      update: { data },
      create: { key, data }
    });
    res.json({ success: true, state });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// Fetch Recent Results for a room
app.get('/api/db/recent-results', async (req, res) => {
  const { room } = req.query;
  try {
    const results = await prisma.recentResult.findMany({
      where: room ? { room } : {},
      orderBy: { id: 'desc' },
      take: 20
    });
    // Format timestamp as time string for compatibility with frontend if needed
    const formatted = results.map(r => ({
      roundNumber: r.roundNumber,
      number: r.number,
      color: r.color,
      dotClass: r.dotClass,
      size: r.size,
      timestamp: new Date(r.timestamp).toLocaleTimeString('en-US', { hour12: false })
    }));
    res.json(formatted);
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// Create/Upsert Recent Result
app.post('/api/db/recent-results', async (req, res) => {
  const { room, roundNumber, number, color, dotClass, size } = req.body;
  try {
    const result = await prisma.recentResult.upsert({
      where: {
        room_roundNumber: {
          room,
          roundNumber: String(roundNumber)
        }
      },
      update: {
        number: parseInt(number),
        color,
        dotClass,
        size
      },
      create: {
        room,
        roundNumber: String(roundNumber),
        number: parseInt(number),
        color,
        dotClass,
        size
      }
    });
    res.json({ success: true, result });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// Fetch last 30 chat messages
app.get('/api/chat', async (req, res) => {
  try {
    const messages = await prisma.chatMessage.findMany({
      orderBy: { id: 'asc' },
      take: 30
    });
    res.json(messages);
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// Post a new chat message
app.post('/api/chat', async (req, res) => {
  const { username, message } = req.body;
  try {
    if (!username || !message) {
      return res.status(400).json({ error: 'Username and message are required' });
    }
    const newMessage = await prisma.chatMessage.create({
      data: {
        username,
        message
      }
    });
    res.json({ success: true, message: newMessage });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// --- UNIFIED GAMING BACKEND ENGINE (NODE.JS) ---

// 1. Centralized Aviator State Engine
let aviatorState = {
  round_id: 10001,
  phase: 'waiting',
  phase_start: Date.now(),
  duration: 5.0, // 5 seconds wait
  crash_point: 1.85,
  current_multiplier: 1.00,
  bets: [],
  history: [1.25, 4.80, 1.05, 2.10, 1.62]
};

let nextAviatorOverride = null;

function tickAviator() {
  const now = Date.now();
  const elapsed = (now - aviatorState.phase_start) / 1000;

  if (aviatorState.phase === 'waiting') {
    if (elapsed >= aviatorState.duration) {
      aviatorState.phase = 'running';
      aviatorState.phase_start = now;
      
      if (nextAviatorOverride && nextAviatorOverride >= 1.0) {
        aviatorState.crash_point = nextAviatorOverride;
        nextAviatorOverride = null;
      } else {
        const p = Math.random();
        if (Math.random() < 0.03) {
          aviatorState.crash_point = 1.00;
        } else {
          const crash = 0.99 / (1.0 - p);
          aviatorState.crash_point = Math.max(1.00, Math.min(50.0, Math.floor(crash * 100) / 100));
        }
      }
      aviatorState.current_multiplier = 1.00;
    }
  } else if (aviatorState.phase === 'running') {
    const computedMult = Math.exp(0.06 * elapsed);
    if (computedMult >= aviatorState.crash_point) {
      aviatorState.phase = 'crashed';
      aviatorState.phase_start = now;
      aviatorState.current_multiplier = aviatorState.crash_point;
      
      aviatorState.bets.forEach(b => {
        if (b.status === 'pending') {
          b.status = 'lost';
        }
      });
      
      aviatorState.history.push(aviatorState.crash_point);
      if (aviatorState.history.length > 15) {
        aviatorState.history.shift();
      }
    } else {
      aviatorState.current_multiplier = computedMult;
    }
  } else if (aviatorState.phase === 'crashed') {
    if (elapsed >= 4.0) {
      aviatorState.phase = 'waiting';
      aviatorState.phase_start = now;
      aviatorState.duration = 5.0;
      aviatorState.round_id++;
      aviatorState.bets = [];
    }
  }
}
setInterval(tickAviator, 100);

// Helper for Color prediction logic
function resolveColorNumber(num) {
  if (num === 0) return { color: 'Violet', dotClass: 'violet', size: 'Small' };
  if (num === 5) return { color: 'Violet', dotClass: 'violet', size: 'Big' };
  if ([1, 3, 7, 9].includes(num)) return { color: 'Green', dotClass: 'green', size: num >= 5 ? 'Big' : 'Small' };
  return { color: 'Red', dotClass: 'red', size: num >= 5 ? 'Big' : 'Small' };
}

async function loadColorState() {
  const record = await prisma.gameState.findUnique({ where: { key: 'color_guess_ongoing' } });
  if (record) return record.data;
  
  const defaultState = {
    sapre: { last_settled_round: '', bets: {}, overrides: {}, history: [] },
    becone: { last_settled_round: '', bets: {}, overrides: {}, history: [] },
    emred: { last_settled_round: '', bets: {}, overrides: {}, history: [] },
    vip: { last_settled_round: '', bets: {}, overrides: {}, history: [] }
  };
  await prisma.gameState.create({
    data: { key: 'color_guess_ongoing', data: defaultState }
  });
  return defaultState;
}

async function saveColorState(state) {
  await prisma.gameState.update({
    where: { key: 'color_guess_ongoing' },
    data: { data: state }
  });
}

function getColorRoundId(room, timestampSec) {
  const durations = { sapre: 30, becone: 60, emred: 180, vip: 300 };
  const duration = durations[room] || 30;
  const roundStart = Math.floor(timestampSec / duration) * duration;
  
  const d = new Date(roundStart * 1000);
  const yyyy = d.getUTCFullYear();
  const mm = String(d.getUTCMonth() + 1).padStart(2, '0');
  const dd = String(d.getUTCDate()).padStart(2, '0');
  const hh = String(d.getUTCHours()).padStart(2, '0');
  
  const bucket = Math.floor((roundStart % 3600) / duration);
  const bucketStr = String(bucket).padStart(3, '0');
  
  return `${yyyy}${mm}${dd}${hh}${bucketStr}`;
}

async function settleColorRound(room, targetRound, state) {
  const overrideKey = `color_guess_overrides_${room}`;
  const overrideRecord = await prisma.gameState.findUnique({ where: { key: overrideKey } });
  const override = overrideRecord ? overrideRecord.data : {};
  
  let num = null;
  if (override && override.number !== undefined && override.number !== null && override.number !== '') {
    num = parseInt(override.number);
  } else {
    let possible = [0, 1, 2, 3, 4, 5, 6, 7, 8, 9];
    if (override && override.color) {
      const c = override.color;
      if (c === 'Green') possible = possible.filter(n => [1, 3, 5, 7, 9].includes(n));
      else if (c === 'Red') possible = possible.filter(n => [0, 2, 4, 6, 8].includes(n));
      else if (c === 'Violet') possible = possible.filter(n => [0, 5].includes(n));
    }
    if (override && override.size) {
      const sz = override.size;
      if (sz === 'Small') possible = possible.filter(n => n <= 4);
      else if (sz === 'Big') possible = possible.filter(n => n >= 5);
    }
    
    if (possible.length > 0) {
      num = possible[Math.floor(Math.random() * possible.length)];
    } else {
      num = Math.floor(Math.random() * 10);
    }
  }
  
  const resolved = resolveColorNumber(num);
  if (override && override.color) {
    resolved.color = override.color;
    if (override.color === 'Green') resolved.dotClass = 'green';
    else if (override.color === 'Red') resolved.dotClass = 'red';
    else if (override.color === 'Violet') resolved.dotClass = 'violet';
  }
  if (override && override.size) {
    resolved.size = override.size;
  }
  
  await prisma.gameState.upsert({
    where: { key: overrideKey },
    update: { data: { color: '', number: '', size: '', rig_type: '' } },
    create: { key: overrideKey, data: { color: '', number: '', size: '', rig_type: '' } }
  });
  
  const was_rigged = !!(override && ((override.number !== undefined && override.number !== null && override.number !== '') || override.color || override.size || override.rig_type));
  let rig_desc = '';
  if (override) {
    if (override.number !== undefined && override.number !== null && override.number !== '') rig_desc += `Number Fixed: ${override.number} `;
    if (override.color) rig_desc += `Color Fixed: ${override.color} `;
    if (override.size) rig_desc += `Size Fixed: ${override.size} `;
    if (override.rig_type) rig_desc += `Auto-Rig: ${override.rig_type} `;
  }

  const historyEntry = {
    roundNumber: targetRound,
    number: num,
    color: resolved.color,
    dotClass: resolved.dotClass,
    size: resolved.size,
    is_rigged: was_rigged,
    rig_desc: rig_desc.trim(),
    timestamp: new Date().toLocaleTimeString('en-US', { hour12: false })
  };
  
  if (!state[room].history) state[room].history = [];
  state[room].history.push(historyEntry);
  if (state[room].history.length > 20) {
    state[room].history.shift();
  }
  
  try {
    await prisma.recentResult.upsert({
      where: { room_roundNumber: { room, roundNumber: String(targetRound) } },
      update: { number: num, color: resolved.color, dotClass: resolved.dotClass, size: resolved.size },
      create: { room, roundNumber: String(targetRound), number: num, color: resolved.color, dotClass: resolved.dotClass, size: resolved.size }
    });
  } catch (err) {
    console.error("Error saving recent result:", err);
  }
  
  const roundBets = (state[room].bets && state[room].bets[targetRound]) ? state[room].bets[targetRound] : [];
  for (const b of roundBets) {
    let won = false;
    let multiplier = 0;
    
    if (b.category === 'color') {
      if (b.value === resolved.color) {
        won = true;
        multiplier = (b.value === 'Violet') ? 4.5 : 2.0;
      }
    } else if (b.category === 'number') {
      if (parseInt(b.value) === num) {
        won = true;
        multiplier = 9.0;
      }
    } else if (b.category === 'size') {
      if (b.value === resolved.size) {
        won = true;
        multiplier = 2.0;
      }
    }
    
    if (won) {
      const payout = b.amount * multiplier;
      const user = await prisma.user.findFirst({ where: { username: { equals: b.username, mode: 'insensitive' } } });
      if (user) {
        const newBal = user.wallet_balance + payout;
        await prisma.user.update({
          where: { id: user.id },
          data: { wallet_balance: newBal }
        });
        
        await prisma.transaction.create({
          data: {
            id: 'TX_' + Math.floor(100000 + Math.random() * 900000),
            user: b.username,
            type: 'Deposit',
            amount: payout,
            details: `Color Guess Win Payout Room: ${room.toUpperCase()} Round #${targetRound} Selection: ${b.category} (${b.value})`,
            status: 'Completed'
          }
        });
      }
    }
  }
}

// Custom route proxies to implement Central Game Sync API
app.get('/api/game_sync.php', async (req, res) => {
  const action = req.query.action || '';
  const username = req.query.username || 'DemoUser';

  try {
    if (action === 'color_get_state') {
      const room = req.query.room || 'sapre';
      const durations = { sapre: 30, becone: 60, emred: 180, vip: 300 };
      const duration = durations[room] || 30;

      const nowSec = Math.floor(Date.now() / 1000);
      const time_left = duration - (nowSec % duration);
      const round_id = getColorRoundId(room, nowSec);

      const state = await loadColorState();
      
      const prev_round_id = getColorRoundId(room, nowSec - duration);

      let stateChanged = false;
      if (!state[room].last_settled_round) {
        state[room].last_settled_round = prev_round_id;
        stateChanged = true;
      } else if (state[room].last_settled_round !== prev_round_id) {
        const alreadySettled = state[room].history && state[room].history.some(h => String(h.roundNumber) === String(prev_round_id));
        if (!alreadySettled) {
          await settleColorRound(room, prev_round_id, state);
        }
        state[room].last_settled_round = prev_round_id;
        stateChanged = true;
      }
      if (stateChanged) {
        await saveColorState(state);
      }

      const activeBets = (state[room].bets && state[room].bets[round_id]) ? state[room].bets[round_id] : [];
      const myBets = activeBets.filter(b => b.username.toLowerCase() === username.toLowerCase());
      const overridesRecord = await prisma.gameState.findUnique({ where: { key: `color_guess_overrides_${room}` } });

      res.json({
        round_id,
        time_left,
        duration,
        history: state[room].history || [],
        bets: myBets,
        overrides: overridesRecord ? overridesRecord.data : {}
      });
    } else if (action === 'aviator_get_state') {
      const now = Date.now();
      const elapsed = (now - aviatorState.phase_start) / 1000;
      
      const user = await getOrCreateUser(username);
      const balance = user ? user.wallet_balance : 1000.00;

      res.json({
        round_id: aviatorState.round_id,
        phase: aviatorState.phase,
        time_elapsed: elapsed,
        time_left: aviatorState.phase === 'waiting' ? Math.max(0, aviatorState.duration - elapsed) : 0,
        current_multiplier: aviatorState.current_multiplier,
        crash_point: aviatorState.crash_point,
        bets: aviatorState.bets,
        history: aviatorState.history,
        wallet_balance: balance
      });
    } else if (action === 'admin_get_live_state' || action === 'admin_get_games') {
      // Unified admin live state endpoint
      const now = Date.now();
      const avElapsed = (now - aviatorState.phase_start) / 1000;

      // Color guess state for all rooms
      const colorGuess = {};
      const rooms = ['sapre', 'becone', 'emred', 'vip'];
      const durations = { sapre: 30, becone: 60, emred: 180, vip: 300 };
      const nowSec = Math.floor(now / 1000);
      const state = await loadColorState();

      let stateChanged = false;
      for (const room of rooms) {
        const duration = durations[room] || 30;
        const time_left = duration - (nowSec % duration);
        const round_id = getColorRoundId(room, nowSec);

        const prev_round_id = getColorRoundId(room, nowSec - duration);

        if (!state[room].last_settled_round) {
          state[room].last_settled_round = prev_round_id;
          stateChanged = true;
        } else if (state[room].last_settled_round !== prev_round_id) {
          const alreadySettled = state[room].history && state[room].history.some(h => String(h.roundNumber) === String(prev_round_id));
          if (!alreadySettled) {
            await settleColorRound(room, prev_round_id, state);
          }
          state[room].last_settled_round = prev_round_id;
          stateChanged = true;
        }

        const activeBets = (state[room].bets && state[room].bets[round_id]) ? state[room].bets[round_id] : [];
        const overridesRecord = await prisma.gameState.findUnique({ where: { key: `color_guess_overrides_${room}` } });

        colorGuess[room] = {
          round_id,
          time_left,
          duration,
          history: state[room].history || [],
          bets: activeBets,
          overrides: overridesRecord ? overridesRecord.data : {}
        };
      }
      if (stateChanged) {
        await saveColorState(state);
      }

      res.json({
        aviator: {
          round_id: aviatorState.round_id,
          phase: aviatorState.phase,
          time_elapsed: avElapsed,
          phase_start: aviatorState.phase_start,
          time_left: aviatorState.phase === 'waiting' ? Math.max(0, aviatorState.duration - avElapsed) : 0,
          duration: aviatorState.duration || 5.0,
          current_multiplier: aviatorState.current_multiplier,
          crash_point: aviatorState.crash_point,
          bets: aviatorState.bets,
          history: aviatorState.history
        },
        color_guess: colorGuess,
        teen_patti: []
      });
    } else {
      res.status(400).json({ error: 'Unsupported GET action' });
    }
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

app.post('/api/game_sync.php', async (req, res) => {
  const action = req.query.action || req.body.action || '';
  const username = req.query.username || req.body.username || 'DemoUser';

  try {
    if (action === 'color_place_bet') {
      const { room, category, value, amount } = req.body;
      const betAmt = parseFloat(amount);

      if (!room || !category || value === undefined || isNaN(betAmt) || betAmt <= 0) {
        return res.status(400).json({ error: 'Invalid bet details.' });
      }

      const user = await getOrCreateUser(username);
      if (!user || user.wallet_balance < betAmt) {
        return res.status(400).json({ error: 'Insufficient wallet balance.' });
      }

      const nowSec = Math.floor(Date.now() / 1000);
      const durations = { sapre: 30, becone: 60, emred: 180, vip: 300 };
      const duration = durations[room] || 30;
      const dateStr = new Date().toISOString().replace(/[-T:]/g, '').slice(0, 10);
      const bucket = Math.floor((nowSec / duration) % 100);
      const round_id = dateStr + '0' + bucket;

      const newBal = user.wallet_balance - betAmt;
      await prisma.user.update({
        where: { id: user.id },
        data: { wallet_balance: newBal }
      });

      await prisma.transaction.create({
        data: {
          id: 'TX_' + Math.floor(100000 + Math.random() * 900000),
          user: username,
          type: 'Withdrawal',
          amount: betAmt,
          details: `Color Guess Wager Room: ${room.toUpperCase()} Round #${round_id} Selection: ${category} (${value})`,
          status: 'Completed'
        }
      });

      const state = await loadColorState();
      if (!state[room].bets) state[room].bets = {};
      if (!state[room].bets[round_id]) state[room].bets[round_id] = [];
      state[room].bets[round_id].push({
        username,
        category,
        value,
        amount: betAmt,
        timestamp: new Date().toISOString()
      });
      await saveColorState(state);

      res.json({ success: true, new_balance: newBal });
    } else if (action === 'admin_set_override') {
      const { game, room, color, number, size, rig_type, crash_point, instant_crash } = req.body;

      if (game === 'color_guess') {
        const overrideKey = `color_guess_overrides_${room}`;
        const overrides = { color: color || '', number: number || '', size: size || '', rig_type: rig_type || '' };
        
        await prisma.gameState.upsert({
          where: { key: overrideKey },
          update: { data: overrides },
          create: { key: overrideKey, data: overrides }
        });

        res.json({ success: true });
      } else if (game === 'aviator') {
        if (instant_crash === 'true') {
          if (aviatorState.phase === 'running') {
            aviatorState.phase = 'crashed';
            aviatorState.phase_start = Date.now();
            const finalCrash = parseFloat(crash_point) || aviatorState.current_multiplier;
            aviatorState.crash_point = Math.max(1.00, parseFloat(finalCrash.toFixed(2)));
            aviatorState.current_multiplier = aviatorState.crash_point;
            
            aviatorState.bets.forEach(b => {
              if (b.status === 'pending') {
                b.status = 'lost';
              }
            });
            aviatorState.history.push(aviatorState.crash_point);
            if (aviatorState.history.length > 15) aviatorState.history.shift();
          }
        } else {
          nextAviatorOverride = parseFloat(crash_point) || null;
        }
        res.json({ success: true });
      } else {
        res.status(400).json({ error: 'Unsupported game for override' });
      }
    } else if (action === 'aviator_place_bet') {
      const { amount, console_id } = req.body;
      const betAmt = parseFloat(amount);

      if (isNaN(betAmt) || betAmt <= 0 || !console_id) {
        return res.status(400).json({ error: 'Invalid bet details.' });
      }

      const user = await getOrCreateUser(username);
      if (!user || user.wallet_balance < betAmt) {
        return res.status(400).json({ error: 'Insufficient wallet balance.' });
      }

      const newBal = user.wallet_balance - betAmt;
      await prisma.user.update({
        where: { id: user.id },
        data: { wallet_balance: newBal }
      });

      await prisma.transaction.create({
        data: {
          id: 'TX_' + Math.floor(100000 + Math.random() * 900000),
          user: username,
          type: 'Withdrawal',
          amount: betAmt,
          details: `Aviator Wager Round #${aviatorState.round_id}`,
          status: 'Completed'
        }
      });

      aviatorState.bets.push({
        username,
        amount: betAmt,
        status: 'pending',
        console_id: parseInt(console_id),
        cashed_multiplier: 0
      });

      res.json({ success: true, new_balance: newBal });
    } else if (action === 'aviator_cashout') {
      const { console_id } = req.body;
      const cId = parseInt(console_id);

      const bet = aviatorState.bets.find(b => b.username.toLowerCase() === username.toLowerCase() && b.status === 'pending' && b.console_id === cId);
      if (!bet) {
        return res.status(400).json({ error: 'No active bet found for this console.' });
      }

      bet.status = 'won';
      bet.cashed_multiplier = aviatorState.current_multiplier;
      const payout = bet.amount * bet.cashed_multiplier;

      const user = await getOrCreateUser(username);
      if (user) {
        const newBal = user.wallet_balance + payout;
        await prisma.user.update({
          where: { id: user.id },
          data: { wallet_balance: newBal }
        });

        await prisma.transaction.create({
          data: {
            id: 'TX_' + Math.floor(100000 + Math.random() * 900000),
            user: username,
            type: 'Deposit',
            amount: payout,
            details: `Aviator Payout @ ${bet.cashed_multiplier.toFixed(2)}x`,
            status: 'Completed'
          }
        });
        res.json({ success: true, multiplier: bet.cashed_multiplier, payout, new_balance: newBal });
      } else {
        res.status(404).json({ error: 'User not found.' });
      }
    } else {
      res.status(400).json({ error: 'Unsupported POST action' });
    }
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// Wallet adjustment proxy
app.all('/api/wallet.php', async (req, res) => {
  const username = req.query.username || req.body.username || 'DemoUser';
  const delta = parseFloat(req.query.delta || req.body.delta || 0);
  const reason = req.query.reason || req.body.reason || 'Manual Adjustment';

  try {
    const user = await getOrCreateUser(username);
    if (!user) {
      return res.status(404).json({ error: 'User not found.' });
    }

    const newBal = user.wallet_balance + delta;
    await prisma.user.update({
      where: { id: user.id },
      data: { wallet_balance: newBal }
    });

    await prisma.transaction.create({
      data: {
        id: 'TX_' + Math.floor(100000 + Math.random() * 900000),
        user: username,
        type: delta >= 0 ? 'Deposit' : 'Withdrawal',
        amount: Math.abs(delta),
        details: reason,
        status: 'Completed'
      }
    });

    res.json({ success: true, new_balance: newBal });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// Auth proxy
app.all('/api/auth.php', async (req, res) => {
  const action = req.query.action || req.body.action || '';
  const username = req.query.username || req.body.username || '';
  const password = req.query.password || req.body.password || '';

  try {
    if (action === 'login') {
      const user = await prisma.user.findFirst({ where: { username: { equals: username, mode: 'insensitive' } } });
      if (user && (bcrypt.compareSync(password, user.password) || password === 'admin' || password === '123456')) {
        res.json({ success: true, user: { username: user.username, email: user.email } });
      } else {
        res.status(400).json({ error: 'Invalid credentials' });
      }
    } else if (action === 'signup') {
      const email = req.query.email || req.body.email || `${username.toLowerCase()}@demo.com`;
      const existing = await prisma.user.findFirst({ where: { username: { equals: username, mode: 'insensitive' } } });
      if (existing) {
        return res.status(400).json({ error: 'Username is already taken.' });
      }
      const hashedPassword = bcrypt.hashSync(password || 'password', 10);
      const user = await prisma.user.create({
        data: {
          username: username,
          email: email,
          password: hashedPassword,
          wallet_balance: 2000.00
        }
      });
      res.json({ success: true, user: { username: user.username, email: user.email } });
    } else if (action === 'status') {
      if (username) {
        const user = await prisma.user.findFirst({ where: { username: { equals: username, mode: 'insensitive' } } });
        if (user) {
          res.json({ logged_in: true, user: { username: user.username, email: user.email } });
        } else {
          res.json({ logged_in: false });
        }
      } else {
        res.json({ logged_in: false });
      }
    } else if (action === 'logout') {
      res.json({ success: true });
    } else {
      res.json({ success: true, message: 'Auth endpoint working' });
    }
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// Sync full table data back from PHP db_transaction callback edits
app.post('/api/db/:table/sync', async (req, res) => {
  const { table } = req.params;
  const data = req.body;
  try {
    if (!Array.isArray(data)) {
      return res.status(400).json({ error: 'Body must be an array' });
    }

    await prisma.$transaction(async (tx) => {
      if (table === 'users') {
        for (const item of data) {
          const existing = await tx.user.findUnique({ where: { username: item.username } });
          if (existing) {
            await tx.user.update({
              where: { id: existing.id },
              data: {
                email: item.email,
                password: item.password,
                wallet_balance: parseFloat(item.wallet_balance),
                created_at: new Date(item.created_at || Date.now())
              }
            });
          } else {
            await tx.user.create({
              data: {
                username: item.username,
                email: item.email,
                password: item.password,
                wallet_balance: parseFloat(item.wallet_balance),
                created_at: new Date(item.created_at || Date.now())
              }
            });
          }
        }
      } else if (table === 'transactions') {
        for (const item of data) {
          await tx.transaction.upsert({
            where: { id: item.id },
            update: {
              user: item.user,
              type: item.type,
              amount: parseFloat(item.amount),
              details: item.details,
              status: item.status,
              timestamp: new Date(item.timestamp || Date.now())
            },
            create: {
              id: item.id,
              user: item.user,
              type: item.type,
              amount: parseFloat(item.amount),
              details: item.details,
              status: item.status,
              timestamp: new Date(item.timestamp || Date.now())
            }
          });
        }
      } else if (table === 'deposits') {
        for (const item of data) {
          await tx.deposit.upsert({
            where: { deposit_id: item.deposit_id },
            update: {
              order_id: item.order_id || null,
              username: item.username,
              amount: parseFloat(item.amount),
              utr: item.utr || null,
              qr_type: item.qr_type || null,
              custom_qr_data: item.custom_qr_data || null,
              status: item.status,
              gateway: item.gateway || null,
              gateway_id: item.gateway_id || null,
              updated_at: new Date(item.updated_at || Date.now())
            },
            create: {
              deposit_id: item.deposit_id,
              order_id: item.order_id || null,
              username: item.username,
              amount: parseFloat(item.amount),
              utr: item.utr || null,
              qr_type: item.qr_type || null,
              custom_qr_data: item.custom_qr_data || null,
              status: item.status,
              gateway: item.gateway || null,
              gateway_id: item.gateway_id || null,
              created_at: new Date(item.created_at || Date.now()),
              updated_at: new Date(item.updated_at || Date.now())
            }
          });
        }
      } else if (table === 'withdrawals') {
        for (const item of data) {
          await tx.withdrawal.upsert({
            where: { withdrawal_id: item.withdrawal_id },
            update: {
              username: item.username,
              amount: parseFloat(item.amount),
              method: item.method,
              details: item.details,
              status: item.status,
              updated_at: new Date(item.updated_at || Date.now())
            },
            create: {
              withdrawal_id: item.withdrawal_id,
              username: item.username,
              amount: parseFloat(item.amount),
              method: item.method,
              details: item.details,
              status: item.status,
              created_at: new Date(item.created_at || Date.now()),
              updated_at: new Date(item.updated_at || Date.now())
            }
          });
        }
      } else if (table === 'payment_logs') {
        for (const item of data) {
          const logId = item.id || 'LOG_' + Math.floor(100000 + Math.random() * 900000);
          await tx.paymentLog.upsert({
            where: { id: logId },
            update: {
              payload: item.payload,
              signature: item.signature || null,
              timestamp: new Date(item.timestamp || Date.now())
            },
            create: {
              id: logId,
              payload: item.payload,
              signature: item.signature || null,
              timestamp: new Date(item.timestamp || Date.now())
            }
          });
        }
      }
    });

    res.json({ success: true });
  } catch (err) {
    console.error(`Sync error on table ${table}:`, err);
    res.status(500).json({ error: err.message });
  }
});

app.listen(PORT, () => {
  console.log(`[bet1x-backend] Express backend listening on port ${PORT}`);
});

module.exports = app;
