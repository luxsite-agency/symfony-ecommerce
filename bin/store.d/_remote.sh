#!/usr/bin/env bash
# SPDX-License-Identifier: Apache-2.0
# Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.
# shellcheck disable=SC2016,SC2012  # SC2016: $ у sh -c для контейнера; SC2012: імена архівів/релізів — [0-9a-z.-]
# Серверна частина bin/store: виконується НА СЕРВЕРІ магазину під користувачем deploy (група docker, без root).
# bin/store передає цей файл через ssh (або запускає локально для server.host: local) і викликає:
#   _remote.sh <дія> <key> <root> [аргументи]      дії: deploy | backup | restore | archive | doctor | prune
# Деплой ставить копію в <root>/store-remote.sh — для cron бекапу без операторської машини:
#   <root>/store-remote.sh backup <key> <root> >> <root>/backups/backup.log 2>&1
#
# Розкладка <root> (/work/stores/<key>):
#   .env.local, .secrets/mysql-{root,password}.txt   — секрети (600), створюються на першому деплої, у stdout не йдуть
#   store.env    — параметри з реєстру (хости, проксі), переписується кожним деплоєм
#   tuning.env   — ручні параметри сервера (пам'ять, buffer pool, X-Robots-Tag, traefik), деплой не перезаписує
#   release.env  — STORE_TAG (поточний реліз) і STORE_PREV_TAG
#   releases/<tag>/ — контекст збірки (рушій + пак), current → поточний; backups/ — архіви; ARCHIVED — маркер архіву
#   dc           — docker compose цього магазину: <root>/dc ps | logs -f php | exec php bin/console …
# Змінні: DRY_RUN=1 (змінні дії лише друкуються), UNARCHIVE=1, STORE_CRON=0 (не ставити cron), STORE_SKIP_BACKUP=1,
#   STORE_BACKUP_KEEP_DAYS (14), STORE_YES=1 (restore без питання — питає локальна частина).
set -euo pipefail

ACTION="${1:?дія}"; KEY="${2:?key}"; ROOT="${3:?root}"; shift 3
DRY_RUN="${DRY_RUN:-0}"
P="store-$KEY"

log() { printf '>> %s\n' "$*" >&2; }
warn() { printf '!! %s\n' "$*" >&2; }
die() { printf '!! %s: %s\n' "$P" "$*" >&2; exit 1; }
run() {
    if [ "$DRY_RUN" = 1 ]; then printf '[dry-run] %s\n' "$(printf '%q ' "$@")" >&2; return 0; fi
    "$@"
}

[[ "$KEY" =~ ^[a-z0-9][a-z0-9-]{0,30}$ ]] || die "некоректний ключ"
# Нова схема: лише <щось>/stores/<key>. Стару (/work/shop-engine, магазин Сокіл 77 на старому прод-сервері) цей скрипт не чіпає ніколи.
case "$ROOT" in */stores/"$KEY") ;; *) die "шлях '$ROOT' не за схемою */stores/$KEY — відмова (стара схема не обслуговується)" ;; esac
command -v docker >/dev/null || die "нема docker"

env_get() { if [ -f "$2" ]; then sed -n "s/^$1=//p" "$2" | tail -n1; fi; }
cur_tag() { env_get STORE_TAG "$ROOT/release.env"; }

# compose магазину. Файл compose — з релізу, який зараз збирається/працює ($COMPOSE_FILE). </dev/null: скрипт міг прийти через stdin.
COMPOSE_FILE="$ROOT/current/docker-compose.store.yml"
compose() {
    local envs=(--env-file "$ROOT/store.env")
    [ -f "$ROOT/tuning.env" ] && envs+=(--env-file "$ROOT/tuning.env")
    [ -f "$ROOT/release.env" ] && envs+=(--env-file "$ROOT/release.env")
    docker compose -p "$P" --project-directory "$ROOT" "${envs[@]}" -f "$COMPOSE_FILE" "$@" </dev/null
}
# Те саме, але stdin лишається (імпорт дампу)
compose_in() {
    local envs=(--env-file "$ROOT/store.env")
    [ -f "$ROOT/tuning.env" ] && envs+=(--env-file "$ROOT/tuning.env")
    [ -f "$ROOT/release.env" ] && envs+=(--env-file "$ROOT/release.env")
    docker compose -p "$P" --project-directory "$ROOT" "${envs[@]}" -f "$COMPOSE_FILE" "$@"
}
mysql_root() { compose exec -T mysql sh -c 'MYSQL_PWD="$(cat /run/secrets/mysql_root)" exec mysql -uroot "$@"' mysql "$@"; }

