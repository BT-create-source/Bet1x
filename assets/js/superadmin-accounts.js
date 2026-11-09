/* Player deletion is restricted again on the server; this panel has no independent credentials. */
(() => {
  const form = document.getElementById('account-search-form');
  const results = document.getElementById('account-results');
  const status = document.getElementById('account-status');
  async function call(path, options) {
    const response = await adminFetch(path, options);
    const data = await response.json();
    if (!response.ok) throw new Error(data.error || 'Request failed.');
    return data;
  }
  form.addEventListener('submit', async event => {
    event.preventDefault();
    results.replaceChildren(); status.textContent = 'Searching…';
    try {
      const data = await call('/api/admin/accounts/search?q=' + encodeURIComponent(document.getElementById('account-query').value.trim()));
      status.textContent = data.users.length ? data.users.length + ' matches (maximum 50). Select the exact account.' : 'No matching accounts.';
      for (const user of data.users) {
        const row = document.createElement('div');
        row.style.cssText = 'padding:14px 0;border-bottom:1px solid var(--border);display:flex;gap:12px;align-items:center;flex-wrap:wrap';
        const info = document.createElement('span');
        info.style.cssText = 'flex:1;overflow-wrap:anywhere';
        info.textContent = '#' + user.id + ' · ' + user.username + ' · ' + user.email + ' · ' + (user.phone || 'No phone') + ' · Phone ' + (Number(user.phone_verified) === 1 ? 'verified' : 'unverified') + ' · Wallet ' + Number(user.wallet_balance).toFixed(2);
        const button = document.createElement('button');
        button.type = 'button'; button.textContent = 'Delete permanently'; button.style.color = 'var(--loss)';
        button.addEventListener('click', async () => {
          const confirmation = prompt('Permanently delete #' + user.id + ' (' + user.email + ', ' + (user.phone || 'no phone') + ') and all its wallet/history records?\nType DELETE ' + user.username + ' to confirm.');
          if (confirmation !== 'DELETE ' + user.username) return;
          button.disabled = true; status.textContent = 'Deleting selected account…';
          try {
            await call('/api/admin/accounts/delete', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({id:user.id, confirmation})});
            row.remove(); status.textContent = 'Account deleted. Its email and phone number can be used for a new signup.';
          } catch (error) { status.textContent = error.message; button.disabled = false; }
        });
        row.append(info, button); results.append(row);
      }
    } catch (error) { status.textContent = error.message; }
  });
})();
