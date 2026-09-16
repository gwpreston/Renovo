#!/usr/bin/env bash
#
# One-command local development setup.
#
# Brings up the Docker stack, creates .env with a real session key, applies
# migrations to both the development and test databases, and checks that the
# result actually serves a page. Safe to re-run: every step is idempotent and
# nothing that already exists is overwritten without being asked.
#
# It also stops the stack again, so starting and stopping local development is
# the one command either way.
#
set -euo pipefail

cd "$(dirname "$0")/.."
ROOT="$(pwd)"

# ---------------------------------------------------------------------------
# Output helpers
# ---------------------------------------------------------------------------
if [ -t 1 ]; then
    BOLD=$'\033[1m'; DIM=$'\033[2m'; RED=$'\033[31m'; GREEN=$'\033[32m'
    YELLOW=$'\033[33m'; BLUE=$'\033[34m'; RESET=$'\033[0m'
else
    BOLD=''; DIM=''; RED=''; GREEN=''; YELLOW=''; BLUE=''; RESET=''
fi

STEP=0
step()  { STEP=$((STEP + 1)); printf '\n%s[%d/%d]%s %s%s%s\n' "$BLUE" "$STEP" "$TOTAL_STEPS" "$RESET" "$BOLD" "$1" "$RESET"; }
ok()    { printf '      %s✓%s %s\n' "$GREEN" "$RESET" "$1"; }
info()  { printf '      %s·%s %s\n' "$DIM" "$RESET" "$1"; }
warn()  { printf '      %s!%s %s\n' "$YELLOW" "$RESET" "$1"; }
die()   { printf '\n%serror:%s %s\n\n' "$RED" "$RESET" "$1" >&2; exit 1; }
have()  { command -v "$1" >/dev/null 2>&1; }

# GNU and BSD sed disagree about -i. This works on both.
sed_i() {
    if sed --version >/dev/null 2>&1; then sed -i "$@"; else sed -i '' "$@"; fi
}

# Set KEY=VALUE in .env, replacing the line if the key is already there.
set_env() {
    local key="$1" value="$2"
    if grep -qE "^${key}=" .env; then
        sed_i -E "s|^${key}=.*|${key}=${value}|" .env
    else
        printf '%s=%s\n' "$key" "$value" >> .env
    fi
}

get_env() {
    local key="$1" fallback="${2:-}"
    local line
    line="$(grep -E "^${key}=" .env 2>/dev/null | tail -1 || true)"
    if [ -z "$line" ]; then printf '%s' "$fallback"; else printf '%s' "${line#*=}"; fi
}

# ---------------------------------------------------------------------------
# Arguments
# ---------------------------------------------------------------------------
ENGINE="postgres"
SAMPLE_DATA=0
DO_RESET=0
DO_STOP=0
ASSUME_YES=0

# Printed by --help. Kept here rather than read back out of the file's own
# comments, which breaks the moment a line is added above it.
usage() {
    cat <<'USAGE'
Local development for Renovo: start it, or stop it again.

  ./bin/dev-setup.sh                     Set up and start (PostgreSQL)
  ./bin/dev-setup.sh --with-sample-data  ...and create an admin plus sample rows
  ./bin/dev-setup.sh --mysql             Use MySQL/MariaDB instead
  ./bin/dev-setup.sh --reset             Start over from an empty database
  ./bin/dev-setup.sh --stop              Stop the stack, keeping all data
  ./bin/dev-setup.sh --stop --reset      Stop it and delete the data too

Options
  --with-sample-data   An administrator and seven sample subscriptions.
  --mysql, --postgres  Which database engine to run. PostgreSQL is the default.
  --reset              Delete the database volume. Prompts unless --yes.
  --stop, --down       Stop the containers instead of starting them.
  -y, --yes            Do not prompt before anything destructive.
  -h, --help           This message.

Ports may be overridden for a single run without editing .env:
  APP_PORT=9090 DB_PORT_PUBLISHED=55432 ./bin/dev-setup.sh
USAGE
}

