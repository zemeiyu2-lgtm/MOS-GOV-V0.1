#!/usr/bin/env bash
# =============================================================================
# MOS-GOV Cloudflare Quick Tunnel（匿名、随机 *.trycloudflare.com、$0）
#
# 用途：在没有域名 / 不改任何 DNS 的前提下，把本机回环上的教会实例临时暴露为
#       HTTPS 地址，用于**合成数据**的外部访问与边界测试。
# 边界：仅测试用途。地址随机且每次重启都变；邮件里的绝对链接会指向旧地址；
#       不得用于真实教会资料录入（除非另行完成正式域名 + TLS 方案）。
#
# 用法：
#   quick-tunnel.sh install                 # 从 Cloudflare 官方签名源安装 cloudflared（幂等）
#   quick-tunnel.sh start [tenant]          # 启动并打印随机 HTTPS 地址
#   quick-tunnel.sh url [tenant]            # 只打印当前地址
#   quick-tunnel.sh status [tenant]         # 服务状态 + 最近连接日志
#   quick-tunnel.sh stop [tenant]           # 停止隧道（应用本身不受影响）
#
# 多教会说明（重要）：
#   所有教会 vhost 共用 127.0.0.1:8080，Apache 靠 **Host 头** 选 vhost；而隧道
#   回源时 Host 是 *.trycloudflare.com，匹配不到任何 ServerName，会落到默认
#   vhost。因此**每个教会必须各起一个隧道实例**，并用 --http-host-header 强制
#   成该教会的 ServerName（从 /etc/mosgov/<tenant>/env 的 MOSGOV_TENANT_HOST 读取）。
#   于是：N 个教会 = N 个 trycloudflare 地址 = N 个 systemd 单元。
#
# 依赖：Ubuntu 24.04（noble）。不写入任何账号凭据，Quick Tunnel 不需要登录。
# =============================================================================
set -euo pipefail

CF_KEY_FPR="CC94B39C77AE7342A68B89628A682D308D4E5E73"   # CloudFlare Software Packaging 2025
CF_REPO_LINE='deb [signed-by=/usr/share/keyrings/cloudflare-main.gpg] https://pkg.cloudflare.com/cloudflared noble main'
APP_PORT="${MOSGOV_APP_PORT:-8080}"
APP_URL="http://127.0.0.1:${APP_PORT}"
TENANT="${2:-}"

if [ -n "$TENANT" ]; then
    TUNNEL_LOG="/var/log/mosgov/${TENANT}/tunnel.log"
    UNIT="/etc/systemd/system/mosgov-quick-tunnel-${TENANT}.service"
    UNIT_NAME="mosgov-quick-tunnel-${TENANT}"
else
    TUNNEL_LOG="/var/log/mosgov/tunnel.log"
    UNIT="/etc/systemd/system/mosgov-quick-tunnel.service"
    UNIT_NAME="mosgov-quick-tunnel"
fi

die() { echo "!! $*" >&2; exit 1; }
need_root() { [ "$(id -u)" -eq 0 ] || die "需要 root 执行"; }

tenant_host() { # 从租户 env 读 MOSGOV_TENANT_HOST
    local f="/etc/mosgov/${TENANT}/env"
    [ -f "$f" ] || die "找不到 $f（该教会开通了吗？）"
    sed -n 's/^MOSGOV_TENANT_HOST=//p' "$f" | head -1
}

do_install() {
    need_root
    if command -v cloudflared >/dev/null 2>&1; then
        echo "cloudflared 已安装：$(cloudflared --version 2>&1 | head -1)"
        return 0
    fi

    echo "[1/4] 导入 Cloudflare 官方签名公钥并校验指纹"
    command -v gpg >/dev/null 2>&1 || apt-get install -y --no-install-recommends gnupg >/dev/null
    install -d -m 0755 /usr/share/keyrings
    local tmp fpr
    tmp="$(mktemp)"
    curl -fsSL --max-time 60 https://pkg.cloudflare.com/cloudflare-main.gpg -o "$tmp" \
        || die "下载 Cloudflare 公钥失败"
    fpr="$(gpg --show-keys --with-colons "$tmp" 2>/dev/null | awk -F: '/^fpr:/{print $10; exit}')"
    [ "$fpr" = "$CF_KEY_FPR" ] \
        || die "Cloudflare 公钥指纹不符（期望 $CF_KEY_FPR，实际 ${fpr:-空}），中止"
    install -m 0644 "$tmp" /usr/share/keyrings/cloudflare-main.gpg
    rm -f "$tmp"
    echo "    指纹一致：$fpr"

    echo "[2/4] 写入 apt 源（仅 cloudflared 一个仓库，无其他第三方源）"
    printf '%s\n' "$CF_REPO_LINE" > /etc/apt/sources.list.d/cloudflared.list
    apt-get update -o Dir::Etc::sourcelist=/etc/apt/sources.list.d/cloudflared.list \
                    -o Dir::Etc::sourceparts=/dev/null -o APT::Get::List-Cleanup=0 >/dev/null

    echo "[3/4] 安装 cloudflared（apt 会校验 InRelease 签名）"
    DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends cloudflared >/dev/null
    echo "    已安装：$(cloudflared --version 2>&1 | head -1)"

    echo "[4/4] 完成。下一步：sudo bash $0 start [tenant]"
}

