#!/usr/bin/env bash
set -euo pipefail

# OQLook Linux production hardening rollback helper
#
# Removes production runtime pieces installed by hardening.sh:
# - Nginx/Apache vhost files
# - systemd queue worker + watchdog timer units
# - logrotate rule
#
# Usage:
#   chmod +x scripts/install/linux/hardening-rollback.sh
#   sudo ./scripts/install/linux/hardening-rollback.sh --web auto
#
# Options:
#   --web <nginx|apache|auto|none>  Web server config to remove (default: auto)
#   --skip-systemd                  Keep systemd units
#   --skip-logrotate                Keep logrotate rule
#   --dry-run                       Show actions without writing files
#   --help                          Show help

WEB="auto"
REMOVE_SYSTEMD=true
REMOVE_LOGROTATE=true
DRY_RUN=false

while [[ $# -gt 0 ]]; do
  case "$1" in
    --web)
      WEB="$2"
      shift 2
      ;;
    --skip-systemd)
      REMOVE_SYSTEMD=false
      shift
      ;;
    --skip-logrotate)
      REMOVE_LOGROTATE=false
      shift
      ;;
    --dry-run)
      DRY_RUN=true
      shift
      ;;
    --help|-h)
      sed -n '1,38p' "$0"
      exit 0
      ;;
    *)
      echo "Unknown option: $1" >&2
      exit 1
      ;;
  esac
done

SUDO=""
if [[ "${EUID:-0}" -ne 0 ]]; then
  if command -v sudo >/dev/null 2>&1; then
    SUDO="sudo"
  else
    echo "Run as root or install sudo." >&2
    exit 1
  fi
fi

run_cmd() {
  if [[ "$DRY_RUN" == "true" ]]; then
    echo "[dry-run] $*"
    return
  fi
  "$@"
}

remove_file_if_exists() {
  local path="$1"
  if [[ -e "$path" || -L "$path" ]]; then
    echo "Remove: $path"
    run_cmd $SUDO rm -f "$path"
  fi
}

disable_nginx() {
  local available="/etc/nginx/sites-available/oqlook.conf"
  local enabled="/etc/nginx/sites-enabled/oqlook.conf"

  remove_file_if_exists "$enabled"
  remove_file_if_exists "$available"

  if command -v nginx >/dev/null 2>&1; then
    run_cmd $SUDO nginx -t
    run_cmd $SUDO systemctl reload nginx
  fi
}

disable_apache() {
  local conf="/etc/apache2/sites-available/oqlook.conf"

  if command -v a2dissite >/dev/null 2>&1; then
    run_cmd $SUDO a2dissite oqlook.conf || true
  fi
  remove_file_if_exists "$conf"

  if command -v apache2ctl >/dev/null 2>&1; then
    run_cmd $SUDO apache2ctl configtest
    run_cmd $SUDO systemctl reload apache2
  fi
}

disable_systemd_units() {
  if ! command -v systemctl >/dev/null 2>&1; then
    echo "systemctl not found, skipping systemd rollback."
    return
  fi

  local units=(
    "oqlook-queue-worker.service"
    "oqlook-watchdog.service"
    "oqlook-watchdog.timer"
  )

  for unit in "${units[@]}"; do
    run_cmd $SUDO systemctl disable --now "$unit" || true
  done

  remove_file_if_exists "/etc/systemd/system/oqlook-queue-worker.service"
  remove_file_if_exists "/etc/systemd/system/oqlook-watchdog.service"
  remove_file_if_exists "/etc/systemd/system/oqlook-watchdog.timer"

  run_cmd $SUDO systemctl daemon-reload
  run_cmd $SUDO systemctl reset-failed
}

remove_logrotate_rule() {
  remove_file_if_exists "/etc/logrotate.d/oqlook"
}

echo "== OQLook production hardening rollback =="
echo "Web server      : $WEB"
echo "Remove systemd  : $REMOVE_SYSTEMD"
echo "Remove logrotate: $REMOVE_LOGROTATE"
echo "Dry run         : $DRY_RUN"

case "$WEB" in
  nginx)
    disable_nginx
    ;;
  apache)
    disable_apache
    ;;
  auto)
    disable_nginx || true
    disable_apache || true
    ;;
  none)
    echo "Skipping web server rollback."
    ;;
  *)
    echo "Invalid --web value: $WEB (expected nginx|apache|auto|none)" >&2
    exit 1
    ;;
esac

if [[ "$REMOVE_SYSTEMD" == "true" ]]; then
  disable_systemd_units
fi

if [[ "$REMOVE_LOGROTATE" == "true" ]]; then
  remove_logrotate_rule
fi

if [[ "$DRY_RUN" == "true" ]]; then
  echo "Dry run complete."
  exit 0
fi

echo "Rollback complete."