while [ $# -gt 0 ]; do
    case "$1" in
        --mysql|--mariadb)  ENGINE="mysql" ;;
        --postgres|--pgsql) ENGINE="postgres" ;;
        --with-sample-data) SAMPLE_DATA=1 ;;
        --reset)            DO_RESET=1 ;;
        -y|--yes)           ASSUME_YES=1 ;;
        --stop|--down)      DO_STOP=1 ;;
        -h|--help)          usage; exit 0 ;;
        *) die "Unknown option \"$1\". Try --help." ;;
    esac
    shift
done

COMPOSE_FILES=(-f docker-compose.yml)
if [ "$ENGINE" = "mysql" ]; then
    COMPOSE_FILES+=(-f docker-compose.mysql.yml)
    DB_DRIVER="mysql"; DB_PORT="3306"; DEFAULT_PUBLISHED_PORT="3306"
else
    DB_DRIVER="pgsql"; DB_PORT="5432"; DEFAULT_PUBLISHED_PORT="5432"
fi

compose() { docker compose "${COMPOSE_FILES[@]}" "$@"; }

TOTAL_STEPS=7
[ "$SAMPLE_DATA" -eq 1 ] && TOTAL_STEPS=8
[ "$DO_STOP" -eq 1 ] && TOTAL_STEPS=2

if [ "$DO_STOP" -eq 1 ]; then
    printf '\n%s Renovo — stopping local development %s\n' "$BOLD" "$RESET"
else
    printf '\n%s Renovo — local development setup %s\n' "$BOLD" "$RESET"
    printf '%s Engine: %s %s\n' "$DIM" "$ENGINE" "$RESET"
fi

# ---------------------------------------------------------------------------
step "Checking prerequisites"
# ---------------------------------------------------------------------------
have docker || die "Docker is not installed. See https://docs.docker.com/get-docker/"

if ! docker compose version >/dev/null 2>&1; then
    die "The Docker Compose plugin is missing. Install Docker Desktop, or the docker-compose-plugin package."
fi
ok "docker $(docker version --format '{{.Client.Version}}' 2>/dev/null || echo '?') with the compose plugin"

if ! docker info >/dev/null 2>&1; then
    if [ "$(uname -s)" = "Darwin" ] && [ -d "/Applications/Docker.app" ]; then
        warn "The Docker daemon is not running. Starting Docker Desktop…"
        open -a Docker
        for _ in $(seq 1 60); do
            docker info >/dev/null 2>&1 && break
            sleep 2
        done
    fi
    if [ "$DO_STOP" -eq 1 ]; then
        # Nothing can be running if the daemon is not. That is the desired
        # end state, so report it and succeed rather than fail.
        ok "the Docker daemon is not running — nothing to stop"
        printf '\n%s Stopped. %s\n\n' "$GREEN$BOLD" "$RESET"
        exit 0
    fi
    docker info >/dev/null 2>&1 || die "The Docker daemon is not running. Start Docker and run this again."
fi
ok "the Docker daemon is running"

