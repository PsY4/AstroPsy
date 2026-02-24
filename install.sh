#!/usr/bin/env bash
# =============================================================================
# AstroPsy — Installation & Update Script
# Usage:
#   ./install.sh                # Interactive install
#   ./install.sh --update       # Update existing installation
#   ./install.sh --reconfigure  # Reconfigure existing installation
#   ./install.sh --uninstall    # Remove AstroPsy completely
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
BOLD='\033[1m'
DIM='\033[2m'
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
        --reconfigure)  MODE="reconfigure" ;;
        --uninstall)    MODE="uninstall" ;;
        --with-alpaca)  WITH_ALPACA=true ;;
        --help|-h)
            echo "Usage: $0 [OPTIONS]"
            echo ""
            echo "  --update        Update an existing AstroPsy installation"
            echo "  --reconfigure   Reconfigure and restart (re-run the setup wizard)"
            echo "  --uninstall     Remove AstroPsy (containers, volumes, images, config)"
            echo "  --with-alpaca   Include the Alpaca ASCOM service"
            exit 0
            ;;
        *) die "Unknown argument: $arg" ;;
    esac
done

# =============================================================================
# STEP DISPLAY
# =============================================================================
step() {
    echo -ne "  ${CYAN}…${NC} $1"
}

step_ok() {
    echo -e "\r\033[2K  ${GREEN}✓${NC} $1"
}

step_fail() {
    echo -e "\r\033[2K  ${RED}✗${NC} $1"
}

# =============================================================================
# UPDATE MODE
# =============================================================================
do_update() {
    show_banner

    if [ ! -f docker-compose.yml ]; then
        die "No docker-compose.yml found in current directory. Run this from your AstroPsy install directory."
    fi

    # Backup database before update
    info "Backing up database..."
    local backup_file="backup_$(date +%Y%m%d_%H%M%S).sql"
    if docker compose exec -T db pg_isready -U astro &>/dev/null; then
        if docker compose exec -T db pg_dump -U astro astro > "$backup_file" 2>/dev/null; then
            ok "Database backed up to ${backup_file}"
        else
            warn "Could not backup database, continuing anyway..."
        fi
    else
        warn "Database not running, skipping backup"
    fi

    step "Downloading latest release..."
    docker compose pull --quiet 2>/dev/null
    step_ok "Downloaded latest release"

    step "Restarting services, please wait..."
    docker compose up -d 2>/dev/null
    step_ok "Services restarted"

    step "Waiting for database..."
    wait_for_db
    step_ok "Database ready"

    step "Updating database..."
    docker compose exec -T app php bin/console doctrine:migrations:migrate -n --env=prod &>/dev/null
    step_ok "Database updated"

    step "Preparing app..."
    docker compose exec -T app php bin/console cache:clear --env=prod &>/dev/null
    step_ok "App ready"

    # Health check
    local ip port
    ip=$(detect_local_ip)
    port=$(grep -oP 'WEB_PORT=\K.*' .env 2>/dev/null || echo "8080")
    check_health "$ip" "$port"

    show_final_banner "$ip" "$port" "Update complete!"
}

# =============================================================================
# RECONFIGURE MODE
# =============================================================================
do_reconfigure() {
    if [ ! -f docker-compose.yml ]; then
        die "No docker-compose.yml found in current directory. Run this from your AstroPsy install directory."
    fi

    INSTALL_DIR="$(pwd)"
    run_wizard

    info "Generating configuration files..."
    generate_env
    generate_compose
    ok "Files regenerated"

    step "Restarting services, please wait..."
    docker compose down 2>/dev/null
    docker compose up -d 2>/dev/null
    step_ok "Services restarted"

    step "Waiting for database..."
    wait_for_db
    step_ok "Database ready"

    step "Preparing app..."
    docker compose exec -T app php bin/console cache:clear --env=prod &>/dev/null
    step_ok "App ready"

    local ip
    ip=$(detect_local_ip)
    check_health "$ip" "$WEB_PORT"

    show_final_banner "$ip" "$WEB_PORT" "Reconfiguration complete!"
}

