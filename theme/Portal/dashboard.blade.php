<!doctype html>
<html lang="zh-CN">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,minimum-scale=1,user-scalable=no" />
  <meta http-equiv="Content-Security-Policy" content="script-src 'self' 'unsafe-inline' 'unsafe-eval' https://challenges.cloudflare.com https://static.cloudflareinsights.com https://yue.yuebao.website; frame-src 'self' https://challenges.cloudflare.com; connect-src 'self' https://challenges.cloudflare.com https://cloudflareinsights.com https://*.cloudflareinsights.com https://yue.yuebao.website; object-src 'none'; base-uri 'self'; form-action 'self'" />
  <meta name="robots" content="noindex,nofollow,noarchive" />
  <meta name="referrer" content="same-origin" />
  <meta name="description" content="{{ admin_setting('app_description', '专为 AI 与流媒体打造的全球加速网络') }}" />
  <link rel="icon" href="/favicon.ico" />
  <title>{{ admin_setting('app_name') ?: '控制台' }}</title>
  <script>
    if (!window.location.hash) {
      window.location.replace(window.location.pathname + window.location.search + '#/login');
    }
  </script>
  <style id="portal-auth-contrast-fix">
    :root {
      --portal-auth-agree-text: #000000;
      --portal-auth-checkbox-bg: #ffffff;
      --portal-auth-checkbox-fg: #000000;
    }

    @media (prefers-color-scheme: dark) {
      :root {
        --portal-auth-agree-text: #ffffff;
        --portal-auth-checkbox-bg: #000000;
        --portal-auth-checkbox-fg: #ffffff;
      }
    }

    html.dark,
    body.dark,
    [data-theme='dark'],
    .dark {
      --portal-auth-agree-text: #ffffff;
      --portal-auth-checkbox-bg: #000000;
      --portal-auth-checkbox-fg: #ffffff;
    }

    #app .n-checkbox {
      --n-color: var(--portal-auth-checkbox-bg) !important;
      --n-color-hover: var(--portal-auth-checkbox-bg) !important;
      --n-color-focus: var(--portal-auth-checkbox-bg) !important;
      --n-color-pressed: var(--portal-auth-checkbox-bg) !important;
      --n-color-checked: var(--portal-auth-checkbox-fg) !important;
      --n-color-checked-hover: var(--portal-auth-checkbox-fg) !important;
      --n-color-checked-pressed: var(--portal-auth-checkbox-fg) !important;
      --n-border: 1px solid var(--portal-auth-checkbox-fg) !important;
      --n-border-hover: 1px solid var(--portal-auth-checkbox-fg) !important;
      --n-border-focus: 1px solid var(--portal-auth-checkbox-fg) !important;
      --n-border-pressed: 1px solid var(--portal-auth-checkbox-fg) !important;
      --n-border-checked: 1px solid var(--portal-auth-checkbox-fg) !important;
      --n-check-mark-color: var(--portal-auth-checkbox-bg) !important;
      opacity: 1 !important;
    }

    #app .n-checkbox .n-checkbox-box {
      background: var(--portal-auth-checkbox-bg) !important;
      box-shadow: 0 0 0 0.5px var(--portal-auth-checkbox-fg) !important;
      opacity: 1 !important;
    }

    #app .n-checkbox .n-checkbox-box .n-checkbox-box__border {
      border: 1px solid var(--portal-auth-checkbox-fg) !important;
      opacity: 1 !important;
    }

    #app .n-checkbox.n-checkbox--checked .n-checkbox-box {
      background: var(--portal-auth-checkbox-fg) !important;
      box-shadow: 0 0 0 0.5px var(--portal-auth-checkbox-fg) !important;
    }

    #app .n-checkbox.n-checkbox--checked .n-checkbox-box .n-checkbox-box__border {
      border-color: var(--portal-auth-checkbox-fg) !important;
    }

    #app .n-checkbox.n-checkbox--checked .n-checkbox-box svg,
    #app .n-checkbox.n-checkbox--checked .n-checkbox-box path {
      color: var(--portal-auth-checkbox-bg) !important;
      fill: var(--portal-auth-checkbox-bg) !important;
      stroke: var(--portal-auth-checkbox-bg) !important;
    }

    #app .n-checkbox .n-checkbox__label {
      color: var(--portal-auth-agree-text) !important;
      font-weight: 500 !important;
      opacity: 1 !important;
      text-shadow: none !important;
    }

    #app .n-checkbox .n-checkbox__label a,
    #app .n-checkbox .n-checkbox__label a.text-blue-500 {
      color: var(--portal-auth-agree-text) !important;
      font-weight: 600 !important;
      opacity: 1 !important;
      text-decoration-color: currentColor !important;
      text-decoration-line: underline !important;
    }

    /* hide NaiveUI top loading bar (登录页顶部黑线) */
    .n-loading-bar-container,
    .n-loading-bar-container .n-loading-bar {
      display: none !important;
    }
  </style>
  <script type="module" crossorigin src="/assets/u/app-core.js?v={{ $version }}"></script>
</head>

