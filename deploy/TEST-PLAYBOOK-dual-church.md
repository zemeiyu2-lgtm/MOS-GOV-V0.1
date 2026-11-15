# MOS-GOV · 双教会隔离真机测试剧本

> 目标：在真实 Oracle Ubuntu 24.04 实例上，用**合成数据**验证 silo-per-church 物理隔离的每一项。
> 实验室已完成组件级隔离测试（`tests/phase3/suite.php` main 阶段 94 PASS，含多教会隔离用例），
> 本剧本把实验室证据映射到真机复跑步骤，并明确标注“真实环境尚未验证”的项。
> 约束：不导入真实教会数据；不合并 main；不公开部署。

---

## 隔离维度与验证方法

### 1. 数据库隔离
- **方法**：创建 `church_a` / `church_b` 两个独立 MariaDB 库与最小权限账号；用 `church_a` 账号尝试访问 `church_b` 库。
- **预期**：`church_a` 账号对 `church_b` 的 `SELECT/INSERT` 被拒（access denied）。
- **实验室证据**：`evidence/T-main.log` 中多教会迁移/CRUD 用例（两个库各 25 表，独立账号）。
- **真机**：`provision-tenant.sh` 后执行 `mysql -u church_a -p <wrong-db>` 应失败。状态：**实验室 PASS / 真机 BLOCKED（待实例）**。

### 2. 用户与权限隔离
- **方法**：在 `church_a` 创建管理员，确认其登录态/Session 无法操作 `church_b` 的 `gov_tenant` 或业务表。
- **预期**：`TenantContext::fromEnvironment` 在 host/db 错配时 fail closed（403/异常）。
- **实验室证据**：`T-main.log` 中 tenant boundary 用例；HTTP 实验台 `T-http.log`（26 PASS）含 Host 错配 403。
- **真机**：两个教会各建管理员，跨域带对方 Cookie 访问应 401/403。状态：**实验室 PASS / 真机 BLOCKED**。

### 3. 上传文件隔离
- **方法**：`church_a` 上传文件，验证落盘于 `/var/lib/mosgov/church-a/...`，且 `church_b` 的私有根 `/var/lib/mosgov/church-b/` 不可见、不可写。
- **预期**：`TenantFilePolicy::objectKey()` 生成按 tenant 划分的 key；路径遍历被拒。
- **实验室证据**：`T-main.log` 上传路径用例；`src/Security/TenantFilePolicy.php`。
- **真机**：上传后 `ls` 核对各自根目录。状态：**实验室 PASS / 真机 BLOCKED**。

### 4. 会话与 Cookie 隔离
- **方法**：两个教会独立 vhost + 独立 PHP-FPM pool + 独立 `session.cookie_path`/域名；确认 Cookie 不跨域。
- **预期**：`church-a.eu.org` 的 Session Cookie 不被 `church-b.eu.org` 携带；`SameSite=Lax` + `Secure` + `HttpOnly`。
- **实验室证据**：`T-http.log` 中 HttpOnly/Secure/SameSite 断言、登录后 session id 轮换。
- **真机**：浏览器分别登录两域，DevTools 查看 Cookie 作用域。状态：**实验室 PASS / 真机 BLOCKED（需浏览器）**。

### 5. 审计证据隔离
- **方法**：在 `church_a` 触发治理操作，确认 `gov_evidence_log` 写入 `tenant_id='church-a'`，且 `church_b` 的审计查询不返回对方记录。
- **预期**：`GovernanceAuditService` 每条证据带 `tenant_id`；append-only 触发器生效。
- **实验室证据**：`T-main.log`（94 PASS）、`T-tamper.log`（3 PASS，篡改被检测）。
- **真机**：两教会各做一次操作，交叉查询应为空。状态：**实验室 PASS / 真机 BLOCKED**。

### 6. 备份路径隔离
- **方法**：`backup-db.sh --tenant church-a` 与 `--tenant church-b` 输出到各自 `MOSGOV_BACKUP_DIR` 与 R2 prefix。
- **预期**：`church-a` 备份文件不含 `church-b` 数据；R2 key 前缀 `mosgov-backup/church-a/` vs `.../church-b/`。
- **实验室证据**：`T-backup.log` / `T-restore.log` / `T-postverify.log`。
- **真机**：`sync-to-r2.sh --tenant church-a --verify` 后核对远程 key 前缀。状态：**实验室 PASS / 真机 BLOCKED（sync 脚本待执行）**。

### 7. 恢复过程隔离
- **方法**：仅恢复 `church-a` 备份，确认 `church-b` 库不受影响。
- **预期**：`restore-db.sh` 只 drop/create 目标租户库。
- **实验室证据**：`T-restore.log` + `T-postverify.log`（7 PASS）。
- **真机**：恢复 a 后，b 业务查询正常。状态：**实验室 PASS / 真机 BLOCKED**。

### 8. 越权访问（跨教会）
- **方法**：用 `church_a` 的 API token / Session 调用 `church_b` 的 `/api/governance` 端点。
- **预期**：`PublicRequestGuardMiddleware` + `TenantContext` 拒绝（401/403）。
- **实验室证据**：`T-http.log` 中匿名 401、跨域 POST 403、错误 Host 403。
- **真机**：跨域带 Cookie 调 API。状态：**实验室 PASS / 真机 BLOCKED**。

### 9. URL 与 API 跨教会访问
- **方法**：直接请求 `https://church-b.eu.org/.well-known/mosgov-liveness` 之外的 `church_a` 业务路径。
- **预期**：vhost 层按 Host 路由，错配 Host 返回 403；同实例不同 Host 不串数据。
- **实验室证据**：HTTP 实验台 Host 绑定校验。
- **真机**：curl 带错误 Host 头应 403。状态：**实验室 PASS / 真机 BLOCKED**。

---

## 真机复跑命令（实例就绪后）

```bash
# 1) 建两教会
provision-tenant.sh --tenant church-a --host church-a.eu.org --db church_a --storage /var/lib/mosgov/church-a
provision-tenant.sh --tenant church-b --host church-b.eu.org --db church_b --storage /var/lib/mosgov/church-b

# 2) 发布
mosgov-deploy.sh --release <40-hex-commit>

# 3) 隔离断言（节选）
mysql -u church_a -p"$PW_A" -e "SELECT 1 FROM church_b.any_table"   # 应 ERROR 1142
mysql -u church_b -p"$PW_B" -e "SELECT 1 FROM church_a.any_table"   # 应 ERROR 1142

# 4) 审计隔离
mysql -u root -e "SELECT tenant_id,count(*) FROM church_a.gov_evidence_log GROUP BY tenant_id;"
mysql -u root -e "SELECT tenant_id,count(*) FROM church_b.gov_evidence_log GROUP BY tenant_id;"

# 5) 备份+远程副本校验
backup-db.sh --tenant church-a && sync-to-r2.sh --tenant church-a --verify
backup-db.sh --tenant church-b && sync-to-r2.sh --tenant church-b --verify

# 6) 健康检查
mosgov-healthcheck.sh --all
```

---

## 结论

- 9 项隔离维度在**实验室（合成数据、隔离 MariaDB 3406、双空库）**均已通过组件级与 HTTP 实验台测试。
- **真实 Oracle 实例**尚未创建，故“真机验证”状态全部为 BLOCKED，待用户完成账户注册与首个教会 provision 后复跑。
- 本剧本不重复实验室已完成的测试，只做映射与真机补齐清单。
