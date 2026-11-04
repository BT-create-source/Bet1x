/* Operator controls for the PHP Originals catalogue. Shared auth fetch adds the admin token. */
(function () {
  'use strict';
  const panel = document.getElementById('admin-originals');
  if (!panel) return;
  const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const fields = [['rtp','Return to player (%)'],['min_bet','Minimum bet (₹)'],['max_bet','Maximum bet (₹)'],['max_win','Maximum return per round (₹)']];
  function fill(card,g) {
    card.querySelector('[name="enabled"]').checked=g.enabled;
    fields.forEach(([k]) => { card.querySelector('[name="'+k+'"]').value=k==='rtp' ? +(g[k]*100).toFixed(4) : g[k]; });
  }
  async function load() {
    panel.textContent='Loading Originals settings…';
    try {
      const r=await fetch('api/admin/originals/config'), d=await r.json();
      if (!r.ok || !d.ok) throw new Error(d.error || 'Cannot load settings.');
      panel.innerHTML='<h3>Originals</h3><p style="color:var(--text-dim);font-size:13px">Settings apply to new rounds. Open Blackjack and Coinflip rounds retain their original terms. Return limits can reduce the effective RTP. Baccarat return varies by bet; Blackjack return depends on play.</p><div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,300px),1fr));gap:16px">'+d.games.map((g) => '<form data-game="'+g.key+'" class="card" style="padding:16px;border:1px solid var(--border);border-radius:12px;background:var(--surface)"><h4>'+esc(g.label)+' <a href="'+g.key+'.html" target="_blank" rel="noopener" style="color:var(--gold);font-size:12px">Open ↗</a></h4><label style="display:block;margin:12px 0"><input type="checkbox" name="enabled"> Open for play</label>'+fields.map(([k,l]) => '<label style="display:block;margin:10px 0;font-size:12px">'+l+'<input class="cashier-input" style="margin-top:4px" type="number" name="'+k+'" step="'+(k==='rtp' ? '0.01' : '0.01')+'" min="'+(k==='rtp'?'80':'0.01')+'" '+(k==='rtp' ? (g.rtp_fixed?'disabled':'max="99"') : '')+' required></label>').join('')+(g.rtp_fixed?'<p style="font-size:12px;color:var(--text-dim)">Return fixed by game rules'+(g.key==='blackjack'?' (estimate with good play)':'')+'.</p>':'')+'<button class="btn btn-gold btn-block" type="submit">Save '+esc(g.label)+'</button><div role="status" data-message style="margin-top:8px;font-size:12px"></div></form>').join('')+'</div>';
      d.games.forEach((g) => {
        const card=panel.querySelector('[data-game="'+g.key+'"]'); fill(card,g);
        card.onsubmit=async (e) => {
          e.preventDefault();
          const button=card.querySelector('button'), msg=card.querySelector('[data-message]');
          button.disabled=true; msg.textContent='Saving…';
          const body={game:g.key,enabled:card.elements.enabled.checked};
          fields.forEach(([k]) => { if(k!=='rtp'||!g.rtp_fixed) body[k]=Number(card.elements[k].value)/(k==='rtp'?100:1); });
          try { const r=await fetch('api/admin/originals/config',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)}); const d=await r.json(); if(!r.ok||!d.ok) throw new Error(d.error||'Save failed.'); fill(card,d.config); msg.textContent='Saved.'; }
          catch(e) { msg.textContent=e.message; }
          finally { button.disabled=false; }
        };
      });
    } catch(e) { panel.textContent=e.message; }
  }
  document.addEventListener('click',(e) => { const b=e.target.closest('.admin-tab-btn'); if(b && /'originals'/.test(b.getAttribute('onclick')||'')) load(); });
  document.addEventListener('DOMContentLoaded',() => { if(localStorage.getItem('admin_active_tab')==='originals') load(); });
})();
