#!/usr/bin/env bash
# =============================================================================
# AstroPsy — Installation & Update Script
# Usage:
#   ./install.sh                # Interactive install
#   ./install.sh --update       # Update existing installation
#   ./install.sh --with-alpaca  # Include Alpaca service
# =============================================================================
set -euo pipefail

# --- Configuration ---
GHCR_OWNER="psy4"
APP_IMAGE="ghcr.io/${GHCR_OWNER}/astropsy-app"
ASTROPY_IMAGE="ghcr.io/${GHCR_OWNER}/astropsy-astropy"
VERSION="latest"

# --- Colors & helpers ---
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m'

info()  { echo -e "${CYAN}[info]${NC} $*"; }
ok()    { echo -e "${GREEN}  ✓${NC} $*"; }
warn()  { echo -e "${YELLOW}[warn]${NC} $*"; }
error() { echo -e "${RED}[error]${NC} $*" >&2; }
die()   { error "$*"; exit 1; }

# --- Parse arguments ---
MODE="install"
WITH_ALPACA=false
INSTALL_DIR=""

for arg in "$@"; do
    case "$arg" in
        --update)       MODE="update" ;;
        --with-alpaca)  WITH_ALPACA=true ;;
        --help|-h)
            echo "Usage: $0 [--update] [--with-alpaca]"
            echo ""
            echo "  --update       Update an existing AstroPsy installation"
            echo "  --with-alpaca  Include the Alpaca ASCOM service"
            exit 0
            ;;
        *) die "Unknown argument: $arg" ;;
    esac
done

# =============================================================================
# UPDATE MODE
# =============================================================================
do_update() {
    info "AstroPsy — Update mode"

    if [ ! -f docker-compose.yml ]; then
        die "No docker-compose.yml found in current directory. Run this from your AstroPsy install directory."
    fi

    info "Pulling latest images..."
    docker compose pull

    info "Restarting services..."
    docker compose up -d

    info "Waiting for database..."
    wait_for_db

    info "Running migrations..."
    docker compose exec -T app php bin/console doctrine:migrations:migrate -n --env=prod

    info "Clearing cache..."
    docker compose exec -T app php bin/console cache:clear --env=prod

    echo ""
    ok "AstroPsy updated successfully!"
}

# =============================================================================
# PREREQUISITE CHECKS
# =============================================================================
check_prerequisites() {
    local missing=false

    if ! command -v docker &>/dev/null; then
        error "Docker is not installed. Install it from https://docs.docker.com/get-docker/"
        missing=true
    fi

    if ! docker compose version &>/dev/null; then
        error "Docker Compose v2 is required. 'docker compose' command not found."
        missing=true
    fi

    if ! docker info &>/dev/null 2>&1; then
        error "Docker daemon is not running. Start Docker and try again."
        missing=true
    fi

    if ! command -v openssl &>/dev/null; then
        error "openssl is required (for generating secrets)."
        missing=true
    fi

    $missing && die "Missing prerequisites. Install them and retry."
}

# =============================================================================
# HELPERS
# =============================================================================
ask() {
    local prompt="$1" default="$2" var="$3"
    if [ -n "$default" ]; then
        read -rp "$(echo -e "${CYAN}?${NC} ${prompt} [${default}]: ")" value
        eval "$var=\"${value:-$default}\""
    else
        read -rp "$(echo -e "${CYAN}?${NC} ${prompt}: ")" value
        eval "$var=\"$value\""
    fi
}

ask_yn() {
    local prompt="$1" default="$2"
    local yn
    read -rp "$(echo -e "${CYAN}?${NC} ${prompt} (o/N): ")" yn
    case "$yn" in
        [oOyY]*) return 0 ;;
        *)       return 1 ;;
    esac
}

detect_timezone() {
    if command -v timedatectl &>/dev/null; then
        timedatectl show --property=Timezone --value 2>/dev/null && return
    fi
    if [ -f /etc/timezone ]; then
        cat /etc/timezone && return
    fi
    if [ -L /etc/localtime ]; then
        readlink /etc/localtime | sed 's|.*/zoneinfo/||' && return
    fi
    echo "UTC"
}

wait_for_db() {
    local max_wait=60
    local elapsed=0
    while [ $elapsed -lt $max_wait ]; do
        if docker compose exec -T db pg_isready -U astro &>/dev/null; then
            return 0
        fi
        sleep 2
        elapsed=$((elapsed + 2))
    done
    die "Database did not become ready within ${max_wait}s"
}

