/* ChangeEmail Widget v1.1 — 个人中心「修改登录邮箱」卡（原生级 NaiveUI 适配）
 *
 * 策略（兄弟于 invite-alias-widget / commission-tier-widget）：
 *   - 仅 #/profile 路由显示
 *   - 自适应再认证：has_password=true → 当前密码；false → 原邮箱验证码
 *   - 新邮箱永远要验证码；倒计时 60s
 *   - 表单只在 mode 切换时重渲染，倒计时/提示直接改 DOM，避免清空输入
 *
 * 原生级外观（panel 主题 = NaiveUI）：
 *   - scoped <style>，真 hover/focus/placeholder 态
 *   - 暗色感知（复刻 blade 的 isDarkMode：.dark / data-theme / prefers-color-scheme）
 *   - 运行时「采样」页面上真实 .n-card 背景/文字 + .n-button 主色，CSS 变量注入，
 *     主题切换 / 自定义主题色都自动跟随；采样失败回退 NaiveUI 默认调色板
 *
 * 后端：
 *   GET  /api/v1/user/change-email/status        → {enabled, has_password, current_email, cooldown_until, ...}
 *   POST /api/v1/user/change-email/send-new-code → {email}
 *   POST /api/v1/user/change-email/send-old-code → (空)
 *   POST /api/v1/user/change-email/commit        → {email, new_email_code, password?, old_email_code?}
 */
