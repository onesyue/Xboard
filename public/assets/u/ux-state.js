/* === commission-tier-widget.js === */
/* CommissionTier Widget v2.3 — 邀请页上下文等级卡（不抢占全站顶部）
 *
 * 策略：
 *   - anchor div 挂在邀请页内容区 grid 之前，跟随页面滚动
 *   - 仅 location.hash 含 /invite 时显示
 *   - 默认紧凑摘要，完整等级体系按需展开
 *   - inline style，不依赖任何 CSS class（CLAUDE.md 规范）
 */
(function () {
  if (window.__commission_tier_widget__) return;
  window.__commission_tier_widget__ = true;

  // ====== Token 提取 ======
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

  // ====== State ======
  var STATE = {
    tier: null,
    fetching: false,
    lastFetch: 0,
    expanded: localStorage.getItem('commission_tier_expanded') === '1',
  };

  // ====== Helpers ======
  function fmtYuan(cents) {
    if (cents == null) return '—';
    var s = (cents / 100).toFixed(2).replace(/\.?0+$/, '');
    return '¥' + (s || '0');
  }

  function esc(v) {
    return String(v == null ? '' : v)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function safeColor(v) {
    v = String(v || '').trim();
    return /^#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/.test(v) ? v : '#9ca3af';
  }

  // ====== DOM anchor ======
  var anchor = document.createElement('div');
  anchor.id = 'commission-tier-anchor';
  anchor.setAttribute('style',
    'box-sizing:border-box;width:100%;display:none;' +
    'margin:0 0 16px;font-family:-apple-system,system-ui,"PingFang SC",sans-serif;' +
    'pointer-events:auto;'
  );

  anchor.addEventListener('click', function (e) {
    var btn = e.target && e.target.closest && e.target.closest('[data-ct-toggle]');
    if (!btn) return;
    STATE.expanded = !STATE.expanded;
    try { localStorage.setItem('commission_tier_expanded', STATE.expanded ? '1' : '0'); } catch (err) {}
    paint();
  });

  function findInviteMountTarget() {
    var article = document.querySelector('article.flex.flex-col.flex-1.overflow-hidden') || document.querySelector('article');
    if (!article) return null;
    var grid = article.querySelector('div.grid.grid-cols-1.lg\\:grid-cols-3.gap-6');
    if (grid && grid.parentElement) return { parent: grid.parentElement, before: grid };
    var content = article.querySelector('section.flex-1.overflow-y-auto section') || article.querySelector('section.flex-1.overflow-y-auto');
    if (content) return { parent: content, before: content.firstChild };
    return null;
  }

  function ensureAnchorMounted() {
    if (!document.body) return;
    var target = findInviteMountTarget();
    if (target) {
      if (anchor.parentNode !== target.parent || anchor.nextSibling !== target.before) {
        target.parent.insertBefore(anchor, target.before);
      }
      return;
    }
    if (!anchor.parentNode) document.body.appendChild(anchor);
  }

  // ====== Render ======
  function tplLoading() {
    return '<div style="box-sizing:border-box;padding:12px 16px;border-radius:12px;background:rgba(255,255,255,.52);border:1px solid rgba(148,163,184,.18);box-shadow:0 10px 28px rgba(15,23,42,.06);color:#64748b;font-size:13px;">加载返利等级…</div>';
  }

  function tplGuestOrError() {
    return ''; // 未登录或加载失败：完全隐藏，不打扰
  }

  function tplCard(d) {
    var nxt = (d.tiers || []).find(function (t) { return t.level === d.next_level; });
    var color = safeColor(d.color);
    var windowDays = parseInt(d.window_days || 90, 10) || 90;
    var pct = (d.next_threshold && d.next_threshold > 0)
      ? Math.min(100, Math.round(d.current_amount * 100 / d.next_threshold))
      : 100;

    var nextHint = nxt
      ? '距 ' + esc(nxt.name) + (nxt.badge ? ' ' + esc(nxt.badge) : '') + ' 还差 ' + fmtYuan(d.gap_to_next)
      : '已达最高等级';

    var peakTier = (d.tiers || []).find(function (t) { return t.level === d.peak_level; }) || null;
    var peakLabel = d.peak_level > 0
      ? (esc(peakTier && peakTier.name ? peakTier.name : ('VIP' + d.peak_level)) +
          (peakTier && peakTier.badge && peakTier.badge !== '—' ? ' · ' + esc(peakTier.badge) : ''))
      : '未解锁';
    var peakBadge =
      '<span style="padding:2px 8px;background:rgba(124,58,237,.08);color:#7c3aed;border-radius:999px;font-size:11px;font-weight:700;border:1px solid rgba(124,58,237,.14);white-space:nowrap;">永久铭牌 ' + peakLabel + '</span>';

    var tierRail = (d.tiers || []).map(function (t) {
      var here = t.level === d.level;
      var done = t.level <= d.peak_level && t.level > 0;
      return '<span title="' + esc(t.name) + '" style="display:inline-flex;align-items:center;gap:5px;min-width:0;color:' + (here ? '#111827' : '#64748b') + ';font-size:11px;font-weight:' + (here ? '700' : '500') + ';">' +
        '<span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:' + safeColor(t.color) + ';box-shadow:' + (here ? '0 0 0 4px rgba(251,191,36,.16)' : 'none') + ';"></span>' +
        '<span style="white-space:nowrap;">' + esc(t.name) + (here ? ' 当前' : (done ? ' ✓' : '')) + '</span>' +
      '</span>';
    }).join('');

    var tierGrid = (d.tiers || []).map(function (t) {
      var here = t.level === d.level;
      var done = t.level <= d.peak_level && t.level > 0;
      var bg = here ? 'rgba(250,204,21,.12)' : 'rgba(255,255,255,.58)';
      var border = here ? 'rgba(245,158,11,.28)' : 'rgba(148,163,184,.18)';
      return '<div style="box-sizing:border-box;flex:1 1 172px;min-width:150px;padding:9px 11px;border-radius:10px;background:' + bg + ';border:1px solid ' + border + ';display:flex;align-items:center;justify-content:space-between;gap:10px;">' +
        '<div style="display:flex;align-items:center;gap:8px;">' +
          '<span style="display:inline-block;width:9px;height:9px;border-radius:50%;background:' + safeColor(t.color) + ';"></span>' +
          '<span style="font-size:12px;color:#1f2937;font-weight:' + (here ? '700' : '600') + ';white-space:nowrap;">' +
            esc(t.name) + (t.badge && t.badge !== '—' ? ' · ' + esc(t.badge) : '') +
            (here ? ' <span style="color:#d97706;font-size:11px;">当前</span>' : (done ? ' <span style="color:#059669;font-size:11px;">✓</span>' : '')) +
          '</span>' +
        '</div>' +
        '<div style="font-size:11px;color:#64748b;text-align:right;line-height:1.35;white-space:nowrap;">' +
          (t.threshold > 0 ? '<div>' + fmtYuan(t.threshold) + '</div>' : '<div>默认</div>') +
          '<div style="color:#d97706;font-weight:800;">' + t.rate + '%</div>' +
        '</div>' +
      '</div>';
    }).join('');

    var details = STATE.expanded
      ? '<div style="border-top:1px solid rgba(148,163,184,.16);padding:12px 16px 14px;background:rgba(248,250,252,.48);">' +
          '<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:10px;">' +
            '<div style="font-size:12px;color:#64748b;font-weight:700;">完整等级体系</div>' +
            '<div style="font-size:11px;color:#64748b;">点亮即解锁，历史最高铭牌永久保留</div>' +
          '</div>' +
          '<div style="display:flex;flex-wrap:wrap;gap:8px;">' + tierGrid + '</div>' +
	          '<div style="margin-top:10px;font-size:11px;color:#64748b;line-height:1.5;">当前等级/返利率按滚动 ' + windowDays + ' 天邀请成交实时判定 · 历史最高铭牌不影响当前返利率 · 个人专属费率优先</div>' +
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
                '<span style="font-size:11px;color:#64748b;font-weight:700;letter-spacing:.3px;">我的返利等级</span>' +
                '<span style="font-size:16px;font-weight:800;color:' + color + ';line-height:1.2;">' +
                esc(d.name) + (d.badge && d.badge !== '—' ? ' · ' + esc(d.badge) : '') +
                '</span>' + peakBadge +
              '</div>' +
              '<div style="margin-top:8px;height:7px;border-radius:999px;background:rgba(148,163,184,.16);overflow:hidden;">' +
                '<div style="height:100%;width:' + pct + '%;background:linear-gradient(90deg,' + color + ',#f59e0b);border-radius:999px;transition:width .35s ease;"></div>' +
              '</div>' +
              '<div style="display:flex;justify-content:space-between;gap:10px;margin-top:7px;font-size:12px;color:#64748b;flex-wrap:wrap;">' +
                '<span>90 天邀请成交 <b style="color:#1f2937;">' + fmtYuan(d.current_amount) + '</b></span>' +
                '<span style="color:#d97706;font-weight:700;">' + nextHint + '</span>' +
              '</div>' +
            '</div>' +
            '<div style="display:flex;align-items:center;gap:14px;flex:0 0 auto;">' +
              '<div style="text-align:right;">' +
                '<div style="font-size:11px;color:#64748b;font-weight:700;">循环返利</div>' +
                '<div style="font-size:24px;font-weight:900;color:#d97706;line-height:1;">' + d.rate + '%</div>' +
              '</div>' +
              '<button type="button" data-ct-toggle="1" style="height:32px;border:1px solid rgba(148,163,184,.24);background:rgba(255,255,255,.72);color:#334155;border-radius:8px;padding:0 11px;font-size:12px;font-weight:700;cursor:pointer;white-space:nowrap;">' +
                (STATE.expanded ? '收起等级' : '完整等级') +
              '</button>' +
            '</div>' +
          '</div>' +
          '<div style="display:flex;gap:14px;align-items:center;flex-wrap:wrap;margin-top:10px;overflow:hidden;">' +
            tierRail +
          '</div>' +
        '</div>' +
        details +
      '</div>'
    );
  }

  function paint() {
    if (STATE.fetching && !STATE.tier) {
      anchor.innerHTML = tplLoading();
      return;
    }
    if (!STATE.tier || STATE.tier.enabled === false) {
      anchor.innerHTML = tplGuestOrError();
      return;
    }
    anchor.innerHTML = tplCard(STATE.tier);
  }

  // ====== 数据 ======
  function fetchTier(force) {
    var token = getToken();
    if (!token) return;
    var now = Date.now();
    if (!force && now - STATE.lastFetch < 60000 && STATE.tier) return;
    STATE.fetching = true;
    STATE.lastFetch = now;
    paint();
    fetch('/api/v1/user/commission/tier', {
      headers: { 'Authorization': authHeader(token), 'Accept': 'application/json' },
      credentials: 'same-origin',
    })
      .then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); })
      .then(function (j) {
        STATE.tier = j && j.data ? j.data : null;
        STATE.fetching = false;
        paint();
      })
      .catch(function () {
        STATE.fetching = false;
        STATE.tier = null;
        paint();
      });
  }

  // ====== 路由判断 + 显隐 ======
  function isInvitePage() {
    var h = (location.hash || '').toLowerCase();
    var p = (location.pathname || '').toLowerCase();
    return /\binvite|\breferral|\bpromote|\baff/.test(h) || /\binvite|\breferral|\bpromote|\baff/.test(p);
  }

  function syncVisibility() {
    ensureAnchorMounted();
    if (isInvitePage() && getToken()) {
      anchor.style.display = 'block';
      fetchTier();
    } else {
      anchor.style.display = 'none';
    }
  }

  window.addEventListener('hashchange', syncVisibility);
  window.addEventListener('popstate', syncVisibility);
  // SPA 更新 URL 不一定触发 popstate，pushState/replaceState 也要 hook
  ['pushState', 'replaceState'].forEach(function (m) {
    var orig = history[m];
    history[m] = function () {
      var r = orig.apply(this, arguments);
      setTimeout(syncVisibility, 0);
      return r;
    };
  });

  // 周期性兜底（防 Vue 把 anchor 移走或 DOM 重排）
  setInterval(function () {
    ensureAnchorMounted();
    syncVisibility();
  }, 2000);

  // 初始化
  function start() {
    if (!document.body) return setTimeout(start, 200);
    ensureAnchorMounted();
    syncVisibility();
  }
  start();
})();

