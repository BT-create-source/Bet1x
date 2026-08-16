
const API = 'http://localhost:5000';
const GAME_ORDER = ['color_guess', 'aviator', 'teenpatti', 'mines', 'youreleven', 'boundary', 'football'];
const GAME_LABELS = {
  color_guess: 'Color Prediction', aviator: 'Aviator', teenpatti: 'Teen Patti', mines: 'Mines',
  youreleven: 'Your Eleven', boundary: 'Boundary Baazi', football: 'Football'
};
let knownTxIds = new Set();
let firstLoad = true;

function fmtMoney(n) {
  const v = parseFloat(n) || 0;
  const sign = v < 0 ? '−' : '';
  return sign + '₹' + Math.abs(v).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
function fmtInt(n) { return (parseInt(n) || 0).toLocaleString('en-IN'); }
function timeAgo(iso) {
  const s = Math.max(0, Math.floor((Date.now() - new Date(iso).getTime()) / 1000));
  if (s < 60) return s + 's';
  if (s < 3600) return Math.floor(s / 60) + 'm';
  if (s < 86400) return Math.floor(s / 3600) + 'h';
  return Math.floor(s / 86400) + 'd';
}
function setProfitColor(el, value) {
  el.textContent = fmtMoney(value);
  el.style.color = value > 0 ? 'var(--profit)' : (value < 0 ? 'var(--loss)' : 'var(--text)');
}

function renderKPIs(d) {
  document.getElementById('kpiTotalUsers').textContent = fmtInt(d.users.total_registered);
  document.getElementById('kpiNewToday').textContent = fmtInt(d.users.new_today);
  document.getElementById('kpiNewMonth').textContent = fmtInt(d.users.new_this_month);
  document.getElementById('kpiLiveUsers').textContent = fmtInt(d.live.total_unique);

  setProfitColor(document.getElementById('kpiProfitAll'), d.gameplay.house_profit_all_time);
  document.getElementById('kpiWagered').textContent = fmtMoney(d.gameplay.total_wagered);
  document.getElementById('kpiPaidOut').textContent = fmtMoney(d.gameplay.total_paid_out);

  setProfitColor(document.getElementById('kpiProfitToday'), d.gameplay.house_profit_today);
  const today = new Date(d.generated_at);
  document.getElementById('kpiTodayDate').textContent = today.toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' });

  setProfitColor(document.getElementById('kpiProfitMonth'), d.gameplay.house_profit_this_month);
  document.getElementById('kpiMonthLabel').textContent = today.toLocaleDateString('en-IN', { month: 'long', year: 'numeric' });
}

function renderLiveGames(d) {
  const el = document.getElementById('liveGamesList');
  const rows = GAME_ORDER.map(k => ({ key: k, label: GAME_LABELS[k], count: (d.live.per_game[k] || 0) }));
  const max = Math.max(1, ...rows.map(r => r.count));
  el.innerHTML = rows.map(r => `
    <div class="game-row">
      <div class="gname">${r.label}</div>
      <div class="bar-track"><div class="bar-fill" style="width:${(r.count / max * 100).toFixed(1)}%;"></div></div>
      <div class="gcount">${r.count}</div>
    </div>`).join('');
}

function renderBotGrid(d) {
  const el = document.getElementById('botGrid');
  const keys = ['global', ...GAME_ORDER];
  el.innerHTML = keys.map(k => {
    const cfg = d.bot_takeover[k] || { enabled: false, profit_pct: 0 };
    const label = k === 'global' ? 'Global (master)' : GAME_LABELS[k];
    return `
      <div class="bot-card ${cfg.enabled ? 'on' : ''}">
        <div class="bname">${label}</div>
        <div class="bstate"><span class="bdot"></span>${cfg.enabled ? 'ACTIVE' : 'STANDBY'}</div>
        <div class="bpct">${cfg.enabled ? cfg.profit_pct + '% target' : '—'}</div>
      </div>`;
  }).join('');
}

function renderPerGameTable(d) {
  const body = document.getElementById('perGameBody');
  const rows = GAME_ORDER.map(k => d.gameplay.per_game[k]).filter(Boolean);
  rows.sort((a, b) => b.profit - a.profit);
  body.innerHTML = rows.map(r => `
    <tr>
      <td class="gname-cell">${r.label}</td>
      <td class="num">${fmtInt(r.bet_count)}</td>
      <td class="num">${fmtInt(r.win_count)}</td>
      <td class="num">${fmtMoney(r.wagered)}</td>
      <td class="num">${fmtMoney(r.paid_out)}</td>
      <td class="num ${r.profit > 0 ? 'profit-pos' : (r.profit < 0 ? 'profit-neg' : '')}">${fmtMoney(r.profit)}</td>
    </tr>`).join('');
}

function renderWinnersLosers(d) {
  document.getElementById('wlLosingCount').textContent = fmtInt(d.players.net_losing_count);
  document.getElementById('wlWinningCount').textContent = fmtInt(d.players.net_winning_count);
  document.getElementById('wlEvenCount').textContent = fmtInt(d.players.break_even_count);

  const losers = document.getElementById('topLosersList');
  losers.innerHTML = d.players.top_losers.length ? d.players.top_losers.map(u => `
    <div class="wl-row"><span class="wname">${u.username}</span><span class="wnet" style="color:var(--loss);">+${fmtMoney(u.net)}</span></div>
  `).join('') : '<div class="wl-empty">No losing players yet.</div>';

  const winners = document.getElementById('topWinnersList');
  winners.innerHTML = d.players.top_winners.length ? d.players.top_winners.map(u => `
    <div class="wl-row"><span class="wname">${u.username}</span><span class="wnet" style="color:var(--profit);">${fmtMoney(u.net)}</span></div>
  `).join('') : '<div class="wl-empty">No winning players yet.</div>';
}

function renderFeed(d) {
  const panel = document.getElementById('feedPanel');
  const items = d.recent_transactions.slice(0, 40);
  panel.innerHTML = items.map(t => {
    const isNew = !firstLoad && t.id && !knownTxIds.has(t.id);
    const houseTag = t.is_house ? '<span class="house-tag">HOUSE</span>' : '';
    return `
      <div class="feed-row ${isNew ? 'flash' : ''}">
        <span class="feed-kind-dot ${t.kind}"></span>
        <span class="feed-game">${t.game}</span>
        <span class="feed-user">${t.user}${houseTag}</span>
        <span class="feed-amt" style="color:${t.kind === 'win' ? 'var(--profit)' : 'var(--text-dim)'};">${t.kind === 'win' ? '+' : '−'}${fmtMoney(t.amount)}</span>
        <span class="feed-time">${timeAgo(t.timestamp)}</span>
      </div>`;
  }).join('') || '<div class="wl-empty">No gameplay activity yet.</div>';
  knownTxIds = new Set(items.map(t => t.id).filter(Boolean));
  firstLoad = false;
}

function drawProfitChart(d) {
  const canvas = document.getElementById('profitChart');
  const dpr = window.devicePixelRatio || 1;
  const cssW = canvas.clientWidth || 860;
  const cssH = 220;
  canvas.width = cssW * dpr;
  canvas.height = cssH * dpr;
  canvas.style.height = cssH + 'px';
  const ctx = canvas.getContext('2d');
  ctx.scale(dpr, dpr);
  ctx.clearRect(0, 0, cssW, cssH);

  const data = d.gameplay.daily_trend || [];
  if (data.length === 0) {
    ctx.fillStyle = '#545c74';
    ctx.font = '13px "IBM Plex Sans", sans-serif';
    ctx.fillText('No profit history yet — chart fills in as gameplay happens.', 8, cssH / 2);
    return;
  }

  const padL = 8, padR = 8, padT = 10, padB = 24;
  const plotW = cssW - padL - padR;
  const plotH = cssH - padT - padB;
  const maxAbs = Math.max(1, ...data.map(p => Math.abs(p.profit)));
  const zeroY = padT + plotH / 2;
  const scale = (plotH / 2) / maxAbs;

  // zero baseline
  ctx.strokeStyle = '#232a3c';
  ctx.lineWidth = 1;
  ctx.beginPath();
  ctx.moveTo(padL, zeroY);
  ctx.lineTo(cssW - padR, zeroY);
  ctx.stroke();

  const bw = plotW / data.length;
  data.forEach((p, i) => {
    const x = padL + i * bw + bw * 0.18;
    const w = bw * 0.64;
    const h = Math.abs(p.profit) * scale;
    const y = p.profit >= 0 ? zeroY - h : zeroY;
    const grad = ctx.createLinearGradient(0, y, 0, y + h);
    if (p.profit >= 0) {
      grad.addColorStop(0, '#34d399'); grad.addColorStop(1, 'rgba(52,211,153,0.35)');
    } else {
      grad.addColorStop(0, 'rgba(251,113,133,0.35)'); grad.addColorStop(1, '#fb7185');
    }
    ctx.fillStyle = grad;
    ctx.beginPath();
    const r = 3;
    ctx.moveTo(x, y + (h > r ? r : 0));
    ctx.arcTo(x, y, x + r, y, r);
    ctx.lineTo(x + w - r, y);
    ctx.arcTo(x + w, y, x + w, y + r, r);
    ctx.lineTo(x + w, y + h);
    ctx.lineTo(x, y + h);
    ctx.closePath();
    ctx.fill();

    ctx.fillStyle = '#545c74';
    ctx.font = '9.5px "IBM Plex Mono", monospace';
    ctx.textAlign = 'center';
    const label = p.date.slice(5).replace('-', '/');
    ctx.fillText(label, x + w / 2, cssH - 8);
  });
  ctx.textAlign = 'left';
}

async function loadDashboard() {
  try {
    const res = await fetch(API + '/api/admin/super-dashboard');
    if (!res.ok) throw new Error('HTTP ' + res.status);
    const d = await res.json();

    renderKPIs(d);
    renderLiveGames(d);
    renderBotGrid(d);
    renderPerGameTable(d);
    renderWinnersLosers(d);
    renderFeed(d);
    drawProfitChart(d);
    window._lastDashData = d;

    document.getElementById('lastUpdated').textContent = 'live · updated ' + new Date(d.generated_at).toLocaleTimeString('en-IN', { hour12: false });
  } catch (err) {
    document.getElementById('lastUpdated').textContent = 'connection lost — retrying…';
    console.warn('Dashboard fetch failed:', err);
  }
}

function checkGateAndInit() {
  const loggedIn = localStorage.getItem('bet1x_admin_logged_in') === 'true';
  if (!loggedIn) return;
  document.getElementById('gate').style.display = 'none';
  document.getElementById('dashboard').style.display = 'block';
  loadDashboard();
  setInterval(loadDashboard, 6000);
  window.addEventListener('resize', () => { if (window._lastDashData) drawProfitChart(window._lastDashData); });
}

checkGateAndInit();
