# V0.2 — Security Mode (LOCAL / LAN)

## Configuration

```bash
# environment variable (default: LOCAL)
MOS_GOV_SECURITY_MODE=LOCAL   # loopback (127.0.0.1, ::1) + this container host
MOS_GOV_SECURITY_MODE=LAN     # loopback + trusted private ranges
MOS_GOV_SECURITY_MODE=OFF     # enforcement disabled (documented opt-out)
```

## Docker localhost note (LOCAL mode)

Docker 部署中,宿主机浏览器经 Docker bridge 访问发布端口,Apache 看到的
`REMOTE_ADDR` 是 bridge 网关地址(即宿主机自身在该网络上的地址,如
`172.18.0.1`),而不是 `127.0.0.1`。LOCAL 模式因此额外接受**且仅接受**:

- 容器所连网络的网关地址(运行时从 `/proc/net/route` 解析,精确 IP 匹配);
- 可选的精确地址白名单 `MOS_GOV_LOCAL_EXTRA_CLIENTS`(逗号分隔,默认空,
  供反向代理等特殊拓扑使用)。

不会因此开放任何私网网段:同一 bridge 上的其它容器(非网关地址)与 LAN
客户端(其真实 LAN 源 IP)在 LOCAL 模式下仍被拒绝;"LOCAL = 仅本机"语义
不变。LAN 模式规则不受影响。

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
