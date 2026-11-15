# MOS-GOV 服务器初始化与运维手册

> 适用对象：Ubuntu 24.04 LTS 上的 **独立公网 MOS-GOV 实例**。
> 本文档是「部署准备」交付物之一，对应 Phase 3 第七节。所有脚本位于 `deploy/scripts/`，
> 由 `server-init.sh` 安装到 `/usr/local/bin/mosgov-*.sh` 与 `/usr/local/lib/mosgov/`。
> **本阶段没有购买服务器、没有申请证书、没有导入真实教会数据、没有正式公网发布。**

---

## 0. 先读这一节：当前不能上线的原因

| 阻断项 | 原因 | 需要的动作 |
|---|---|---|
| 无服务器 | 未购买独立云主机；现有 `43.136.118.96` 属 MOSRove，禁止挪用 | 采购授权 |
| 无独立域名 / 证书 | `mosrove.com` 绑定 MOSRove ICP 备案，不能给 MOS-GOV 用 | 注册新域名 + ICP 备案 + certbot |
| PHP 8.4 / MariaDB 11.8 源未授权 | Ubuntu 24.04 默认是 PHP 8.3 / MariaDB 10.11；添加第三方源是未授权变更 | 单独授权 apt 源，或书面批准改用 8.3/10.11 并补跑全量测试 |
| ChurchCRM 7.8.0 源码归档未上传 | `deploy/upstream-lock.json` 的 `sha256` 仍是占位符，`deploy.sh` 会拒绝发布 | 运维上传经核验的归档并填真实 sha256 |
| Linux 链路未真机验证 | 现有安装链路全是 Windows（Inno Setup / PowerShell）；Apache + PHP-FPM 下的 `.htaccess`、`$URL[0]`、上传权限未实测 | 服务器到位后跑完整浏览器 E2E |
| 无云账户凭据 | 无法核实腾讯云账户内的实例、余额、备案主体 | 提供只读控制台/API 凭据 |

---

## 1. 架构总览

```
                    Internet
                       │ HTTPS 443
        ┌──────────────▼──────────────┐
        │  Apache 2.4 TLS vhost        │  certbot / 云 LB 终结 TLS
        │  127.0.0.1:8080 回环转发     │  X-Forwarded-Proto 仅信任精确代理 IP
        └──────────────┬──────────────┘
                       │ 每教会独立 vhost + 独立 FPM socket
        ┌──────────────▼──────────────────────────────┐
        │ /var/www/mos-gov/current  (只读，共享代码)     │
        │   symlink → releases/<40-hex-commit>           │
        │   ChurchCRM 7.8.0 + plugins/community/mos-gov │
        └──────┬─────────────────────────────┬─────────┘
               │                             │
   ┌───────────▼──────────┐      ┌───────────▼──────────┐
   │ 教会 A                │      │ 教会 B                │
   │ DB mosgov_church_a    │      │ DB mosgov_church_b    │
   │ DB user mosgov_a      │      │ DB user mosgov_b      │
   │ /var/lib/mosgov/A/... │      │ /var/lib/mosgov/B/... │
   │ backup prefix A       │      │ backup prefix B       │
   │ HMAC key A ≠ B        │      │ HMAC key B ≠ A        │
   └───────────────────────┘      └───────────────────────┘
```

**隔离模型是 silo-per-church（物理隔离），不是共享库 SaaS 多租户。**
共享的只有只读应用代码目录；不提供跨教会超级管理员，不支持跨教会查询。
教会数量增加时按 `provision-tenant.sh` 复制一整套资源，不改业务表结构。

---

## 2. 服务器初始化

```bash
sudo bash deploy/scripts/server-init.sh
```

10 个步骤：基础包 → 版本闸门 → apt 版本锁 → 系统账号/目录 → 安装部署模板 →
Apache/PHP 配置 → MariaDB 基础加固 → 防火墙 → 日志轮转与 cron → 安装运维脚本。

关键行为：

- **不会**自动添加 ondrej/php 或 MariaDB 官方 PPA。缺 PHP 8.4 时 exit 3；
  MariaDB 不是 11.8.x 时 exit 4，并打印需要哪一级授权。
- `apt-mark hold` + `/etc/apt/apt.conf.d/51mosgov-unattended` +
  `/etc/apt/preferences.d/mosgov-pin` 禁止 DB/PHP 主版本自动升级。
- `dpkg-query -W` 全量包版本快照写入 `/var/log/mosgov-package-lock-<date>.txt`
  —— 这才是「版本已固定」的验收证据，`VERSIONS.md` 里的版本号只是目标值。
