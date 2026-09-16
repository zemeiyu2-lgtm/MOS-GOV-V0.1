# V0.2 — Security Mode (LOCAL / LAN)

## Configuration

```bash
# environment variable (default: LOCAL)
MOS_GOV_SECURITY_MODE=LOCAL   # loopback only (127.0.0.1, ::1)
MOS_GOV_SECURITY_MODE=LAN     # loopback + trusted private ranges
MOS_GOV_SECURITY_MODE=OFF     # enforcement disabled (documented opt-out)
```

LAN 模式接受的地址段:10.0.0.0/8、172.16.0.0/12、192.168.0.0/16、IPv6 fc00::/7。
当前生效模式显示在 `/plugins/mos-gov/settings`。

## Defaults (never loosened by configuration)

- `OUTBOUND_NETWORK = DENY` — MOS-GOV 源代码不包含任何 curl/Guzzle/socket 外呼;
  测试套件静态扫描强制(`v02_security_test.php`)。
- `PUBLIC_BINDING = DENY` — 非 loopback/LAN 客户端在任何治理逻辑执行前收到 403
  (`GovSecureModeMiddleware` 绑定在每一条 mos-gov 路由上)。

## Database (§34)

MariaDB 只允许 localhost / Docker internal network / trusted LAN 访问;
3306 端口不得对互联网开放。本插件不提供任何绕过该策略的配置。

## Backup (§35)

不建云备份。要求:数据库可本地备份(`mysqldump` / Mariabackup)+ 至少一份离线副本。
治理系统的数据不允许只存在一份。备份步骤写入 R09-DEPLOYMENT。

## Environment note (found during this deployment)

CLI 进程(如 `docker exec … php tests/run_all.php`)以 root 运行时,Monolog 会创建
root 属主的当日日志文件,导致 www-data 的 web 请求 fatal(500)。修复:

```bash
docker exec docker-webserver-1 chown www-data:www-data /var/www/html/logs/$(date +%F)-*.log
```

这是环境问题,与插件代码无关;建议在跑完 CLI 测试后执行一次。