# =============================================================================
# UNINSTALL MODE
# =============================================================================
do_uninstall() {
    show_banner

    if [ ! -f docker-compose.yml ]; then
        die "No docker-compose.yml found in current directory. Run this from your AstroPsy install directory."
    fi

    local install_path
    install_path="$(pwd)"

    echo -e "  ${RED}${BOLD}This will permanently remove:${NC}"
    echo -e "  ${DIM}  - All AstroPsy containers and networks${NC}"
    echo -e "  ${DIM}  - All data volumes (database, cache, app data)${NC}"
    echo -e "  ${DIM}  - Downloaded Docker images${NC}"
    echo -e "  ${DIM}  - Configuration files in ${install_path}${NC}"
    echo ""
    echo -e "  ${YELLOW}Your astro sessions directory will NOT be deleted.${NC}"
    echo ""

    if ! ask_yn "Are you sure you want to uninstall AstroPsy?"; then
        info "Uninstall cancelled."
        exit 0
    fi

    # Optional: backup before uninstall
    if docker compose exec -T db pg_isready -U astro &>/dev/null 2>&1; then
        if ask_yn "Backup the database before uninstalling?"; then
            local backup_file="backup_final_$(date +%Y%m%d_%H%M%S).sql"
            docker compose exec -T db pg_dump -U astro astro > "$backup_file" 2>/dev/null
            ok "Database backed up to ${install_path}/${backup_file}"
        fi
    fi

    step "Stopping and removing containers..."
    docker compose down -v 2>/dev/null
    step_ok "Containers and volumes removed"

    step "Removing Docker images..."
    docker rmi "${APP_IMAGE}:${VERSION}" "${ASTROPY_IMAGE}:${VERSION}" \
        "timescale/timescaledb:latest-pg16" "mcuadros/ofelia:latest" 2>/dev/null || true
    step_ok "Docker images removed"

    # Remove config files (but not backup files)
    rm -f docker-compose.yml .env install.sh 2>/dev/null

    echo ""
    ok "AstroPsy has been uninstalled."
    echo -e "  ${DIM}Backup files (if any) remain in ${install_path}${NC}"
    echo ""
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

    if $missing; then
        die "Missing prerequisites. Install them and retry."
    fi
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

ask_sub() {
    local prompt="$1" default="$2" var="$3"
    if [ -n "$default" ]; then
        read -rp "$(echo -e "   ${CYAN}→${NC} ${prompt} [${default}]: ")" value
        eval "$var=\"${value:-$default}\""
    else
        read -rp "$(echo -e "   ${CYAN}→${NC} ${prompt}: ")" value
        eval "$var=\"$value\""
    fi
}

ask_yn() {
    local prompt="$1"
    local yn
    read -rp "$(echo -e "${CYAN}?${NC} ${prompt} (y/N): ")" yn
    case "$yn" in
        [yY]*) return 0 ;;
        *)       return 1 ;;
    esac
}

detect_timezone() {
    # Try local system config first
    if command -v timedatectl &>/dev/null; then
        local tz
        tz=$(timedatectl show --property=Timezone --value 2>/dev/null)
        if [ -n "$tz" ] && [ "$tz" != "UTC" ] && [ "$tz" != "Etc/UTC" ]; then
            echo "$tz"
            return
        fi
    fi
    if [ -f /etc/timezone ]; then
        local tz
        tz=$(cat /etc/timezone 2>/dev/null)
        if [ -n "$tz" ] && [ "$tz" != "UTC" ] && [ "$tz" != "Etc/UTC" ]; then
            echo "$tz"
            return
        fi
    fi
    if [ -L /etc/localtime ]; then
        local tz
        tz=$(readlink /etc/localtime | sed 's|.*/zoneinfo/||')
        if [ -n "$tz" ] && [ "$tz" != "UTC" ] && [ "$tz" != "Etc/UTC" ]; then
            echo "$tz"
            return
        fi
    fi
    # Fallback: detect via IP geolocation
    if command -v curl &>/dev/null; then
        local tz
        tz=$(curl -sf --max-time 3 "https://ipapi.co/timezone" 2>/dev/null)
        if [ -n "$tz" ] && [[ "$tz" == *"/"* ]]; then
            echo "$tz"
            return
        fi
    fi
    echo "UTC"
}

detect_local_ip() {
    if command -v hostname &>/dev/null; then
        local ip
        ip=$(hostname -I 2>/dev/null | awk '{print $1}')
        if [ -n "$ip" ]; then
            echo "$ip"
            return
        fi
    fi
    if command -v ip &>/dev/null; then
        local ip
        ip=$(ip route get 1.1.1.1 2>/dev/null | awk '{for(i=1;i<=NF;i++) if($i=="src") print $(i+1)}')
        if [ -n "$ip" ]; then
            echo "$ip"
            return
        fi
    fi
    echo "localhost"
}

