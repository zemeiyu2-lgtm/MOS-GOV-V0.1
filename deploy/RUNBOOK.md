# MOS-GOV · 服务器初始化 Runbook（Oracle Always Free / Ubuntu 24.04）

> 适用范围：Oracle Ampere A1 Always Free（4 OCPU / 24 GB / 200 GB 块存储），Ubuntu 24.04 LTS。
> 所有步骤对应现有脚本或明确标注“待实现”。
> **公网接入有两种模式**（详见 `deploy/scripts/quick-tunnel.sh` 与 `deploy/scripts/add-d1-repos.sh` 头部注释）：
> - `MOSGOV_INGRESS_MODE=tunnel`（当前默认方案）：Cloudflare Quick Tunnel，**不需要域名、不需要 80/443 入站、不需要证书**；
> - `MOSGOV_INGRESS_MODE=tls`：自有域名 + Let's Encrypt，需要 80/443 入站。
> 两种模式都**不涉及** `mosrove.com` 的 DNS / NS / 备案。

---

## 0. 前置检查（用户侧，不在本会话执行）

- [ ] Oracle Free Tier 账户 + 信用卡预授权（冻结，非扣费）
- [ ] Home Region = `ap-singapore-1`（注册时锁定，不可改）
- [ ] 已创建 1 台 A1 实例并拿到公网 IPv4
- [ ] **不需要域名**：隧道模式由 Cloudflare 分配随机 `*.trycloudflare.com`，无需账号、无需改 NS
- [ ] （仅 `tls` 模式）域名 + Cloudflare 站点已 Active
- [ ] SSH 公钥已加入 Oracle 实例

---

## 1. Oracle Ubuntu 24.04 环境检查

| 检查项 | 命令 | 通过标准 | 对应脚本 |
|--------|------|----------|----------|
| OS 版本 | `. /etc/os-release; echo $VERSION_ID` | `24.04` | `server-init.sh` §1 |
| 架构 | `uname -m` | `aarch64`（Ampere） | `server-init.sh` §1 |
| 内存/CPU | `nproc; free -h` | ≥2 vCPU / ≥12GB | `server-init.sh` §1 |
| 磁盘 | `lsblk; df -h /` | 系统盘 + 200GB 块存储挂载 | `server-init.sh` §1 |

> Oracle Always Free 标准：`VM.Standard.A1.Flex` 4 OCPU / 24GB / 200GB 块存储。
> 新加坡 A1 容量紧张时可能 `out of host capacity`，需重试或换时段；**绝不自动改用付费 shape**。

---

## 2. PHP / MariaDB / Apache 版本核验（与 VERSIONS.md 对齐）

| 组件 | 锁定版本 | 核验命令 | 偏差处理 |
|------|----------|----------|----------|
| PHP | 8.4.x | `php8.4 -v` | 缺则 `server-init.sh` 退出码 3，不擅自加 PPA |
| MariaDB | 11.8.9 | `mariadbd --version` | 非 11.8 → 退出码 4 |
| Apache | 2.4.x | `apache2 -v` | 缺则安装 |
| Ubuntu | 24.04 | §1 | — |

- `server-init.sh` 生成 `package-lock-<date>.txt` 快照，固定 apt 版本。
- `apt-mark hold php8.4 mariadb-server apache2` 锁定，禁止自动升级（尤其数据库主版本）。

---

## 3. 防火墙与 SSH 加固

| 步骤 | 命令 | 说明 |
|------|------|------|
| Oracle 控制台安全列表 | Web 控制台 | `tunnel` 模式：**只放 22**（源限你的 IP）；`tls` 模式：22/80/443 |
| ufw | `MOSGOV_INGRESS_MODE=tunnel bash deploy/scripts/server-init.sh` | 由 `server-init.sh` §8 按模式写入；隧道模式会主动删除 80/443 规则 |
| 应用端口 | `/etc/apache2/conf-available/mosgov-listen.conf` | `Listen 127.0.0.1:8080`；缺失会让 vhost 被 Apache 忽略 |
| SSH | `PermitRootLogin prohibit-password; PasswordAuthentication no` | 已由 `02-cloud-init.yaml` 的 sshd drop-in 落地 |
| 失败锁定 | `fail2ban` 或 `pam_faillock` | 待实现：补充 `server-init.sh` §8 的 fail2ban 段 |

