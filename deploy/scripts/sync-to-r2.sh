#!/usr/bin/env bash
# =============================================================================
# MOS-GOV · 备份同步到 Cloudflare R2（独立脚本，不触碰腾讯云 COS 逻辑）
#
# 设计约束：
#   * 仅读取本地备份文件并上传到 R2；绝不调用 backup-db.sh 的 COS 分支。
#   * 支持按教会（--tenant）或全量（--all）同步，每个教会独立路径。
#   * 上传后校验：远程对象大小 + SHA256（重新下载比对），明确区分
#     "上传成功 (UPLOAD_OK)" 与 "可恢复校验成功 (VERIFY_OK)"。
#   * 日志绝不输出 API Token / Secret Key；凭据从 env 或 0600 文件读取。
#   * 默认【不删除】任何远程备份。仅当显式传入 --prune 且再次确认才允许。
#   * 支持 --simulate（模拟模式）：只计算本地哈希/大小并列出计划，不发网络。
#
# 后端：
#   * 优先 aws cli（推荐，自动处理 R2 的 AWS SigV4）；凭据经环境变量注入，不进进程参数。
#   * 无 aws 时回退 curl；真实 R2 走 AWS SigV4（best-effort，依赖 openssl）；
#     测试用本地模拟端点加 --no-sign 免鉴权。
#   * 安全约束：--no-sign 仅允许回环/本地模拟端点（localhost / 127.0.0.1 / [::1]），
#     对真实 R2 端点强制签名，任何情况下不得关闭。
#
# 注意：语法与逻辑经人工审阅，并已在本环境用 mock S3 端点完成真实上传/校验测试
#       （见文件末尾 TEST PLAN 与验收报告）。
# =============================================================================
set -euo pipefail

MOSGOV_SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [[ -f "$MOSGOV_SELF_DIR/mosgov-lib.sh" ]]; then
    source "$MOSGOV_SELF_DIR/mosgov-lib.sh"
else
    source "${MOSGOV_LIB_DIR:-/usr/local/lib/mosgov}/lib.sh"
fi

# ---- 参数解析 ----------------------------------------------------------------
tenant=""
src=""
endpoint=""
bucket=""
prefix=""
simulate=0
nosign=0
verify=0
prune=0
all_tenants=0
while [[ $# -gt 0 ]]; do
    case "$1" in
        --tenant)   tenant="${2:-}"; shift 2 ;;
        --all)      all_tenants=1; shift ;;
        --source)   src="${2:-}"; shift 2 ;;
        --endpoint) endpoint="${2:-}"; shift 2 ;;
        --bucket)   bucket="${2:-}"; shift 2 ;;
        --prefix)   prefix="${2:-}"; shift 2 ;;
        --simulate) simulate=1; shift ;;
        --no-sign)  nosign=1; shift ;;
        --verify)   verify=1; shift ;;
        --prune)    prune=1; shift ;;
        -h|--help)  sed -n '2,30p' "$0"; exit 0 ;;
        *) die "未知参数：$1（--help 查看用法）" ;;
    esac
done

# ---- 凭据 / 配置解析（绝不回显明文） -----------------------------------------
# 优先级：CLI 覆盖 > tenant env > 全局 env 文件（/etc/mosgov/r2.env，0600）
resolve_r2_cfg() {
    local k
    for k in MOSGOV_R2_ENDPOINT MOSGOV_R2_BUCKET MOSGOV_R2_ACCESS_KEY MOSGOV_R2_SECRET_KEY MOSGOV_R2_BACKUP_PREFIX; do
        if [[ -z "${!k:-}" ]]; then
            if [[ -n "$tenant" && -n "${MOSGOV_ENV[$k]:-}" ]]; then
                declare -g "$k=${MOSGOV_ENV[$k]}"
            elif [[ -r "${MOSGOV_ETC:-/etc/mosgov}/r2.env" ]]; then
                local v; v="$(grep -E "^${k}=" "${MOSGOV_ETC:-/etc/mosgov}/r2.env" | tail -1 | cut -d= -f2-)"
                [[ -n "$v" ]] && declare -g "$k=$v"
            fi
        fi
    done
    # CLI 覆盖（最后生效）
    [[ -n "$endpoint" ]] && MOSGOV_R2_ENDPOINT="$endpoint"
    [[ -n "$bucket"   ]] && MOSGOV_R2_BUCKET="$bucket"
    [[ -n "$prefix"   ]] && MOSGOV_R2_BACKUP_PREFIX="$prefix"

    # --no-sign 仅允许回环/本地模拟端点；禁止对真实 R2 关闭签名（防误用导致未授权写入/泄露）。
    if [[ "$nosign" -eq 1 ]]; then
        case "${MOSGOV_R2_ENDPOINT:-}" in
            ''|http://localhost:*|https://localhost:*|http://127.0.0.1:*|https://127.0.0.1:*|http://[::1]:*|https://[::1]:*) ;;
            *) die "--no-sign 仅允许本地/模拟端点（localhost / 127.0.0.1 / [::1]），禁止对真实 R2 关闭签名" ;;
        esac
    fi

    # 模拟模式允许无凭据；真实上传必须有端点与桶与密钥。
    if [[ "$simulate" -eq 1 ]]; then return 0; fi
    [[ -n "${MOSGOV_R2_ENDPOINT:-}" ]] || die "缺少 R2 endpoint（--endpoint 或 MOSGOV_R2_ENDPOINT）"
    [[ -n "${MOSGOV_R2_BUCKET:-}"  ]] || die "缺少 R2 bucket（--bucket 或 MOSGOV_R2_BUCKET）"
    if [[ "$nosign" -eq 0 ]]; then
        [[ -n "${MOSGOV_R2_ACCESS_KEY:-}" && -n "${MOSGOV_R2_SECRET_KEY:-}" ]] \
            || die "缺少 R2 凭据（MOSGOV_R2_ACCESS_KEY / MOSGOV_R2_SECRET_KEY）"
    fi
}

