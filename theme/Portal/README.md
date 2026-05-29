# Portal —— YueOps 去特征主题（baked into image）

这是 YueLink 面板对外主题，由第三方 Vue3 + NaiveUI 编译产物（LiquidGlass 系）+ YueOps 自有去特征外壳组成。
**烘焙进镜像**（不再靠运行时 `harden-xboard-portal-theme.sh` 打补丁）。`current_theme=Portal`（DB 设置，持久）。

## 文件构成

| 路径 | 作用 |
|------|------|
| `theme/Portal/dashboard.blade.php` | SPA 外壳（去特征 CSP / 中性 title / 登录页对比度修复 / 隐藏 loading 黑线）。动态值走 Blade 变量。 |
| `theme/Portal/config.json` | 主题设置项（主题色/标题/背景/页脚 HTML 等），面板"主题设置"读它。 |
| `theme/Portal/index.html` | 直接访问 fallback（正常入口是 controller 渲染的 blade）。 |
| `theme/Portal/widgets/*.js` | 4 个插件 widget 源（与各插件同步的副本，供 `build-ux-state.sh` 拼接）。 |
| `theme/Portal/build-ux-state.sh` | 把 widgets 拼成 `ux-state.js`。 |
| `public/assets/u/*` | **实际服务的资源**：`app-core.js`(主包) + 全部 vite chunk + `ux-state.js`(widget) + `chat.js` + `images/`。blade 用中性 `/assets/u/` 路径引用（去特征，不暴露 `/theme/Portal/`）。 |

## 资源服务路径（重要 / 去特征 invariant）

blade 引用 `/assets/u/app-core.js` 与 `/assets/u/ux-state.js`，由 Octane 从镜像内 `/www/public/assets/u/` 直接吐。
**绝不**改成标准主题约定的 `/theme/Portal/assets/`（会重新暴露 Xboard 主题指纹）。
`app-core.js` 内部用相对路径 `import('./<chunk>.js')`，所以全部 chunk 必须与它同目录（即 `public/assets/u/`）。

## 缓存失效

`?v={{ $version }}` —— `$version` = 镜像 `config/app.php` 的版本号，GHA build 时写成 `YYYY.MM.DD-sha`。
每次发版自动 bust，**无需手动改版本号**。改 widget/资源 → push → 新镜像新 version → 客户端自动拉新。

## 维护流程（不再打补丁）

- **改外壳（CSP / title / 登录页 CSS）**：直接编辑 `dashboard.blade.php`，commit + push。
- **改 widget**：改 `widgets/<x>.js`（并同步回对应插件源），跑 `bash build-ux-state.sh`，commit `widgets/*` + `public/assets/u/ux-state.js`，push。
- **升级第三方主题包（LiquidGlass）**：用新编译产物替换 `public/assets/u/` 内除 `ux-state.js` 外的文件，commit + push。
- push master → GHA 自动 build `ghcr.io/onesyue/xboard:latest` + `sha-*`；面板 pin 新 sha → `compose pull && up -d --force-recreate web ws horizon`。
