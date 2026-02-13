#!/usr/bin/env bash
set -euo pipefail

# OQLook Linux production hardening helper
#
# Installs production templates for:
# - Nginx or Apache vhost
# - systemd queue worker + watchdog timer
# - logrotate for Laravel logs
#
# Usage:
#   chmod +x scripts/install/linux/hardening.sh
#   sudo ./scripts/install/linux/hardening.sh --server-name oqlook.example.com --web nginx
#
# Options:
#   --app-dir <path>           Application root (default: repo root)
#   --server-name <name>       Domain / host (default: _)
#   --web <nginx|apache|none>  Web server template (default: nginx)
#   --php-bin <path>           PHP CLI binary (default: /usr/bin/php)
#   --php-fpm-socket <value>   Nginx fastcgi_pass (default: unix:/run/php/php8.2-fpm.sock)
#   --web-user <user>          Web user (default: www-data)
#   --web-group <group>        Web group (default: same as web-user)
#   --enable-systemd           Install queue worker + watchdog timer
#   --enable-logrotate         Install logrotate rule
#   --skip-systemd             Skip systemd setup
#   --skip-logrotate           Skip logrotate setup
#   --dry-run                  Show actions without writing files
#   --help                     Show help

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
SERVER_NAME="_"
WEB="nginx"
PHP_BIN="/usr/bin/php"
PHP_FPM_SOCKET="unix:/run/php/php8.2-fpm.sock"
WEB_USER="www-data"
WEB_GROUP=""
ENABLE_SYSTEMD=true
ENABLE_LOGROTATE=true
DRY_RUN=false

while [[ $# -gt 0 ]]; do
  case "$1" in
    --app-dir)
      APP_DIR="$2"
      shift 2
      ;;
    --server-name)
      SERVER_NAME="$2"
      shift 2
      ;;
    --web)
      WEB="$2"
      shift 2
      ;;
    --php-bin)
      PHP_BIN="$2"
      shift 2
      ;;
    --php-fpm-socket)
      PHP_FPM_SOCKET="$2"
      shift 2
      ;;
    --web-user)
      WEB_USER="$2"
      shift 2
      ;;
    --web-group)
      WEB_GROUP="$2"
      shift 2
      ;;
    --enable-systemd)
      ENABLE_SYSTEMD=true
      shift
      ;;
    --enable-logrotate)
      ENABLE_LOGROTATE=true
      shift
      ;;
    --skip-systemd)
      ENABLE_SYSTEMD=false
      shift
      ;;
    --skip-logrotate)
      ENABLE_LOGROTATE=false
      shift
      ;;
    --dry-run)
      DRY_RUN=true
      shift
      ;;
    --help|-h)
      sed -n '1,52p' "$0"
      exit 0
      ;;
    *)
      echo "Unknown option: $1" >&2
      exit 1
      ;;
  esac
done

if [[ -z "$WEB_GROUP" ]]; then
  WEB_GROUP="$WEB_USER"
fi

if [[ ! -d "$APP_DIR" ]]; then
  echo "Application directory not found: $APP_DIR" >&2
  exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(cd "$SCRIPT_DIR/../../.." && pwd)"
DEPLOY_DIR="$REPO_DIR/deploy/linux"

if [[ ! -d "$DEPLOY_DIR" ]]; then
  echo "Deploy templates not found at: $DEPLOY_DIR" >&2
  exit 1
fi

SUDO=""
if [[ "${EUID:-0}" -ne 0 ]]; then
  if command -v sudo >/dev/null 2>&1; then
    SUDO="sudo"
  else
    echo "Run as root or install sudo." >&2
    exit 1
  fi
fi