> 当前 `server-init.sh` 已含 ufw 基础规则与端口自检（`0.0.0.0:8080` 出现即 `exit 6`）；fail2ban 为“待实现”项，上线前必须补齐。

---

## 4. 公网入口与 TLS

### 4.1 隧道模式（当前默认，$0、无域名）

```bash
sudo bash deploy/scripts/quick-tunnel.sh install   # 装 cloudflared（校验 Cloudflare 公钥指纹）
sudo bash deploy/scripts/quick-tunnel.sh start     # 打印 https://<random>.trycloudflare.com
```

- TLS 在 Cloudflare 边缘终止，源站只监听 `127.0.0.1:8080`；
- 主机名每次重启都变 → 租户的 `MOSGOV_TENANT_HOST` 必须同步更新并重渲染 `Config.php`；
- 官方限制：无 SLA、200 in-flight 并发（超出 429）、不支持 SSE、随机主机名。**仅用于测试与联调。**

### 4.2 域名模式（`tls`）

| 方式 | 命令 | 说明 |
|------|------|------|
| Let's Encrypt（推荐） | `certbot certonly --webroot -w <docroot> -d <host>` | 必须 `certonly`；**不要**用 `--apache`，它会按 ServerName 改错 vhost |
| 强制 HSTS | vhost 已写 `Strict-Transport-Security` | `deploy/apache/mosgov-vhost.conf` |

> **不要用 Cloudflare Origin CA**：`healthcheck.sh` L1 用 `curl --resolve host:443:127.0.0.1` 且不带 `-k`，
> 只信任公共 CA；Origin CA 会导致健康检查必然失败。

---

## 5. 服务状态与健康检查

- PHP-FPM：`systemctl enable --now php8.4-fpm`
- Apache：`systemctl enable --now apache2`
- MariaDB：`systemctl enable --now mariadb`
- 存活探针：`curl -fsS https://<当前对外主机名>/.well-known/mosgov-liveness` → `ok`
  （由 `deploy/scripts/healthcheck.sh` + `healthcheck.php` 提供；探针不含租户/DB 信息）
- 业务健康检查：`/usr/local/bin/mosgov-healthcheck.sh --all`（含 DB 连通、证据链、租户边界）

---

## 6. 日志与告警

- 应用日志：`/var/log/mosgov/*.log`，按 `deploy/logrotate/mosgov` 每日轮转、保留 30 天。
- Apache/MariaDB 错误日志：系统 journald。
- 告警（待实现）：cron 每日跑 `mosgov-backup-all.sh` + `mosgov-audit-anchor.sh`，失败发邮件/Webhook；当前为“待实现”项。

---

## 7. 首个教会实例初始化

> **顺序先发布、后开通**：`provision-tenant.sh` 需要从 `$MOSGOV_CURRENT` 读取
> `REVISION` 与 ChurchCRM 核心 `mysql/install/Install.sql`，因此发布必须在开通之前。
> 早期文档写成「先开通后发布」，会因缺少已部署源码而直接 die。

1. `mosgov-deploy.sh --release <40-hex-commit>`（当前 `a275f43` 或后续锁定 commit）
   - 锁定校验 → 源码组装 → 上游补丁 → 全量 `php -l` → 配置体检 → vhost 渲染 → symlink 切换 → 健康检查。
