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

app.use(cors());
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
    const user = await prisma.user.findFirst({
      where: { username: { equals: username, mode: 'insensitive' } }
    });
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