/* === invite-alias-widget.js === */
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

/* === change-email-widget.js === */
/* ChangeEmail Widget v1.3 — 个人中心「修改登录邮箱」卡（原生级 NaiveUI 适配）
 * v1.3: 挂载改为「修改密码」卡最近的 .n-card 前、同父插入 —— 大小/宽度/滚动
 *       与页面原生卡片对齐（不再climb 4层落进窄容器）。
 * v1.2: 修复填表途中被状态轮询 paint() 清空输入（「还没填完就刷新」）——
 *       fetchStatus 仅在 collapsed 态重渲染。
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
      // 用户正在填表 / 已成功时绝不重渲染——否则 2s 轮询每 4s 一次 paint() 会清空
      // 已输入的新邮箱+验证码，导致「还没填完就刷新、来不及」。仅折叠态随轮询刷新
      // 当前邮箱/冷却提示。
      if (STATE.mode !== 'form' && STATE.mode !== 'success') paint();
    }).catch(function () { STATE.fetching = false; });
  }

  /* ======== Mount target ======== */
  function findMountTarget() {
    var article = document.querySelector('article.flex.flex-col.flex-1.overflow-hidden') ||
                  document.querySelector('article') || document.querySelector('main');
    if (!article) return null;
    // 插在「修改密码」卡（最近的 .n-card）前面 —— 同父、同宽、同一滚动容器，
    // 让本卡大小/滚动与页面原生卡片对齐（参照返利等级/专属链接卡同口径）。
    var nodes = article.querySelectorAll('.n-card,section,div');
    for (var i = 0; i < nodes.length; i++) {
      var t = (nodes[i].textContent || '');
      if (/修改密码|更改密码|重置密码|Change Password/i.test(t) && t.length < 400) {
        var card = (nodes[i].closest && nodes[i].closest('.n-card')) || nodes[i];
        if (card && card.parentElement) return { parent: card.parentElement, before: card };
      }
    }
    // 兜底：主内容 grid 之前 / 滚动区顶部（与 CT/IA 卡一致）
    var grid = article.querySelector('div.grid.grid-cols-1.lg\\:grid-cols-3.gap-6');
    if (grid && grid.parentElement) return { parent: grid.parentElement, before: grid };
    var scroll = article.querySelector('section.flex-1.overflow-y-auto');
    if (scroll) return { parent: scroll, before: scroll.firstChild };
    return { parent: article, before: article.firstChild };
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

/* === yue-online-count-widget.js === */
/* YueOnlineCount Widget v2.0 — 「已连接设备」卡片 (2026-05-22 P1-E)
 *
 * 挂载策略 (与 commission-tier / invite-alias 同款液态玻璃风格):
 *   - 路径: /#/profile 或 /#/user (用户中心)
 *   - 锚点: 紧跟个人资料卡之后，间距 12px
 *   - 数据: GET /api/v1/user/devices
 *   - 操作: POST /api/v1/user/devices/reset-all (踢下线所有设备)
 *
 * 设计原则:
 *   - inline style, 无 CSS class 依赖
 *   - 60s 自动刷新
 *   - 数据陈旧时显示 "0 个设备" 不显示 "你有 X 个幽灵设备"
 *   - 超限时红色提示 + 一键解决按钮
 */
(function () {
  if (window.__yue_online_count_widget__) return;
  window.__yue_online_count_widget__ = true;

  /* ======== Token (与其他 widget 同口径) ======== */
  function readTokenValue(raw) {
    if (!raw) return '';
    var value = raw;
    try {
      var d = JSON.parse(raw);
      if (d && d.expire && d.expire < Date.now()) return '';
      if (typeof d === 'string') value = d;
      else if (d && typeof d === 'object') {
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
      var t = readTokenValue(raw);
      if (t) return t;
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
    data: null,        // { ips, count, limit, over_limit, last_online_at }
    fetching: false,
    lastFetch: 0,
    refreshTimer: null,
    resetting: false,
  };

  function esc(v) {
    return String(v == null ? '' : v).replace(/[&<>"']/g, function (ch) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[ch];
    });
  }

  function fmtTime(epoch) {
    if (!epoch) return '从未上线';
    var ms = Number(epoch) * 1000;
    if (!Number.isFinite(ms)) return '';
    var diff = Date.now() - ms;
    if (diff < 30 * 1000) return '刚刚';
    if (diff < 60 * 60 * 1000) return Math.floor(diff / 60000) + ' 分钟前';
    if (diff < 24 * 60 * 60 * 1000) return Math.floor(diff / 3600000) + ' 小时前';
    return Math.floor(diff / 86400000) + ' 天前';
  }

  /* ======== DOM anchor ======== */
  var anchor = document.createElement('div');
  anchor.id = 'yue-device-anchor';
  anchor.setAttribute('style',
    'box-sizing:border-box;width:100%;display:none;' +
    'margin:0 0 16px;font-family:-apple-system,system-ui,"PingFang SC",sans-serif;' +
    'pointer-events:auto;'
  );

  anchor.addEventListener('click', function (e) {
    var t = e.target;
    if (t && t.closest && t.closest('[data-yd-refresh]')) {
      fetchData(true);
      return;
    }
    if (t && t.closest && t.closest('[data-yd-reset]')) {
      handleReset();
    }
  });

  /* ======== Paint ======== */
  function paint() {
    var d = STATE.data;
    if (!d) {
      anchor.innerHTML = '';
      return;
    }
    var count = Number(d.count || 0);
    var limit = Number(d.limit || 0);
    var over = !!d.over_limit;
    var lastSeen = fmtTime(d.last_online_at);

    var statusColor = over ? '#ef4444'
                    : count >= limit && limit > 0 ? '#f59e0b'
                    : '#22c55e';
    var statusBg = over ? 'rgba(239,68,68,0.10)'
                 : count >= limit && limit > 0 ? 'rgba(245,158,11,0.10)'
                 : 'rgba(34,197,94,0.10)';
    var statusText = over ? '⚠️ 已超出上限'
                   : count === 0 ? '当前无设备在线'
                   : limit > 0 ? count + ' / ' + limit + ' 台'
                   : count + ' 台';

    var ipsHtml = '';
    if (d.ips && d.ips.length) {
      ipsHtml = '<div style="margin-top:10px;font-size:12px;color:#64748b;">' +
        '当前在线 IP：' +
        d.ips.map(function (ip) {
          return '<span style="display:inline-block;margin:2px 4px 2px 0;padding:2px 8px;' +
                 'background:rgba(0,0,0,0.04);border-radius:6px;color:#475569;' +
                 'font-family:ui-monospace,monospace;">' + esc(ip) + '</span>';
        }).join('') +
        '</div>';
    }

    var actionHtml = '';
    if (count > 0) {
      var btnLabel = STATE.resetting ? '处理中…' : '一键踢下线所有设备';
      var btnStyle = 'display:inline-flex;align-items:center;gap:6px;padding:8px 14px;' +
        'background:' + (over ? 'linear-gradient(135deg,#ef4444,#dc2626)' : 'rgba(0,0,0,0.06)') + ';' +
        'color:' + (over ? '#fff' : '#475569') + ';' +
        'border:none;border-radius:10px;font-size:13px;font-weight:500;' +
        'cursor:' + (STATE.resetting ? 'wait' : 'pointer') + ';transition:transform 0.1s;';
      if (STATE.resetting) btnStyle += 'opacity:0.6;pointer-events:none;';
      actionHtml = '<div style="margin-top:12px;">' +
        '<button data-yd-reset type="button" style="' + btnStyle + '">' + btnLabel + '</button>' +
        '<span style="margin-left:10px;font-size:11px;color:#94a3b8;">' +
        '重置会让所有设备订阅失效，需要在每台设备点「更新订阅」' +
        '</span></div>';
    }

    var overWarnHtml = over ?
      '<div style="margin-top:10px;padding:10px 12px;background:rgba(239,68,68,0.08);' +
      'border-left:3px solid #ef4444;border-radius:6px;font-size:12px;color:#991b1b;">' +
      '检测到当前在线设备数 ' + count + ' 超过套餐上限 ' + limit + ' 台。' +
      '24 小时后系统会自动重置订阅 UUID。' +
      '建议立即手动处理（关闭部分设备或点下方按钮）。' +
      '</div>' : '';

    anchor.innerHTML =
      '<div style="background:linear-gradient(135deg,rgba(255,255,255,0.96),rgba(248,250,252,0.96));' +
      'border:1px solid rgba(226,232,240,0.8);border-radius:14px;padding:18px 20px;' +
      'box-shadow:0 1px 3px rgba(0,0,0,0.04),0 4px 16px rgba(0,0,0,0.04);' +
      'backdrop-filter:blur(8px);">' +

      '<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;">' +
      '<div style="display:flex;align-items:center;gap:10px;">' +
      '<div style="width:36px;height:36px;border-radius:10px;background:' + statusBg + ';' +
      'display:flex;align-items:center;justify-content:center;font-size:18px;">📱</div>' +
      '<div>' +
      '<div style="font-size:15px;font-weight:600;color:#1e293b;">已连接设备</div>' +
      '<div style="font-size:12px;color:#64748b;margin-top:2px;">' +
      '最近活动：' + esc(lastSeen) + '</div>' +
      '</div></div>' +

      '<div style="display:flex;align-items:center;gap:8px;">' +
      '<span style="padding:4px 10px;border-radius:8px;background:' + statusBg + ';' +
      'color:' + statusColor + ';font-size:13px;font-weight:600;">' +
      esc(statusText) + '</span>' +
      '<button data-yd-refresh type="button" title="刷新" style="' +
      'background:transparent;border:1px solid rgba(0,0,0,0.08);border-radius:8px;' +
      'width:32px;height:28px;cursor:pointer;color:#64748b;font-size:14px;">⟳</button>' +
      '</div></div>' +

      overWarnHtml +
      ipsHtml +
      actionHtml +

      '</div>';
  }

  /* ======== Fetch ======== */
  function fetchData(force) {
    if (STATE.fetching) return;
    if (!force && (Date.now() - STATE.lastFetch < 30000)) return;
    var token = getToken();
    if (!token) {
      anchor.style.display = 'none';
      return;
    }
    STATE.fetching = true;
    fetch('/api/v1/user/devices', {
      method: 'GET',
      headers: {
        'Authorization': authHeader(token),
        'Accept': 'application/json',
      },
      credentials: 'omit',
    })
      .then(function (r) { return r.json(); })
      .then(function (resp) {
        if (resp && resp.data) {
          STATE.data = resp.data;
          STATE.lastFetch = Date.now();
          paint();
          anchor.style.display = '';
        }
      })
      .catch(function () {})
      .finally(function () { STATE.fetching = false; });
  }

  /* ======== Reset action ======== */
  function handleReset() {
    if (STATE.resetting) return;
    if (!window.confirm('确定要踢下线所有设备吗？\n\n所有设备的订阅链接会立即失效，需要在每台设备点「更新订阅」才能继续使用。')) {
      return;
    }
    var token = getToken();
    if (!token) return;
    STATE.resetting = true;
    paint();

    fetch('/api/v1/user/devices/reset-all', {
      method: 'POST',
      headers: {
        'Authorization': authHeader(token),
        'Accept': 'application/json',
        'Content-Type': 'application/json',
      },
      body: '{}',
      credentials: 'omit',
    })
      .then(function (r) { return r.json(); })
      .then(function (resp) {
        if (resp && resp.data && resp.data.reset) {
          window.alert('✅ 已踢下线所有设备\n\n' + (resp.data.hint || '请在每台设备上更新订阅。'));
          // 等节点 push 反映新状态
          setTimeout(function () { fetchData(true); }, 3000);
        } else if (resp && resp.status === 429) {
          window.alert('⏱ 操作太频繁，请 1 分钟后再试');
        } else if (resp && resp.message) {
          window.alert('操作失败：' + resp.message);
        } else {
          window.alert('操作失败，请稍后再试');
        }
      })
      .catch(function () {
        window.alert('网络错误，请稍后再试');
      })
      .finally(function () {
        STATE.resetting = false;
        paint();
      });
  }

  /* ======== Route binding (挂在 /#/profile or /#/user) ======== */
  function shouldShow() {
    var h = (location.hash || '').toLowerCase();
    return /#\/(profile|user|setting|safety)/.test(h);
  }

  function mount() {
    if (!shouldShow()) {
      anchor.style.display = 'none';
      anchor.remove();
      return;
    }
    if (anchor.parentNode) return;
    // 尝试找用户资料卡 sibling, 否则插到 #app 顶部
    var host = document.querySelector('.ant-card, .profile-card, [class*="profile"], main, .main');
    if (host && host.parentNode) {
      host.parentNode.insertBefore(anchor, host.nextSibling);
    } else {
      var app = document.getElementById('app') || document.body;
      app.insertBefore(anchor, app.firstChild);
    }
    fetchData(true);
  }

  function start() {
    mount();
    if (STATE.refreshTimer) clearInterval(STATE.refreshTimer);
    STATE.refreshTimer = setInterval(function () {
      if (shouldShow()) fetchData(false);
    }, 60000);
  }

  // 监听 hash 变化 (umi router)
  window.addEventListener('hashchange', start);
  // 首次
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
})();

