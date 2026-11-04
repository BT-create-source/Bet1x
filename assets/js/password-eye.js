/**
 * Show / hide password: every password box on the page gets a small eye button inside it that
 * toggles between hidden and visible.
 *
 * One self-contained file (its own styles, no dependencies), loaded on every page: the player
 * sign-in / sign-up / forgot-password / settings forms (ui-common.js), the Admin and Super Admin
 * sign-ins and the sub-admin console. It starts itself, and an observer catches password boxes
 * that are rendered later (pop-up sign-in forms, settings panels). A visible password is hidden
 * again whenever its form is submitted, so it never stays on screen.
 */
(function () {
  'use strict';
  if (window.__bet1xPasswordEye) return;   // loaded twice on one page: run once
  window.__bet1xPasswordEye = true;

  var EYE_OPEN = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg>';
  var EYE_SHUT = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 19c-7 0-11-7-11-7a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 7 11 7a18.5 18.5 0 0 1-2.16 3.19"/><path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/><path d="M1 1l22 22"/></svg>';

  var CSS =
    '.pw-eye-wrap{position:relative;display:block;width:100%;}' +
    '.pw-eye-wrap>input{width:100%;box-sizing:border-box;}' +
    '.pw-eye-btn{position:absolute;right:6px;top:50%;transform:translateY(-50%);display:inline-flex;align-items:center;justify-content:center;' +
    'width:32px;height:32px;padding:0;margin:0;border:0;border-radius:7px;background:none;cursor:pointer;opacity:.6;color:inherit;' +
    'color:var(--text-dim,var(--sa-dim,#8790b8));z-index:1;}' +
    '.pw-eye-btn:hover,.pw-eye-btn:focus-visible{opacity:1;background:rgba(255,255,255,.08);}' +
    '.pw-eye-btn svg{display:block;}';

  function injectStyle() {
    if (document.getElementById('pw-eye-style')) return;
    var s = document.createElement('style');
    s.id = 'pw-eye-style';
    s.textContent = CSS;
    (document.head || document.documentElement).appendChild(s);
  }

  function attach(input) {
    input.setAttribute('data-eye', '1');
    var wrap = document.createElement('span');
    wrap.className = 'pw-eye-wrap';
    // Move the box's own outer margins onto the wrapper, so the wrapper is exactly as tall as the
    // box and the eye sits in its vertical centre.
    var cs = window.getComputedStyle ? getComputedStyle(input) : null;
    if (cs) {
      wrap.style.marginTop = cs.marginTop; wrap.style.marginBottom = cs.marginBottom;
      wrap.style.marginLeft = cs.marginLeft; wrap.style.marginRight = cs.marginRight;
    }
    input.parentNode.insertBefore(wrap, input);
    wrap.appendChild(input);
    input.style.margin = '0';
    input.style.paddingRight = '44px';   // room for the button, whatever the page's own input style
    var b = document.createElement('button');
    b.type = 'button';
    b.className = 'pw-eye-btn';
    b.tabIndex = 0;
    var set = function (shown) {
      input.type = shown ? 'text' : 'password';
      b.innerHTML = shown ? EYE_SHUT : EYE_OPEN;
      b.setAttribute('aria-label', shown ? 'Hide password' : 'Show password');
      b.setAttribute('title', shown ? 'Hide password' : 'Show password');
      b.setAttribute('aria-pressed', shown ? 'true' : 'false');
    };
    set(false);
    b.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      set(input.type === 'password');
      input.focus();
    });
    wrap.appendChild(b);
    if (input.form) input.form.addEventListener('submit', function () { set(false); });
  }

  function scan() {
    var list = document.querySelectorAll('input[type="password"]:not([data-eye])');
    for (var i = 0; i < list.length; i++) attach(list[i]);
  }

  function start() {
    injectStyle();
    scan();
    if (window.MutationObserver && document.body) {
      var pending = false;
      new MutationObserver(function () {
        if (pending) return;
        pending = true;
        // Batch bursts of DOM changes (game pages redraw often) into one scan per frame.
        (window.requestAnimationFrame || setTimeout)(function () { pending = false; scan(); });
      }).observe(document.body, { childList: true, subtree: true });
    }
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