check_port() {
    local port="$1"
    if command -v ss &>/dev/null; then
        if ss -tlnp 2>/dev/null | grep -q ":${port} "; then
            return 1
        fi
    elif command -v netstat &>/dev/null; then
        if netstat -tlnp 2>/dev/null | grep -q ":${port} "; then
            return 1
        fi
    fi
    return 0
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

check_health() {
    local ip="$1" port="$2"
    local max_wait=30
    local elapsed=0
    step "Verifying AstroPsy is responding..."
    while [ $elapsed -lt $max_wait ]; do
        if curl -sf -o /dev/null "http://127.0.0.1:${port}/" 2>/dev/null || \
           curl -sf -o /dev/null "http://127.0.0.1:${port}/login" 2>/dev/null; then
            step_ok "AstroPsy is up and running"
            return 0
        fi
        sleep 2
        elapsed=$((elapsed + 2))
    done
    step_fail "AstroPsy did not respond within ${max_wait}s"
    warn "The app may still be starting. Try opening http://${ip}:${port} in a few moments."
}

show_banner() {
    clear
    echo ""
    echo -e "${CYAN}${BOLD}     ___         __           ____"
    echo -e "    /   |  _____/ /__________/ __ \\________  __"
    echo -e "   / /| | / ___/ __/ ___/ __ / /_/ / ___/ / / /"
    echo -e "  / ___ |(__  ) /_/ /  / /_/ / ____(__  ) /_/ /"
    echo -e " /_/  |_/____/\\__/_/   \\____/_/   /____/\\__, /"
    echo -e "                                       /____/${NC}"
    echo ""
    echo -e "  ${DIM}Digital Asset Management for Astrophotographers${NC}"
    echo -e "  ${DIM}─────────────────────────────────────────────────${NC}"
    echo ""
}

show_final_banner() {
    local ip="$1" port="$2" msg="$3" install_dir="${4:-$(pwd)}"
    echo ""
    echo -e "${GREEN}${BOLD}     ___         __           ____"
    echo -e "    /   |  _____/ /__________/ __ \\________  __"
    echo -e "   / /| | / ___/ __/ ___/ __ / /_/ / ___/ / / /"
    echo -e "  / ___ |(__  ) /_/ /  / /_/ / ____(__  ) /_/ /"
    echo -e " /_/  |_/____/\\__/_/   \\____/_/   /____/\\__, /"
    echo -e "                                       /____/${NC}"
    echo ""
    echo -e "  ${GREEN}${BOLD}✓ ${msg}${NC}"
    echo ""
    echo -e "  → Open ${CYAN}${BOLD}http://${ip}:${port}${NC}"
    echo ""
    echo -e "  ${DIM}Useful commands (run from ${install_dir}):${NC}"
    echo -e "  ${DIM}  docker compose logs -f         # View logs${NC}"
    echo -e "  ${DIM}  docker compose down            # Stop${NC}"
    echo -e "  ${DIM}  docker compose up -d           # Start${NC}"
    echo -e "  ${DIM}  ./install.sh --update          # Update${NC}"
    echo -e "  ${DIM}  ./install.sh --reconfigure     # Reconfigure${NC}"
    echo -e "  ${DIM}  ./install.sh --uninstall       # Uninstall${NC}"
    echo ""
    echo -e "  ${DIM}Database backup:${NC}"
    echo -e "  ${DIM}  docker compose exec db pg_dump -U astro astro > backup.sql${NC}"
    echo ""
}

# =============================================================================
# INTERACTIVE WIZARD
# =============================================================================
run_wizard() {
    show_banner

    # 1. Install directory
    ask "Installation directory" "$HOME/astropsy" INSTALL_DIR
    mkdir -p "$INSTALL_DIR"

    # 2. Sessions: NAS or local path
    USE_CIFS=false
    CIFS_SERVER="" CIFS_SHARE="" CIFS_USER="" CIFS_PASS=""
    local sessions_path=""

    if ask_yn "Mount a NAS share (CIFS/SMB) for your astro sessions?"; then
        USE_CIFS=true
        ask_sub "NAS IP address" "" CIFS_SERVER
        ask_sub "Share path (e.g. astro-data/sessions)" "" CIFS_SHARE
        ask_sub "CIFS username" "" CIFS_USER
        read -rsp "$(echo -e "   ${CYAN}→${NC} CIFS password: ")" CIFS_PASS
        echo ""
    else
        ask "Local sessions directory (where your images are stored)" "" sessions_path
        if [ -n "$sessions_path" ] && [ ! -d "$sessions_path" ]; then
            if ask_yn "Directory '$sessions_path' does not exist. Create it?"; then
                mkdir -p "$sessions_path"
            fi
        fi
    fi

    # 3. Timezone
    local detected_tz
    detected_tz=$(detect_timezone)
    ask "Timezone" "$detected_tz" TIMEZONE

    # 4. Web port (with availability check)
    while true; do
        ask "Web port" "8080" WEB_PORT
        if check_port "$WEB_PORT"; then
            break
        else
            warn "Port ${WEB_PORT} is already in use. Please choose another port."
        fi
    done

    # 5. SMTP
    MAILER_DSN="null://null"
    if ask_yn "Configure SMTP email notifications?"; then
        local smtp_host smtp_port smtp_user smtp_pass
        ask_sub "SMTP host" "" smtp_host
        ask_sub "SMTP port" "587" smtp_port
        ask_sub "SMTP username" "" smtp_user
        read -rsp "$(echo -e "   ${CYAN}→${NC} SMTP password: ")" smtp_pass
        echo ""
        MAILER_DSN="smtp://${smtp_user}:${smtp_pass}@${smtp_host}:${smtp_port}"
    fi

    # 6. Alpaca
    ALPACA_URL=""
    if $WITH_ALPACA || ask_yn "Include Alpaca ASCOM service?"; then
        WITH_ALPACA=true
        ask_sub "Alpaca base URL" "http://localhost:11111" ALPACA_URL
    fi

    # Generate secrets
    APP_SECRET=$(openssl rand -hex 32)
    DB_PASSWORD=$(openssl rand -hex 16)

    SESSIONS_PATH="$sessions_path"
}

# =============================================================================
# SUMMARY & CONFIRMATION
# =============================================================================
show_summary() {
    local ip
    ip=$(detect_local_ip)

    echo ""
    echo -e "  ${BOLD}Configuration summary${NC}"
    echo -e "  ${DIM}─────────────────────────────────────────────────${NC}"
    echo -e "  ${DIM}Install directory${NC}   ${INSTALL_DIR}"
    if $USE_CIFS; then
        echo -e "  ${DIM}Sessions${NC}            NAS //${CIFS_SERVER}/${CIFS_SHARE}"
    elif [ -n "$SESSIONS_PATH" ]; then
        echo -e "  ${DIM}Sessions${NC}            ${SESSIONS_PATH}"
    else
        echo -e "  ${DIM}Sessions${NC}            ${DIM}(not configured)${NC}"
    fi
    echo -e "  ${DIM}Timezone${NC}            ${TIMEZONE}"
    echo -e "  ${DIM}Web port${NC}            ${WEB_PORT} → http://${ip}:${WEB_PORT}"
    if [ "$MAILER_DSN" != "null://null" ]; then
        echo -e "  ${DIM}Email${NC}               SMTP configured"
    else
        echo -e "  ${DIM}Email${NC}               ${DIM}disabled${NC}"
    fi
    if $WITH_ALPACA; then
        echo -e "  ${DIM}Alpaca${NC}              ${ALPACA_URL}"
    else
        echo -e "  ${DIM}Alpaca${NC}              ${DIM}disabled${NC}"
    fi
    echo -e "  ${DIM}─────────────────────────────────────────────────${NC}"
    echo ""

    if ! ask_yn "Proceed with installation?"; then
        info "Installation cancelled."
        exit 0
    fi
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

    step "Downloading AstroPsy, please wait..."
    docker compose pull --quiet 2>/dev/null
    step_ok "AstroPsy downloaded"

    step "Starting services, please wait..."
    docker compose up -d 2>/dev/null
    step_ok "Services started"

    step "Waiting for database..."
    wait_for_db
    step_ok "Database ready"

    step "Setting up database..."
    docker compose exec -T app php bin/console doctrine:migrations:migrate -n --env=prod &>/dev/null
    step_ok "Database ready"

    step "Preparing app..."
    docker compose exec -T app php bin/console cache:clear --env=prod &>/dev/null
    step_ok "App ready"

    local ip
    ip=$(detect_local_ip)

    # Health check
    check_health "$ip" "$WEB_PORT"

    show_final_banner "$ip" "$WEB_PORT" "Installation complete!"
}

# =============================================================================
# MAIN
# =============================================================================
main() {
    case "$MODE" in
        update)
            do_update
            exit 0
            ;;
        reconfigure)
            do_reconfigure
            exit 0
            ;;
        uninstall)
            show_banner
            do_uninstall
            exit 0
            ;;
    esac

    echo ""
    info "Checking prerequisites..."
    check_prerequisites
    ok "All prerequisites met"

    run_wizard
    show_summary

    info "Generating configuration files..."
    generate_env
    generate_compose
    ok "Files generated in ${INSTALL_DIR}"

    # Copy install.sh to install dir for future updates
    local self inst
    self=$(realpath "$0" 2>/dev/null || echo "$0")
    inst=$(realpath "$INSTALL_DIR/install.sh" 2>/dev/null || echo "")
    if [ "$self" != "$inst" ]; then
        cp "$0" "$INSTALL_DIR/install.sh"
        chmod +x "$INSTALL_DIR/install.sh"
    fi

    post_install
}

main