wait_mysql() {
    for _ in $(seq 1 60); do
        [ "$(docker inspect -f '{{.State.Health.Status}}' "$(compose ps -q mysql 2>/dev/null)" 2>/dev/null || true)" = healthy ] && return 0
        sleep 3
    done
    die "mysql не став healthy за 3 хв (логи: $ROOT/dc logs --tail 50 mysql)"
}

# http_ok <сервіс-nginx> <host> <path>: запит усередині контейнера nginx (busybox wget), 2xx → 0
http_ok() {
    compose exec -T "$1" wget -q -O /dev/null -T 20 --header "Host: $2" --header "X-Forwarded-Proto: https" "http://127.0.0.1$3" >/dev/null 2>&1
}
health() {
    local svc="$1" site admin
    site="$(env_get STORE_SITE_HOST "$ROOT/store.env")"; admin="$(env_get STORE_ADMIN_HOST "$ROOT/store.env")"
    [ "$DRY_RUN" = 1 ] && { log "[dry-run] health $svc: https://$site/ і https://$admin/login"; return 0; }
    for _ in $(seq 1 15); do
        if http_ok "$svc" "$site" / && http_ok "$svc" "$admin" /login; then
            log "health $svc: $site/ і $admin/login → 2xx"; return 0
        fi
        sleep 3
    done
    warn "health $svc: $site/ або $admin/login не відповідає 2xx"
    return 1
}

# ---------- deploy <tag> <site> <admin> <proxy> <traefik-network> <certresolver> ----------
first_run() {
    local site="$1"
    umask 077
    run mkdir -p "$ROOT/.secrets" "$ROOT/releases" "$ROOT/backups"
    run chmod 700 "$ROOT/.secrets"
    gen() { openssl rand -hex "$1" | tr -d '\n'; }
    if [ ! -s "$ROOT/.secrets/mysql-root.txt" ]; then run sh -c "openssl rand -hex 24 | tr -d '\n' > '$ROOT/.secrets/mysql-root.txt'"; log "створено .secrets/mysql-root.txt"; fi
    if [ ! -s "$ROOT/.secrets/mysql-password.txt" ]; then run sh -c "openssl rand -hex 24 | tr -d '\n' > '$ROOT/.secrets/mysql-password.txt'"; log "створено .secrets/mysql-password.txt"; fi
    if [ ! -s "$ROOT/.env.local" ] && [ "$DRY_RUN" != 1 ]; then
        {
            echo "# $P: env Symfony для php і worker. Секрети — не копіювати в чат/git/доки."
            echo "APP_ENV=prod"
            echo "APP_DEBUG=0"
            echo "APP_SECRET=$(gen 32)"
            echo "SETTINGS_SECRET_KEY=$(openssl rand -base64 32 | tr -d '\n')"
            echo "DATABASE_URL=mysql://shop:$(cat "$ROOT/.secrets/mysql-password.txt")@mysql:3306/shop?serverVersion=$MYSQL_VERSION&charset=utf8mb4"
            echo "MESSENGER_TRANSPORT_DSN=doctrine://default?auto_setup=0"
            echo "LOCK_DSN=flock:///app/var/share/lock"
            echo "MAILER_DSN=null://null"
            echo "DEFAULT_URI=https://$site"
        } > "$ROOT/.env.local"
        log "створено .env.local"
    fi
    [ "$DRY_RUN" = 1 ] || chmod 600 "$ROOT/.env.local" "$ROOT/.secrets/"*.txt
    umask 022
    if [ ! -f "$ROOT/tuning.env" ] && [ "$DRY_RUN" != 1 ]; then
        cat > "$ROOT/tuning.env" <<'EOF'
# Ручні параметри магазину на цьому сервері (деплой не перезаписує). Після зміни: <root>/dc up -d
# "noindex, nofollow" = технічний домен закритий від індексу; порожньо (STORE_X_ROBOTS_TAG=) = сайт відкритий
STORE_X_ROBOTS_TAG=noindex, nofollow
# Заголовок з реальною IP клієнта для nginx; типово зі store.env (traefik за Cloudflare = CF-Connecting-IP, standalone = X-Forwarded-For).
# Сервер з traefik без Cloudflare: STORE_REAL_IP_HEADER=X-Forwarded-For
#STORE_REAL_IP_HEADER=X-Forwarded-For
STORE_MYSQL_BUFFER_POOL=192M
STORE_MEM_MYSQL=512m
STORE_MEM_PHP=384m
STORE_MEM_WORKER=256m
STORE_MEM_FEED_WORKER=192m
STORE_MEM_NGINX=64m
STORE_MEM_CADDY=128m
STORE_TRAEFIK_ENTRYPOINT=websecure
EOF
        log "створено tuning.env"
    fi
}

