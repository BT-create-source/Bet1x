(function () {
  'use strict';
  const $ = id => document.getElementById(id);
  const labels = { dragon: 'Dragon', tiger: 'Tiger', tie: 'Tie' };
  const pays = { dragon: 2, tiger: 2, tie: 12 };
  let bets = {}, undo = [], road = [];
  const total = () => Object.values(bets).reduce((a, b) => a + b, 0);
  const rank = c => c.slice(0, -1);

  function spots() {
    $('dtSpots').innerHTML = Object.keys(labels).map(key =>
      '<button type="button" class="dt-spot ' + key + '" data-side="' + key + '" aria-label="Bet on ' + labels[key] + '">' +
      '<strong>' + labels[key].toUpperCase() + '</strong><small>' + pays[key] + 'x return</small>' +
      (bets[key] ? '<span class="og-stack">' + Orig.money(bets[key]) + '</span>' : '') + '</button>'
    ).join('');
    // Keep Dragon / Tie / Tiger in table order.
    $('dtSpots').appendChild($('dtSpots').querySelector('[data-side="tiger"]'));
    $('dtSpots').querySelectorAll('[data-side]').forEach(button => {
      button.onclick = () => {
        if (Orig.state.busy) return;
        const value = Orig.chip(), key = button.dataset.side;
        if (Orig.state.config && total() + value > Orig.state.config.max_bet) {
          Orig.msg('Maximum total bet is ' + Orig.money(Orig.state.config.max_bet) + '.', true); return;
        }
        bets[key] = (bets[key] || 0) + value;
        undo.push([key, value]); Orig.sound('chipStack'); spots();
      };
    });
    Orig.setTotal(total());
  }

  function drawRoad() {
    $('dtRoad').innerHTML = road.slice(-40).map(w => '<span class="' + w + '" title="' + labels[w] + '">' +
      (w === 'tie' ? '=' : w === 'dragon' ? 'D' : 'T') + '</span>').join('');
  }

  Orig.mount({
    game: 'dragontiger', title: 'Dragon Tiger', sub: 'Two cards. Highest wins.', playLabel: 'Deal cards', chipMode: true, rtpLabel: ' main bets',
    stage: '<div class="dt-table"><div class="dt-heading">DRAGON TIGER</div>' +
      '<div class="dt-hands"><div class="dt-hand dt-dragon" id="dtDragon"><h2>DRAGON</h2><div class="dt-card" id="dtDC"></div></div>' +
      '<div class="dt-vs">VS</div><div class="dt-hand dt-tiger" id="dtTiger"><h2>TIGER</h2><div class="dt-card" id="dtTC"></div></div></div>' +
      '<div class="dt-result" id="dtResult" role="status">Choose your side and place your chips</div>' +
      '<div class="dt-spots" id="dtSpots"></div><div class="dt-note">A is low · K is high · Suits do not break a tie</div>' +
      '<div class="dt-road" id="dtRoad" aria-label="Your recent results"></div></div>',
    howto: 'Place chips on <b>Dragon</b>, <b>Tiger</b> or <b>Tie</b>, then confirm during the shared betting countdown. ' +
      'One card is drawn independently for each side; the higher rank wins. Aces are lowest, kings highest, and suits have no effect. ' +
      'Total returns include your stake: Dragon and Tiger <b>2x</b>, Tie <b>12x</b>. On a tie, Dragon and Tiger bets return <b>half</b> their stake. ' +
      'RTP before rounding and return limits: Dragon/Tiger <b>96.15%</b>, Tie <b>92.31%</b>. Cards are drawn with replacement, so identical cards can appear. ' +
      'Chips on the table are a selection; your wallet is charged only when you confirm. Result dots show your own recent deals.',
    collect: () => ({ bets: Object.keys(bets).map(type => ({ type, amount: bets[type] })) }),
    onClear: () => { bets = {}; undo = []; spots(); },
    onUndo: () => {
      const last = undo.pop(); if (!last) return;
      bets[last[0]] -= last[1]; if (bets[last[0]] <= 0) delete bets[last[0]]; spots();
    },
    resultText: round => '<b>' + labels[round.result.winner] + '</b> <span style="color:var(--text-dim)">' +
      Orig.esc(round.result.dragon) + ' / ' + Orig.esc(round.result.tiger) + '</span>',
    verifyFloats: 2,
    verify: (floats, round) => {
      const card = f => { const i = Math.floor(f * 52); return ['A','2','3','4','5','6','7','8','9','10','J','Q','K'][Math.floor(i / 4)] + 'SHDC'[i % 4]; };
      const dragon = card(floats[0]), tiger = card(floats[1]);
      return 'Recomputed cards: Dragon ' + dragon + ', Tiger ' + tiger + '. ' +
        (dragon === round.result.dragon && tiger === round.result.tiger ? 'Both cards match.' : 'Card verification failed.');
    },
    onMount: () => { spots(); $('dtDC').innerHTML = Orig.cardHTML('??'); $('dtTC').innerHTML = Orig.cardHTML('??'); },
    onLoad: data => {
      road = (data.recent || []).slice().reverse().map(round => round.result.winner); drawRoad();
      const latest = (data.recent || [])[0];
      if (latest) {
        $('dtDC').innerHTML = Orig.cardHTML(latest.result.dragon);
        $('dtTC').innerHTML = Orig.cardHTML(latest.result.tiger);
        $('dtResult').textContent = 'Last deal: ' + labels[latest.result.winner];
      } else {
        $('dtDC').innerHTML = Orig.cardHTML('??'); $('dtTC').innerHTML = Orig.cardHTML('??');
        $('dtResult').textContent = 'Choose your side and place your chips';
      }
    },
    onResult: async round => {
      const r = round.result;
      ['dtDragon', 'dtTiger'].forEach(id => $(id).classList.remove('won'));
      document.querySelectorAll('.dt-spot').forEach(el => el.classList.remove('win'));
      $('dtDC').innerHTML = Orig.cardHTML('??'); $('dtTC').innerHTML = Orig.cardHTML('??');
      $('dtResult').textContent = 'Dealing cards...';
      await Orig.sleep(250); $('dtDC').innerHTML = Orig.cardHTML(r.dragon); Orig.sound('cardDeal');
      await Orig.sleep(450); $('dtTC').innerHTML = Orig.cardHTML(r.tiger); Orig.sound('cardDeal');
      if (r.winner !== 'tie') $(r.winner === 'dragon' ? 'dtDragon' : 'dtTiger').classList.add('won');
      $('dtSpots').querySelector('[data-side="' + r.winner + '"]').classList.add('win');
      $('dtResult').textContent = r.winner === 'tie' ? 'Tie — half of Dragon / Tiger stakes returned' :
        labels[r.winner] + ' wins · ' + rank(r.dragon) + ' vs ' + rank(r.tiger);
      road.push(r.winner); drawRoad();
    },
  });
})();
