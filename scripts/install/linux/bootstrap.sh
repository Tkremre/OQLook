#!/usr/bin/env bash
set -euo pipefail

# OQLook Linux bootstrap
# - Installs system dependencies (optional)
# - Configures application and runs Laravel setup commands
#
# Usage:
#   chmod +x scripts/install/linux/bootstrap.sh
#   ./scripts/install/linux/bootstrap.sh --install-deps
#
# Options:
#   --app-dir <path>            Application root path (default: repo root)
#   --install-deps              Install OS packages, Composer, Node.js
#   --skip-build                Skip npm install/build
#   --skip-migrate              Skip php artisan migrate --force
#   --node-major <version>      Node.js major for NodeSource (default: 22)
#   --php-bin <binary>          PHP binary (default: php)
#   --composer-bin <binary>     Composer binary (default: composer)
#   --npm-bin <binary>          npm binary (default: npm)
#   --web-user <user>           Web user for permissions (default: www-data)
#   --help                      Show help

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
INSTALL_DEPS=false
SKIP_BUILD=false
SKIP_MIGRATE=false
NODE_MAJOR=22
PHP_BIN="php"
COMPOSER_BIN="composer"
NPM_BIN="npm"
WEB_USER="www-data"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --app-dir)
      APP_DIR="$2"
      shift 2
      ;;
    --install-deps)
      INSTALL_DEPS=true
      shift
      ;;
    --skip-build)
      SKIP_BUILD=true
      shift
      ;;
    --skip-migrate)
      SKIP_MIGRATE=true
      shift
      ;;
    --node-major)
      NODE_MAJOR="$2"
      shift 2
      ;;
    --php-bin)
      PHP_BIN="$2"
      shift 2
      ;;
    --composer-bin)
      COMPOSER_BIN="$2"
      shift 2
      ;;
    --npm-bin)
      NPM_BIN="$2"
      shift 2
      ;;
    --web-user)
      WEB_USER="$2"
      shift 2
      ;;
    --help|-h)
      sed -n '1,45p' "$0"
      exit 0
      ;;
    *)
      echo "Unknown option: $1" >&2
      exit 1
      ;;
  esac
done

if [[ ! -d "$APP_DIR" ]]; then
  echo "Application directory not found: $APP_DIR" >&2
  exit 1
fi

SUDO=""
if [[ "${EUID:-0}" -ne 0 ]]; then
  if command -v sudo >/dev/null 2>&1; then
    SUDO="sudo"
  else
    echo "This script needs root privileges for dependency installation (sudo not found)." >&2
    echo "Run as root or install dependencies manually." >&2
    INSTALL_DEPS=false
  fi
fi

has_cmd() {
  command -v "$1" >/dev/null 2>&1
}

detect_pm() {
  if has_cmd apt-get; then
    echo "apt"
    return
  fi
  if has_cmd dnf; then
    echo "dnf"
    return
  fi
  if has_cmd yum; then
    echo "yum"
    return
  fi
  if has_cmd zypper; then
    echo "zypper"
    return
  fi
  if has_cmd pacman; then
    echo "pacman"
    return
  fi
  echo "unknown"
}

install_with_apt() {
  $SUDO apt-get update
  $SUDO apt-get install -y ca-certificates curl unzip git lsb-release software-properties-common
  $SUDO apt-get install -y php-cli php-fpm php-mbstring php-xml php-curl php-zip php-intl php-bcmath php-pgsql php-sqlite3 php-gd php-ldap
  $SUDO apt-get install -y postgresql-client redis-tools
}

install_with_dnf_or_yum() {
  local PM="$1"
  $SUDO "$PM" install -y ca-certificates curl unzip git
  $SUDO "$PM" install -y php php-cli php-fpm php-mbstring php-xml php-curl php-zip php-intl php-bcmath php-pgsql php-sqlite3 php-gd php-ldap
  $SUDO "$PM" install -y postgresql redis
}

