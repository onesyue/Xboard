# CommissionTier · 邀请返利 VIP 等级

替换 XBoard upstream 的 "首单 50% / 续费 0%" 为 **滚动 90d 邀请成交额 5 档 VIP 循环返利**。

| 等级 | 季度门槛 | 循环返利 | 命中率 (90d) |
|------|----------|----------|--------------|
| 普通 | 0 | 10% | 100% |
| VIP1 青铜 | ¥200 | 18% | 20% |
| VIP2 白银 | ¥500 | 26% | 6% |
| VIP3 黄金 | ¥1000 | 35% | 4% |
| VIP4 铂金 | ¥2000 | 42% | 3% |
| VIP5 钻石 | ¥4000 | **50%** | 2% |

阈值为本季度（90d 滚动）通过 `v2_order` 累计已完成邀请订单**网关实付**（`total_amount`，不含余额抵扣，2026-05-27 policy）。

## 工作机制

```
order.create.after  ─→  TierService::resolve(inviter_id)
                            ↓
                  Redis cache 30min / 否则查 v2_order SUM(GMV)
                            ↓
                  覆写 $order->commission_balance ＝ (total_amount + balance_amount) × rate / 100
                            ↓
                  $order->save() （仍在 OrderService 的 transaction 内）
```

## 升档 / 降档机制

- 当前返利率：实时按滚动窗口成交额判定，不读 `commission_tier_user.current_tier` 作事实源
- 升降档通知：每日 03:30 `commission-tier:recompute` 巡检镜像表并推送变化
- `peak_tier`：历史最高铭牌；会按全量历史成交额校准，避免配置错误授予无法达到的高档

## 配置项

`enabled` / `dry_run` / `window_days` / `demote_grace_days` / `tiers_json` / `telegram_notify`

`tiers_json` 默认为 6 档 JSON，admin 可在线编辑；threshold 单位：分，口径为邀请成交额 GMV。

## 旁路开关

- 个人 `User.commission_rate` 非空 → 跳过插件，按个人率走（KOL/渠道）
- `enabled=false` → 完全回退到 upstream `admin_setting('invite_commission')`
- `dry_run=true` → 只算不写，日志 `[CommissionTier] dry-run` 可观察

## 同步 admin_setting

切换上线时需同时改面板设置：
```
commission_first_time_enable = 0   (从首单 50% 切到循环模式)
invite_commission             = 10 (与 L0 baseline 对齐，万一插件挂了订单仍走 10%)
```

## 部署

```bash
bash /opt/yueops/scripts/deploy-xboard-plugin.sh CommissionTier
# 然后跑 migration
ssh root@66.55.76.208 "docker exec yue-to-web-1 php /www/artisan migrate \
    --path=plugins/CommissionTier/database/migrations --force"
# 首次重算（同步表）
ssh root@66.55.76.208 "docker exec yue-to-web-1 php /www/artisan commission-tier:recompute"
```

## 常用命令

```bash
# 干跑回测
docker exec yue-to-web-1 php /www/artisan commission-tier:recompute --dry

# 立即重算
docker exec yue-to-web-1 php /www/artisan commission-tier:recompute

# 用户查询
curl -H "Authorization: Bearer $TOKEN" https://panel/api/v1/user/commission/tier

# 管理员看分布
curl -H "Authorization: Bearer $ADMIN_TOKEN" https://panel/api/v2/admin/commission-tier/stats
```

## 风险与回滚

- 回滚单刀：`enabled=false` → 一键停用，订单 commission_balance 不再被覆写
- 完整回滚：`commission_first_time_enable=1` 恢复首单 50% 旧逻辑
- 退款：upstream `commission_status=3 已退` 走原 OrderService.cancel 流程，不动；本插件仅 markDirty 让下次查询重算
