# MOS-GOV · 服务器初始化 Runbook（Oracle Always Free / Ubuntu 24.04）

> 适用范围：Phase 4 定义的免费部署目标（Oracle Ampere A1 Always Free，2 OCPU / 12GB / 200GB，
> Ubuntu 24.04 LTS）。所有步骤对应现有脚本或明确标注“待实现”。
> 执行前置：用户已完成 Oracle / Cloudflare / eu.org 注册，Home Region 选 `ap-singapore-1`。

---

## 0. 前置检查（用户侧，不在本会话执行）

- [ ] Oracle Free Tier 账户 + 信用卡预授权（冻结，非扣费）
- [ ] Home Region = `ap-singapore-1`（注册时锁定，不可改）
- [ ] eu.org 子域名（如 `mosgov.eu.org`）已申请，NS 委派到 Cloudflare
- [ ] Cloudflare 账户，已添加站点并接管 DNS
- [ ] SSH 公钥已加入 Oracle 实例

---

## 1. Oracle Ubuntu 24.04 环境检查

| 检查项 | 命令 | 通过标准 | 对应脚本 |
|--------|------|----------|----------|
| OS 版本 | `. /etc/os-release; echo $VERSION_ID` | `24.04` | `server-init.sh` §1 |
| 架构 | `uname -m` | `aarch64`（Ampere） | `server-init.sh` §1 |
| 内存/CPU | `nproc; free -h` | ≥2 vCPU / ≥12GB | `server-init.sh` §1 |
| 磁盘 | `lsblk; df -h /` | 系统盘 + 200GB 块存储挂载 | `server-init.sh` §1 |

> Oracle Always Free 标准：`VM.Standard.A1.Flex` 2 OCPU / 12GB / 200GB 块存储。
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
| Oracle 控制台安全列表 | Web 控制台 | 仅放 22/80/443 入站；默认拒绝其余 |
| ufw | `ufw default deny incoming; ufw allow 22,80,443/tcp; ufw enable` | `server-init.sh` §7 |
| SSH | `PermitRootLogin no; PasswordAuthentication no; AllowUsers mosgov` | 编辑 `/etc/ssh/sshd_config`，重启 sshd |
| 失败锁定 | `fail2ban` 或 `pam_faillock` | 待实现：补充 `server-init.sh` §7 的 fail2ban 段 |

> 当前 `server-init.sh` 已含 ufw 基础规则；fail2ban 为“待实现”项，上线前必须补齐。

---

## 4. HTTPS 证书配置（免费）

| 方式 | 命令 | 说明 |
|------|------|------|
| Cloudflare 起源证书 | 控制台生成，存入 `/etc/ssl/mosgov/` | 源站用 CF 签发证书，CF 边缘 termination |
| 或 Let's Encrypt | `certbot --apache -d mosgov.eu.org` | 脚本待实现：`server-init.sh` §8 留空，需补 `certbot` 段 |
| 强制 HSTS | vhost 已写 `Strict-Transport-Security` | `deploy/apache/mosgov-vhost.conf` |

> 当前 vhost 模板已含 HSTS/CSP/nosniff/frame-deny/no-store；实际证书签发由运维在 §8 完成。

---

## 5. 服务状态与健康检查

- PHP-FPM：`systemctl enable --now php8.4-fpm`
- Apache：`systemctl enable --now apache2`
- MariaDB：`systemctl enable --now mariadb`
- 存活探针：`curl -fsS https://mosgov.eu.org/.well-known/mosgov-liveness` → `ok`
  （由 `deploy/scripts/healthcheck.sh` + `healthcheck.php` 提供；探针不含租户/DB 信息）
- 业务健康检查：`/usr/local/bin/mosgov-healthcheck.sh --all`（含 DB 连通、证据链、租户边界）

---

## 6. 日志与告警

- 应用日志：`/var/log/mosgov/*.log`，按 `deploy/logrotate/mosgov` 每日轮转、保留 30 天。
- Apache/MariaDB 错误日志：系统 journald。
- 告警（待实现）：cron 每日跑 `mosgov-backup-all.sh` + `mosgov-audit-anchor.sh`，失败发邮件/Webhook；当前为“待实现”项。

---

## 7. 首个教会实例初始化

1. `provision-tenant.sh --tenant church-a --host mosgov.eu.org --db church_a --storage /var/lib/mosgov/church-a`
   - 创建独立 DB + 最小权限账号 + vhost + FPM pool + 私有存储 + `gov_tenant` 单行 + 备份前缀。
2. `mosgov-deploy.sh --release <40-hex-commit>`（当前 `809adac` 或后续锁定 commit）
   - 锁定校验 → 源码组装 → 全量 `php -l` → 配置体检 → vhost 渲染 → symlink 切换 → 健康检查。
3. 首次 `mosgov-healthcheck.sh --all` 必须通过。
4. 第二个教会 `church-b` 重复上述，验证 silo 隔离。

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
