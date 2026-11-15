#!/usr/bin/env bash
#
# apply-upstream-patches.sh
# ---------------------------------------------------------------------------
# 将 deploy/patches/<version>/ 下的补丁应用到【已校验 sha256 的】上游源码树。
#
# 前置条件（由 deploy.sh / server-init 保证）：
#   1. 上游 ChurchCRM 7.8.0 归档已下载，sha256 与 upstream-lock.json 一致；
#   2. 已解压到 $SRC 根目录，根目录下存在 ChurchCRM/Service/GroupService.php 等。
#
# 本脚本只做补丁应用与回滚，不下载、不联网、不处理凭据。
#
# 用法：
#   ./apply-upstream-patches.sh <extracted-crm-src-root> [--dry-run]
#
# 回滚：
#   ./apply-upstream-patches.sh <extracted-crm-src-root> --rollback
# ---------------------------------------------------------------------------
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
PATCH_DIR="$REPO_ROOT/patches/7.8.0"

usage() { echo "usage: $0 <extracted-crm-src-root> [--dry-run|--rollback]" >&2; exit 2; }
[[ $# -ge 1 ]] || usage
SRC="$1"; shift

DRY=0; ROLLBACK=0
for a in "$@"; do
  case "$a" in
    --dry-run) DRY=1 ;;
    --rollback) ROLLBACK=1 ;;
    *) usage ;;
  esac
done

[[ -d "$SRC" ]] || { echo "ERROR: source root not found: $SRC" >&2; exit 3; }
[[ -d "$PATCH_DIR" ]] || { echo "ERROR: patch dir not found: $PATCH_DIR" >&2; exit 3; }

# 快速确认这是 ChurchCRM 源码（防止误打到错误目录）
if [[ ! -f "$SRC/ChurchCRM/Service/GroupService.php" ]]; then
  echo "ERROR: $SRC 看起来不是 ChurchCRM 源码根目录（缺少 ChurchCRM/Service/GroupService.php）" >&2
  exit 3
fi

cd "$SRC"

patches=("$PATCH_DIR"/*.patch)
if [[ ${#patches[@]} -eq 0 || ! -e "${patches[0]}" ]]; then
  echo "WARN: $PATCH_DIR 下没有 .patch 文件，无需应用。" >&2
  exit 0
fi

MODE="apply"; PATCH_OPT="-p1"
if [[ $ROLLBACK -eq 1 ]]; then MODE="rollback"; PATCH_OPT="-R -p1"; fi
if [[ $DRY -eq 1 && $ROLLBACK -eq 0 ]]; then PATCH_OPT="-p1 --dry-run"; fi

fail=0
for p in "${patches[@]}"; do
  name="$(basename "$p")"
  echo ">>> [$MODE] $name"
  if patch $PATCH_OPT < "$p"; then
    echo "    OK: $name"
  else
    echo "    FAIL: $name (退出码 $?)" >&2
    fail=1
  fi
done

if [[ $DRY -eq 1 ]]; then
  echo "DRY-RUN 完成，未修改文件。" >&2
elif [[ $fail -eq 0 ]]; then
  echo "所有补丁已$MODE。"
else
  echo "部分补丁失败，请检查上方输出。" >&2
  exit 4
fi