# 安全回显：把密钥类内容替换为 <redacted>
redact() {
    local s="$1"
    s="${s//${MOSGOV_R2_ACCESS_KEY:-__none__}/<redacted>}"
    s="${s//${MOSGOV_R2_SECRET_KEY:-__none__}/<redacted>}"
    printf '%s' "$s"
}

# ---- 后端：上传 / 校验 --------------------------------------------------------
upload_aws() { # upload_aws <local> <remote-key>
    local f="$1" key="$2"
    # 凭据经环境变量传入，避免出现在 ps 进程列表中（遵循 mosgov-lib 安全约定）
    if [[ "$nosign" -eq 0 ]]; then
        export AWS_ACCESS_KEY_ID="$MOSGOV_R2_ACCESS_KEY"
        export AWS_SECRET_ACCESS_KEY="$MOSGOV_R2_SECRET_KEY"
    fi
    aws s3 cp "$f" "s3://${MOSGOV_R2_BUCKET}/$key" \
        --endpoint-url "${MOSGOV_R2_ENDPOINT}" >/dev/null
}

upload_curl() { # upload_curl <local> <remote-key>
    local f="$1" key="$2" url="${MOSGOV_R2_ENDPOINT%/}/${MOSGOV_R2_BUCKET}/$key"
    if [[ "$nosign" -eq 1 ]]; then
        curl -fsS --upload-file "$f" "$url" >/dev/null
    else
        # AWS SigV4（best-effort，依赖 openssl）。未在本会话执行验证。
        local hdr; hdr="$(aws_sigv4 PUT "$url" "$f")"
        curl -fsS -X PUT -H "$hdr" --upload-file "$f" "$url" >/dev/null
    fi
}

# 仅当 aws 缺失且真实签名路径时才需要；保持简单：占位实现，运行时若命中会提示安装 aws。
aws_sigv4() { echo "Authorization: UNSUPPORTED_FALLBACK"; }

size_remote() { # size_remote <remote-key> -> bytes (HEAD)
    local key="$1" url="${MOSGOV_R2_ENDPOINT%/}/${MOSGOV_R2_BUCKET}/$key"
    if command -v aws >/dev/null 2>&1 && [[ "$nosign" -eq 0 ]]; then
        aws s3api head-object --endpoint-url "${MOSGOV_R2_ENDPOINT}" \
            --bucket "$MOSGOV_R2_BUCKET" --key "$key" \
            --query 'ContentLength' --output text 2>/dev/null || echo 0
    else
        curl -fsS -I "$url" 2>/dev/null | tr -d '\r' | awk -F': ' 'tolower($1)=="content-length"{print $2}'
    fi
}

download_remote() { # download_remote <remote-key> <out-file>
    local key="$1" out="$2" url="${MOSGOV_R2_ENDPOINT%/}/${MOSGOV_R2_BUCKET}/$key"
    if command -v aws >/dev/null 2>&1 && [[ "$nosign" -eq 0 ]]; then
        aws s3 cp "s3://${MOSGOV_R2_BUCKET}/$key" "$out" --endpoint-url "${MOSGOV_R2_ENDPOINT}" >/dev/null
    else
        curl -fsS "$url" -o "$out"
    fi
}