# ---------------------------------------------------------------------------
# Stopping. Everything below this point is about starting up, so the stop path
# finishes here.
# ---------------------------------------------------------------------------
if [ "$DO_STOP" -eq 1 ]; then
    step "Stopping containers"

    # docker-compose.yml interpolates SESSION_KEY and refuses to run without
    # it. Bringing the stack down should not depend on .env being intact.
    export SESSION_KEY="${SESSION_KEY:-stopping-only}"
    export DB_PORT_PUBLISHED="${DB_PORT_PUBLISHED:-$DEFAULT_PUBLISHED_PORT}"

    before="$(compose ps --services --status running 2>/dev/null || true)"

    if [ "$DO_RESET" -eq 1 ]; then
        if [ "$ASSUME_YES" -ne 1 ]; then
            printf '      %sThis also deletes the database volume and everything in it. Continue? [y/N] %s' \
                "$YELLOW" "$RESET"
            read -r reply
            case "$reply" in [yY]*) ;; *) die "Aborted. Nothing was stopped." ;; esac
        fi
        compose down -v >/dev/null 2>&1 || die "Could not stop the stack."
        ok "containers stopped and the ${ENGINE} database volume deleted"

        # `down -v` only knows about volumes declared in the compose files it
        # was given, so the other engine's data survives. Say so rather than
        # leaving it to be discovered later.
        other_volume="renovo_database_mysql"
        [ "$ENGINE" = "mysql" ] && other_volume="renovo_database"
        if docker volume inspect "$other_volume" >/dev/null 2>&1; then
            info "kept: $other_volume — remove it with the matching engine flag, or: docker volume rm $other_volume"
        fi
    else
        compose down >/dev/null 2>&1 || die "Could not stop the stack."
        ok "containers stopped — your data is kept"
    fi

    if [ -n "$before" ]; then
        printf '%s\n' "$before" | while IFS= read -r service; do
            [ -n "$service" ] && info "stopped: $service"
        done
    else
        info "nothing was running"
    fi

    # Anything left over means a container outside this compose project is
    # still holding the port, which is worth knowing about now rather than
    # next time the app mysteriously fails to start.
    leftover="$(compose ps --services --status running 2>/dev/null || true)"
    [ -n "$leftover" ] && warn "still running: $(printf '%s' "$leftover" | tr '\n' ' ')"

    printf '\n%s Stopped. %s\n\n' "$GREEN$BOLD" "$RESET"
    printf '  %sStart it again with%s  ./bin/dev-setup.sh\n\n' "$BOLD" "$RESET"
    exit 0
fi

have openssl || die "openssl is required to generate a session key."

# Host-side PHP is optional: it is only needed to run the quality gates outside
# the containers. The application itself never touches it.
if have php && have composer; then
    HOST_PHP=1
    ok "php $(php -r 'echo PHP_VERSION;') and composer are available for host-side tooling"
else
    HOST_PHP=0
    info "no host php/composer — that is fine, the gates can run inside the app container"
fi

# ---------------------------------------------------------------------------
step "Writing .env"
# ---------------------------------------------------------------------------
if [ ! -f .env ]; then
    cp .env.example .env
    ok "created .env from .env.example"
else
    ok ".env already exists — keeping it, updating only what this script owns"
fi

if [ -z "$(get_env SESSION_KEY)" ]; then
    set_env SESSION_KEY "$(openssl rand -hex 32)"
    ok "generated a session key"
else
    info "session key already set — left alone"
fi

# Development defaults. These differ from .env.example, which ships with
# production-safe values.
set_env APP_ENV development
set_env APP_DEBUG true
set_env SESSION_COOKIE_SECURE false
set_env DB_DRIVER "$DB_DRIVER"
set_env DB_PORT "$DB_PORT"
# The containers always talk to the "database" host; this value is what the
# tools you run on your own machine (phinx, phpunit, psql) will use.
set_env DB_HOST 127.0.0.1
ok "development defaults applied (APP_ENV=development, DB_DRIVER=$DB_DRIVER)"

# Ports may be overridden for a single run without editing .env:
#   APP_PORT=9090 DB_PORT_PUBLISHED=55432 ./bin/dev-setup.sh
APP_PORT="${APP_PORT:-$(get_env APP_PORT 8080)}"
DB_NAME="$(get_env DB_NAME renovo)"
DB_USER="$(get_env DB_USER renovo)"
DB_PASSWORD="$(get_env DB_PASSWORD renovo)"
TEST_DB_NAME="${DB_NAME}_test"
PUBLISHED_DB_PORT="${DB_PORT_PUBLISHED:-$(get_env DB_PORT_PUBLISHED "$DEFAULT_PUBLISHED_PORT")}"

