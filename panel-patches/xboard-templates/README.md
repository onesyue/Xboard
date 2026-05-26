# xboard-templates/

YueLink 订阅模板。

常规变更的事实源是 `onesyue/yuelink` 仓库里的
`server/xboard-rules/xboard-templates/`。线上
`/home/xboard/yue-to/xboard-templates/` 是部署目标和应急回滚点，不再作为常规
编辑入口。

## 数据流

```
xboard-templates/{clashmeta,clash,stash,singbox,surge,surfboard}.yaml/json
        ↓ patch-subscribe-templates.sh (md5 diff + bash docker exec)
DB postgres.v2_subscribe_templates (table)
        ↓ ClashMeta.php / SingBox.php / Stash.php (rendered per request)
HTTPS GET https://sso.yuetoto.com/api/v2/avatar/<token>
        ↓ User-Agent: clash.meta → clashmeta template
yuelink-mihomo (在用户机器上跑)
```

⚠️ **常规改动必须先进 yuelink 仓库、review 后部署到线上，再跑同步脚本；不能直接改
DB。** 只有紧急止血允许在线上临时编辑，事后必须立刻拉回仓库。

## 核心文件

| 文件 | 用途 |
|------|------|
| `clashmeta.yaml` | yuelink (UA `clash.meta`) 客户端订阅模板 ← **yuelink 主路径** |
| `clash.yaml` | 普通 clash 用户 (UA `clash`) 订阅模板 |
| `stash.yaml` | iOS Stash 用户订阅模板 |
| `singbox.json` | sing-box 客户端订阅模板 |
| `surge.conf` | Surge 用户订阅模板 |
| `surfboard.conf` | Surfboard 用户订阅模板 |

## 同步流程（手册）

```bash
cd /path/to/yuelink
# 1. 修改 server/xboard-rules/xboard-templates/* 并提交
# 2. 部署到线上
sshpass -e scp -r server/xboard-rules/xboard-templates root@66.55.76.208:/home/xboard/yue-to/
sshpass -e scp server/xboard-rules/patch-subscribe-templates.sh root@66.55.76.208:/home/xboard/yue-to/
# 3. 同步到 DB
sshpass -e ssh root@66.55.76.208 'cd /home/xboard/yue-to && bash patch-subscribe-templates.sh'
# 4. 用户客户端 /同步订阅 → 拉新模板
```

## 备份历史

`*.bak-*` 文件是 patch 脚本每次运行时的回滚备份。命名规则：
- `.bak-mainstream-fix-*` — 2026-05-06 url-test 类型修复
- `.bak-ai-unlock-v2-*` — 2026-05-06 AI 解锁聚合加入
- `.bak-no-cn-dns-leak-*` — 2026-05-06 DNS 防泄漏
- `.bak-openclash-lessons-*` — 2026-05-06 OpenClash 经验同步（force-domain + geosite:cn）
- `.bak-*-*` — 老的历史回滚点（4 月以来）

## 上游 image 更新策略

- XBoard image (`ghcr.io/cedar2025/xboard:latest`) 容器内是 PHP 代码，不挂这个目录
- 父级 `/home/xboard/yue-to/.git` 是 cedar2025/Xboard fork，**xboard-templates/ 是 untracked**，`git pull` 不会影响
- 线上目录曾经有独立 git 快照；现在只作为历史/应急参考，常规变更以 yuelink 仓库为准

## 恢复路径

如果 xboard-templates/ 内容意外丢失：
1. 从 yuelink 仓库重新部署 `server/xboard-rules/xboard-templates/`
2. `bash /home/xboard/yue-to/patch-subscribe-templates.sh` 同步到 DB
3. 如仓库不可用，再用线上历史 git 快照或最近的 `/tmp/xboard-templates-pre-*` tar 包应急恢复

如果 patch-subscribe-templates.sh 本身丢失：
1. 从 yuelink 仓库复制 `server/xboard-rules/patch-subscribe-templates.sh`
2. `chmod +x /home/xboard/yue-to/patch-subscribe-templates.sh`