# mysql_version <compose-файл> — тег образу mysql (8.0.46): він же serverVersion для Doctrine
mysql_version() {
    local v
    v="$(sed -n 's/^[[:space:]]*image:[[:space:]]*mysql:\([0-9][0-9.]*\)[[:space:]]*$/\1/p' "$1" | head -n1)"
    [[ "$v" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || die "у $1 образ mysql без точної версії x.y.z (потрібна для serverVersion)"
    echo "$v"
}

# server_version_env <версія> — serverVersion у DATABASE_URL наявного .env.local (секрети не друкуються)
server_version_env() {
    local want="$1" f="$ROOT/.env.local"
    [ -f "$f" ] || return 0
    grep -q "^DATABASE_URL=.*[?&]serverVersion=$want\(&\|\$\)" "$f" && return 0
    if [ "$DRY_RUN" = 1 ]; then log "[dry-run] DATABASE_URL: serverVersion → $want"; return 0; fi
    local tmp="$f.tmp.$$"
    (umask 077; awk -v want="$want" '
        /^DATABASE_URL=/ {
            if ($0 ~ /[?&]serverVersion=/) { sub(/serverVersion=[^&]*/, "serverVersion=" want) }
            else if ($0 ~ /\?/) { $0 = $0 "&serverVersion=" want }
            else { $0 = $0 "?serverVersion=" want }
        }
        { print }' "$f" > "$tmp")
    chmod 600 "$tmp" && mv "$tmp" "$f"
    log "DATABASE_URL у .env.local: serverVersion → $want"
}

# settings_secret_key_env — SETTINGS_SECRET_KEY (шифрування секретів налаштувань, E15 BYOK) у наявному .env.local магазинів,
# розгорнутих до його появи: дописується один раз; значення не друкується
settings_secret_key_env() {
    local f="$ROOT/.env.local"
    [ -f "$f" ] || return 0
    grep -q '^SETTINGS_SECRET_KEY=.\+' "$f" && return 0
    if [ "$DRY_RUN" = 1 ]; then log "[dry-run] .env.local: додати SETTINGS_SECRET_KEY"; return 0; fi
    (umask 077; { grep -v '^SETTINGS_SECRET_KEY=' "$f"; echo "SETTINGS_SECRET_KEY=$(openssl rand -base64 32 | tr -d '\n')"; } > "$f.tmp.$$")
    chmod 600 "$f.tmp.$$" && mv "$f.tmp.$$" "$f"
    log "SETTINGS_SECRET_KEY додано в .env.local"
}

# settings_key_state — yes / no / invalid: ключ є, але не 32 байти base64 (44 символи одним рядком) — застосунок вважає його
# відсутнім, а doctor каже чому. Значення не друкується.
settings_key_state() {
    local v
    v="$(grep -m1 '^SETTINGS_SECRET_KEY=' "$ROOT/.env.local" 2>/dev/null | cut -d= -f2- | tr -d "\"' \r")"
    [ -n "$v" ] || { echo no; return 0; }
    if [ "${#v}" -eq 44 ] && printf '%s' "$v" | grep -Eq '^[A-Za-z0-9+/]{43}=$'; then echo yes; else echo invalid; fi
}

do_deploy() {
    local tag="$1" site="$2" admin="$3" proxy="$4" tnet="$5" resolver="$6"
    local rel="$ROOT/releases/$tag" prev edge
    if [ -f "$ROOT/ARCHIVED" ] && [ "${UNARCHIVE:-0}" != 1 ]; then
        die "магазин заархівовано ($ROOT/ARCHIVED); підняти лише з UNARCHIVE=1 і рішенням власника"
    fi
    if [ ! -d "$rel" ]; then
        [ "$DRY_RUN" = 1 ] || die "нема контексту релізу $rel"
        warn "[dry-run] контексту $rel нема (не завантажувався)"
    fi
    COMPOSE_FILE="$rel/docker-compose.store.yml"
    MYSQL_VERSION=""
    [ -f "$COMPOSE_FILE" ] && MYSQL_VERSION="$(mysql_version "$COMPOSE_FILE")"
    first_run "$site"
    [ -n "$MYSQL_VERSION" ] && server_version_env "$MYSQL_VERSION"
    settings_secret_key_env

    case "$proxy" in
        traefik) edge="${tnet:-edge}"
                 if ! docker network inspect "$edge" >/dev/null 2>&1; then
                     if [ "$DRY_RUN" = 1 ]; then warn "[dry-run] нема мережі traefik '$edge'"; else die "нема мережі traefik '$edge' (server.traefik_network у реєстрі)"; fi
                 fi ;;
        standalone) edge="$P-edge"
                 docker network inspect "$edge" >/dev/null 2>&1 || run docker network create "$edge" >/dev/null ;;
        *) die "proxy має бути traefik|standalone, а не '$proxy'" ;;
    esac
    if [ "$DRY_RUN" != 1 ]; then
        {
            echo "# Згенеровано bin/store deploy $(date '+%F %T') з реєстру — не редагувати (ручне — у tuning.env)"
            echo "STORE_KEY=$KEY"
            echo "STORE_ROOT=$ROOT"
            echo "STORE_SITE_HOST=$site"
            echo "STORE_ADMIN_HOST=$admin"
            echo "COMPOSE_PROFILES=$proxy"
            echo "STORE_EDGE_NETWORK=$edge"
            echo "STORE_TRAEFIK_ENABLE=$([ "$proxy" = traefik ] && echo true || echo false)"
            echo "STORE_TRAEFIK_CERTRESOLVER=$resolver"
            # реальна IP для nginx: наші сервери з traefik стоять за Cloudflare (CF-Connecting-IP), standalone (Caddy) — X-Forwarded-For;
            # інше — у tuning.env (STORE_REAL_IP_HEADER=), він читається після store.env і перекриває
            echo "STORE_REAL_IP_HEADER=$([ "$proxy" = traefik ] && echo CF-Connecting-IP || echo X-Forwarded-For)"
        } > "$ROOT/store.env"
    fi

    prev="$(cur_tag)"
    log "реліз $tag (попередній: ${prev:-нема})"

    # 1. Образи нового релізу (старі лишаються — відкат = попередній тег)
    local t
    for t in php nginx; do
        if docker image inspect "$P-$t:$tag" >/dev/null 2>&1; then
            log "образ $P-$t:$tag уже є"
        else
            log "збірка $P-$t:$tag"
            if [ "$DRY_RUN" = 1 ]; then run docker build --target "$t" -t "$P-$t:$tag" "$rel"; continue; fi
            if ! docker build --progress=plain --target "$t" -t "$P-$t:$tag" -f "$rel/docker/php/Dockerfile.prod" "$rel" </dev/null >"$rel.build.log" 2>&1; then
                tail -n 40 "$rel.build.log" >&2
                die "збірка $P-$t:$tag не вдалася (повний лог: $rel.build.log); робочі контейнери лишились на ${prev:-—}"
            fi
        fi
    done

    # Далі compose бачить робочі сервіси на попередньому тегу (або новому, якщо релізу ще не було)
    export STORE_TAG="${prev:-$tag}" STORE_NEXT_TAG="$tag"

    # 2. MySQL і страховий бекап перед міграціями
    run compose up -d --no-deps mysql
    [ "$DRY_RUN" = 1 ] || wait_mysql
    if [ -n "$prev" ] && [ "${STORE_SKIP_BACKUP:-0}" != 1 ]; then
        log "бекап перед деплоєм"
        do_backup pre-deploy >/dev/null
    fi

    # 3. Кандидат: php-next + nginx-next з новим тегом поруч із робочими → міграції → health
    run compose --profile candidate up -d --no-deps --force-recreate php-next nginx-next
    run compose --profile candidate exec -T -u root php-next sh -c 'mkdir -p /app/var/share/lock && chown app:app /app/public/media /app/public/feeds /app/var/share /app/var/share/lock /app/var/log'
    drop_candidate() { run compose --profile candidate rm -sf php-next nginx-next >/dev/null 2>&1 || true; }
    log "міграції (кандидат)"
    if ! run compose --profile candidate exec -T php-next php bin/console doctrine:migrations:migrate -n --allow-no-migration \
        || ! run compose --profile candidate exec -T php-next php bin/console messenger:setup-transports -n; then
        compose --profile candidate logs --tail 50 php-next >&2 || true
        drop_candidate
        die "міграції не пройшли; робочі контейнери лишились на ${prev:-—}. Бекап перед деплоєм — у $ROOT/backups (*-pre-deploy.tar)"
    fi
    # store:validate (тема й параметри паку) — якщо команда є в цій версії рушія
    if [ "$DRY_RUN" != 1 ] && [ "$(compose --profile candidate exec -T php-next php bin/console list --raw 2>/dev/null | grep -c '^store:validate ' || true)" != 0 ]; then
        if ! compose --profile candidate exec -T php-next php bin/console store:validate -n >&2; then
            drop_candidate
            die "store:validate у кандидаті не пройшов; робочі контейнери лишились на ${prev:-—}"
        fi
    fi
    # store:smoke (E14): ключові сторінки сайту через ядро кандидата — шаблони паку (templates/store/) рендеряться з
    # живою БД, а не лише лінтуються; провал = не перемикати. Якщо команди в цій версії рушія нема — пропуск.
    if [ "$DRY_RUN" != 1 ] && [ "$(compose --profile candidate exec -T php-next php bin/console list --raw 2>/dev/null | grep -c '^store:smoke ' || true)" != 0 ]; then
        if ! compose --profile candidate exec -T php-next php bin/console store:smoke -n >&2; then
            drop_candidate
            die "store:smoke у кандидаті не пройшов (сторінки сайту не рендеряться — див. вище); робочі контейнери лишились на ${prev:-—} (міграції вже застосовано — див. бекап pre-deploy)"
        fi
    fi
    if ! health nginx-next; then
        compose --profile candidate logs --tail 80 php-next nginx-next >&2 || true
        drop_candidate
        die "кандидат $tag не пройшов health; робочі контейнери лишились на ${prev:-—} (міграції вже застосовано — див. бекап pre-deploy)"
    fi
    drop_candidate

    # 4. Перемикання робочих сервісів на новий тег
    log "перемикання на $tag"
    if [ "$DRY_RUN" != 1 ]; then
        # Повторний деплой того ж тегу не затирає справжній попередній реліз
        local keep_prev="$prev"
        [ "$prev" = "$tag" ] && keep_prev="$(env_get STORE_PREV_TAG "$ROOT/release.env")"
        printf 'STORE_TAG=%s\nSTORE_PREV_TAG=%s\n' "$tag" "$keep_prev" > "$ROOT/release.env"
    fi
    export STORE_TAG="$tag"
    run compose up -d --remove-orphans
    run compose exec -T -u root php sh -c 'mkdir -p /app/var/share/lock && chown app:app /app/public/media /app/public/feeds /app/var/share /app/var/share/lock /app/var/log'
    if ! health nginx; then
        if [ -n "$prev" ]; then
            warn "відкат на $prev"
            printf 'STORE_TAG=%s\nSTORE_PREV_TAG=\n' "$prev" > "$ROOT/release.env"
            export STORE_TAG="$prev"
            COMPOSE_FILE="$ROOT/current/docker-compose.store.yml"
            compose up -d --remove-orphans || true
        fi
        die "після перемикання сайт не відповідає; повернуто ${prev:-нічого — першого релізу нема куди відкочувати}"
    fi
    run ln -sfn "releases/$tag" "$ROOT/current"
    [ -f "$ROOT/ARCHIVED" ] && run mv "$ROOT/ARCHIVED" "$ROOT/ARCHIVED.lifted-$(date +%Y%m%d)"
    COMPOSE_FILE="$ROOT/current/docker-compose.store.yml"

    # 5. Обслуговування: dc, копія цього скрипта для cron, cron бекапу, прибирання старих релізів
    if [ "$DRY_RUN" != 1 ]; then
        cat > "$ROOT/dc" <<EOF