# A port already in use is the most common reason this fails, and the error
# Docker gives for it is not obvious. This pre-check is best-effort — a binding
# held inside Docker's own VM is invisible to lsof — so the authoritative check
# is Docker's bind, whose error is translated into the same advice below.
port_in_use() {
    if have lsof; then lsof -nP -iTCP:"$1" -sTCP:LISTEN >/dev/null 2>&1
    elif have nc; then nc -z 127.0.0.1 "$1" >/dev/null 2>&1
    else return 1
    fi
}

# A host port we are about to bind may already be held by one of our own
# containers, which is fine, or by something else, which is not. Ask compose
# which host ports this project currently publishes and compare against that —
# "is our database container running" is not the same question, and gets the
# answer wrong the moment the engine changes and the port with it.
#
# Captured into a variable rather than piped into `grep -q`, which would exit
# early, hand docker a SIGPIPE, and leave `set -o pipefail` reporting the whole
# test as failed.
PUBLISHED_PORTS="$(docker compose "${COMPOSE_FILES[@]}" ps --format '{{.Publishers}}' 2>/dev/null || true)"

# Publishers render as {ip targetPort publishedPort protocol}.
port_is_ours() {
    case "$PUBLISHED_PORTS" in
        *" $1 tcp}"*) return 0 ;;
        *)            return 1 ;;
    esac
}

if port_in_use "$APP_PORT" && ! port_is_ours "$APP_PORT"; then
    die "Port $APP_PORT is already in use by something else. Set APP_PORT in .env and run this again."
fi
if port_in_use "$PUBLISHED_DB_PORT" && ! port_is_ours "$PUBLISHED_DB_PORT"; then
    die "Port $PUBLISHED_DB_PORT is in use by something else (another database?).
       Set DB_PORT_PUBLISHED in .env to a free port and run this again."
fi

export DB_PORT_PUBLISHED="$PUBLISHED_DB_PORT"
export APP_PORT

# ---------------------------------------------------------------------------
step "Starting containers"
# ---------------------------------------------------------------------------
if [ "$DO_RESET" -eq 1 ]; then
    if [ "$ASSUME_YES" -ne 1 ]; then
        printf '      %sThis deletes the database volume and everything in it. Continue? [y/N] %s' "$YELLOW" "$RESET"
        read -r reply
        case "$reply" in [yY]*) ;; *) die "Aborted." ;; esac
    fi
    compose down -v >/dev/null 2>&1 || true
    ok "removed the existing stack and its volumes"
fi

# A stale container from the other engine holds the same service name.
build_log="$(mktemp -t renovo-build)"
if ! compose up -d --build >"$build_log" 2>&1; then
    sed 's/^/      /' "$build_log" | tail -20
    clash=0
    grep -q "address already in use" "$build_log" && clash=1
    rm -f "$build_log"
    if [ "$clash" -eq 1 ]; then
        die "A port is already taken by something outside this project.
       Set APP_PORT or DB_PORT_PUBLISHED in .env and run this again."
    fi
    die "Could not start the containers. See the output above."
fi
tail -5 "$build_log" | sed 's/^/      /'
rm -f "$build_log"
ok "containers started"

# ---------------------------------------------------------------------------
step "Waiting for the database"
# ---------------------------------------------------------------------------
db_ready() {
    if [ "$ENGINE" = "mysql" ]; then
        compose exec -T database mysqladmin ping -h 127.0.0.1 -u root \
            -p"$(get_env DB_ROOT_PASSWORD renovo-root)" >/dev/null 2>&1
    else
        compose exec -T database pg_isready -U "$DB_USER" -d "$DB_NAME" >/dev/null 2>&1
    fi
}

for attempt in $(seq 1 60); do
    db_ready && break
    [ "$attempt" -eq 60 ] && die "The database did not become ready. Check: docker compose logs database"
    sleep 2
