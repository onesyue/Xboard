/* Yue.to 悦通面板客服小助手 v1.0 — XBoard 专版（Bearer Auth + /api/xboard/ai）
 * 部署路径：storage/theme/LiquidGlass/assets/chat-widget-panel.js
 * API 端点：https://yue.yuebao.website/api/xboard/ai（绝对 URL，跨域）
 */
(function () {
  'use strict';

  var XBOARD_AI_API = 'https://yue.yuebao.website/api/xboard/ai';
  var TG = 'https://t.me/yue_to';

  // 读取 XBoard Bearer token（多个 key 兜底）
  var _authToken = '';
  try {
    var raw = localStorage.getItem('token') ||
              localStorage.getItem('auth_token') ||
              localStorage.getItem('auth_data') || '';
    if (raw) {
      try { raw = JSON.parse(raw).token || raw; } catch(e) {}
      _authToken = raw.startsWith('Bearer ') ? raw : 'Bearer ' + raw;
    }
  } catch(e) {}

  // ── 主题（跟随 XBoard 暗色模式）──────────────────────────────────────────
  function isDark() {
    return document.documentElement.classList.contains('dark') ||
      document.body.classList.contains('dark') ||
      localStorage.getItem('theme') === 'dark' ||
      (localStorage.getItem('theme') !== 'light' &&
        window.matchMedia('(prefers-color-scheme: dark)').matches);
  }

  var C = {
    get bg()         { return isDark() ? '#18181b' : '#ffffff'; },
    get border()     { return isDark() ? '#3f3f46' : '#e5e5e5'; },
    get msgsBg()     { return isDark() ? '#18181b' : '#fafafa'; },
    get botBubble()  { return isDark() ? '#27272a' : '#ffffff'; },
    get botText()    { return isDark() ? '#f4f4f5' : '#171717'; },
    get ftBg()       { return isDark() ? '#1c1c1f' : '#ffffff'; },
    get inputBg()    { return isDark() ? '#27272a' : '#f5f5f5'; },
    get inputText()  { return isDark() ? '#f4f4f5' : '#171717'; },
    get inputBorder(){ return isDark() ? '#3f3f46' : '#e5e5e5'; },
    get quickBg()    { return isDark() ? '#1e293b' : '#eff6ff'; },
    get quickText()  { return isDark() ? '#93c5fd' : '#3291ff'; },
    get quickBorder(){ return isDark() ? '#1e40af' : '#bfdbfe'; },
    get hintColor()  { return isDark() ? '#71717a' : '#737373'; },
  };

  // ── 全局动画 CSS ──────────────────────────────────────────────────────────
  var styleTag = document.createElement('style');
  styleTag.textContent =
    '@keyframes yueBounce{0%,60%,100%{transform:translateY(0)}30%{transform:translateY(-6px)}}' +
    '@keyframes yueSlideIn{from{opacity:0;transform:scale(.92) translateY(16px)}to{opacity:1;transform:scale(1) translateY(0)}}' +
    '#yue-panel-btn:hover{transform:scale(1.08)!important;box-shadow:0 6px 28px rgba(50,145,255,.6)!important;}' +
    '#yue-panel-btn:active{transform:scale(.96)!important;}' +
    '.yue-p-typing-dot{width:7px;height:7px;border-radius:50%;background:#9ca3af;display:inline-block;animation:yueBounce .9s infinite;}' +
    '.yue-p-bot-msg a{color:#3291ff;text-decoration:underline;}' +
    '.yue-p-bot-msg b{font-weight:600;}' +
    '.yue-p-quick-chip:hover{opacity:.8;}' +
    '.yue-p-close-x:hover{background:rgba(255,255,255,.35)!important;}' +
    '.yue-p-send-btn:hover{background:#1d6feb!important;}' +
    '#yue-panel-msgs::-webkit-scrollbar{width:4px;}' +
    '#yue-panel-msgs::-webkit-scrollbar-thumb{background:rgba(128,128,128,.3);border-radius:2px;}' +
    '@media(max-width:420px){#yue-panel-win{width:calc(100vw - 24px)!important;right:12px!important;bottom:84px!important;}#yue-panel-btn{bottom:20px!important;right:16px!important;}}';
  document.head.appendChild(styleTag);

  // ── 悬浮按钮 ──────────────────────────────────────────────────────────────
  var btn = document.createElement('button');
  btn.id = 'yue-panel-btn';
  btn.setAttribute('aria-label', '在线客服');
  btn.setAttribute('type', 'button');
  btn.style.cssText = [
    'position:fixed', 'bottom:28px', 'right:28px', 'z-index:2147483647',
    'width:52px', 'height:52px', 'border-radius:50%',
    'background:linear-gradient(135deg,#3291ff,#1d6feb)',
    'box-shadow:0 4px 20px rgba(50,145,255,.45)',
    'border:none', 'cursor:pointer',
    'display:flex', 'align-items:center', 'justify-content:center',
    'transition:transform .2s,box-shadow .2s',
    'outline:none', 'padding:0', 'margin:0',
  ].join(';');
  btn.innerHTML =
    '<svg width="26" height="26" viewBox="0 0 24 24" fill="#fff">' +
    '<path d="M12 2C6.477 2 2 6.27 2 11.5c0 2.28.82 4.37 2.17 6.01L3 21l3.61-1.2A10.07 10.07 0 0 0 12 21c5.523 0 10-4.27 10-9.5S17.523 2 12 2zm0 17a8.08 8.08 0 0 1-3.94-1.02l-.28-.17-2.9.97.93-2.83-.19-.29A7.44 7.44 0 0 1 4 11.5C4 7.36 7.58 4 12 4s8 3.36 8 7.5-3.58 7.5-8 7.5z"/>' +
    '</svg>';

  // ── 聊天窗口 ──────────────────────────────────────────────────────────────
  var win = document.createElement('div');
  win.id = 'yue-panel-win';

  // 头部
  var hd = document.createElement('div');
  hd.style.cssText = 'background:linear-gradient(135deg,#3291ff,#1d6feb);color:#fff;padding:12px 16px;display:flex;align-items:center;gap:10px;flex-shrink:0;';
  hd.innerHTML =
    '<div style="width:32px;height:32px;border-radius:50%;background:rgba(255,255,255,.25);display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0;">🤖</div>' +
    '<div style="flex:1;"><div style="font-size:14px;font-weight:600;">悦通小助手</div><div style="font-size:11px;opacity:.8;margin-top:1px;">已接入账号信息</div></div>';

  var closeBtn = document.createElement('button');
  closeBtn.className = 'yue-p-close-x';
  closeBtn.setAttribute('type', 'button');
  closeBtn.setAttribute('aria-label', '关闭');
  closeBtn.style.cssText = 'background:rgba(255,255,255,.2);border:none;cursor:pointer;width:26px;height:26px;border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;flex-shrink:0;transition:background .15s;padding:0;';
  closeBtn.innerHTML = '<svg width="11" height="11" viewBox="0 0 14 14" fill="none" stroke="white" stroke-width="2.2" stroke-linecap="round"><line x1="1" y1="1" x2="13" y2="13"/><line x1="13" y1="1" x2="1" y2="13"/></svg>';
  hd.appendChild(closeBtn);

  // 消息区
  var msgs = document.createElement('div');
  msgs.id = 'yue-panel-msgs';
  msgs.style.cssText = 'flex:1;overflow-y:auto;padding:12px;display:flex;flex-direction:column;gap:10px;scroll-behavior:smooth;';

  // 快捷问题按钮
  var quickWrap = document.createElement('div');
  quickWrap.style.cssText = 'display:flex;flex-wrap:wrap;gap:6px;padding:4px 12px 8px;flex-shrink:0;';
  var quickBtns = [
    { q: '查询流量', label: '📊 查流量' },
    { q: '连不上怎么办', label: '🔧 连不上' },
    { q: '速度慢怎么办', label: '⚡ 速度慢' },
    { q: '如何续费', label: '💰 续费' },
    { q: '客户端怎么下载', label: '📦 下载客户端' },
  ];
  quickBtns.forEach(function(item) {
    var qb = document.createElement('button');
    qb.className = 'yue-p-quick-chip';
    qb.setAttribute('type', 'button');
    qb.setAttribute('data-q', item.q);
    qb.textContent = item.label;
    qb.style.cssText = 'border:none;cursor:pointer;padding:5px 10px;border-radius:20px;font-size:12px;white-space:nowrap;transition:opacity .15s;';
    quickWrap.appendChild(qb);
  });

  // 底部输入区
  var ft = document.createElement('div');
  ft.style.cssText = 'padding:10px 12px;flex-shrink:0;border-top-width:1px;border-top-style:solid;';

  var inputRow = document.createElement('div');
  inputRow.style.cssText = 'display:flex;gap:8px;';

  var input = document.createElement('input');
  input.id = 'yue-panel-input';
  input.type = 'text';
  input.placeholder = '输入问题，按回车发送…';
  input.maxLength = 200;
  input.autocomplete = 'off';
  input.style.cssText = 'flex:1;border-radius:22px;padding:9px 14px;font-size:13px;outline:none;border-width:1px;border-style:solid;font-family:inherit;height:36px;box-sizing:border-box;';

  var sendBtn = document.createElement('button');
  sendBtn.className = 'yue-p-send-btn';
  sendBtn.setAttribute('type', 'button');
  sendBtn.setAttribute('aria-label', '发送');
  sendBtn.style.cssText = 'border:none;cursor:pointer;width:36px;height:36px;border-radius:50%;background:#3291ff;display:flex;align-items:center;justify-content:center;transition:background .15s;flex-shrink:0;padding:0;';
  sendBtn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="#fff"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg>';

  inputRow.appendChild(input);
  inputRow.appendChild(sendBtn);

  var hint = document.createElement('div');
  hint.style.cssText = 'font-size:11px;text-align:center;margin-top:6px;';
  hint.innerHTML = '直接输入问题，小悦帮你解答';

  ft.appendChild(inputRow);
  ft.appendChild(hint);

  win.appendChild(hd);
  win.appendChild(msgs);
  win.appendChild(quickWrap);
  win.appendChild(ft);

  win.style.cssText = [
    'position:fixed', 'bottom:90px', 'right:24px', 'z-index:2147483646',
    'width:320px', 'height:460px', 'max-height:calc(100vh - 120px)',
    'border-radius:14px', 'overflow:hidden',
    'display:none',
    'flex-direction:column',
    'box-shadow:0 12px 48px rgba(0,0,0,.2)',
  ].join(';');

  // ── 主题应用 ──────────────────────────────────────────────────────────────
  function applyTheme() {
    win.style.background = C.bg;
    win.style.border = '1px solid ' + C.border;
    msgs.style.background = C.msgsBg;
    ft.style.background = C.ftBg;
    ft.style.borderTopColor = C.border;
    input.style.background = C.inputBg;
    input.style.color = C.inputText;
    input.style.borderColor = C.inputBorder;
    hint.style.color = C.hintColor;
    quickWrap.querySelectorAll('.yue-p-quick-chip').forEach(function(qb) {
      qb.style.background = C.quickBg;
      qb.style.color = C.quickText;
      qb.style.border = '1px solid ' + C.quickBorder;
    });
    msgs.querySelectorAll('.yue-p-bot-msg').forEach(function(el) {
      el.style.background = C.botBubble;
      el.style.color = C.botText;
      el.style.border = '1px solid ' + C.border;
    });
  }

  new MutationObserver(applyTheme).observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
  new MutationObserver(applyTheme).observe(document.body, { attributes: true, attributeFilter: ['class'] });
  window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', applyTheme);

  // ── 添加消息 ───────────────────────────────────────────────────────────────
  var firstOpen = true;

  function addMsg(html, isUser) {
    var row = document.createElement('div');
    row.style.cssText = 'display:flex;align-items:flex-end;gap:8px;' + (isUser ? 'flex-direction:row-reverse;' : '');
    if (!isUser) {
      var av = document.createElement('div');
      av.style.cssText = 'width:26px;height:26px;border-radius:50%;background:#3291ff;display:flex;align-items:center;justify-content:center;font-size:13px;flex-shrink:0;';
      av.textContent = '🤖';
      row.appendChild(av);
    }
    var bubble = document.createElement('div');
    bubble.style.cssText = 'max-width:80%;padding:8px 11px;font-size:13px;line-height:1.55;word-break:break-word;' +
      (isUser
        ? 'border-radius:14px 4px 14px 14px;background:#3291ff;color:#fff;'
        : 'border-radius:4px 14px 14px 14px;');
    if (isUser) {
      bubble.textContent = html;
    } else {
      bubble.className = 'yue-p-bot-msg';
      bubble.style.background = C.botBubble;
      bubble.style.color = C.botText;
      bubble.style.border = '1px solid ' + C.border;
      bubble.innerHTML = html;
      bubble.querySelectorAll('a').forEach(function(a) {
        a.setAttribute('target', '_blank');
        a.setAttribute('rel', 'noopener');
      });
    }
    row.appendChild(bubble);
    msgs.appendChild(row);
    msgs.scrollTop = msgs.scrollHeight;
    return bubble;
  }

  function addTyping() {
    var row = document.createElement('div');
    row.style.cssText = 'display:flex;align-items:flex-end;gap:8px;';
    var av = document.createElement('div');
    av.style.cssText = 'width:26px;height:26px;border-radius:50%;background:#3291ff;display:flex;align-items:center;justify-content:center;font-size:13px;flex-shrink:0;';
    av.textContent = '🤖';
    var typing = document.createElement('div');
    typing.style.cssText = 'padding:9px 13px;display:flex;gap:4px;align-items:center;background:' + C.botBubble + ';border:1px solid ' + C.border + ';border-radius:4px 14px 14px 14px;';
    for (var i = 0; i < 3; i++) {
      var dot = document.createElement('span');
      dot.className = 'yue-p-typing-dot';
      dot.style.animationDelay = (i * 0.15) + 's';
      typing.appendChild(dot);
    }
    row.appendChild(av);
    row.appendChild(typing);
    msgs.appendChild(row);
    msgs.scrollTop = msgs.scrollHeight;
    return row;
  }

  function removeEl(el) { el && el.parentNode && el.parentNode.removeChild(el); }

  // ── 问答（同步 POST，非流式）─────────────────────────────────────────────
  var pending = false;
  var chatHistory = [];
  var sessionId = 'panel_' + Math.random().toString(36).slice(2, 10);

  function ask(text) {
    text = text.trim();
    if (pending || !text) return;
    pending = true;
    addMsg(text, true);
    quickWrap.style.display = 'none';
    var typingEl = addTyping();

    // 重新读取 token（面板登录后可能更新）
    try {
      var raw = localStorage.getItem('token') ||
                localStorage.getItem('auth_token') ||
                localStorage.getItem('auth_data') || '';
      if (raw) {
        try { raw = JSON.parse(raw).token || raw; } catch(e) {}
        _authToken = raw.startsWith('Bearer ') ? raw : 'Bearer ' + raw;
      }
    } catch(e) {}

    var hdrs = { 'Content-Type': 'application/json' };
    if (_authToken) hdrs['Authorization'] = _authToken;

    fetch(XBOARD_AI_API, {
      method: 'POST',
      headers: hdrs,
      body: JSON.stringify({
        q: text.slice(0, 200),
        session_id: sessionId,
        history: chatHistory.slice(-12),
      }),
    })
    .then(function(resp) { return resp.json(); })
    .then(function(data) {
      removeEl(typingEl);
      var answer = (data && data.data && data.data.answer) ? data.data.answer : null;
      if (!answer) {
        answer = data && data.detail
          ? ('⚠️ ' + data.detail)
          : '😅 暂时无法回答，换个方式问试试～';
      }
      var bubble = addMsg(answer, false);
      chatHistory.push({ role: 'user', content: text });
      chatHistory.push({ role: 'assistant', content: answer.replace(/<[^>]+>/g, '').slice(0, 200) });
      if (chatHistory.length > 24) chatHistory = chatHistory.slice(-20);
      pending = false;
    })
    .catch(function() {
      removeEl(typingEl);
      addMsg('网络请求失败，请稍后重试或<a href="' + TG + '">联系客服</a>。', false);
      pending = false;
    });
  }

  // ── 开关逻辑 ──────────────────────────────────────────────────────────────
  var isOpen = false;

  function openChat() {
    isOpen = true;
    applyTheme();
    win.style.display = 'flex';
    win.style.animation = 'none';
    void win.offsetWidth;
    win.style.animation = 'yueSlideIn .22s cubic-bezier(.34,1.56,.64,1) forwards';
    if (firstOpen) {
      firstOpen = false;
      var greeting = _authToken
        ? 'Hey 👋 我是小悦，已识别你的账号\n套餐、流量、订阅、节点有问题直接问'
        : 'Hey 👋 我是小悦\n登录后能帮你查套餐和流量，或者直接问也行';
      addMsg(greeting, false);
    }
    setTimeout(function() { input.focus(); }, 50);
  }

  function closeChat() {
    isOpen = false;
    win.style.display = 'none';
    win.style.animation = 'none';
  }

  // ── 事件绑定 ──────────────────────────────────────────────────────────────
  btn.addEventListener('click', function(e) {
    e.stopPropagation();
    isOpen ? closeChat() : openChat();
  });
  closeBtn.addEventListener('click', function(e) {
    e.stopPropagation();
    closeChat();
  });
  quickWrap.addEventListener('click', function(e) {
    var qb = e.target.closest('[data-q]');
    if (qb) ask(qb.getAttribute('data-q'));
  });
  sendBtn.addEventListener('click', function() {
    var v = input.value.trim();
    if (v) { input.value = ''; ask(v); }
  });
  input.addEventListener('keydown', function(e) {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      var v = input.value.trim();
      if (v) { input.value = ''; ask(v); }
    }
  });
  document.addEventListener('click', function(e) {
    if (isOpen && !win.contains(e.target) && !btn.contains(e.target)) closeChat();
  });
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && isOpen) closeChat();
  });

  // ── 挂载 ──────────────────────────────────────────────────────────────────
  document.body.appendChild(btn);
  document.body.appendChild(win);
  applyTheme();

  console.log('[悦通面板助手] v1.0 已加载 → ' + XBOARD_AI_API);
})();
