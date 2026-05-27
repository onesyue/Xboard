# InviteAlias Changelog

## v1.0.0 (2026-05-04) — 骨架 + DB + 验证器

### Plugin 骨架
- `Plugin.php` — AbstractPlugin 入口，注册 ResolveAliasMiddleware + 4 个 cron 任务
- `config.json` — 25 项可调配置（价格/门槛/AI/生命周期/cookie/...）

### 数据库
- `v2_invite_alias` 主表 —— 含状态机、生命周期、风控、转让预留字段
- `v2_invite_alias_event` 事件表 —— 点击/转化追踪，BRIN 索引节省空间
- PG partial unique index `uq_alias_active_zone` 防并发抢注

### 自助审核（六层防线）
- `Services/AliasValidator.php` —— L1 格式 + L2 黑名单 + L3 同形 + L4 AI + L5 风控
- `Services/reserved-names.json` —— 1172 词（jedireza 975 + 自家 197）+ 19 regex
  - 分类：yue_brand / sensitive / official_sub / brand / crypto_phishing / authority / common_trap / community

### 解析层
- `Services/AliasResolver.php` —— Host → user_id 解析，5min Redis cache
- `Http/Middleware/ResolveAliasMiddleware.php` —— 子域 → cookie 中间件，支持 i.yue.to + yue.to

### 路由
- `routes/api.php` —— 用户端 7 个 endpoint + 管理端 7 个 endpoint + 内部 1 个

### 待补完（P1 收尾）
- `Controllers/User/AliasController.php` (policy/precheck/redeem-pending/confirm/release-pending/appeal/internalResolve)
- `Controllers/Admin/AliasController.php` (list/stats/detail/ban/unban/forceDormant/refund)
- `Jobs/RecordAliasClickJob.php`
- `Commands/LifecycleTickCommand.php` (active → dormant → released)
- `Commands/SafeBrowsingScanCommand.php`
- `Commands/CtLogMonitorCommand.php`
- `Commands/CleanupPendingCommand.php`
- `assets/invite-alias-widget.js` (用户中心嵌入式卡片)

### P0 待执行（运维侧）
- CF DNS 加 `i.yue.to` A + `*.i.yue.to` A → 66.55.76.208 (proxied)
- 签 `*.i.yue.to` 证书（推荐 acme.sh + DNS-01，不依赖 Origin CA Key）
- nginx 加 server block

### P2 待开发（TG bot 侧）
- `telegram-bot/yue/service/invite_alias.py` —— ConversationHandler + gambling_exchange 双行
- `/兑换` 菜单加三档入口