done
ok "$ENGINE is accepting connections"

# ---------------------------------------------------------------------------
step "Applying migrations"
# ---------------------------------------------------------------------------
# The migrate container runs on start; wait for it and check how it finished,
# rather than assuming.
for attempt in $(seq 1 60); do
    state="$(compose ps -a --format '{{.Service}} {{.State}} {{.ExitCode}}' 2>/dev/null | awk '$1=="migrate"{print $2" "$3}')"
    case "$state" in
        "exited 0") break ;;
        "exited "*)
            migrate_log="$(compose logs migrate 2>&1 || true)"
            printf '%s\n' "$migrate_log" | tail -20 | sed 's/^/      /'

            # The database keeps the password it was first initialised with.
            # Changing DB_PASSWORD in .env afterwards produces an auth failure
            # that says nothing about why, so name the actual cause.
            case "$migrate_log" in
                *"password authentication failed"*|*"Access denied for user"*)
                    die "The database rejected the credentials in .env.
       Its volume still has the password it was created with, and changing
       DB_PASSWORD afterwards does not change it. Either put the old password
       back, or start from an empty database:

           ./bin/dev-setup.sh --reset"
                    ;;
            esac

            die "Migrations failed. See the output above."
            ;;
    esac
    [ "$attempt" -eq 60 ] && die "The migrate container did not finish. Check: docker compose logs migrate"
    sleep 2
done
ok "development database \"$DB_NAME\" is migrated"

# The test suite truncates every table, so it gets a database of its own and
# refuses to run against one whose name does not say "test".
if [ "$ENGINE" = "mysql" ]; then
    compose exec -T database mysql -u root -p"$(get_env DB_ROOT_PASSWORD renovo-root)" \
        -e "CREATE DATABASE IF NOT EXISTS \`${TEST_DB_NAME}\`; GRANT ALL ON \`${TEST_DB_NAME}\`.* TO '${DB_USER}'@'%'; FLUSH PRIVILEGES;" 2>/dev/null
else
    existing="$(compose exec -T database psql -U "$DB_USER" -d "$DB_NAME" -tAc \
        "SELECT 1 FROM pg_database WHERE datname = '${TEST_DB_NAME}'" 2>/dev/null || true)"
    if [ "$(printf '%s' "$existing" | tr -d '[:space:]')" != "1" ]; then
        compose exec -T database psql -U "$DB_USER" -d "$DB_NAME" \
            -c "CREATE DATABASE ${TEST_DB_NAME} OWNER ${DB_USER};" >/dev/null 2>&1 \
            || die "Could not create the test database \"$TEST_DB_NAME\"."
    fi
fi

compose exec -T -e DB_NAME="$TEST_DB_NAME" app vendor/bin/phinx migrate >/dev/null 2>&1 \
    || die "Could not migrate the test database \"$TEST_DB_NAME\"."
ok "test database \"$TEST_DB_NAME\" is migrated"

# ---------------------------------------------------------------------------
step "Installing host-side dependencies"
# ---------------------------------------------------------------------------
if [ "$HOST_PHP" -eq 1 ]; then
    if [ ! -d vendor ] || [ composer.lock -nt vendor ]; then
        composer install --no-interaction --quiet
        ok "composer dependencies installed"
    else
        ok "composer dependencies already up to date"
    fi
else
    info "skipped — run the gates with: docker compose exec app vendor/bin/phpunit"
fi

# ---------------------------------------------------------------------------
step "Checking the application responds"
# ---------------------------------------------------------------------------
BASE_URL="http://localhost:${APP_PORT}"

for attempt in $(seq 1 30); do
    code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 5 "$BASE_URL/" || echo 000)"
    case "$code" in
        200|302) break ;;
    esac
    [ "$attempt" -eq 30 ] && { compose logs app | tail -20; die "The app did not respond on $BASE_URL (last status: $code)."; }
    sleep 2