- 模板与锁定清单安装到 `/etc/mosgov/deploy/`（`0640 root:mosgov`）：
  `upstream-lock.json`、`VERSIONS.md`、`tenant-registry.json` → `/etc/mosgov/tenants.json`、
  env 模板、`Config.php.tpl`、vhost 模板、`99-mosgov.ini`。
- 只开放 22/80/443；8080 仅回环，外部无法绕过 TLS 直连应用。
- **不创建任何教会数据库、不导入任何数据。**

初始化完成后必须先人工完成两件事，脚本才会允许发布：

1. 上传经核验的 ChurchCRM 7.8.0 源码归档到
   `/var/www/mos-gov/staging/churchcrm-7.8.0.tar.gz`（单一顶层目录，内部即 Web 根内容），
   把真实 sha256 填进 `/etc/mosgov/deploy/upstream-lock.json` 的 `churchcrm.sha256`。
2. 确认 `/etc/mosgov/tenants.json` 中每个教会的 `host` 是真实域名（示例域名会被拒绝）。

---

## 3. 发布（固定 commit）

```bash
sudo mosgov-deploy.sh --release <40位commit> --dry-run   # 只校验，不落盘
sudo mosgov-deploy.sh --release <40位commit>
```

闸门顺序，任何一步失败都不会切换 symlink：

1. 校验 `upstream-lock.json`：sha256 必须是 64 位真实值（占位符直接拒绝）；
2. 校验 ChurchCRM 归档 sha256；
3. 在 `/var/www/mos-gov/mirror/MOS-GOV-V0.1.git` 固定镜像中确认 commit 存在
   —— **不接受分支名 / HEAD / latest**；
4. 组装 `releases/<commit>`：ChurchCRM 归档 `--strip-components=1` +
   `git archive <commit>` 解出的插件 → `plugins/community/mos-gov/`；
5. 从模板安装 `Include/Config.php`（只 `getenv()`，发布包内无明文口令）；
6. 写入 `REVISION`、静态探针 `.well-known/mosgov-liveness`；
7. **全量 `php -l`**（不跳过 vendor），日志 `/var/log/mosgov/syntax-<commit>.log`；
8. `verify-secrets.sh --quiet`；
9. 按 active 教会重新渲染 vhost，`apache2ctl configtest`；
10. 原子切换 `current` symlink，追加 `RELEASE_HISTORY`；
11. 发布后 `healthcheck.sh --all`，**失败自动回退到上一个发布**；
12. 写 `DEPLOY_MANIFEST.json`（commit / 归档 sha256 / 插件版本 / 语法检查文件数）。

重复执行同一 commit 是幂等的：复用已有发布目录，重新走全部闸门。
`--no-http-health` 仅供证书尚未就绪时使用，正式上线不得带此参数。

---

## 4. 新增教会

```bash
# 先在 /etc/mosgov/tenants.json 加一行 status=planned 的真实配置
sudo mosgov-provision-tenant.sh --tenant church-a --dry-run
sudo mosgov-provision-tenant.sh --tenant church-a
```

为每个教会创建：独立 DB（utf8mb4 空库）+ 独立最小权限 DB 账号 +
独立系统账号 `mosgov-<tenant>` + 独立 FPM pool/socket + 独立 vhost +
独立私有存储 + 独立备份前缀 + 独立 64 位十六进制 HMAC 密钥。

- 依次执行 6 个迁移（`001` → `006`），写入 `gov_tenant` singleton 行并置 `active`。
- 授权粒度：业务表 `INSERT/UPDATE/DELETE`；
  `gov_evidence_log` / `gov_audit_log` **只有 SELECT + INSERT**；
  `gov_tenant` / `gov_tenant_file` 不给写权限。
- 目标库若已存在直接拒绝，**绝不覆盖已有教会数据**。
- FPM pool 里用 `clear_env = no` + 显式 `env[MOSGOV_*]` 注入，
  否则 PHP-FPM 默认会丢掉 Apache `SetEnv`，`getenv()` 全部读不到，
  租户边界会 fail closed（这是必须的，不是可选项）。
- env 文件 `/etc/mosgov/<tenant>/env` 为 `0640 root:mosgov`，
  任何残留 `__XXX__` 占位符都会导致半成品文件被删除并报错。
- 写入首个库外证据锚点，跑配置体检，最后把登记表状态改为 `active`。

**本脚本没有任何导入入口，不会也不允许导入真实教会成员 / 奉献数据。**

脚本结束后仍需人工完成（脚本不会自动做）：

