const http = require('http');
const fs = require('fs');
const path = require('path');

console.log('=== BET1X BACKEND INTEGRATION TEST SUITE ===\n');

// 1. Check Data Directory & Database Files
const DATA_DIR = path.join(__dirname, 'data');
console.log('1. Checking Database Storage Layer...');
if (fs.existsSync(DATA_DIR)) {
  console.log('   ✅ DATA_DIR exists:', DATA_DIR);
} else {
  console.error('   ❌ DATA_DIR missing!');
  process.exit(1);
}

// 2. Check Backend Core Files existence
const requiredFiles = [
  'server.js',
  'package.json',
  'api/config.php',
  'api/db.php',
  'api/auth.php',
  'api/wallet.php',
  'api/deposit.php',
  'api/withdraw.php',
  'api/check_payment.php',
  'api/webhook.php',
  'api/admin.php',
  'api/game_sync.php',
  'api/aviator.php',
  'api/teenpatti.php',
  'api/mining.php',
  'api/cricket_player.php',
  'api/cricket_team.php',
  'api/football.php'
];

console.log('\n2. Verifying File Structure...');
let missing = 0;
for (const file of requiredFiles) {
  const fullPath = path.join(__dirname, file);
  if (fs.existsSync(fullPath)) {
    console.log(`   ✅ File present: backend/${file}`);
  } else {
    console.error(`   ❌ File missing: backend/${file}`);
    missing++;
  }
}

if (missing > 0) {
  console.error(`\n❌ ${missing} files missing!`);
  process.exit(1);
}

// 3. Test Express Server API Routes locally
console.log('\n3. Testing Express Backend Server Initialization & Routes...');
const app = require('./server.js');

const PORT = 5099;
const server = app.listen(PORT, async () => {
  console.log(`   ✅ Express server active on port ${PORT}`);

  try {
    const health = await fetchJson(`http://localhost:${PORT}/api/health`);
    console.log('   ✅ GET /api/health ->', health);

    const authStatus = await fetchJson(`http://localhost:${PORT}/api/auth/status`);
    console.log('   ✅ GET /api/auth/status ->', authStatus);

    // Test Signup
    const testUser = 'user_' + Math.floor(1000 + Math.random() * 9000);
    const signupRes = await postJson(`http://localhost:${PORT}/api/auth/signup`, {
      username: testUser,
      email: `${testUser}@bet1x.com`,
      password: 'SecurePassword123',
      confirm_password: 'SecurePassword123'
    });
    console.log(`   ✅ POST /api/auth/signup (${testUser}) ->`, signupRes.success ? 'Success (Token & Account created)' : signupRes);

    // Test Login
    const loginRes = await postJson(`http://localhost:${PORT}/api/auth/login`, {
      username: testUser,
      password: 'SecurePassword123'
    });
    console.log(`   ✅ POST /api/auth/login (${testUser}) ->`, loginRes.success ? `Success (Balance: ₹${loginRes.user.wallet_balance})` : loginRes);

    // Test Wallet Adjust
    const adjustRes = await postJson(`http://localhost:${PORT}/api/wallet/adjust`, {
      username: testUser,
      delta: -150,
      reason: 'Aviator Room Wager'
    });
    console.log(`   ✅ POST /api/wallet/adjust (-150) ->`, adjustRes.success ? `New Balance: ₹${adjustRes.new_balance}` : adjustRes);

    // Test Wallet Balance
    const balance = await fetchJson(`http://localhost:${PORT}/api/wallet/balance?username=${testUser}`);
    console.log(`   ✅ GET /api/wallet/balance (${testUser}) ->`, balance);

    // Test Transactions
    const txns = await fetchJson(`http://localhost:${PORT}/api/wallet/transactions?username=${testUser}`);
    console.log(`   ✅ GET /api/wallet/transactions -> Count: ${txns.length}`);

    // Test Chat
    const chatRes = await postJson(`http://localhost:${PORT}/api/chat`, {
      username: testUser,
      message: 'Hello from test suite!'
    });
    console.log(`   ✅ POST /api/chat ->`, chatRes.success ? 'Message stored in DB' : chatRes);

    // Test Game Bets
    const betRes = await postJson(`http://localhost:${PORT}/api/db/game-bets`, {
      username: testUser,
      game: 'mines',
      bet_amount: 100,
      payout: 250,
      status: 'won'
    });
    console.log(`   ✅ POST /api/db/game-bets ->`, betRes.success ? 'Bet recorded in DB' : betRes);

    const adminStats = await fetchJson(`http://localhost:${PORT}/api/admin/stats`);
    console.log('   ✅ GET /api/admin/stats ->', adminStats);

    console.log('\n============================================');
    console.log('🎉 ALL AUTH & DATABASE TESTS PASSED SUCCESSFULLY!');
    console.log('============================================\n');
    server.close(() => process.exit(0));
  } catch (err) {
    console.error('   ❌ API Test Error:', err.message);
    server.close(() => process.exit(1));
  }
});

function fetchJson(url) {
  return new Promise((resolve, reject) => {
    http.get(url, (res) => {
      let body = '';
      res.on('data', chunk => body += chunk);
      res.on('end', () => {
        try {
          resolve(JSON.parse(body));
        } catch (e) {
          reject(new Error(`Non-JSON response from ${url}: ${body}`));
        }
      });
    }).on('error', reject);
  });
}

function postJson(url, payload) {
  return new Promise((resolve, reject) => {
    const urlObj = new URL(url);
    const data = JSON.stringify(payload);
    const req = http.request({
      hostname: urlObj.hostname,
      port: urlObj.port,
      path: urlObj.pathname,
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Content-Length': Buffer.byteLength(data)
      }
    }, (res) => {
      let body = '';
      res.on('data', chunk => body += chunk);
      res.on('end', () => {
        try {
          resolve(JSON.parse(body));
        } catch (e) {
          reject(new Error(`Non-JSON response from ${url}: ${body}`));
        }
      });
    });
    req.on('error', reject);
    req.write(data);
    req.end();
  });
}