<body>
  <script>
    window.routerBase = '{{ $theme_config['api_url'] ?? '/' }}';
    window.settings = {
      title: @json(admin_setting('app_name', '')),
      assets_path: '/assets/u',
      theme: {
        color: '{{ $theme_config['theme_color'] ?? 'default' }}',
      },
      version: '{{ $version }}',
      background_url: '{{ $theme_config['background_url'] }}',
      description: @json(admin_setting('app_description', '')),
      i18n: ['zh-CN', 'en-US'],
      logo: '{{ $logo ?: '/favicon.ico' }}',
      show_payment_title: '{{ $theme_config['show_payment_title'] ?? 'false' }}',
      hide_gift_card_menu: '{{ $theme_config['hide_gift_card_menu'] ?? 'false' }}',
    }
  </script>
  <script>
    window.__assets_path__ = window.settings?.assets_path || '/';
    __webpack_public_path__ = window.__assets_path__ + 'assets/';
  </script>
  <div id="app"></div>
  <script>
    (function () {
      function isDarkMode() {
        var root = document.documentElement;
        var body = document.body;
        return root.classList.contains('dark') ||
          body.classList.contains('dark') ||
          root.getAttribute('data-theme') === 'dark' ||
          body.getAttribute('data-theme') === 'dark' ||
          (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
      }

      function paintCheckbox(checkbox) {
        var dark = isDarkMode();
        var fg = dark ? '#ffffff' : '#000000';
        var bg = dark ? '#000000' : '#ffffff';
        var label = checkbox.querySelector('.n-checkbox__label');
        var text = label ? label.textContent : checkbox.textContent;
        if (!/服务条款|Terms of Service|Terms/.test(text || '')) return;

        var checked = checkbox.classList.contains('n-checkbox--checked') ||
          !!checkbox.querySelector('input:checked');
        var box = checkbox.querySelector('.n-checkbox-box');
        var border = checkbox.querySelector('.n-checkbox-box__border');
        var markNodes = checkbox.querySelectorAll('svg, path, .n-checkbox-box__check');

        checkbox.style.setProperty('--n-color', bg, 'important');
        checkbox.style.setProperty('--n-color-hover', bg, 'important');
        checkbox.style.setProperty('--n-color-focus', bg, 'important');
        checkbox.style.setProperty('--n-color-pressed', bg, 'important');
        checkbox.style.setProperty('--n-color-checked', fg, 'important');
        checkbox.style.setProperty('--n-color-checked-hover', fg, 'important');
        checkbox.style.setProperty('--n-color-checked-pressed', fg, 'important');
        checkbox.style.setProperty('--n-border', '1px solid ' + fg, 'important');
        checkbox.style.setProperty('--n-border-hover', '1px solid ' + fg, 'important');
        checkbox.style.setProperty('--n-border-focus', '1px solid ' + fg, 'important');
        checkbox.style.setProperty('--n-border-pressed', '1px solid ' + fg, 'important');
        checkbox.style.setProperty('--n-border-checked', '1px solid ' + fg, 'important');
        checkbox.style.setProperty('--n-check-mark-color', bg, 'important');
        checkbox.style.setProperty('opacity', '1', 'important');

        if (box) {
          box.style.setProperty('background', checked ? fg : bg, 'important');
          box.style.setProperty('box-shadow', '0 0 0 0.5px ' + fg, 'important');
          box.style.setProperty('opacity', '1', 'important');
        }
        if (border) {
          border.style.setProperty('border', '1px solid ' + fg, 'important');
          border.style.setProperty('opacity', '1', 'important');
        }
        markNodes.forEach(function (node) {
          node.style.setProperty('color', bg, 'important');
          node.style.setProperty('fill', bg, 'important');
          node.style.setProperty('stroke', bg, 'important');
          node.style.setProperty('opacity', '1', 'important');
        });
        if (label) {
          label.style.setProperty('color', fg, 'important');
          label.style.setProperty('font-weight', '500', 'important');
          label.style.setProperty('opacity', '1', 'important');
          label.querySelectorAll('*').forEach(function (node) {
            node.style.setProperty('color', fg, 'important');
            node.style.setProperty('font-weight', '600', 'important');
            node.style.setProperty('opacity', '1', 'important');
          });
        }
      }

      function applyAuthContrast() {
        document.querySelectorAll('#app .n-checkbox').forEach(paintCheckbox);
      }

      document.addEventListener('click', function () {
        setTimeout(applyAuthContrast, 0);
        setTimeout(applyAuthContrast, 80);
      }, true);
      window.addEventListener('hashchange', function () {
        setTimeout(applyAuthContrast, 120);
        setTimeout(applyAuthContrast, 600);
      });
      document.addEventListener('DOMContentLoaded', applyAuthContrast);
      setTimeout(applyAuthContrast, 300);
      setTimeout(applyAuthContrast, 1000);
      setTimeout(applyAuthContrast, 2500);

      if (window.MutationObserver) {
        new MutationObserver(applyAuthContrast).observe(document.documentElement, {
          childList: true,
          subtree: true,
          attributes: true,
          attributeFilter: ['class', 'data-theme']
        });
      }
    })();
  </script>
  <script src="/assets/u/ux-state.js?v={{ $version }}"></script>
  {!! $theme_config['custom_html'] ?? '' !!}
</body>

</html>
