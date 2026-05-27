/* InviteAlias Widget v1.0 — 邀请页"我的专属链接"卡（兄弟于 commission-tier-widget）
 *
 * 策略：
 *   - anchor 挂在邀请页 grid 之前、commission_tier 卡之后（间距 12px）
 *   - 仅 /invite 路径显示
 *   - 紧凑摘要 + 展开三档对比 + 已持有列表
 *   - 复用 commission-tier 同款液态玻璃风格
 *   - inline style，无 CSS class 依赖
 *
 * 数据：
 *   GET /api/v1/user/invite-alias/policy  → tiers, user{invited_count,age_days,invite_code}, rules
 *   GET /api/v1/user/invite-alias/mine    → 已持有 alias 列表
 */
(function () {
  if (window.__invite_alias_widget__) return;
  window.__invite_alias_widget__ = true;

  /* ======== Token (与 CT 同口径) ======== */
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

  /* ======== State ======== */
  var STATE = {
    policy: null,   // tiers / user / rules
    mine: [],       // 已持有 alias
    fetching: false,
    lastFetch: 0,
    expanded: localStorage.getItem('invite_alias_expanded') === '1',
  };

  /* ======== Helpers ======== */
  var TIER_META = {
    1: { badge: '🥉 银',   name: '自定义邀请码',  preview: 'my.yue.to/#/register?code=' },
    2: { badge: '🥈 金',   name: '隔离子域 (短链)', preview: '·.i.yue.to' },
    3: { badge: '🥇 铂金', name: '主域子域 (短链)', preview: '·.yue.to' },
  };

  function fmtNum(n) { return String(n || 0); }

  /* ======== DOM anchor ======== */
  var anchor = document.createElement('div');
  anchor.id = 'invite-alias-anchor';
  anchor.setAttribute('style',
    'box-sizing:border-box;width:100%;display:none;' +
    'margin:0 0 16px;font-family:-apple-system,system-ui,"PingFang SC",sans-serif;' +
    'pointer-events:auto;'
  );

  anchor.addEventListener('click', function (e) {
    var btn = e.target && e.target.closest && e.target.closest('[data-ia-toggle]');
    if (btn) {
      STATE.expanded = !STATE.expanded;
      try { localStorage.setItem('invite_alias_expanded', STATE.expanded ? '1' : '0'); } catch (err) {}
      paint();
      return;
    }
    var copyBtn = e.target && e.target.closest && e.target.closest('[data-ia-copy]');
    if (copyBtn) {
      var url = copyBtn.getAttribute('data-ia-copy');
      try {
        navigator.clipboard.writeText(url);
        copyBtn.innerText = '已复制 ✓';
        setTimeout(function () { copyBtn.innerText = '复制链接'; }, 1500);
      } catch (err) {}
    }
  });

  function findInviteMountTarget() {
    var article = document.querySelector('article.flex.flex-col.flex-1.overflow-hidden') || document.querySelector('article');
    if (!article) return null;
    var grid = article.querySelector('div.grid.grid-cols-1.lg\\:grid-cols-3.gap-6');
    if (grid && grid.parentElement) return { parent: grid.parentElement, before: grid };
    return null;
  }

  function ensureAnchorMounted() {
    if (!document.body) return;
    var target = findInviteMountTarget();
    if (target) {
      // 挂在 commission-tier-anchor 之后（如果存在），让 CT 在前我们在后
      var ct = document.getElementById('commission-tier-anchor');
      if (ct && ct.parentNode === target.parent) {
        if (anchor.parentNode !== target.parent || anchor.previousSibling !== ct) {
          target.parent.insertBefore(anchor, ct.nextSibling);
        }
      } else {
        if (anchor.parentNode !== target.parent || anchor.nextSibling !== target.before) {
          target.parent.insertBefore(anchor, target.before);
        }
      }
      return;
    }
    if (!anchor.parentNode) document.body.appendChild(anchor);
  }

  /* ======== Render ======== */
  function tplLoading() {
    return '<div style="box-sizing:border-box;padding:12px 16px;border-radius:12px;background:rgba(255,255,255,.52);border:1px solid rgba(148,163,184,.18);box-shadow:0 10px 28px rgba(15,23,42,.06);color:#64748b;font-size:13px;">加载专属链接…</div>';
  }

  function tplGuestOrError() { return ''; }

  function tplOwnedRow(item) {
    var meta = TIER_META[item.alias_type] || {};
    var url;
    if (item.alias_type === 1) url = 'https://my.yue.to/#/register?code=' + item.alias;
    else if (item.alias_type === 2) url = 'https://' + item.alias + '.i.yue.to';
    else url = 'https://' + item.alias + '.yue.to';

    var status = item.status === 1 ? '🟢 active' : '🟡 dormant';
    var statusColor = item.status === 1 ? '#059669' : '#d97706';

    return '<div style="display:flex;align-items:center;justify-content:space-between;gap:10px;padding:9px 11px;border-radius:10px;background:rgba(255,255,255,.58);border:1px solid rgba(148,163,184,.18);">' +
      '<div style="display:flex;flex-direction:column;gap:3px;min-width:0;flex:1;">' +
        '<div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">' +
          '<span style="font-size:12px;font-weight:700;color:#1f2937;">' + (meta.badge || '?') + '</span>' +
          '<span style="font-size:13px;color:#0f172a;font-weight:600;word-break:break-all;">' + url + '</span>' +
        '</div>' +
        '<div style="display:flex;gap:10px;font-size:11px;color:#64748b;">' +
          '<span style="color:' + statusColor + ';font-weight:700;">' + status + '</span>' +
          (item.conv_count > 0 ? '<span>✓ 转化 ' + fmtNum(item.conv_count) + '</span>' : '') +
        '</div>' +
      '</div>' +
      '<button type="button" data-ia-copy="' + url + '" style="height:28px;border:1px solid rgba(148,163,184,.24);background:rgba(255,255,255,.72);color:#334155;border-radius:7px;padding:0 10px;font-size:11px;font-weight:700;cursor:pointer;white-space:nowrap;">复制链接</button>' +
    '</div>';
  }

  function tplTierGrid(tiers, user) {
    return tiers.map(function (t) {
      var meta = TIER_META[t.type] || {};
      var canInvite = !t.min_invite_count || user.invited_count >= t.min_invite_count;
      var canAge    = !t.min_account_age_days || user.account_age_days >= t.min_account_age_days;
      var locked    = !canInvite || !canAge;
      var bg     = locked ? 'rgba(148,163,184,.06)' : 'rgba(255,255,255,.62)';
      var border = locked ? 'rgba(148,163,184,.18)' : 'rgba(245,158,11,.22)';
      var lockHint = '';
      if (!canInvite) lockHint = '需邀请 ≥' + t.min_invite_count + '人 (当前 ' + user.invited_count + ')';
      else if (!canAge) lockHint = '账号需 ≥' + t.min_account_age_days + ' 天 (当前 ' + user.account_age_days + ')';

      return '<div style="box-sizing:border-box;flex:1 1 220px;min-width:200px;padding:11px 13px;border-radius:11px;background:' + bg + ';border:1px solid ' + border + ';display:flex;flex-direction:column;gap:8px;">' +
        '<div style="display:flex;align-items:center;justify-content:space-between;gap:8px;">' +
          '<span style="font-size:13px;color:#1f2937;font-weight:700;">' + (meta.badge || '?') + '</span>' +
          '<span style="font-size:18px;color:#d97706;font-weight:900;line-height:1;">' + t.price + '<span style="font-size:11px;color:#92400e;font-weight:700;margin-left:2px;">积分</span></span>' +
        '</div>' +
        '<div style="font-size:11px;color:#64748b;line-height:1.4;">' + meta.name + '</div>' +
        '<div style="font-size:10px;color:#9ca3af;font-family:monospace;word-break:break-all;">' + (t.type === 1 ? 'my.yue.to/#/register?code=<你的名字>' : '<你的名字>' + (t.type === 2 ? '.i.yue.to' : '.yue.to')) + '</div>' +
        (locked
          ? '<div style="font-size:11px;color:#dc2626;background:rgba(254,226,226,.5);padding:4px 8px;border-radius:6px;">🔒 ' + lockHint + '</div>'
          : '<div style="font-size:11px;color:#059669;background:rgba(220,252,231,.5);padding:4px 8px;border-radius:6px;">✓ 可兑换</div>') +
      '</div>';
    }).join('');
  }

  function tplCard(d) {
    var policy = d.policy || {};
    var mine = d.mine || [];
    var user = policy.user || {};
    var tiers = policy.tiers || [];

    var ownedTypes = {};
    mine.forEach(function (m) { ownedTypes[m.alias_type] = true; });
    var ownedCount = mine.length;

    var summary = ownedCount > 0
      ? '已持有 ' + ownedCount + ' 个专属链接'
      : '未购买专属链接，下方查看 3 档';

    var fastestUnlocked = null;
    for (var i = 0; i < tiers.length; i++) {
      var t = tiers[i];
      if (ownedTypes[t.type]) continue;
      var canInvite = !t.min_invite_count || user.invited_count >= t.min_invite_count;
      var canAge    = !t.min_account_age_days || user.account_age_days >= t.min_account_age_days;
      if (canInvite && canAge) { fastestUnlocked = t; break; }
    }

    var rightHint = fastestUnlocked
      ? '✓ 可兑换 ' + (TIER_META[fastestUnlocked.type] || {}).badge
      : (mine.length === tiers.length ? '已收藏全档' : '门槛未达成');

    var ownedSection = ownedCount > 0
      ? '<div style="display:flex;flex-direction:column;gap:6px;margin-top:10px;">' + mine.map(tplOwnedRow).join('') + '</div>'
      : '';

    var details = STATE.expanded
      ? '<div style="border-top:1px solid rgba(148,163,184,.16);padding:12px 16px 14px;background:rgba(248,250,252,.48);">' +
          '<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:10px;">' +
            '<div style="font-size:12px;color:#64748b;font-weight:700;">三档对比 · 永久持有</div>' +
            '<div style="font-size:11px;color:#64748b;">前往 TG bot 完成兑换 · /myalias</div>' +
          '</div>' +
          '<div style="display:flex;flex-wrap:wrap;gap:8px;">' + tplTierGrid(tiers, user) + '</div>' +
          '<div style="margin-top:10px;font-size:11px;color:#64748b;line-height:1.5;">' +
            '兑换永久持有，需保持账号活跃。一旦兑换不可修改不可转让。' +
          '</div>' +
        '</div>'
      : '';

    return (
      '<div style="box-sizing:border-box;width:100%;border-radius:14px;overflow:hidden;' +
        'background:rgba(255,255,255,.62);border:1px solid rgba(148,163,184,.18);box-shadow:0 12px 32px rgba(15,23,42,.07);' +
        'backdrop-filter:blur(14px);-webkit-backdrop-filter:blur(14px);">' +
        '<div style="padding:13px 16px 12px;">' +
          '<div style="display:flex;align-items:flex-start;justify-content:space-between;gap:14px;flex-wrap:wrap;">' +
            '<div style="min-width:220px;flex:1 1 320px;">' +
              '<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">' +
                '<span style="font-size:11px;color:#64748b;font-weight:700;letter-spacing:.3px;">我的专属链接</span>' +
                '<span style="font-size:16px;font-weight:800;color:#1f2937;line-height:1.2;">' +
                  summary +
                '</span>' +
              '</div>' +
              '<div style="display:flex;justify-content:flex-start;gap:14px;margin-top:7px;font-size:12px;color:#64748b;flex-wrap:wrap;">' +
                '<span>邀请人数 <b style="color:#1f2937;">' + fmtNum(user.invited_count) + '</b></span>' +
                '<span>账号年龄 <b style="color:#1f2937;">' + fmtNum(user.account_age_days) + '</b> 天</span>' +
                '<span style="color:#d97706;font-weight:700;">' + rightHint + '</span>' +
              '</div>' +
            '</div>' +
            '<div style="display:flex;align-items:center;gap:14px;flex:0 0 auto;">' +
              '<div style="text-align:right;">' +
                '<div style="font-size:11px;color:#64748b;font-weight:700;">已拥有</div>' +
                '<div style="font-size:24px;font-weight:900;color:#d97706;line-height:1;">' + ownedCount + '<span style="font-size:13px;color:#92400e;font-weight:700;margin-left:2px;">/3</span></div>' +
              '</div>' +
              '<button type="button" data-ia-toggle="1" style="height:32px;border:1px solid rgba(148,163,184,.24);background:rgba(255,255,255,.72);color:#334155;border-radius:8px;padding:0 11px;font-size:12px;font-weight:700;cursor:pointer;white-space:nowrap;">' +
                (STATE.expanded ? '收起档位' : '查看档位') +
              '</button>' +
            '</div>' +
          '</div>' +
          ownedSection +
        '</div>' +
        details +
      '</div>'
    );
  }

  function paint() {
    if (STATE.fetching && !STATE.policy) {
      anchor.innerHTML = tplLoading();
      return;
    }
    if (!STATE.policy) {
      anchor.innerHTML = tplGuestOrError();
      return;
    }
    anchor.innerHTML = tplCard({ policy: STATE.policy, mine: STATE.mine });
  }

  /* ======== Data fetch ======== */
  function fetchAll(force) {
    var token = getToken();
    if (!token) return;
    var now = Date.now();
    if (!force && now - STATE.lastFetch < 60000 && STATE.policy) return;
    STATE.fetching = true;
    STATE.lastFetch = now;
    paint();

    var headers = { 'Authorization': authHeader(token), 'Accept': 'application/json' };
    var p1 = fetch('/api/v1/user/invite-alias/policy', { headers: headers, credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : null; });
    var p2 = fetch('/api/v1/user/invite-alias/mine', { headers: headers, credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : null; });

    Promise.all([p1, p2]).then(function (arr) {
      STATE.policy = arr[0] && arr[0].data ? arr[0].data : null;
      STATE.mine = arr[1] && arr[1].data ? (Array.isArray(arr[1].data) ? arr[1].data : []) : [];
      STATE.fetching = false;
      paint();
    }).catch(function () {
      STATE.fetching = false;
      STATE.policy = null;
      paint();
    });
  }

  /* ======== Routing visibility ======== */
  function isInvitePage() {
    var h = (location.hash || '').toLowerCase();
    var p = (location.pathname || '').toLowerCase();
    return /\binvite|\breferral|\bpromote|\baff/.test(h) || /\binvite|\breferral|\bpromote|\baff/.test(p);
  }

  function syncVisibility() {
    ensureAnchorMounted();
    if (isInvitePage() && getToken()) {
      anchor.style.display = 'block';
      fetchAll();
    } else {
      anchor.style.display = 'none';
    }
  }

  window.addEventListener('hashchange', syncVisibility);
  window.addEventListener('popstate', syncVisibility);
  ['pushState', 'replaceState'].forEach(function (m) {
    var orig = history[m];
    history[m] = function () {
      var r = orig.apply(this, arguments);
      setTimeout(syncVisibility, 0);
      return r;
    };
  });

  setInterval(function () {
    ensureAnchorMounted();
    syncVisibility();
  }, 2000);

  function start() {
    if (!document.body) return setTimeout(start, 200);
    ensureAnchorMounted();
    syncVisibility();
  }
  start();
})();