done
ok "the app responds on $BASE_URL (HTTP $code)"

SETUP_NEEDED=1
redirect="$(curl -s -o /dev/null -w '%{redirect_url}' "$BASE_URL/" || true)"
case "$redirect" in
    */setup) ;;
    *)  SETUP_NEEDED=0
        info "this instance already has an administrator — the first-run wizard is closed" ;;
esac

# ---------------------------------------------------------------------------
# Optional: a populated instance to look at.
#
# This is a convenience for local development, not the product's demo mode
# (which belongs to a later phase). It just drives the ordinary web forms with
# curl, exactly as a person clicking through them would.
# ---------------------------------------------------------------------------
if [ "$SAMPLE_DATA" -eq 1 ]; then
    step "Creating sample data"

    if [ "$SETUP_NEEDED" -eq 0 ]; then
        warn "skipped — the instance already has accounts, and this would need to sign in as one"
    else
        JAR="$(mktemp -t renovo-setup)"
        trap 'rm -f "$JAR"' EXIT

        # Pulled out with parameter expansion rather than a pipeline: a
        # `grep | head` here exits early, and pipefail would report the whole
        # substitution as failed.
        csrf() {
            local html rest
            html="$(curl -s -b "$JAR" -c "$JAR" "$1" || true)"
            case "$html" in
                *'name="_csrf" value="'*) ;;
                *) return 1 ;;
            esac
            rest="${html#*name=\"_csrf\" value=\"}"
            printf '%s' "${rest%%\"*}"
        }

        ADMIN_EMAIL="dev@example.test"
        ADMIN_PASSWORD="$(openssl rand -base64 18 | tr -d '/+=' | cut -c1-20)"

        token="$(csrf "$BASE_URL/setup")"
        [ -n "$token" ] || die "Could not read a CSRF token from the setup page."

        setup_status="$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -c "$JAR" \
            -d "_csrf=$token" \
            -d "display_name=Developer" \
            -d "email=$ADMIN_EMAIL" \
            -d "password=$ADMIN_PASSWORD" \
            -d "password_confirm=$ADMIN_PASSWORD" \
            "$BASE_URL/setup" || echo 000)"

        if [ "$setup_status" != "302" ]; then
            die "The setup form was rejected (HTTP $setup_status). Create the account yourself at $BASE_URL."
        fi
        ok "administrator created: $ADMIN_EMAIL"

        CREATED=0
        add_subscription() {
            local t status
            if ! t="$(csrf "$BASE_URL/subscriptions/new")"; then
                warn "could not load the new-subscription form — is the session still valid?"
                return 0
            fi
            status="$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -c "$JAR" \
                -d "_csrf=$t" "$@" "$BASE_URL/subscriptions" || echo 000)"
            if [ "$status" = "302" ]; then
                CREATED=$((CREATED + 1))
            else
                warn "a sample subscription was rejected (HTTP $status)"
            fi
        }

        today_plus() {
            if date -v +1d >/dev/null 2>&1; then date -v "+$1d" +%Y-%m-%d; else date -d "+$1 days" +%Y-%m-%d; fi
        }

        # A deliberate spread: several currencies, every billing cycle, a
        # notice period, a paused row, and a lifetime purchase — enough to show
        # what each part of the dashboard actually does.
        add_subscription -d "name=Netflix"        -d "price=15.99" -d "currency=GBP" \
            -d "subscription_type=recurring" -d "billing_cycle=monthly" \
            -d "next_payment_date=$(today_plus 5)"  -d "notice_period_amount=14" \
            -d "notice_period_unit=days" -d "tags=streaming, shared" -d "is_active=1"

        add_subscription -d "name=Spotify Family" -d "price=19.99" -d "currency=GBP" \
            -d "subscription_type=recurring" -d "billing_cycle=monthly" \
            -d "next_payment_date=$(today_plus 21)" -d "tags=music, shared" -d "is_active=1"

        add_subscription -d "name=Gym"            -d "price=12,50" -d "currency=EUR" \
            -d "subscription_type=recurring" -d "billing_cycle=weekly" \
            -d "next_payment_date=$(today_plus 2)"  -d "tags=health" -d "is_active=1"

        add_subscription -d "name=Domain renewal" -d "price=11.00" -d "currency=USD" \
            -d "subscription_type=recurring" -d "billing_cycle=yearly" \
            -d "next_payment_date=$(today_plus 120)" -d "notice_period_amount=1" \
            -d "notice_period_unit=months" -d "tags=infrastructure" -d "is_active=1"

        add_subscription -d "name=Contact lenses" -d "price=24.00" -d "currency=GBP" \
            -d "subscription_type=recurring" -d "billing_cycle=custom_days" \
            -d "cycle_days=28" -d "next_payment_date=$(today_plus 9)" \
            -d "tags=health" -d "is_active=1"

        add_subscription -d "name=Old newspaper"  -d "price=8.00"  -d "currency=GBP" \
            -d "subscription_type=recurring" -d "billing_cycle=monthly" \
            -d "next_payment_date=$(today_plus 30)" -d "is_active=0"

        add_subscription -d "name=Sublime Text licence" -d "price=99.00" -d "currency=GBP" \
            -d "subscription_type=lifetime" -d "tags=software" -d "is_active=1"

        if [ "$CREATED" -eq 7 ]; then
            ok "7 sample subscriptions across 3 currencies and every billing cycle"
        else
            warn "created $CREATED of 7 sample subscriptions"
        fi

        SAMPLE_CREDENTIALS="$ADMIN_EMAIL / $ADMIN_PASSWORD"
    fi
