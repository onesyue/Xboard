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
