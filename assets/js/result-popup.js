/**
 * ResultPopup — a global, self-contained win/loss confirmation.
 *
 * Every game already knows the exact moment a round resolves win or lose (that is where
 * SoundFX.play('win'/'bigWin'/'lose'/'cashout'/'mineHit') already gets called). This module adds an
 * unmissable full-screen popup at those same moments, on every game, without each page building its
 * own — include this file once (after sound-fx.js) and call ResultPopup.show(...) at the point a
 * round is already known to be won or lost.
 *
 * Usage:
 *   ResultPopup.show({ outcome: 'win', amount: 123.45, big: false });
 *   ResultPopup.show({ outcome: 'lose', amount: 50 });
 *   ResultPopup.show({ outcome: 'lose' }); // amount is optional
 */
(function () {
  if (window.ResultPopup) return; // idempotent if this file is ever included twice on one page

  var dismissTimer = null;

  function ensureDom() {
    if (document.getElementById('bet1x-result-popup')) return;
    var overlay = document.createElement('div');
    overlay.id = 'bet1x-result-popup';
    overlay.className = 'bet1x-result-popup';
    overlay.innerHTML =
      '<div class="bet1x-result-card">' +
        '<div class="bet1x-result-icon"></div>' +
        '<div class="bet1x-result-title"></div>' +
        '<div class="bet1x-result-amount"></div>' +
      '</div>' +
      '<div class="bet1x-result-confetti"></div>';
    // Tapping anywhere dismisses early — a player mid-session should not be blocked waiting out
    // the auto-dismiss timer to place their next bet.
    overlay.addEventListener('click', hide);
    document.body.appendChild(overlay);
  }

  function buildConfetti(container) {
    container.innerHTML = '';
    var colors = ['#2ed47a', '#ffc53d', '#a56bff', '#ff4b6e', '#3d8bfd'];
    for (var i = 0; i < 26; i++) {
      var piece = document.createElement('span');
      piece.className = 'bet1x-confetti-piece';
      piece.style.left = (Math.random() * 100) + '%';
      piece.style.background = colors[i % colors.length];
      piece.style.animationDelay = (Math.random() * 0.3) + 's';
      piece.style.animationDuration = (1.3 + Math.random() * 0.9) + 's';
      container.appendChild(piece);
    }
  }

  function hide() {
    var overlay = document.getElementById('bet1x-result-popup');
    if (!overlay) return;
    overlay.classList.remove('active');
    if (dismissTimer) { clearTimeout(dismissTimer); dismissTimer = null; }
  }

  function formatMoney(n) {
    return '₹' + n.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  function show(opts) {
    opts = opts || {};
    var isWin = opts.outcome === 'win';
    var big = !!opts.big;
    var amount = (typeof opts.amount === 'number' && isFinite(opts.amount) && opts.amount > 0) ? opts.amount : null;

    ensureDom();
    var overlay = document.getElementById('bet1x-result-popup');
    var icon = overlay.querySelector('.bet1x-result-icon');
    var title = overlay.querySelector('.bet1x-result-title');
    var amountEl = overlay.querySelector('.bet1x-result-amount');
    var confetti = overlay.querySelector('.bet1x-result-confetti');

    overlay.classList.remove('is-win', 'is-lose', 'is-big');
    overlay.classList.add(isWin ? 'is-win' : 'is-lose');
    if (big) overlay.classList.add('is-big');

    icon.textContent = isWin ? (big ? '🏆' : '🎉') : '😔';
    title.textContent = isWin ? (big ? 'Big Win!' : 'You Won!') : 'You Lost';

    if (amount !== null) {
      amountEl.textContent = (isWin ? '+' : '-') + formatMoney(amount);
      amountEl.style.display = '';
    } else {
      amountEl.style.display = 'none';
    }

    if (isWin) {
      buildConfetti(confetti);
    } else {
      confetti.innerHTML = '';
    }

    // Force the enter animation to restart even if a popup is already mid-animation (e.g. two
    // quick rounds back to back) by dropping the class and forcing a reflow before re-adding it.
    overlay.classList.remove('active');
    void overlay.offsetWidth;
    overlay.classList.add('active');

    if (dismissTimer) clearTimeout(dismissTimer);
    dismissTimer = setTimeout(hide, isWin ? 3000 : 2200);
  }

  window.ResultPopup = { show: show, hide: hide };
})();