#!/usr/bin/env bash
# docker compose магазину $P: $ROOT/dc ps | logs -f php | exec php bin/console …
cd '$ROOT' || exit 1
envs=(--env-file store.env)
[ -f tuning.env ] && envs+=(--env-file tuning.env)
exec docker compose -p '$P' --project-directory '$ROOT' "\${envs[@]}" --env-file release.env -f current/docker-compose.store.yml "\$@"
EOF
        chmod 755 "$ROOT/dc"
        install -m 755 "$rel/bin/store.d/_remote.sh" "$ROOT/store-remote.sh"
    fi
    cron_on
    do_prune "$tag" "$prev"
    proxy_check "$site" "$proxy"
    log "готово: $P → $tag"
    compose ps --format 'table {{.Service}}\t{{.Status}}' >&2 || true
}

CRON_MARK="# bin/store backup $P"
cron_on() {
    [ "${STORE_CRON:-1}" = 1 ] || { log "cron бекапу не ставлю (STORE_CRON=0)"; return 0; }
    command -v crontab >/dev/null || { warn "нема crontab — щоденний бекап налаштувати вручну"; return 0; }
    crontab -l 2>/dev/null | grep -qF "$CRON_MARK" && return 0
    local m=$(( $(printf '%s' "$KEY" | cksum | cut -d' ' -f1) % 50 ))
    run sh -c "(crontab -l 2>/dev/null; echo '$m 3 * * * $ROOT/store-remote.sh backup $KEY $ROOT >> $ROOT/backups/backup.log 2>&1 $CRON_MARK') | crontab -"
    log "cron бекапу: щодня 03:$(printf %02d "$m")"
}
cron_off() {
    command -v crontab >/dev/null || return 0
    crontab -l 2>/dev/null | grep -qF "$CRON_MARK" || return 0
    run sh -c "crontab -l 2>/dev/null | grep -vF '$CRON_MARK' | crontab -"
    log "cron бекапу вимкнено"
}