install_with_zypper() {
  $SUDO zypper --non-interactive install ca-certificates curl unzip git
  $SUDO zypper --non-interactive install php8 php8-cli php8-fpm php8-mbstring php8-xmlreader php8-curl php8-zip php8-intl php8-pgsql php8-sqlite3 php8-gd php8-ldap
  $SUDO zypper --non-interactive install postgresql redis
}

install_with_pacman() {
  $SUDO pacman -Sy --noconfirm --needed ca-certificates curl unzip git
  $SUDO pacman -Sy --noconfirm --needed php php-fpm php-gd php-intl postgresql-libs redis
}

install_composer_if_missing() {
  if has_cmd "$COMPOSER_BIN"; then
    return
  fi

  echo "Installing Composer..."
  local TMP_INSTALLER
  TMP_INSTALLER="$(mktemp)"
  curl -fsSL https://getcomposer.org/installer -o "$TMP_INSTALLER"
  $PHP_BIN "$TMP_INSTALLER" --install-dir=/usr/local/bin --filename=composer
  rm -f "$TMP_INSTALLER"
  COMPOSER_BIN="composer"
}

install_node_if_missing() {
  if has_cmd node && has_cmd "$NPM_BIN"; then
    return
  fi

  echo "Installing Node.js v${NODE_MAJOR}..."
  if has_cmd apt-get; then
    curl -fsSL "https://deb.nodesource.com/setup_${NODE_MAJOR}.x" | $SUDO -E bash -
    $SUDO apt-get install -y nodejs
    return
  fi

  if has_cmd dnf; then
    curl -fsSL "https://rpm.nodesource.com/setup_${NODE_MAJOR}.x" | $SUDO bash -
    $SUDO dnf install -y nodejs
    return
  fi

  if has_cmd yum; then
    curl -fsSL "https://rpm.nodesource.com/setup_${NODE_MAJOR}.x" | $SUDO bash -
    $SUDO yum install -y nodejs
    return
  fi

  echo "Node.js automatic installation is not supported on this distro. Install Node.js ${NODE_MAJOR}+ manually." >&2
}

if [[ "$INSTALL_DEPS" == "true" ]]; then
  PM="$(detect_pm)"
  echo "Installing dependencies using package manager: $PM"
  case "$PM" in
    apt)
      install_with_apt
      ;;
    dnf|yum)
      install_with_dnf_or_yum "$PM"
      ;;
    zypper)
      install_with_zypper
      ;;
    pacman)
      install_with_pacman
      ;;
    *)
      echo "Unsupported package manager. Install dependencies manually." >&2
      ;;
  esac

  install_composer_if_missing
  install_node_if_missing
fi

cd "$APP_DIR"
echo "Working directory: $APP_DIR"

if [[ ! -f ".env" && -f ".env.example" ]]; then
  cp .env.example .env
  echo "Created .env from .env.example"
fi

echo "Installing PHP dependencies..."
"$COMPOSER_BIN" install --no-interaction --prefer-dist

if [[ "$SKIP_BUILD" != "true" ]]; then
  echo "Installing frontend dependencies..."
  if [[ -f "package-lock.json" ]]; then
    "$NPM_BIN" ci
  else
    "$NPM_BIN" install
  fi

  echo "Building frontend..."
  "$NPM_BIN" run build
fi

echo "Generating app key..."
"$PHP_BIN" artisan key:generate --force

if [[ "$SKIP_MIGRATE" != "true" ]]; then
  echo "Running database migrations..."
  "$PHP_BIN" artisan migrate --force
fi

echo "Clearing cache..."
"$PHP_BIN" artisan optimize:clear

if [[ -d "storage" && -d "bootstrap/cache" ]]; then
  if id "$WEB_USER" >/dev/null 2>&1; then
    echo "Setting write permissions for $WEB_USER..."
    $SUDO chown -R "$WEB_USER":"$WEB_USER" storage bootstrap/cache || true
  fi
fi

echo "Done."
echo "Next steps:"
echo "  1) Configure .env (DB, APP_URL, OQLIKE_*)"
echo "  2) Start worker if needed: $PHP_BIN artisan queue:work --queue=default --tries=1"
echo "  3) Configure web server root to: $APP_DIR/public"