```bash
# 1) 域名解析到本机公网 IP，确认 80/443 放通
sudo certbot --apache -d a.gov.example.org
# 2) 健康检查
sudo mosgov-healthcheck.sh --tenant church-a
# 3) 配置离站备份凭据 0400（子账号只给 PutObject/GetObject，不给 DeleteObject）
sudo mosgov-backup-db.sh --tenant church-a --with-files
```

---

## 5. 健康检查

```bash
sudo mosgov-healthcheck.sh --all
sudo mosgov-healthcheck.sh --tenant church-a --no-http   # 证书未就绪时
```

| 层 | 检查内容 |
|---|---|
| L1 | HTTPS 静态存活探针 200；HTTP 不得明文返回应用内容 |
| L2 | `healthcheck.php`（本机 CLI，不经 Web）：DB 归属、租户 singleton 行、旧密码哈希、append-only 触发器、应用账号最小权限、HMAC 密钥长度、证据链完整性、私有存储、备份空间 ≥1GiB、发布版本锁、PHP 扩展 |
| L3 | 独立 FPM socket、`apache2ctl configtest`、备份卷余量、库外锚点 36 小时内新鲜度 |

**设计决定**：公网下不暴露 `/plugins/mos-gov/health` 这类未认证 DB 探测端点。
`MOSGOV_HEALTHCHECK_PATH` 固定为静态 `/.well-known/mosgov-liveness`，
所有涉及数据库与证据链的检查都走本机 CLI。
`env` 模板原来的 `/plugins/mos-gov/health` 在插件里根本不存在，已修正。

`verify-secrets.sh` 单独检查：env 属主/权限、部署模式、可信代理必须是精确 IP、
登记表一致性、口令与 HMAC 强度与**跨教会唯一性**、存储根在 Web 根外、
发布版本必须是 40 位 commit、无占位符/示例域名。全程只打印键名与指纹前 8 位，
不回显任何秘密值。

---

## 6. 备份

```bash
sudo mosgov-backup-db.sh --tenant church-a --with-files
sudo mosgov-backup-db.sh --tenant church-a --prune --yes   # 显式授权才删除过期备份
```

- 时间戳命名，**绝不覆盖**已有备份；`.sql.gz` + `.manifest.json` + `.sha256` 三件套，权限 0400。
- dump 带 `--single-transaction --routines --triggers --events --hex-blob`，
  保证 append-only 触发器随备份一起恢复。
- manifest 记录备份时刻的证据链头（`rows` / `head_hash` / `verified`），
  恢复后可比对是否被「整链重建」。
- 文件归档与数据库归档分别计算 sha256。
- 默认**只报告**过期备份不删除；删除必须同时给 `--prune --yes`。
- 离站副本：配置了 `/etc/mosgov/<tenant>/cos.env` + `coscli` 时只 `Put`（无 Delete 权限），
  否则明确标记 `offsite_copy=skipped`，**不伪报成功**。

cron（`/etc/cron.d/mosgov-backup`）：

```
15 2 * * * root /usr/local/bin/mosgov-backup-all.sh
35 2 * * * root /usr/local/bin/mosgov-audit-anchor.sh
```

`mosgov-backup-all.sh` 逐个教会执行，任一失败不影响其他教会，汇总后非 0 退出便于告警。

### 库外证据锚点

`mosgov-audit-anchor.sh` 每日：校验整条 HMAC 链 → 与前一天锚点比对
（行数只能增长；同一 `last_id` 的 `last_hash` 不得变化）→ 写 0400 时间戳锚点文件 →
尝试同步到 COS。**链损坏时拒绝写新锚点**，避免把损坏状态固化成新的可信起点。
锚点只放本机时防篡改强度弱于离站副本，脚本会明确告警。

---

## 7. 恢复与回滚

### 数据库恢复（高危）

```bash
sudo mosgov-restore-db.sh --tenant church-a \
    --file /var/backups/mosgov/church-a/db-church-a-20261115-021500.sql.gz \
    --yes --confirm RESTORE
```

**⚠️ 此操作非常危险，可能导致不可逆的数据丢失！** 它会 `DROP DATABASE` 并重建当前教会库。
强制保护：

1. 必须 `--yes` 且 `--confirm RESTORE`；
2. 先把当前状态另存为 `pre-restore-<tenant>-<timestamp>.sql.gz`，恢复本身可回退；
3. `sha256sum -c` + manifest 归属与哈希比对；
4. 检查 dump 中 `CREATE DATABASE` 恰好 1 个且库名匹配，防止 A 教会数据恢复到 B 教会；
5. 恢复后重建最小权限授权，跑 `healthcheck.sh --no-http`；
6. 绝不删除备份文件，绝不触碰其他教会库。