proxy_check() {
    local site="$1" proxy="$2"
    [ "$DRY_RUN" = 1 ] && return 0
    command -v curl >/dev/null || return 0
    local code
    # Проксі підхоплює новий контейнер не миттєво (traefik — за подією docker, кілька секунд)
    for _ in $(seq 1 10); do
        code="$(curl -sk -o /dev/null -w '%{http_code}' --max-time 20 --resolve "$site:443:127.0.0.1" "https://$site/" </dev/null || true)"
        [ "$code" = 200 ] && break
        sleep 3
    done
    log "через $proxy: https://$site/ → $code"
    if ! curl -s -o /dev/null --max-time 20 --resolve "$site:443:127.0.0.1" "https://$site/" </dev/null; then
        warn "TLS для $site ще не валідний (ACME може видавати кілька хвилин; DNS має вказувати на сервер)"
    fi
}

# Лишає поточний, попередній і ще 2 найновіші релізи; образи видалених релізів прибирає
do_prune() {
    local cur="${1:-$(cur_tag)}" prev="${2:-$(env_get STORE_PREV_TAG "$ROOT/release.env")}" old n=0
    [ -d "$ROOT/releases" ] || return 0
    # shellcheck disable=SC2045  # імена релізів — теги [0-9a-z-]
    for old in $(ls -1t "$ROOT/releases"); do
        case "$old" in *.part | *.build.log) continue ;; esac
        [ "$old" = "$cur" ] || [ "$old" = "$prev" ] && continue
        n=$((n + 1)); [ "$n" -le 2 ] && continue
        run rm -rf "${ROOT:?}/releases/$old" "${ROOT:?}/releases/$old.build.log"
        run docker image rm "$P-php:$old" "$P-nginx:$old" >/dev/null 2>&1 || true
    done
}