2. `provision-tenant.sh --tenant church-a --host <当前对外主机名> --db church_a --storage /var/lib/mosgov/church-a`
   - `<当前对外主机名>`：隧道模式填 `quick-tunnel.sh start` 打印出的随机 `*.trycloudflare.com`；
     该主机名会随隧道重启变化，变化后需更新 `/etc/mosgov/church-a/env` 的 `MOSGOV_TENANT_HOST` 并重渲染 `Config.php`。
   - 创建独立 DB + 最小权限账号 + vhost + FPM pool + 私有存储 + `gov_tenant` 单行 + 备份前缀。
   - **导入 ChurchCRM 核心 schema（`mysql/install/Install.sql` + `version_ver` 登记），然后才跑 6 个治理迁移。**
     顺序不可调换：ChurchCRM 只在「零张表」时自动建核心表，先建 `gov_*` 会让核心表永远缺失。
   - 结束时做 fail-closed 自检：核心表（`person_per`/`user_usr`/`config_cfg`/`version_ver`）与治理表必须齐备。
   - 写入 `plugin.mos-gov.enabled=1`，新教会开局即可看到治理模块（否则 `/plugins/mos-gov/*` 被重定向回仪表盘）。
3. 首次 `mosgov-healthcheck.sh --all` 必须通过。
4. 用 `mosgov-create-admin.sh --tenant church-a --pass-file <0600路径>` 创建首个管理员
   （详见 §7.4.1；首次登录前完成，凭据转交后立即 shred）。
5. 第二个教会 `church-b` 重复上述，验证 silo 隔离。

---

## 7.4.1 创建首个管理员（mosgov-create-admin.sh）

`provision-tenant.sh` 刻意不创建 ChurchCRM 用户：官方 `Install.sql` 只预置一个
`per_ID=1 / usr_UserName='Admin'` 的**安装 seed 账号**（口令 hash 公开、`usr_Admin=1`），
它属于安装产物，不应作为教会的正式管理员，本命令也**绝不覆盖或重置**它。
首个可用管理员由本命令另行创建（把原先 P9 的手工 SQL 脚本化）。

```bash
# --pass-file 必须给、且必须是尚不存在的新文件路径（命令用 O_EXCL 创建，已存在会被拒绝）；
# 明文口令只写入它（0600），不要预先创建该文件。
sudo mosgov-create-admin.sh --tenant church-a \
    --pass-file /root/.church-a-admin.pass \
    --username admin2 --first-name Church --last-name Admin \
    --email admin@<教会域名>
# 成功：exit 0，JSON 输出 person_id / username / need_password_change=true，
#       输出中绝不含口令或哈希；审计行写 stderr（同样不含口令）。
```

**用户名约束**：`3–50` 位 `[A-Za-z0-9._-]`。注意 `usr_UserName` 唯一键是大小写不敏感
（`utf8_unicode_ci`），因此 `admin` 会与 seed 的 `Admin` 冲突而被拒绝（`exit 4 /
username_exists`）；请选一个不同的名字（如 `admin2`、`office`），随后在 ChurchCRM 内
自行改名即可。

**行为保证（已用隔离合成库 26/26 用例验证，见 evidence/phase7/12-create-admin-suite.log）**：

| 场景 | 结果 | 退出码 |
|---|---|---|
| 首次创建 | person + admin user 同事务写入，`usr_NeedPasswordChange=1` | 0 |
| 同名用户已存在 | 拒绝，不写凭据文件、不改任何行 | 4 |
| 已有非 seed 管理员 | 拒绝（默认只允许首个），不改任何行 | 4 |
| 非法 tenant / username / email / 相对 pass-file | 拒绝 | 2 |
| env 的 TENANT_ID 与 `--tenant` 不符 / `gov_tenant` 锚点不符 | fail-closed 拒绝 | 3 |
| 写入中途异常（DB 触发器注入失败） | 事务回滚，无半账户、凭据文件销毁 | 5 |
| pass-file 无法写入 | 拒绝 | 6 |

**首次登录必改密**：口令经 `password_hash(PASSWORD_DEFAULT)` 生成，与 ChurchCRM
`User::isPasswordValid()` 的 `password_verify` 完全一致；`usr_NeedPasswordChange=1`
会让 `LocalAuthentication` 在首次登录时把用户强制导向
`/v2/user/current/changepassword`，改密后才能进入控制台。