# =============================================================================
# INTERACTIVE WIZARD
# =============================================================================
run_wizard() {
    echo ""
    echo -e "${CYAN}╔══════════════════════════════════════╗${NC}"
    echo -e "${CYAN}║     AstroPsy — Installation          ║${NC}"
    echo -e "${CYAN}╚══════════════════════════════════════╝${NC}"
    echo ""

    # 1. Install directory
    ask "Installation directory" "$HOME/astropsy" INSTALL_DIR
    mkdir -p "$INSTALL_DIR"

    # 2. Sessions directory
    local sessions_path=""
    ask "Astro sessions directory (where your images are stored)" "" sessions_path
    if [ -n "$sessions_path" ] && [ ! -d "$sessions_path" ]; then
        if ask_yn "Directory '$sessions_path' does not exist. Create it?"; then
            mkdir -p "$sessions_path"
        fi
    fi

    # 3. Timezone
    local detected_tz
    detected_tz=$(detect_timezone)
    ask "Timezone" "$detected_tz" TIMEZONE

    # 4. Web port
    ask "Web port" "8080" WEB_PORT

    # 5. NAS CIFS mount
    USE_CIFS=false
    CIFS_SERVER="" CIFS_SHARE="" CIFS_USER="" CIFS_PASS=""
    if ask_yn "Mount a NAS share via CIFS/SMB?"; then
        USE_CIFS=true
        ask "NAS IP address" "" CIFS_SERVER
        ask "Share name (e.g. astro-data)" "" CIFS_SHARE
        ask "CIFS username" "" CIFS_USER
        read -rsp "$(echo -e "${CYAN}?${NC} CIFS password: ")" CIFS_PASS
        echo ""
        sessions_path=""  # CIFS replaces bind mount
    fi

    # 6. SMTP
    MAILER_DSN="null://null"
    if ask_yn "Configure SMTP email notifications?"; then
        local smtp_host smtp_port smtp_user smtp_pass
        ask "SMTP host" "" smtp_host
        ask "SMTP port" "587" smtp_port
        ask "SMTP username" "" smtp_user
        read -rsp "$(echo -e "${CYAN}?${NC} SMTP password: ")" smtp_pass
        echo ""
        MAILER_DSN="smtp://${smtp_user}:${smtp_pass}@${smtp_host}:${smtp_port}"
    fi

    # 7. Alpaca
    ALPACA_URL=""
    if $WITH_ALPACA || ask_yn "Include Alpaca ASCOM service?"; then
        WITH_ALPACA=true
        ask "Alpaca base URL" "http://localhost:11111" ALPACA_URL
    fi

    # Generate secrets
    APP_SECRET=$(openssl rand -hex 32)
    DB_PASSWORD=$(openssl rand -hex 16)

    SESSIONS_PATH="$sessions_path"
}

# =============================================================================
# FILE GENERATION
# =============================================================================
generate_env() {
    cat > "$INSTALL_DIR/.env" <<EOF
# AstroPsy — Generated by install.sh
APP_SECRET=${APP_SECRET}
DB_PASSWORD=${DB_PASSWORD}
SESSIONS_PATH=${SESSIONS_PATH}
TIMEZONE=${TIMEZONE}
WEB_PORT=${WEB_PORT}
MAILER_DSN=${MAILER_DSN}
EOF

    if $USE_CIFS; then
        cat >> "$INSTALL_DIR/.env" <<EOF
CIFS_SERVER=${CIFS_SERVER}
CIFS_SHARE=${CIFS_SHARE}
CIFS_USER=${CIFS_USER}
CIFS_PASS=${CIFS_PASS}
EOF
    fi

    if $WITH_ALPACA; then
        cat >> "$INSTALL_DIR/.env" <<EOF
ALPACA_BASE_URL=${ALPACA_URL}
EOF
    fi
}