# ---------- backup [мітка] → друкує шлях архіву ----------
# Архів <key>-<час>[-мітка].tar: db.sql.gz (mysqldump однієї БД — без CREATE DATABASE/USE), media.tar.gz (том media),
# manifest.txt (ключ, тег, час), SHA256SUMS. Ротація: STORE_BACKUP_KEEP_DAYS (14), але щонайменше 3 останні лишаються.
do_backup() {
    local label="${1-}" ts tag dir name img started=0
    ts="$(date +%Y%m%d-%H%M%S)"; tag="$(cur_tag)"
    name="$KEY-$ts${label:+-$label}.tar"
    if [ "$DRY_RUN" = 1 ]; then log "[dry-run] backup → $ROOT/backups/$name"; echo "$ROOT/backups/$name"; return 0; fi
    [ -n "$tag" ] || die "магазин ще не розгорнуто (нема release.env)"
    img="$P-php:$tag"
    dir="$ROOT/backups/.tmp-$ts"
    umask 077
    mkdir -p "$dir"
    if [ -z "$(compose ps -q --status running mysql 2>/dev/null)" ]; then
        compose up -d --no-deps mysql; started=1; wait_mysql
    fi
    # sessions і messenger_messages — лише схема: живі сесії (зокрема адмінські) і черга (листи з посиланнями-токенами
    # в auth_mail/failed, консиліум безпеки 10.10.2026 п. 10) у бекап не потрапляють, після відновлення таблиці порожні;
    # до міграції таблиць ще нема (бекап перед першим деплоєм) — тоді лише основний дамп
    compose exec -T mysql sh -c 'set -e; export MYSQL_PWD="$(cat /run/secrets/mysql_root)"; mysqldump -uroot --single-transaction --quick --routines --triggers --no-tablespaces --set-gtid-purged=OFF --default-character-set=utf8mb4 --ignore-table=shop.sessions --ignore-table=shop.messenger_messages shop; for t in sessions messenger_messages; do if mysql -uroot -N shop -e "SHOW TABLES LIKE \"$t\"" | grep -q .; then mysqldump -uroot --no-data --skip-triggers --no-tablespaces --set-gtid-purged=OFF --default-character-set=utf8mb4 shop "$t"; fi; done' \
        | gzip -6 > "$dir/db.sql.gz"
    gzip -t "$dir/db.sql.gz"
    zcat "$dir/db.sql.gz" | tail -n1 | grep -q '^-- Dump completed' || die "дамп БД неповний"
    docker run --rm -u 0 --network none -v "$P"_media:/data:ro --entrypoint tar "$img" -C /data -czf - . > "$dir/media.tar.gz"
    gzip -t "$dir/media.tar.gz"
    [ "$started" = 1 ] && compose stop mysql >/dev/null 2>&1
    {
        echo "key=$KEY"
        echo "tag=$tag"
        echo "created=$(date -Is)"
        echo "host=$(hostname)"
        echo "label=$label"
        [ -f "$ROOT/current/STORE_RELEASE" ] && sed 's/^/release./' "$ROOT/current/STORE_RELEASE"
    } > "$dir/manifest.txt"
    (cd "$dir" && sha256sum db.sql.gz media.tar.gz manifest.txt > SHA256SUMS)
    tar -C "$dir" -cf "$ROOT/backups/.$name.part" manifest.txt SHA256SUMS db.sql.gz media.tar.gz
    mv "$ROOT/backups/.$name.part" "$ROOT/backups/$name"
    rm -rf "$dir"
    # Ротація: старші за KEEP_DAYS, крім 3 найновіших
    local keep="${STORE_BACKUP_KEEP_DAYS:-14}" f
    # shellcheck disable=SC2012  # імена архівів — <key>-<час>[-мітка].tar
    for f in $(ls -1t "$ROOT/backups/"*.tar 2>/dev/null | tail -n +4); do
        [ -n "$(find "$f" -mtime +"$keep")" ] && rm -f "$f"
    done
    find "$ROOT/backups" -maxdepth 1 -name '.*.part' -mtime +1 -delete
    find "$ROOT/backups" -maxdepth 1 -name '.tmp-*' -mtime +1 -exec rm -rf {} +
    log "бекап: $ROOT/backups/$name ($(du -h "$ROOT/backups/$name" | cut -f1))"
    echo "$ROOT/backups/$name"
}

