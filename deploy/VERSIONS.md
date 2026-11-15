# 固定依赖版本（禁止 latest / 自动升级）

> 本文件是 Phase 3 部署准备的版本锁。任何变更必须走独立分支 + 评审，生产服务器不得自动升级数据库主版本。

| 组件 | 固定版本 | 获取方式 / 约束 |
|---|---|---|
| 操作系统 | Ubuntu **24.04 LTS** (noble) | 云镜像选择时锁定 24.04，不用 24.10/滚动版 |
| Web | Apache **2.4.x**（24.04 仓库 2.4.58 系列） | `apt-mark hold apache2`；补丁级安全更新由人工窗口执行 |
| PHP | **8.4.x**（与本地运行时 8.4.25 同主线） | Ubuntu 24.04 默认为 PHP 8.3，**必须**显式安装 `php8.4-*`（ondrej/php PPA，需 pin 优先级），或改用已验证的 8.3 并补跑全量测试。禁止 `php` 无版本号的元包 |
| PHP 扩展 | pdo_mysql, mysqli, mbstring, gettext, intl, gd, zip, curl, openssl, sodium, fileinfo | 与 `D:/MOS-GOV-Web/runtime/PHP` 的 11 扩展清单一致 |
| 数据库 | MariaDB **11.8.9**（本地已验证） | 固定 `mariadb-server=1:11.8.*`；**禁止** unattended-upgrades 自动升级 10.x/11.x 主版本；禁止 `mariadb-upgrade` 随包自动执行 |
| ChurchCRM | **7.8.0**（`composer.json` 实测） | 完整源码随发布包锁定，不从上游自动拉取；README 写的 7.7.0 是错的，不要按 7.7.0 装 |
| MOS-GOV 插件 | 基线 **2.0.0**（commit `395db69`）+ Phase 3 补丁集 | 以 `deploy.sh --release <commit>` 锁定具体 commit，禁止部署分支 HEAD |
| Web 根 | `/var/www/mos-gov/current`（symlink → `releases/<commit>`） | 每次发布新目录，回滚只切 symlink |
| TLS | Let's Encrypt / certbot（apt 固定版本） | 证书自动续期，应用与数据库不自动续期/升级 |
| 备份存储 | 腾讯云 COS 标准存储，独立桶 + 独立子账号 | 只给 `PutObject/GetObject`，不给 `DeleteObject`（防勒索误删） |

## apt 版本锁的落地方式

`server-init.sh` 会：

1. 写入 `/etc/apt/preferences.d/mosgov-pin`，把 PHP 8.4 / MariaDB 11.8 的源固定优先级；
2. 安装后执行 `apt-mark hold apache2 php8.4* mariadb-server*`（明确列出，不用通配符猜）；
3. 把 `dpkg-query -W` 的完整包版本快照写到 `/var/log/mosgov/package-lock-<date>.txt`；
4. 关闭 `unattended-upgrades` 对 `mariadb-server` 与 `php8.4*` 的自动升级（只保留安全公告人工窗口）。

**没有服务器就无法给出 apt 的具体补丁版本号**；因此版本锁的验收证据是服务器初始化后生成的 `package-lock-<date>.txt`，不是本文件里的推测值。这一点在正式采购后必须补齐，不得用「已固定」虚报。

## Docker 评估结论（本阶段不采用）

| 判断 | 理由 |
|---|---|
| 可行性 | Web 层容器化在技术上行得通；但 ChurchCRM 7.8.0 是完整源码级集成（1,099 个 PHP 文件 + `vendor/` + 定制 `skin/mos/` + `Include/Config.php`），不是标准 Composer 应用镜像 |
| 风险 1 | 现有安装链路全部是 Windows（`installer/*.ps1`、Inno Setup），从来没有在 Apache + PHP-FPM 下跑过，`.htaccess` 重写、`$URL[0]` 绝对地址、上传目录权限都待 Linux 验证；容器再加一层隔离会同时引入两个未验证变量 |
| 风险 2 | 任务书要求「不得使用未经锁定的 latest 镜像或自动升级生产数据库」。当前不存在经过验证的 `churchcrm:7.8.0-php8.4` 固定镜像，需要自建并按 digest 锁定，工作量大于收益 |
| 风险 3 | 数据库容器化会让「独立数据库 + 独立备份 + 独立恢复」与容器生命周期耦合；本阶段要求可恢复优先于可编排 |
| 结论 | **本阶段采用裸机 Apache + PHP-FPM + 本机 MariaDB 11.8.9**。待 Linux 环境跑通全量测试后，再把 Web 层打成按 digest 锁定的镜像单独评估；数据库继续保持独立、非自动升级 |

## 未完成 / 待服务器到位后补齐

- apt 具体补丁版本快照（Ubuntu 24.04 仓库随区域与时间变化）；
- Apache mod_php vs PHP-FPM 下 `.htaccess` 与 `RewriteBase` 的实测；
- `$URL[0]` 在 HTTPS 域名下的实际跳转验证；
- certbot 自动续期的真实续期演练（DR 运行）。