**凭据交接**：明文只在 `--pass-file`（0600）。用一次性安全渠道交给教会负责人后，
立即 `shred -u <pass-file>`。命令不打印口令、不写任何日志、不写 Git。

---

## 7.5 上游补丁应用与回滚（GroupService SQL 守卫）

ChurchCRM 7.8.0 核心在 `ChurchCRM/Service/GroupService.php` 的 `deleteGroupRole` /
`enableGroupSpecificProperties` / `disableGroupSpecificProperties` 三处将 string 类型
ID 直接拼接进 SQL（含表名拼接 `groupprop_<id>`）。补丁
`deploy/patches/7.8.0/group-service-sql-guard.patch` 在方法入口强制正整数校验 +
`(int)` 转换，属最小范围防御性修复，不改变业务行为、保留上游可升级性。

- 应用（**由 `mosgov-deploy.sh` 在组装阶段自动执行**，无需人工介入）：
  发布时先按 `upstream-lock.json` 校验补丁文件 sha256，再对刚解压的官方源码
  `apply-upstream-patches.sh <target>`；补丁失败即为发布失败，不会带着未修补的上游上线。
- 人工排障用（对【已 sha256 校验】的解压源码）：
  `./deploy/scripts/apply-upstream-patches.sh /var/www/mos-gov/staging/churchcrm-7.8.0-src`
- 干跑：`... apply-upstream-patches.sh <src> --dry-run`
- 回滚：`... apply-upstream-patches.sh <src> --rollback`（回滚后与官方原文件逐字节一致）
- 锁定记录：`deploy/upstream-lock.json` → `patches[0]`
  （`patch_sha256: ea3bac70…`，`base_file_sha256: dbe4e83b…`，`result_file_sha256: fc7af97b…`）
- 版本绑定：补丁仅针对 7.8.0，且必须打在**官方 ChurchCRM-7.8.0.zip**解出的源码上。
  本地参考源码树（sha256 `112ac6e0…`）与官方归档不同源，不得作为补丁基座。
- 升级 ChurchCRM 主版本时须重新评估上下文；`deploy.sh` 会因 sha256 不匹配而阻断发布。

---

## 8. 备份与恢复

- 本地备份：`backup-db.sh --tenant church-a`（`mariadb-dump --single-transaction --routines --triggers --events`）。
- 远程副本：`sync-to-r2.sh --tenant church-a --verify`（R2 10GB 免费额度，远超需求）。
- 证据锚点：`mosgov-audit-anchor.sh` 每日生成库外 HMAC 锚点。
- 恢复：`restore-db.sh --tenant church-a --file <backup.sql>`（先校验 sha256，再 drop/create 导入）。
- 回滚：`rollback.sh`（保留上一 release symlink）。

---

## 9. 故障回滚

- 发布失败：`deploy.sh` 自动回退上一 `MOSGOV_CURRENT` 软链。
- DB 损坏：`restore-db.sh` 从最近备份 + R2 副本恢复；恢复后跑 `suite.php --phase=postverify`。
- 单教会故障：不影响其他教会（silo 隔离），仅回滚该教会 vhost/DB。

---

## 待实现清单（上线前必须补齐）

- [ ] `server-init.sh` §7：fail2ban / pam_faillock
- [ ] `server-init.sh` §8：certbot 自动签发与续期
- [ ] 告警通道（备份/健康检查失败通知）
- [ ] ChurchCRM 全栈 E2E 真机测试（本地 Docker 栈可补，真机待 Oracle 实例就绪）
- [x] `upstream-lock.json` 官方归档 sha256 已填入并验证（发布阻断已解除，见 P5）
- [ ] GroupService 补丁 `php -l` 语法检查（沙箱无 PHP 运行时；补丁已 `patch --dry-run` 验证可干净应用，正式 lint 在部署主机/本地栈执行）