fi

# ---------------------------------------------------------------------------
# Summary
# ---------------------------------------------------------------------------
printf '\n%s Ready. %s\n\n' "$GREEN$BOLD" "$RESET"
printf '  %sApp%s          %s\n' "$BOLD" "$RESET" "$BASE_URL"
printf '  %sMail%s         %s  %s(every email the app sends lands here)%s\n' \
    "$BOLD" "$RESET" "http://localhost:$(get_env MAILPIT_PORT 8025)" "$DIM" "$RESET"
printf '  %sDatabase%s     %s on 127.0.0.1:%s  %s(%s, tests use %s)%s\n' \
    "$BOLD" "$RESET" "$ENGINE" "$PUBLISHED_DB_PORT" "$DIM" "$DB_NAME" "$TEST_DB_NAME" "$RESET"

if [ -n "${SAMPLE_CREDENTIALS:-}" ]; then
    printf '\n  %sSign in with%s  %s\n' "$BOLD" "$RESET" "$SAMPLE_CREDENTIALS"
    printf '  %sThat password is random and shown only here. Note it now.%s\n' "$DIM" "$RESET"
elif [ "$SETUP_NEEDED" -eq 1 ]; then
    printf '\n  %sOpen the app to create your administrator account.%s\n' "$BOLD" "$RESET"
fi

printf '\n  %sUseful commands%s\n' "$BOLD" "$RESET"
printf '    docker compose logs -f app        %sfollow the application log%s\n' "$DIM" "$RESET"
printf '    ./bin/dev-setup.sh --stop         %sstop everything (data is kept)%s\n' "$DIM" "$RESET"
printf '    ./bin/dev-setup.sh --reset        %sstart again from an empty database%s\n' "$DIM" "$RESET"
if [ "$HOST_PHP" -eq 1 ]; then
    printf '    DB_NAME=%s vendor/bin/phpunit  %sthe test suite%s\n' "$TEST_DB_NAME" "$DIM" "$RESET"
    printf '    composer check                    %slint, static analysis and tests%s\n' "$DIM" "$RESET"
else
    printf '    docker compose exec app vendor/bin/phpunit\n'
fi
printf '\n'
