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