(function () {
  if (window.__change_email_widget__) return;
  window.__change_email_widget__ = true;

  var API = '/api/v1/user/change-email';

  /* ======== Token（与 IA/CT 同口径）======== */
  function readTokenValue(raw) {
    if (!raw) return '';
    var value = raw;
    try {
      var d = JSON.parse(raw);
      if (d && d.expire && d.expire < Date.now()) return '';
      if (typeof d === 'string') {
        value = d;
      } else if (d && typeof d === 'object') {
        value = d.value || d.auth_data || d.access_token || d.token || '';
        if (value && typeof value === 'object') {
          value = value.value || value.auth_data || value.access_token || value.token || '';
        }
      }
    } catch (e) {}
    return typeof value === 'string' ? value.trim() : '';
  }
  function getToken() {
    var keys = ['ACCESS_TOKEN', 'access_token', 'AUTH_DATA', 'auth_data', 'token'];
    for (var i = 0; i < keys.length; i++) {
      var raw = null;
      try { raw = localStorage.getItem(keys[i]); } catch (e) {}
      if (!raw) continue;
      var token = readTokenValue(raw);
      if (token) return token;
    }
    return '';
  }
  function authHeader(token) {
    token = (token || '').trim();
    if (!token) return '';
    return /^(Bearer|Basic)\s+/i.test(token) ? token : 'Bearer ' + token;
  }

  function api(method, path, body) {
    var headers = { 'Accept': 'application/json' };
    var auth = authHeader(getToken());
    if (auth) headers['Authorization'] = auth;
    var opt = { method: method, headers: headers, credentials: 'include' };
    if (body) {
      headers['Content-Type'] = 'application/json';
      opt.body = JSON.stringify(body);
    }
    return fetch(API + path, opt).then(function (r) {
      return r.json().then(function (j) { return { ok: r.ok, status: r.status, body: j }; })
        .catch(function () { return { ok: r.ok, status: r.status, body: {} }; });
    });
  }

  /* ======== State ======== */
  var STATE = {
    status: null, mode: 'collapsed', fetching: false, lastFetch: 0,
    newCd: 0, oldCd: 0, newEmail: '',
  };

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  /* ======== 主题引擎（NaiveUI 原生级适配）======== */
  // 复刻 dashboard.blade.php 的 isDarkMode
  function isDarkMode() {
    var root = document.documentElement, body = document.body;
    return (root && root.classList.contains('dark')) ||
      (body && body.classList.contains('dark')) ||
      (root && root.getAttribute('data-theme') === 'dark') ||
      (body && body.getAttribute('data-theme') === 'dark') ||
      (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
  }

  function parseRGB(s) {
    if (!s) return null;
    var m = s.match(/rgba?\(\s*([0-9.]+)[ ,]+([0-9.]+)[ ,]+([0-9.]+)(?:[ ,/]+([0-9.]+))?/i);
    if (!m) return null;
    var a = m[4] === undefined ? 1 : parseFloat(m[4]);
    if (a === 0) return null; // 透明 → 视作未取到
    return { r: +m[1], g: +m[2], b: +m[3], a: a };
  }
  function mix(c, t, amt) { return Math.round(c + (t - c) * amt); }
  function rgb(c) { return 'rgb(' + c.r + ',' + c.g + ',' + c.b + ')'; }
  function lighten(c, amt) { return 'rgb(' + mix(c.r, 255, amt) + ',' + mix(c.g, 255, amt) + ',' + mix(c.b, 255, amt) + ')'; }
  function fade(c, a) { return 'rgba(' + c.r + ',' + c.g + ',' + c.b + ',' + a + ')'; }

  var LIGHT = {
    bg: '#ffffff', text: 'rgba(0,0,0,.82)', dim: 'rgba(0,0,0,.45)', border: 'rgba(0,0,0,.09)',
    inputBg: '#ffffff', inputBorder: 'rgba(0,0,0,.16)', hover: 'rgba(0,0,0,.04)',
    shadow: '0 1px 2px rgba(0,0,0,.06)', warn: '#b45309', warnBg: 'rgba(245,158,11,.12)',
    ok: '#18a058', err: '#d03050',
  };
  var DARK = {
    bg: '#2a2a30', text: 'rgba(255,255,255,.82)', dim: 'rgba(255,255,255,.4)', border: 'rgba(255,255,255,.12)',
    inputBg: 'rgba(255,255,255,.05)', inputBorder: 'rgba(255,255,255,.22)', hover: 'rgba(255,255,255,.09)',
    shadow: '0 1px 2px rgba(0,0,0,.4)', warn: '#f0a020', warnBg: 'rgba(240,160,32,.15)',
    ok: '#63e2b7', err: '#e88080',
  };

  function sampleNative() {
    var out = {};
    try {
      // 主色：页面真实主按钮
      var pb = document.querySelector('.n-button--primary-type, .n-button.n-button--primary');
      if (pb) {
        var c = parseRGB(getComputedStyle(pb).backgroundColor);
        if (c) out.primary = c;
      }
      if (!out.primary) {
        var lnk = document.querySelector('#app a[class*="text-"], #app .n-button--info-type');
        if (lnk) { var lc = parseRGB(getComputedStyle(lnk).color); if (lc) out.primary = lc; }
      }
      // 卡片底色/文字/圆角：页面真实 .n-card
      var card = document.querySelector('#app .n-card');
      if (card) {
        var cs = getComputedStyle(card);
        var cb = parseRGB(cs.backgroundColor); if (cb) out.bg = rgb(cb);
        var ct = parseRGB(cs.color); if (ct) out.text = fade(ct, 0.9);
        var rad = parseFloat(cs.borderTopLeftRadius); if (rad > 0 && rad < 40) out.radius = rad;
      }
    } catch (e) {}
    return out;
  }

  function applyTheme() {
    if (!anchor) return;
    var dark = isDarkMode();
    var p = dark ? DARK : LIGHT;
    var s = sampleNative();
    var prim = s.primary || (dark ? { r: 99, g: 226, b: 183 } : { r: 24, g: 160, b: 88 });
    var radius = s.radius != null ? s.radius : 10;
    var st = anchor.style;
    st.setProperty('--ce-bg', s.bg || p.bg);
    st.setProperty('--ce-text', s.text || p.text);
    st.setProperty('--ce-dim', p.dim);
    st.setProperty('--ce-border', p.border);
    st.setProperty('--ce-input-bg', p.inputBg);
    st.setProperty('--ce-input-border', p.inputBorder);
    st.setProperty('--ce-hover', p.hover);
    st.setProperty('--ce-shadow', p.shadow);
    st.setProperty('--ce-warn', p.warn);
    st.setProperty('--ce-warn-bg', p.warnBg);
    st.setProperty('--ce-ok', p.ok);
    st.setProperty('--ce-err', p.err);
    st.setProperty('--ce-primary', rgb(prim));
    st.setProperty('--ce-primary-hover', dark ? lighten(prim, 0.12) : lighten(prim, 0.08));
    st.setProperty('--ce-primary-fade', fade(prim, 0.16));
    st.setProperty('--ce-primary-text', dark ? 'rgba(0,0,0,.9)' : '#ffffff');
    st.setProperty('--ce-radius', radius + 'px');
    st.setProperty('--ce-radius-ctrl', Math.max(4, Math.round(radius * 0.55)) + 'px');
  }

  function ensureStyle() {
    if (document.getElementById('change-email-style')) return;
    var css =
      '#change-email-anchor *{box-sizing:border-box}' +
      '#change-email-anchor .ce-card{background:var(--ce-bg);border:1px solid var(--ce-border);border-radius:var(--ce-radius);padding:18px 20px;color:var(--ce-text);box-shadow:var(--ce-shadow);font-family:inherit;transition:background .2s,border-color .2s}' +
      '#change-email-anchor .ce-row{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}' +
      '#change-email-anchor .ce-title{font-size:15px;font-weight:600;color:var(--ce-text)}' +
      '#change-email-anchor .ce-sub{font-size:13px;color:var(--ce-dim);margin-top:4px}' +
      '#change-email-anchor .ce-label{display:block;font-size:13px;color:var(--ce-dim);margin:14px 0 6px}' +
      '#change-email-anchor .ce-input{width:100%;height:34px;padding:0 12px;border-radius:var(--ce-radius-ctrl);border:1px solid var(--ce-input-border);background:var(--ce-input-bg);color:var(--ce-text);font-size:14px;outline:none;transition:border-color .2s,box-shadow .2s;font-family:inherit}' +
      '#change-email-anchor .ce-input::placeholder{color:var(--ce-dim);opacity:.75}' +
      '#change-email-anchor .ce-input:focus{border-color:var(--ce-primary);box-shadow:0 0 0 2px var(--ce-primary-fade)}' +
      '#change-email-anchor .ce-field{display:flex;gap:8px}' +
      '#change-email-anchor .ce-btn{height:34px;padding:0 16px;border-radius:var(--ce-radius-ctrl);font-size:14px;font-weight:500;cursor:pointer;border:1px solid transparent;transition:opacity .15s,background .15s,border-color .15s;white-space:nowrap;font-family:inherit;line-height:1}' +
      '#change-email-anchor .ce-btn:disabled{opacity:.5;cursor:default}' +
      '#change-email-anchor .ce-btn-primary{background:var(--ce-primary);color:var(--ce-primary-text);border-color:var(--ce-primary)}' +
      '#change-email-anchor .ce-btn-primary:hover:not(:disabled){background:var(--ce-primary-hover);border-color:var(--ce-primary-hover)}' +
      '#change-email-anchor .ce-btn-default{background:transparent;color:var(--ce-text);border-color:var(--ce-border)}' +
      '#change-email-anchor .ce-btn-default:hover:not(:disabled){background:var(--ce-hover)}' +
      '#change-email-anchor .ce-btn-code{background:transparent;color:var(--ce-primary);border-color:var(--ce-primary)}' +
      '#change-email-anchor .ce-btn-code:hover:not(:disabled){background:var(--ce-primary-fade)}' +
      '#change-email-anchor .ce-msg{min-height:18px;font-size:13px;margin:12px 0 2px;line-height:1.4}' +
      '#change-email-anchor .ce-actions{display:flex;gap:10px;margin-top:8px}' +
      '#change-email-anchor .ce-note{font-size:12px;color:var(--ce-dim);margin-top:6px;line-height:1.5}' +
      '#change-email-anchor .ce-cooldown{margin-top:10px;font-size:12px;color:var(--ce-warn);background:var(--ce-warn-bg);border-radius:var(--ce-radius-ctrl);padding:8px 10px}';
    var el = document.createElement('style');
    el.id = 'change-email-style';
    el.textContent = css;
    (document.head || document.documentElement).appendChild(el);
  }

  /* ======== DOM anchor ======== */
  var anchor = document.createElement('div');
  anchor.id = 'change-email-anchor';
  anchor.setAttribute('style', 'box-sizing:border-box;width:100%;display:none;margin:0 0 16px;font-family:-apple-system,system-ui,"PingFang SC",sans-serif;pointer-events:auto;');

  /* ======== Render ======== */
  function tplCollapsed() {
    var s = STATE.status || {};
    return '<div class="ce-card">' +
      '<div class="ce-row">' +
        '<div><div class="ce-title">登录邮箱</div><div class="ce-sub">' + esc(s.current_email || '—') + '</div></div>' +
        '<button data-ce="open" class="ce-btn ce-btn-default">修改邮箱</button>' +
      '</div>' + tplCooldownNote() +
    '</div>';
  }

  function tplCooldownNote() {
    var s = STATE.status || {};
    if (!s.cooldown_until) return '';
    var now = Math.floor(Date.now() / 1000);
    if (s.cooldown_until <= now) return '';
    var days = Math.ceil((s.cooldown_until - now) / 86400);
    return '<div class="ce-cooldown">距上次修改不足冷却期，约 ' + days + ' 天后可再次修改。</div>';
  }

  function tplForm() {
    var s = STATE.status || {};
    var authField = s.has_password
      ? ('<label class="ce-label">当前密码</label>' +
         '<input id="ce-password" type="password" autocomplete="current-password" placeholder="请输入当前登录密码" class="ce-input" />')
      : ('<label class="ce-label">原邮箱验证码</label>' +
         '<div class="ce-field">' +
           '<input id="ce-old-code" inputmode="numeric" placeholder="发送到当前邮箱的验证码" class="ce-input" />' +
           '<button data-ce="send-old" id="ce-send-old" class="ce-btn ce-btn-code">发送验证码</button>' +
         '</div>' +
         '<div class="ce-note">当前账号未设置密码，需用原邮箱验证码确认身份</div>');

    return '<div class="ce-card">' +
      '<div class="ce-title">修改登录邮箱</div>' +
      '<div class="ce-sub">当前：' + esc(s.current_email || '—') + '</div>' +
      '<label class="ce-label">新邮箱</label>' +
      '<input id="ce-new-email" type="email" autocomplete="off" placeholder="your@newmail.com" class="ce-input" />' +
      '<label class="ce-label">新邮箱验证码</label>' +
      '<div class="ce-field">' +
        '<input id="ce-new-code" inputmode="numeric" placeholder="发送到新邮箱的验证码" class="ce-input" />' +
        '<button data-ce="send-new" id="ce-send-new" class="ce-btn ce-btn-code">发送验证码</button>' +
      '</div>' +
      '<div style="margin-top:14px">' + authField + '</div>' +
      '<div id="ce-msg" class="ce-msg"></div>' +
      '<div class="ce-actions">' +
        '<button data-ce="submit" id="ce-submit" class="ce-btn ce-btn-primary" style="flex:1">确认修改</button>' +
        '<button data-ce="cancel" class="ce-btn ce-btn-default">取消</button>' +
      '</div>' +
    '</div>';
  }

  function tplSuccess() {
    return '<div class="ce-card">' +
      '<div class="ce-title" style="color:var(--ce-ok)">✓ 邮箱已修改</div>' +
      '<div class="ce-sub" style="margin-top:8px">新登录邮箱：' + esc(STATE.newEmail || '') + '</div>' +
      '<div class="ce-note">为保护账号安全，其它设备已被登出，请用新邮箱重新登录。原邮箱已收到变更通知。</div>' +
      '<div class="ce-actions"><button data-ce="cancel" class="ce-btn ce-btn-default">完成</button></div>' +
    '</div>';
  }

  function paint() {
    ensureStyle();
    applyTheme();
    var s = STATE.status;
    if (!s) { anchor.innerHTML = '<div class="ce-card" style="color:var(--ce-dim);font-size:13px">加载中…</div>'; return; }
    if (!s.enabled) { anchor.style.display = 'none'; return; }
    if (STATE.mode === 'form') {
      anchor.innerHTML = tplForm(); msg(''); refreshCdButtons();
    } else if (STATE.mode === 'success') {
      anchor.innerHTML = tplSuccess();
    } else {
      anchor.innerHTML = tplCollapsed();
    }
  }

  /* ======== 直接 DOM 操作（不重渲染，保输入焦点）======== */
  function msg(text, kind) {
    var el = document.getElementById('ce-msg');
    if (!el) return;
    el.style.color = kind === 'ok' ? 'var(--ce-ok)' : (kind === 'info' ? 'var(--ce-primary)' : 'var(--ce-err)');
    el.textContent = text || '';
  }
  function val(id) { var el = document.getElementById(id); return el ? el.value.trim() : ''; }
  function setBtn(id, text, disabled) {
    var el = document.getElementById(id);
    if (!el) return;
    el.textContent = text; el.disabled = !!disabled;
  }
  function refreshCdButtons() {
    setBtn('ce-send-new', STATE.newCd > 0 ? STATE.newCd + 's' : '发送验证码', STATE.newCd > 0);
    setBtn('ce-send-old', STATE.oldCd > 0 ? STATE.oldCd + 's' : '发送验证码', STATE.oldCd > 0);
  }

  setInterval(function () {
    var dirty = false;
    if (STATE.newCd > 0) { STATE.newCd--; dirty = true; }
    if (STATE.oldCd > 0) { STATE.oldCd--; dirty = true; }
    if (dirty && STATE.mode === 'form') refreshCdButtons();
  }, 1000);

  /* ======== Actions ======== */
  function sendNew() {
    if (STATE.newCd > 0) return;
    var email = val('ce-new-email');
    if (!email || email.indexOf('@') < 0) { msg('请先填写有效的新邮箱'); return; }
    setBtn('ce-send-new', '发送中…', true);
    api('POST', '/send-new-code', { email: email }).then(function (res) {
      if (res.ok && res.body && (res.body.data === true || res.body.status === 'success')) {
        STATE.newCd = 60; refreshCdButtons(); msg('验证码已发送到新邮箱，5 分钟内有效', 'ok');
      } else { refreshCdButtons(); msg((res.body && res.body.message) || '发送失败，请稍后再试'); }
    }).catch(function () { refreshCdButtons(); msg('网络错误，请稍后再试'); });
  }

  function sendOld() {
    if (STATE.oldCd > 0) return;
    setBtn('ce-send-old', '发送中…', true);
    api('POST', '/send-old-code', {}).then(function (res) {
      if (res.ok && res.body && (res.body.data === true || res.body.status === 'success')) {
        STATE.oldCd = 60; refreshCdButtons(); msg('验证码已发送到原邮箱，5 分钟内有效', 'ok');
      } else { refreshCdButtons(); msg((res.body && res.body.message) || '发送失败，请稍后再试'); }
    }).catch(function () { refreshCdButtons(); msg('网络错误，请稍后再试'); });
  }

  function submit() {
    var s = STATE.status || {};
    var email = val('ce-new-email'), newCode = val('ce-new-code');
    if (!email || email.indexOf('@') < 0) { msg('请填写有效的新邮箱'); return; }
    if (!newCode) { msg('请填写新邮箱验证码'); return; }
    var payload = { email: email, new_email_code: newCode };
    if (s.has_password) {
      var pwd = val('ce-password');
      if (!pwd) { msg('请输入当前密码'); return; }
      payload.password = pwd;
    } else {
      var oldCode = val('ce-old-code');
      if (!oldCode) { msg('请填写原邮箱验证码'); return; }
      payload.old_email_code = oldCode;
    }
    setBtn('ce-submit', '提交中…', true);
    api('POST', '/commit', payload).then(function (res) {
      if (res.ok && res.body && res.body.status === 'success') {
        STATE.newEmail = (res.body.data && res.body.data.email) || email;
        STATE.mode = 'success';
        STATE.status.cooldown_until = Math.floor(Date.now() / 1000) + 1;
        paint();
      } else {
        setBtn('ce-submit', '确认修改', false);
        msg((res.body && res.body.message) || '修改失败，请稍后再试');
      }
    }).catch(function () { setBtn('ce-submit', '确认修改', false); msg('网络错误，请稍后再试'); });
  }

  anchor.addEventListener('click', function (e) {
    var btn = e.target && e.target.closest && e.target.closest('[data-ce]');
    if (!btn) return;
    var act = btn.getAttribute('data-ce');
    if (act === 'open') { STATE.mode = 'form'; paint(); }
    else if (act === 'cancel') { STATE.mode = 'collapsed'; STATE.newCd = 0; STATE.oldCd = 0; fetchStatus(true); }
    else if (act === 'send-new') { sendNew(); }
    else if (act === 'send-old') { sendOld(); }
    else if (act === 'submit') { submit(); }
  });

  /* ======== Fetch status ======== */
  function fetchStatus(force) {
    if (STATE.fetching) return;
    if (!force && Date.now() - STATE.lastFetch < 4000) return;
    STATE.fetching = true;
    api('GET', '/status').then(function (res) {
      STATE.fetching = false; STATE.lastFetch = Date.now();
      if (res.ok && res.body && res.body.data) STATE.status = res.body.data;
      else if (res.status === 404 || res.status === 403) STATE.status = { enabled: false };
      paint();
    }).catch(function () { STATE.fetching = false; });
  }

  /* ======== Mount target ======== */
  function findMountTarget() {
    var main = document.querySelector('article.flex.flex-col.flex-1.overflow-hidden') ||
               document.querySelector('main') || document.querySelector('article');
    if (!main) return null;
    var nodes = main.querySelectorAll('div,section,form');
    for (var i = 0; i < nodes.length; i++) {
      var t = (nodes[i].textContent || '');
      if (/修改密码|更改密码|重置密码|Change Password/i.test(t) && t.length < 400) {
        var card = nodes[i], hop = 0;
        while (card.parentElement && card.parentElement !== main && hop < 4) { card = card.parentElement; hop++; }
        if (card.parentElement) return { parent: card.parentElement, before: card };
      }
    }
    return { parent: main, before: main.firstChild };
  }

  function ensureMounted() {
    if (!document.body) return;
    var target = findMountTarget();
    if (target && target.parent) {
      if (anchor.parentNode !== target.parent || anchor.nextSibling !== target.before) {
        target.parent.insertBefore(anchor, target.before || null);
      }
      return;
    }
    if (!anchor.parentNode) document.body.appendChild(anchor);
  }

  /* ======== Routing visibility ======== */
  function isProfilePage() {
    var h = (location.hash || '').toLowerCase(), p = (location.pathname || '').toLowerCase();
    return /(^|[#/])profile(\b|\/|$)/.test(h) || /(^|[#/])profile(\b|\/|$)/.test(p);
  }

  function syncVisibility() {
    if (isProfilePage() && getToken()) {
      ensureMounted();
      anchor.style.display = 'block';
      if (!STATE.status) paint(); else applyTheme();
      fetchStatus(false);
    } else {
      anchor.style.display = 'none';
      if (STATE.mode !== 'collapsed') { STATE.mode = 'collapsed'; STATE.newCd = 0; STATE.oldCd = 0; }
    }
  }

  window.addEventListener('hashchange', syncVisibility);
  window.addEventListener('popstate', syncVisibility);
  ['pushState', 'replaceState'].forEach(function (m) {
    var orig = history[m];
    history[m] = function () { var r = orig.apply(this, arguments); setTimeout(syncVisibility, 0); return r; };
  });

  // 主题切换（暗色/亮色 / 自定义主题色）跟随
  if (window.MutationObserver) {
    new MutationObserver(function () { if (anchor.style.display !== 'none') applyTheme(); })
      .observe(document.documentElement, { attributes: true, attributeFilter: ['class', 'data-theme'] });
  }
  if (window.matchMedia) {
    try {
      window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function () {
        if (anchor.style.display !== 'none') applyTheme();
      });
    } catch (e) {}
  }

  setInterval(function () {
    if (anchor.style.display !== 'none') ensureMounted();
    syncVisibility();
  }, 2000);

  function start() {
    if (!document.body) return setTimeout(start, 200);
    ensureStyle();
    syncVisibility();
  }
  start();
})();
