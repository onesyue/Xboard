# InviteAlias —— 邀请别名插件

> 用户用积分兑换永久专属推广链接，三档定价，全自助审核，对齐 2026 主流。

## 产品形态

| 档位 | 价格 | 形式 | 反 aff 强度 | 当前可买（基于 1686 用户分布）|
|------|------|------|-------------|------------------------------|
| 🥉 银 | **888 积分** | `https://yue.to/?aff=demo` | ★★ | 34 人 (2.0%) |
| 🥈 金 | **1888 积分** | `https://demo.i.yue.to/` 浏览器地址栏不变 | ★★★★ | 16 人 (1.0%) |
| 🥇 铂金 | **8888 积分** | `https://demo.yue.to/` 主域稀缺位 | ★★★★★ | 0 人（北极星）|

价格锚定：1 积分 ≈ ¥0.10 (`gambling/config.py:445`)。

## 核心设计

### 1. 域名空间隔离
- **隔离域 `*.i.yue.to`** —— 主力产品，灰产/钓鱼风险污染不到主品牌
- **主域 `*.yue.to`** —— 北极星稀缺位，需账号年龄≥30 天 + 邀请≥10 人

参考 Vercel `.vercel.app` / Notion `.notion.site` 业界做法。

### 2. 全自助审核（六层防线，零人工，~3 秒激活）

```
L1 格式校验      ~1ms     正则 + 长度 + 纯数字防御
L2 黑名单         ~1ms     1172 词 + 19 regex (jedireza + 自家扩展)
L3 同形检测      ~5ms     Damerau-Levenshtein ≤2 vs 高价值保留词
L4 AI 审核       ~1s      Claude Haiku 4.5 (1888/8888 档启用)
L5 行为风控      ~10ms    IP/账号年龄/邀请数/冷却期
L6 唯一性扣分    ~50ms    SELECT FOR UPDATE 防并发抢注
```

预期通过率 **~72%**（健康区间）。

### 3. 永久制 + 活跃绑定生命周期

```
active → (90d 无活跃订阅) → dormant
       → (再 180d) → released
       → (再 30d 冷却) → 开放申请

违规 ban：零退积分；申诉通过：100% 恢复
```

### 4. 解析层（v1 架构）
- `*.i.yue.to` 子域 → **nginx 302** → `https://yue.to/?invite_code=<sub>&utm_source=alias`
- 配套 `AliasController::confirm` 对所有 alias_type 同步 INSERT v2_invite_code
- XBoard 标准 `RegisterService` 读 `?invite_code=` 完成归属
- **不依赖 Laravel middleware**（Octane 状态隔离让 pushMiddleware 不可靠）
- v2 升级：CF ACM + 修 middleware 后可改回 proxy_pass 保 subdomain UX

### 5. 事后监控
- **每日**：Google Safe Browsing API 扫所有 active alias
- **每小时**：CT log 监控 `*.i.yue.to` 异常证书签发
- **每分钟**：清理 pending TTL 过期未确认 alias
- **实时**：用户 `/report_alias` 举报，3 个独立举报自动 dormant

## 文件结构

```
InviteAlias/
├── Plugin.php                         # AbstractPlugin 入口，注册 middleware + cron
├── config.json                        # 24 项可调配置（价格/门槛/AI/生命周期/cookie/...）
├── README.md                          # 本文档
├── ANNOUNCE.md                        # 升级日志
├── database/migrations/
│   ├── 2026_05_04_000001_create_invite_alias_table.php       # 主表
│   └── 2026_05_04_000002_create_invite_alias_event_table.php # 点击/转化事件
├── Services/
│   ├── AliasValidator.php             # L1-L5 自助审核（核心）
│   ├── AliasResolver.php              # Host → user_id 解析 + Redis cache
│   └── reserved-names.json            # 1172 保留词 + 19 regex（可热更新）
├── Http/Middleware/
│   └── ResolveAliasMiddleware.php     # 子域 → cookie 中间件
├── Controllers/
│   ├── User/AliasController.php       # 用户端（policy/precheck/redeem/confirm/appeal）
│   └── Admin/AliasController.php      # 管理端（list/ban/refund/stats）
├── Commands/                          # cron 任务（lifecycle/safe-browsing/ct-log/cleanup）
├── Jobs/
│   └── RecordAliasClickJob.php        # 异步 click 事件落库
├── routes/api.php
└── assets/                            # 用户中心卡片（嵌入式 widget，仿 CommissionTier）
```

## 部署步骤

### P0 运维（生产侧改动，需手动）