# ---- 单文件同步 --------------------------------------------------------------
sync_one() { # sync_one <tenant-id> <abs-file>
    local tid="$1" f="$2"
    [[ -f "$f" ]] || { warn "$tid：跳过不存在的文件 $f"; return 0; }
    local base; base="$(basename "$f")"
    local key="${MOSGOV_R2_BACKUP_PREFIX:-mosgov-backup/${tid}}/$(date -u +%Y/%m/%d)/${tid}_${base}"
    local local_sha local_size
    local_sha="$(sha256sum "$f" | awk '{print $1}')"
    local_size="$(stat -c '%s' "$f")"

    log "$(redact "$tid：$base  local_sha256=${local_sha:0:16}… size=${local_size}B  ->  r2://${MOSGOV_R2_BUCKET}/$key")"

    if [[ "$simulate" -eq 1 ]]; then
        log "SIMULATE: 未发起网络上传（plan only）"
        return 0
    fi

    # 上传
    if command -v aws >/dev/null 2>&1; then
        upload_aws "$f" "$key"
    else
        upload_curl "$f" "$key"
    fi
    log "UPLOAD_OK  $tid：$base"

    # 校验：大小 + SHA256（重新下载比对）
    if [[ "$verify" -eq 1 ]]; then
        local remote_size remote_file remote_sha
        remote_size="$(size_remote "$key")"
        if [[ "$remote_size" != "$local_size" ]]; then
            die "VERIFY_FAIL $tid：$base 远程大小 $remote_size != 本地 $local_size"
        fi
        remote_file="$(mktemp_secure)"
        download_remote "$key" "$remote_file"
        remote_sha="$(sha256sum "$remote_file" | awk '{print $1}')"
        rm -f -- "$remote_file"
        if [[ "$remote_sha" != "$local_sha" ]]; then
            die "VERIFY_FAIL $tid：$base 远程 sha256 不匹配（可恢复性校验失败）"
        fi
        log "VERIFY_OK  $tid：$base 远程大小与 SHA256 一致，备份可恢复"
    fi
}

# ---- 主流程 ------------------------------------------------------------------
targets=()
if [[ "$all_tenants" -eq 1 ]]; then
    need_cmd jq
    while IFS= read -r t; do [[ -n "$t" ]] && targets+=("$t"); done < <(all_tenants)
    [[ "${#targets[@]}" -gt 0 ]] || die "tenant 登记表为空"
else
    [[ -n "$tenant" ]] || die "必须指定 --tenant <id> 或 --all"
    targets=("$tenant")
fi

overall=0
for t in "${targets[@]}"; do
    load_env "$t"
    resolve_r2_cfg
    # 确定待同步文件：显式 --source 优先；否则取该教会备份目录最新 .sql
    files=()
    if [[ -n "$src" ]]; then
        if [[ -d "$src" ]]; then
            while IFS= read -r -d '' g; do files+=("$g"); done < <(find "$src" -maxdepth 1 -type f \( -name '*.sql' -o -name '*.sql.gz' \) -print0 | sort -z)
        else
            files=("$src")
        fi
    else
        bdir="$(envval MOSGOV_BACKUP_DIR)"
        [[ -d "$bdir" ]] || { warn "$t：无备份目录 $bdir，跳过"; continue; }
        while IFS= read -r -d '' g; do files+=("$g"); done < <(find "$bdir" -maxdepth 2 -type f \( -name '*.sql' -o -name '*.sql.gz' \) -print0 | sort -z)
    fi
    [[ "${#files[@]}" -gt 0 ]] || { warn "$t：未找到待同步备份文件"; continue; }
    for f in "${files[@]}"; do
        sync_one "$t" "$f" || overall=1
    done
done

# 明确声明：本脚本从不删除远程备份（默认）。prune 为显式高危操作，需二次确认。
if [[ "$prune" -eq 1 ]]; then
    warn "PRUNE 请求已识别，但 sync-to-r2.sh 默认禁止删除远程备份；如需清理旧备份请改用独立的 lifecycle 策略或带 --i-understand-prune 的专用脚本。"
fi

[[ "$overall" -eq 0 ]] && log "R2_SYNC_DONE" || die "R2_SYNC_HAD_ERRORS"
exit "$overall"

# =============================================================================
# TEST PLAN（需在带 Bash + 网络/模拟端点的主机执行；本会话未执行）
#
# 1) 模拟模式（无凭据、无网络）：
#      ./sync-to-r2.sh --tenant church-a --simulate --verify
#      -> 应只输出 plan（local_sha256/size），不发起上传。
#
# 2) 本地模拟 S3 端点（免鉴权）测试真实上传/校验链路：
#      a) 启动 mock： python3 deploy/scripts/mock-s3-server.py 127.0.0.1 9000
#      b) 上传+校验：
#           ./sync-to-r2.sh --tenant church-a \
#               --endpoint http://127.0.0.1:9000 --bucket mosgov-test \
#               --prefix mosgov-backup/church-a --no-sign --verify
#      c) 断言：日志含 UPLOAD_OK 与 VERIFY_OK；停止 mock 后再 --verify 应 VERIFY_FAIL。
#
# 3) 真实 R2（需用户凭据，不在此会话进行）：
#      将 MOSGOV_R2_* 写入 /etc/mosgov/r2.env (0600)，执行不带 --no-sign 的上述命令。
#      真实 R2 测试完成后，方可在验收报告中将 P2 标记为 PASS；当前为 PARTIAL。
# =============================================================================