generate_compose() {
    # --- Sessions volume/mount ---
    local sessions_volume_def=""
    local sessions_mount=""

    if $USE_CIFS; then
        sessions_mount="      - sessions:/app/data/sessions"
        sessions_volume_def=$(cat <<'CIFSEOF'
  sessions:
    driver_opts:
      type: cifs
      o: "username=${CIFS_USER},password=${CIFS_PASS},file_mode=0777,dir_mode=0777"
      device: "//${CIFS_SERVER}/${CIFS_SHARE}"
CIFSEOF
)
    else
        sessions_mount="      - \${SESSIONS_PATH}:/app/data/sessions"
    fi

    # --- Alpaca service block ---
    local alpaca_block=""
    local alpaca_env=""
    if $WITH_ALPACA; then
        alpaca_env="      ALPACA_BASE_URL: \${ALPACA_BASE_URL:-http://localhost:11111}"
        alpaca_block=$(cat <<'ALPEOF'

  alpaca:
    image: ghcr.io/GHCR_OWNER/astropsy-alpaca:latest
    network_mode: host
    restart: unless-stopped
ALPEOF
)
        alpaca_block="${alpaca_block//GHCR_OWNER/$GHCR_OWNER}"
    fi

    # --- Write docker-compose.yml ---
    cat > "$INSTALL_DIR/docker-compose.yml" <<EOF
# AstroPsy — Production Compose (generated by install.sh)
services:
  app:
    image: ${APP_IMAGE}:${VERSION}
    restart: unless-stopped
    ports:
      - "\${WEB_PORT:-8080}:8080"
    volumes:
      - app_var:/app/var
${sessions_mount}
      - thumb_cache:/var/cache/thumbs
    environment:
      APP_ENV: prod
      APP_SECRET: \${APP_SECRET}
      DATABASE_URL: postgresql://astro:\${DB_PASSWORD}@db:5432/astro?serverVersion=16&charset=utf8
      ASTROPY_BASE_URL: http://astropy:8000
      TZ: \${TIMEZONE}
      MAILER_DSN: \${MAILER_DSN:-null://null}
      SESSIONS_ROOT: /app/data/sessions
EOF

    if $WITH_ALPACA; then
        echo "$alpaca_env" >> "$INSTALL_DIR/docker-compose.yml"
    fi

    cat >> "$INSTALL_DIR/docker-compose.yml" <<EOF
    depends_on:
      db: { condition: service_started }
      astropy: { condition: service_healthy }
    labels:
      ofelia.enabled: "true"
      ofelia.job-exec.notify_evening.schedule: "0 0 20 * * *"
      ofelia.job-exec.notify_evening.command: "php /app/bin/console app:notify:evening --no-interaction --env=prod"

  db:
    image: timescale/timescaledb:latest-pg16
    restart: unless-stopped
    environment:
      POSTGRES_DB: astro
      POSTGRES_USER: astro
      POSTGRES_PASSWORD: \${DB_PASSWORD}
    volumes:
      - db_data:/var/lib/postgresql/data

  astropy:
    image: ${ASTROPY_IMAGE}:${VERSION}
    restart: unless-stopped

  scheduler:
    image: mcuadros/ofelia:latest
    restart: unless-stopped
    command: daemon --docker
    environment:
      TZ: \${TIMEZONE}
    volumes:
      - /var/run/docker.sock:/var/run/docker.sock:ro
    depends_on: [app]
EOF

    if $WITH_ALPACA; then
        echo "$alpaca_block" >> "$INSTALL_DIR/docker-compose.yml"
    fi

    cat >> "$INSTALL_DIR/docker-compose.yml" <<EOF

volumes:
  db_data:
  app_var:
  thumb_cache:
EOF

    if $USE_CIFS; then
        echo "$sessions_volume_def" >> "$INSTALL_DIR/docker-compose.yml"
    fi
}

# =============================================================================
# POST-INSTALL
# =============================================================================
post_install() {
    cd "$INSTALL_DIR"

    info "Pulling Docker images..."
    docker compose pull

    info "Starting services..."
    docker compose up -d

    info "Waiting for database..."
    wait_for_db

    info "Running database migrations..."
    docker compose exec -T app php bin/console doctrine:migrations:migrate -n --env=prod

    info "Clearing cache..."
    docker compose exec -T app php bin/console cache:clear --env=prod

    echo ""
    echo -e "${GREEN}╔══════════════════════════════════════════════╗${NC}"
    echo -e "${GREEN}║                                              ║${NC}"
    echo -e "${GREEN}║   ✓ AstroPsy installed successfully!         ║${NC}"
    echo -e "${GREEN}║                                              ║${NC}"
    echo -e "${GREEN}╚══════════════════════════════════════════════╝${NC}"
    echo ""
    echo -e "  → Open ${CYAN}http://localhost:${WEB_PORT}${NC}"
    echo ""
    echo "  Useful commands:"
    echo "    cd ${INSTALL_DIR}"
    echo "    docker compose logs -f         # View logs"
    echo "    docker compose down            # Stop"
    echo "    docker compose up -d           # Start"
    echo "    ./install.sh --update          # Update"
    echo ""
    echo "  Database backup:"
    echo "    docker compose exec db pg_dump -U astro astro > backup.sql"
    echo ""
}

# =============================================================================
# MAIN
# =============================================================================
main() {
    if [ "$MODE" = "update" ]; then
        do_update
        exit 0
    fi

    echo ""
    info "Checking prerequisites..."
    check_prerequisites
    ok "All prerequisites met"

    run_wizard

    info "Generating configuration files..."
    generate_env
    generate_compose
    ok "Files generated in ${INSTALL_DIR}"

    # Copy install.sh to install dir for future updates
    if [ "$(realpath "$0")" != "$(realpath "$INSTALL_DIR/install.sh")" ] 2>/dev/null; then
        cp "$0" "$INSTALL_DIR/install.sh"
        chmod +x "$INSTALL_DIR/install.sh"
    fi

    post_install
}

main