#### 1. CF DNS 加 wildcard A
```bash
# CF zone yue.to: 4fe43e0a247729e98a1f2ff139429886
curl -X POST "https://api.cloudflare.com/client/v4/zones/4fe43e0a247729e98a1f2ff139429886/dns_records" \
  -H "X-Auth-Email: onesyue@gmail.com" \
  -H "X-Auth-Key: <CF_GLOBAL_API_KEY>" \
  -H "Content-Type: application/json" \
  -d '{"type":"A","name":"i","content":"66.55.76.208","ttl":1,"proxied":true}'

curl -X POST "https://api.cloudflare.com/client/v4/zones/4fe43e0a247729e98a1f2ff139429886/dns_records" \
  -H "X-Auth-Email: onesyue@gmail.com" \
  -H "X-Auth-Key: <CF_GLOBAL_API_KEY>" \
  -H "Content-Type: application/json" \
  -d '{"type":"A","name":"*.i","content":"66.55.76.208","ttl":1,"proxied":true}'
```

#### 2. CF Origin Cert 签发 `*.i.yue.to + i.yue.to`
**需要从 CF Dashboard 拿 Origin CA Key**（不是 Global API Key）：
1. 登录 https://dash.cloudflare.com/profile/api-tokens
2. 找 "API Keys" 区，点 "Origin CA Key" 旁的 "View"，复制
3. 用 acme.sh 或 CF API 签发

最简单：用 acme.sh + DNS-01（不需要 Origin CA Key）：
```bash
ssh root@66.55.76.208 << 'EOF'
curl https://get.acme.sh | sh -s email=onesyue@gmail.com
export CF_Email="onesyue@gmail.com"
export CF_Key="<CF_GLOBAL_API_KEY>"
~/.acme.sh/acme.sh --issue --dns dns_cf -d '*.i.yue.to' -d 'i.yue.to' --keylength 2048
~/.acme.sh/acme.sh --install-cert -d '*.i.yue.to' \
  --key-file       /home/nginx/certs/i_yue_to.key  \
  --fullchain-file /home/nginx/certs/i_yue_to.pem \
  --reloadcmd      "docker exec nginx nginx -s reload"
EOF
```

#### 3. nginx 加 server block
```bash
# 在 /home/nginx/conf.d/ 加 i-yue-to.conf（参考 default.conf 的 *.yue.to block）
# 关键：proxy_pass 同样指向 yue_backend (66.55.76.208:8001)
# server_name *.i.yue.to i.yue.to;
# ssl_certificate /etc/nginx/certs/i_yue_to.pem; (容器内路径)
```

详细 nginx 配置见 `scripts/nginx/i-yue-to.conf` (待补)。

### P1 应用（XBoard 插件）

```bash
# 1. 同步插件目录
scp -r xboard-plugins/InviteAlias root@66.55.76.208:/home/xboard/yue-to/plugins-core/

# 2. 跑 migration
ssh root@66.55.76.208 'cd /home/xboard/yue-to && docker exec yue-to-web-1 php artisan migrate --path=plugins-core/InviteAlias/database/migrations'

# 3. 在面板插件管理启用
# 4. 配置 OPENROUTER_API_KEY / TELEGRAM_BOT_TOKEN
```

### P2 TG bot 兑换菜单（待开发）

新文件 `telegram-bot/yue/service/invite_alias.py`：
- ConversationHandler 兑换流程
- 两阶段提交：调 `redeem-pending` → 扣分 → 调 `confirm`
- 失败回滚：调 `release-pending` + 退积分

## 配置要点

`config.json` 里所有 25 项配置都通过 `admin_setting('invite_alias.<key>')` 读取。变更后通常无需重启，但 `reserved-names.json` 修改需 `Cache::forget('invite_alias.reserved')`。

## 监控指标

应纳入运维 dashboard：
- 每日新增 alias 数（按档位）
- 自助审核通过率（应稳定在 60-85%）
- 异常注册告警（同 IP 高频）
- pending 超时清理频次（应接近 0）
- CT log 异常告警

## 已知限制 / 未来工作

- **v1 不支持中文 IDN** —— Punycode 在 WeChat 内置浏览器显示不一致，2026 钓鱼场景仍活跃
- **v2 边缘解析** —— 当 active alias ≥5k 或 P95 >50ms 时，迁到 CF Workers + KV
- **v2.5 用户绑自定义域** —— `tg.example.com` 走 CF for SaaS Custom Hostname API
- **v3 alias 转让市场** —— `transferable=true` + 平台抽成 10%（schema 已留口）

## 相关 Memory

- `feedback_xboard_patch_anchor_silent_skip.md` —— patch 锚点失效要 fail-loud
- `feedback_xboard_subscribe_template_cache.md` —— 三层缓存清理
- `feedback_callback_duplicate_click.md` —— TG bot 防重复点击扣费
- `feedback_gambling_exchange_reuse.md` —— 复用 gambling_exchange 双行模型