# ---------- restore <файл на сервері> ----------
# .tar з backup (БД + media) або .sql.gz (лише БД, напр. бекап старої схеми). Дамп не повинен мати CREATE DATABASE/USE.
do_restore() {
    local file="$1" tmp db media=""
    [ -f "$file" ] || die "нема файлу $file"
    if [ -f "$ROOT/ARCHIVED" ] && [ "${UNARCHIVE:-0}" != 1 ]; then die "магазин заархівовано ($ROOT/ARCHIVED); відновлення поверх — лише з UNARCHIVE=1"; fi
    [ -n "$(cur_tag)" ] || die "спершу bin/store deploy $KEY (стек і секрети), потім restore"
    tmp="$ROOT/backups/.restore-$$"
    mkdir -p "$tmp"
    # shellcheck disable=SC2064  # шлях фіксується зараз: $tmp — локальна змінна функції
    trap "rm -rf '$tmp'" EXIT
    case "$file" in
        *.tar)
            tar -C "$tmp" -xf "$file"
            (cd "$tmp" && sha256sum -c --quiet SHA256SUMS) || die "контрольні суми архіву не збігаються"
            local akey; akey="$(sed -n 's/^key=//p' "$tmp/manifest.txt")"
            [ "$akey" = "$KEY" ] || warn "архів магазину '$akey' відновлюється в '$KEY'"
            db="$tmp/db.sql.gz"; [ -f "$tmp/media.tar.gz" ] && media="$tmp/media.tar.gz" ;;
        *.sql.gz) db="$file" ;;
        *) die "очікую .tar (bin/store backup) або .sql.gz" ;;
    esac
    gzip -t "$db"
    # grep -c читає все до кінця: grep -q під pipefail обриває zcat (SIGPIPE) і перевірка мовчки «проходить»
    if [ "$(zcat "$db" | grep -cE '^(CREATE DATABASE|USE )' || true)" != 0 ]; then
        die "дамп містить CREATE DATABASE/USE — відновлення могло б піти в іншу БД; потрібен дамп однієї БД без --databases"
    fi
    [ "$DRY_RUN" = 1 ] && { log "[dry-run] restore $file → $P (БД${media:+ + media})"; return 0; }

    compose up -d --no-deps mysql; wait_mysql
    if [ "${STORE_SKIP_BACKUP:-0}" != 1 ]; then log "страховий бекап перед відновленням"; do_backup pre-restore >/dev/null; fi
    log "зупинка php/worker/feed-worker/mail-worker/nginx"
    compose stop worker feed-worker mail-worker nginx php
    log "БД shop ← $(basename "$db")"
    mysql_root -e "DROP DATABASE IF EXISTS shop; CREATE DATABASE shop CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci; GRANT ALL PRIVILEGES ON shop.* TO 'shop'@'%';"
    zcat "$db" | compose_in exec -T mysql sh -c 'MYSQL_PWD="$(cat /run/secrets/mysql_root)" exec mysql -uroot --default-character-set=utf8mb4 shop'
    if [ -n "$media" ]; then
        log "media ← архів"
        docker run --rm -i -u 0 --network none -v "$P"_media:/data --entrypoint sh "$P-php:$(cur_tag)" \
            -c 'find /data -mindepth 1 -delete && tar -C /data -xzf - && chown -R 1000:1000 /data' < "$media"
    fi
    compose up -d php
    compose exec -T php php bin/console doctrine:migrations:migrate -n --allow-no-migration
    compose exec -T php php bin/console messenger:setup-transports -n
    # фіди (Google Merchant, Meta) у томі feeds — від старої БД: перегенерувати (у черзі feeds, воркер підніметься нижче)
    compose exec -T php php bin/console feed:merchant:generate --async -n -q         || warn "фіди не поставлено в чергу: після запуску — $ROOT/dc exec php php bin/console feed:merchant:generate"
    compose up -d
    compose restart nginx >/dev/null
    health nginx || die "після відновлення сайт не відповідає 2xx (логи: $ROOT/dc logs --tail 100 php)"
    proxy_check "$(env_get STORE_SITE_HOST "$ROOT/store.env")" "$(env_get COMPOSE_PROFILES "$ROOT/store.env")"
    log "відновлено з $(basename "$file")"
}