render_template() {
  local src="$1"
  local out="$2"
  sed \
    -e "s#__APP_DIR__#$APP_DIR#g" \
    -e "s#__SERVER_NAME__#$SERVER_NAME#g" \
    -e "s#__PHP_BIN__#$PHP_BIN#g" \
    -e "s#__PHP_FPM_SOCKET__#$PHP_FPM_SOCKET#g" \
    -e "s#__WEB_USER__#$WEB_USER#g" \
    -e "s#__WEB_GROUP__#$WEB_GROUP#g" \
    "$src" > "$out"
}

install_file() {
  local src="$1"
  local dst="$2"
  local mode="${3:-0644}"
  local tmp
  tmp="$(mktemp)"
  render_template "$src" "$tmp"

  echo "Install: $dst"
  if [[ "$DRY_RUN" == "true" ]]; then
    rm -f "$tmp"
    return
  fi

  $SUDO install -m "$mode" "$tmp" "$dst"
  rm -f "$tmp"
}

enable_nginx() {
  local available="/etc/nginx/sites-available/oqlook.conf"
  local enabled="/etc/nginx/sites-enabled/oqlook.conf"
  install_file "$DEPLOY_DIR/nginx/oqlook.conf" "$available" 0644

  if [[ "$DRY_RUN" != "true" ]]; then
    if [[ ! -L "$enabled" ]]; then
      $SUDO ln -s "$available" "$enabled"
    fi
    $SUDO nginx -t
    $SUDO systemctl reload nginx
  fi
}

enable_apache() {
  local conf="/etc/apache2/sites-available/oqlook.conf"
  install_file "$DEPLOY_DIR/apache/oqlook.conf" "$conf" 0644

  if [[ "$DRY_RUN" != "true" ]]; then
    $SUDO a2enmod rewrite headers >/dev/null 2>&1 || true
    $SUDO a2ensite oqlook.conf >/dev/null 2>&1 || true
    $SUDO apache2ctl configtest
    $SUDO systemctl reload apache2
  fi
}

enable_systemd_units() {
  install_file "$DEPLOY_DIR/systemd/oqlook-queue-worker.service" "/etc/systemd/system/oqlook-queue-worker.service" 0644
  install_file "$DEPLOY_DIR/systemd/oqlook-watchdog.service" "/etc/systemd/system/oqlook-watchdog.service" 0644
  install_file "$DEPLOY_DIR/systemd/oqlook-watchdog.timer" "/etc/systemd/system/oqlook-watchdog.timer" 0644

  if [[ "$DRY_RUN" != "true" ]]; then
    $SUDO systemctl daemon-reload
    $SUDO systemctl enable --now oqlook-queue-worker.service
    $SUDO systemctl enable --now oqlook-watchdog.timer
  fi
}

enable_logrotate_rule() {
  install_file "$DEPLOY_DIR/logrotate/oqlook" "/etc/logrotate.d/oqlook" 0644
}

echo "== OQLook production hardening =="
echo "App dir      : $APP_DIR"
echo "Server name  : $SERVER_NAME"
echo "Web server   : $WEB"
echo "PHP bin      : $PHP_BIN"
echo "Web user     : $WEB_USER:$WEB_GROUP"
echo "Dry run      : $DRY_RUN"

case "$WEB" in
  nginx)
    enable_nginx
    ;;
  apache)
    enable_apache
    ;;
  none)
    echo "Skipping web server config."
    ;;
  *)
    echo "Invalid --web value: $WEB (expected nginx|apache|none)" >&2
    exit 1
    ;;
esac

if [[ "$ENABLE_SYSTEMD" == "true" ]]; then
  enable_systemd_units
fi

if [[ "$ENABLE_LOGROTATE" == "true" ]]; then
  enable_logrotate_rule
fi

if [[ "$DRY_RUN" == "true" ]]; then
  echo "Dry run complete."
  exit 0
fi

echo "Done."
echo "Check services:"
echo "  systemctl status oqlook-queue-worker.service"
echo "  systemctl status oqlook-watchdog.timer"
echo "  journalctl -u oqlook-queue-worker.service -f"