write_unit() { # write_unit <host_header|空>
    local host_header="${1:-}"
    local logdir; logdir="$(dirname "$TUNNEL_LOG")"
    install -d -m 0750 -o mosgov -g mosgov "$logdir" 2>/dev/null || install -d -m 0750 "$logdir"

    local extra=""
    [ -n "$host_header" ] && extra="--http-host-header ${host_header}"

    cat > "$UNIT" <<EOF
[Unit]
Description=MOS-GOV Cloudflare Quick Tunnel (${TENANT:-global}, test-only, synthetic data)
Documentation=https://developers.cloudflare.com/cloudflare-one/connections/connect-networks/do-more-with-tunnels/trycloudflare/
After=network-online.target apache2.service
Wants=network-online.target

[Service]
Type=simple
User=mosgov
Group=mosgov
ExecStart=/usr/bin/cloudflared tunnel --url ${APP_URL} ${extra} --no-autoupdate --logfile ${TUNNEL_LOG} --loglevel info
Restart=always
RestartSec=5
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ProtectHome=true
ReadWritePaths=${logdir}

[Install]
WantedBy=multi-user.target
EOF
    systemctl daemon-reload
}

do_start() {
    need_root
    command -v cloudflared >/dev/null 2>&1 || die "未安装 cloudflared，先执行：$0 install"

    local host=""
    if [ -n "$TENANT" ]; then
        host="$(tenant_host)"
        [ -n "$host" ] || die "租户 $TENANT 的 MOSGOV_TENANT_HOST 为空"
        echo "教会 $TENANT：强制 Host 头 = $host（否则 Apache 选不到该 vhost）"
    fi
    write_unit "$host"

    systemctl enable --now "$UNIT_NAME.service" >/dev/null 2>&1 || \
        die "启动失败，见 journalctl -u $UNIT_NAME -n 50"

    local i url=""
    for i in $(seq 1 30); do
        url="$(print_url || true)"
        [ -n "$url" ] && break
        sleep 2
    done
    [ -n "$url" ] || die "30 次轮询未拿到隧道地址，见 $TUNNEL_LOG"

    printf '%s\n' "$url" > "/etc/mosgov/quick-tunnel-url${TENANT:+-$TENANT}" 2>/dev/null || true
    echo "TUNNEL_URL=$url"
    echo
    echo "提示："
    echo "  - 该地址随机生成，服务重启后会变；重启后务必重跑本命令取新地址。"
    echo "  - 教会 vhost 的 ServerName 与 Config 的绝对地址都来自 MOSGOV_TENANT_HOST。"
    echo "    若两者不是同一个主机名，绝对链接（邮件/Logo）会指向旧值——请把上面地址"
    echo "    写回 /etc/mosgov/${TENANT:-<tenant>}/env 并重渲染 Config。"
    echo "  - 仅用于合成数据测试；不要用真实教会资料。"

    if [ -n "$TENANT" ]; then
        local url_host="${url#https://}"; url_host="${url_host%%/*}"
        if [ "$url_host" != "$host" ]; then
            echo
            echo "WARN: 隧道主机名($url_host) 与 MOSGOV_TENANT_HOST($host) 不同。"
            echo "      Host 头已被强制为 $host，所以 vhost 路由正确；"
            echo "      但页面里的绝对链接仍指向 $host。要一致就把 env 改成 $url_host 后重渲染。"
        fi
    fi
}

print_url() {
    [ -f "$TUNNEL_LOG" ] || return 0
    grep -oE 'https://[a-z0-9-]+\.trycloudflare\.com' "$TUNNEL_LOG" 2>/dev/null | tail -1
}

do_url() { local u; u="$(print_url)"; [ -n "$u" ] && echo "$u" || die "日志中没有隧道地址（隧道未启动？）"; }

do_status() {
    systemctl is-active "$UNIT_NAME.service" 2>/dev/null || true
    do_url 2>/dev/null || true
    journalctl -u "$UNIT_NAME" -n 5 --no-pager 2>/dev/null | tail -5 || true
}

do_stop() {
    need_root
    systemctl disable --now "$UNIT_NAME.service" >/dev/null 2>&1 || true
    echo "隧道已停止（应用仍在 ${APP_URL} 运行）"
}

case "${1:-}" in
    install) do_install ;;
    start)   do_start ;;
    url)     do_url ;;
    status)  do_status ;;
    stop)    do_stop ;;
    *) echo "用法：$0 {install|start|url|status|stop} [tenant]" >&2; exit 2 ;;
esac