# ---------- archive <файл бекапу> ----------
do_archive() {
    local file="$1"
    [ "$DRY_RUN" = 1 ] || [ -f "$file" ] || die "нема бекапу $file"
    if [ "$DRY_RUN" != 1 ]; then
        [ "$(tar -tf "$file" | grep -cx db.sql.gz || true)" = 1 ] || die "у $file нема db.sql.gz"
        local t ok=0; t="$(mktemp -d)"
        if tar -C "$t" -xf "$file" && (cd "$t" && sha256sum -c --quiet SHA256SUMS); then ok=1; fi
        rm -rf "$t"
        [ "$ok" = 1 ] || die "архів $file пошкоджений"
    fi
    run compose stop
    cron_off
    if [ "$DRY_RUN" != 1 ]; then
        printf 'archived=%s\nbackup=%s\ntag=%s\n# Підняти: bin/store deploy %s з UNARCHIVE=1 (рішення власника), або restore на іншому сервері\n' \
            "$(date -Is)" "$(basename "$file")" "$(cur_tag)" "$KEY" > "$ROOT/ARCHIVED"
    fi
    log "$P заархівовано: стек зупинено (томи лишились), маркер $ROOT/ARCHIVED"
}

# ---------- doctor: рядки key=value для локальної частини ----------
do_doctor() {
    echo "archived=$([ -f "$ROOT/ARCHIVED" ] && echo yes || echo no)"
    echo "tag=$(cur_tag)"
    [ -f "$ROOT/current/STORE_RELEASE" ] && sed 's/^/release./' "$ROOT/current/STORE_RELEASE"
    if [ -f "$ROOT/release.env" ]; then
        compose ps -a --format '{{.Service}} {{.State}} {{.Health}}' 2>/dev/null | sed 's/^/svc=/' || true
    fi
    # Останній ЗВИЧАЙНИЙ бекап: страхові *-pre-deploy/*-pre-restore (робляться автоматично перед змінами) не рахуються
    local last="" f
    for f in "$ROOT/backups/"*.tar; do
        [ -e "$f" ] || continue
        case "${f##*/}" in *-pre-*.tar | incoming-*) continue ;; esac
        if [ -z "$last" ] || [ "$f" -nt "$last" ]; then last="$f"; fi
    done
    if [ -n "$last" ]; then
        echo "backup.last=$(basename "$last")"
        echo "backup.age_h=$(( ($(date +%s) - $(stat -c %Y "$last")) / 3600 ))"
        echo "backup.size=$(du -h --apparent-size "$last" | cut -f1)"
        echo "backup.bytes=$(stat -c %s "$last")"
    fi
    echo "cron=$(crontab -l 2>/dev/null | grep -qF "$CRON_MARK" && echo yes || echo no)"
    echo "settings_key=$(settings_key_state)"
    echo "disk.root=$(df -P "$ROOT" | awk 'NR==2 {print $5" used, "int($4/1024)"M free"}')"
    if [ -n "$(compose ps -q --status running php 2>/dev/null)" ]; then
        compose exec -T php php bin/console messenger:stats --no-ansi 2>/dev/null | awk '$1 ~ /^(async|failed|scheduler_default)$/ {print "queue."$1"="$2}' || true
        compose exec -T php php bin/console messenger:failed:show --stats --no-ansi 2>/dev/null | grep -Eo '[0-9]+ messages?' | head -n1 | sed 's/^/queue.failed_msgs=/' || true
    fi
}

# STORE_LIB_ONLY=1 + source: лише функції, без дії (тести tests/store/run.sh)
if [ "${STORE_LIB_ONLY:-0}" = 1 ]; then return 0; fi

case "$ACTION" in
    deploy) do_deploy "$@" ;;
    backup) do_backup "${1-}" ;;
    restore) do_restore "${1:?файл}" ;;
    archive) do_archive "${1:?файл бекапу}" ;;
    doctor) do_doctor ;;
    prune) do_prune ;;
    *) die "невідома дія $ACTION" ;;
esac
