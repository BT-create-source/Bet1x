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

    const balance = await fetchJson(`http://localhost:${PORT}/api/wallet/balance?username=DemoUser`);
    console.log('   ✅ GET /api/wallet/balance ->', balance);

    const adminStats = await fetchJson(`http://localhost:${PORT}/api/admin/stats`);
    console.log('   ✅ GET /api/admin/stats ->', adminStats);

    console.log('\n============================================');
    console.log('🎉 ALL BACKEND CHECKS PASSED SUCCESSFULLY!');
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