### 代码回滚

```bash
sudo mosgov-rollback.sh --previous --yes
sudo mosgov-rollback.sh --to <40位commit> --yes
```

- **主机级操作**：只读代码目录是这台主机上所有教会共享的，回滚影响全部 active 教会。
- 只切 `current` symlink，**不改数据库、不做 schema 降级**。
  迁移是前向的；若目标版本与当前 schema 不兼容，必须另走 `restore-db.sh`。
- 回滚后跑 `--all --no-http` 健康检查，失败自动切回原版本。

---

## 8. 日志与轮转

日志统一在 `/var/log/mosgov/`：`healthcheck.log`、`deploy.log`、`restore.log`、
`rollback.log`、`backup-cron.log`、`anchor-cron.log`、每教会 `apache-*.log`、
`syntax-<commit>.log`、包版本快照 `mosgov-package-lock-<date>.txt`。

`deploy/logrotate/mosgov`：daily / rotate 30 / compress / `create 0640 root mosgov` /
轮转后 reload Apache 与 php-fpm。

安全约定：应用侧异常原文只写服务器日志，HTTP 响应只返回固定文案 + 短 correlation id
（`src/Security/SafeError.php`）。运维查看日志时不要把完整日志贴到工单或聊天里。

---

## 9. 日常维护与升级

| 频率 | 动作 |
|---|---|
| 每日 | 检查 `backup-cron.log` / `anchor-cron.log` 无 FAIL；确认 `offsite_copy=ok` |
| 每周 | `mosgov-healthcheck.sh --all`；`verify-secrets.sh` |
| 每月 | 做一次**恢复演练**：在隔离库用 `--yes --confirm RESTORE` 恢复上月备份并跑 postverify；确认 `pre-restore` 快照可用 |
| 每季度 | certbot 续期演练（`certbot renew --dry-run`）；检查 apt hold 未被解除 |
| 升级 | 新 commit → `--dry-run` → `deploy.sh --release` → 健康检查；出问题 `--previous --yes`。**绝不 `apt upgrade` 数据库主版本** |
| 新增教会 | 登记表加 `planned` 行 → `provision-tenant.sh --dry-run` → 正式执行 → certbot → healthcheck |

升级 ChurchCRM 主版本属于架构性变更，需要单独走分支 + 空库全量测试，
不在日常维护范围内。

---

## 10. 脚本清单

| 脚本 | 安装位置 | 作用 |
|---|---|---|
| `server-init.sh` | 手动执行 | 服务器初始化 10 步 |
| `mosgov-lib.sh` | `/usr/local/lib/mosgov/lib.sh` | env 安全解析、租户登记、DB 凭据 option file、临时文件清理 |
| `mosgov-cli.php` | `/usr/local/lib/mosgov/cli.php` | PHP 运维 CLI 公共库 |
| `verify-secrets.sh` | `/usr/local/bin/mosgov-verify-secrets.sh` | 配置/密钥体检 |
| `healthcheck.php` | `/usr/local/lib/mosgov/healthcheck.php` | L2 运行时检查 |
| `healthcheck.sh` | `/usr/local/bin/mosgov-healthcheck.sh` | L1/L2/L3 汇总 |
| `backup-db.sh` | `/usr/local/bin/mosgov-backup-db.sh` | 单教会数据库+文件备份 |
| `mosgov-backup-all.sh` | `/usr/local/bin/mosgov-backup-all.sh` | cron 全教会备份入口 |
| `restore-db.sh` | `/usr/local/bin/mosgov-restore-db.sh` | 高危数据库恢复 |
| `rollback.sh` | `/usr/local/bin/mosgov-rollback.sh` | 代码 symlink 回滚 |
| `deploy.sh` | `/usr/local/bin/mosgov-deploy.sh` | 固定 commit 发布 |
| `provision-tenant.sh` | `/usr/local/bin/mosgov-provision-tenant.sh` | 新建教会全套隔离资源 |
| `audit-anchor.php` | `/usr/local/lib/mosgov/audit-anchor.php` | 库外证据锚点 |
| `mosgov-audit-anchor.sh` | `/usr/local/bin/mosgov-audit-anchor.sh` | cron 锚点入口 |

全部 shell 脚本已通过 `bash -n`；全部 PHP（含模板）已通过 `php -l`
（见 `phase3/evidence/T-php-syntax-final.log`，82 个文件 / 0 失败）。
**脚本本身尚未在 Ubuntu 24.04 真机执行过**，这是上线阻断项，不得据语法检查虚报为已验证。
