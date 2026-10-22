#!/usr/bin/env bash
# Тести bin/store і docker-compose.store.yml без сервера (CI job store-ops; локально: bash tests/store/run.sh):
#   перевірка shellcheck усіх скриптів, `docker compose config` для профілів traefik/standalone/candidate,
#   сухі прогони DRY_RUN=1 команд на реєстрі-фікстурі tests/store/stores.yaml і відмови запобіжників.
set -uo pipefail
cd "$(dirname "$0")/../.." || exit 1
export STORES_FILE=tests/store/stores.yaml DRY_RUN=1 STORE_YES=1
pass=0 fail=0
t() { # t <опис> <очікуваний код: 0|!0> <команда…>
    local name="$1" want="$2"; shift 2
    local out rc; out="$("$@" 2>&1)"; rc=$?
    if { [ "$want" = 0 ] && [ "$rc" = 0 ]; } || { [ "$want" = '!0' ] && [ "$rc" != 0 ]; }; then
        pass=$((pass + 1)); echo "ok   $name"
    else
        fail=$((fail + 1)); echo "FAIL $name (код $rc)"; echo "$out" | sed 's/^/     | /' | tail -n 25
    fi
    LAST="$out"
}
has() { if grep -qF -- "$1" <<<"$LAST"; then pass=$((pass + 1)); echo "ok     ↳ є «$1»"; else fail=$((fail + 1)); echo "FAIL   ↳ нема «$1»"; fi; }

# Перевірка shellcheck: локальний або з образу
sc_files=(bin/store bin/store.d/*.sh tests/store/run.sh)
if command -v shellcheck >/dev/null; then t "shellcheck" 0 shellcheck -x "${sc_files[@]}"
elif command -v docker >/dev/null; then t "shellcheck (docker)" 0 docker run --rm -e LANG=C.UTF-8 -e LC_ALL=C.UTF-8 -v "$PWD:/mnt" -w /mnt koalaman/shellcheck:stable -x "${sc_files[@]}"
else echo "skip shellcheck: нема ні shellcheck, ні docker"; fi

# nginx -t усіх чотирьох конфігів (E15 PR-6: правила /api/admin і /mcp): шаблони рендерить entrypoint образу (envsubst),
# імена upstream — через --add-host (nginx резолвить fastcgi_pass ще при перевірці конфігу)
if command -v docker >/dev/null; then
    NGINX_IMG=nginx:1.27-alpine
    ngt() { # ngt <конфіг> <шлях у контейнері> <docker run args…>
        local conf="$1" dest="$2"; shift 2
        docker run --rm -v "$PWD/docker/nginx/$conf:$dest:ro" "$@" "$NGINX_IMG" sh -c '/docker-entrypoint.sh nginx -t'
    }
    t "nginx -t: prod.conf" 0 ngt prod.conf /etc/nginx/templates/default.conf.template --add-host shop-engine-php:127.0.0.1 \
        -e SHOP_X_ROBOTS_TAG="noindex, nofollow" -e SHOP_REAL_IP_HEADER=CF-Connecting-IP -e SHOP_ADMIN_HOST=shop-admin.example.com
    t "nginx -t: store.conf" 0 ngt store.conf /etc/nginx/store-templates/default.conf.template --add-host php:127.0.0.1 \
        -e NGINX_ENVSUBST_TEMPLATE_DIR=/etc/nginx/store-templates -e STORE_PHP_UPSTREAM=php:9000 -e STORE_X_ROBOTS_TAG= \
        -e STORE_REAL_IP_HEADER=X-Forwarded-For -e STORE_ADMIN_HOST=store-demo-admin.localhost
    t "nginx -t: default.conf" 0 ngt default.conf /etc/nginx/conf.d/default.conf --add-host shop-engine-php:127.0.0.1
else echo "skip nginx -t: нема docker"; fi

# compose config з прикладними параметрами
root="$(mktemp -d)"; trap 'rm -rf "$root"' EXIT
mkdir -p "$root/.secrets"; touch "$root/.env.local" "$root/.secrets/mysql-root.txt" "$root/.secrets/mysql-password.txt"
cc() { STORE_ROOT="$root" COMPOSE_PROFILES="$1" docker compose --env-file tests/store/example.env -f docker-compose.store.yml config "${@:2}"; }
t "compose config: traefik" 0 cc traefik -q
t "compose config: traefik — сервіси" 0 cc traefik --services
has nginx; has worker; has feed-worker; has mail-worker; has mysql
t "compose config: traefik — мітки й томи" 0 cc traefik --format json
# shellcheck disable=SC2016  # бектики — частина правила traefik, не підстановка
has '"traefik.http.routers.store-demo.rule": "Host(`store-demo.localhost`) || Host(`store-demo-admin.localhost`)"'
has '"name": "store-demo_mysql"'; has '"name": "store-demo_feeds"'; has 'innodb-buffer-pool-size=192M'; has '"stop_grace_period": "1m0s"'; has 'time-limit=3600'
t "compose config: standalone (caddy)" 0 cc standalone --services
has caddy
t "compose config: candidate" 0 cc traefik,candidate --services
has php-next; has nginx-next
t "compose config: без STORE_KEY — помилка" '!0' env STORE_KEY= STORE_ROOT="$root" docker compose -f docker-compose.store.yml config -q

# Диспетчер
t "help" 0 bin/store help
has deploy; has doctor; has theme
t "невідома команда" '!0' bin/store nope
t "команда з _ не запускається" '!0' bin/store _lib
t "некоректний ключ" '!0' bin/store deploy 'Bad_Key'
t "ключа нема в реєстрі" '!0' bin/store deploy nosuch

# Сухі прогони
t "deploy demo (local, traefik)" 0 bin/store deploy demo
has "тег"; has "[dry-run] health nginx-next"; has "перемикання на"
t "deploy client (ssh, standalone, --pack)" 0 bin/store deploy client HEAD --pack tests/store/pack-client
has "[dry-run] ssh deploy@client.example.com deploy"
addon_pack="$root/pack-addon"; cp -a tests/store/pack-demo "$addon_pack"; echo 'addons: [gmb-reviews]' >>"$addon_pack/store.yaml"
if [ -d packages/symfony/gmb-reviews-bundle ]; then
    t "deploy з addons (store.yaml)" 0 bin/store deploy demo HEAD --pack "$addon_pack"
    has "аддони: gmb-reviews → luxsite/gmb-reviews-bundle:^1.2"
else # публічна копія рушія — без приватного пакета
    t "deploy з addons без пакета в рушії — відмова" '!0' bin/store deploy demo HEAD --pack "$addon_pack"
    has "недоступний"
fi
cp -a tests/store/pack-demo "$root/pack-bad"; echo 'addons: [nope]' >>"$root/pack-bad/store.yaml"
t "deploy з невідомим аддоном — відмова" '!0' bin/store deploy demo HEAD --pack "$root/pack-bad"
t "backup demo" 0 bin/store backup demo
t "backup --all" 0 bin/store backup --all
t "restore client (файл)" 0 bin/store restore client tests/store/stores.yaml --yes
has "[dry-run] ssh deploy@client.example.com restore"
t "restore без архіву" '!0' bin/store restore demo
t "archive client" 0 bin/store archive client
t "doctor --all" 0 bin/store doctor --all
has "[SKIP] стара схема"

# Запобіжники старої схеми й архіву
t "deploy old (стара схема)" '!0' bin/store deploy old
has "стара схема"
t "deploy old з UNARCHIVE=1 — однаково відмова" '!0' env UNARCHIVE=1 bin/store deploy old
t "backup old" '!0' bin/store backup old
t "restore old" '!0' bin/store restore old x.sql.gz --yes
t "archive old" '!0' bin/store archive old
t "_remote.sh: шлях не */stores/<key>" '!0' bash bin/store.d/_remote.sh doctor demo /work/shop-engine

# doctor: останній звичайний бекап (страхові pre-* не рахуються), WARN на < 100K і > 36 год.
# Сервер — тимчасова тека (host: local) з фейковими архівами; контейнерів нема, тож інші перевірки дають WARN/FAIL.
doc_root="$root/srv/stores/demo"
mkdir -p "$doc_root/backups"
echo "STORE_TAG=0123456789ab-cafe0123" > "$doc_root/release.env"
cat > "$root/stores.yaml" <<YAML
version: 1
stores:
  - key: demo
    server: { host: local, path: $doc_root, proxy: traefik }
    status: active
YAML
mk() { truncate -s "$2" "$doc_root/backups/$1"; touch -d "$3" "$doc_root/backups/$1"; }
doctor_t() { env -u DRY_RUN STORES_FILE="$root/stores.yaml" bin/store doctor demo; }
mk demo-20261001-031500.tar 300K '2 hours ago'
mk demo-20261001-091500-pre-deploy.tar 20K '1 hour ago'
mk demo-20261001-101500-pre-restore.tar 20K '30 minutes ago'
t "doctor: свіжий звичайний бекап, новіші страхові pre-* ігноруються" 0 doctor_t
has "[OK]   бекап: demo-20261001-031500.tar"
mk demo-20261001-031500.tar 20K '2 hours ago'
t "doctor: звичайний бекап < 100K" 0 doctor_t
has "[WARN] останній бекап demo-20261001-031500.tar: 20K (< 100K"
mk demo-20261001-031500.tar 300K '40 hours ago'
t "doctor: звичайний бекап > 36 год" 0 doctor_t
has "[WARN] останній бекап demo-20261001-031500.tar: 40 год тому (> 36 год)"
rm -f "$doc_root/backups/demo-20261001-031500.tar"
t "doctor: лише страхові pre-* → FAIL" '!0' doctor_t
has "[FAIL] звичайних бекапів на сервері нема"

# serverVersion у DATABASE_URL: з тегу mysql у compose, дописується в наявний .env.local
t "compose: mysql з точною версією x.y.z" 0 grep -Eq '^    image: mysql:[0-9]+\.[0-9]+\.[0-9]+$' docker-compose.store.yml
sv_t() { # sv_t <рядок DATABASE_URL до> → рядок після
    local r2="$root/sv/stores/demo"; mkdir -p "$r2"; printf 'APP_ENV=prod\n%s\nAPP_DEBUG=0\n' "$1" > "$r2/.env.local"
    DRY_RUN=0 STORE_LIB_ONLY=1 bash -c 'source bin/store.d/_remote.sh fn demo "$1"; server_version_env "$(mysql_version docker-compose.store.yml)"' _ "$r2" 2>/dev/null
    grep '^DATABASE_URL=' "$r2/.env.local"; stat -c %a "$r2/.env.local"
}
v="$(sed -n 's/^    image: mysql://p' docker-compose.store.yml)"
t "serverVersion=8.0 → точна" 0 sv_t 'DATABASE_URL=mysql://u:p@mysql:3306/shop?serverVersion=8.0&charset=utf8mb4'
has "DATABASE_URL=mysql://u:p@mysql:3306/shop?serverVersion=$v&charset=utf8mb4"; has 600
t "без serverVersion, з ?" 0 sv_t 'DATABASE_URL=mysql://u:p@mysql:3306/shop?charset=utf8mb4'
has "shop?charset=utf8mb4&serverVersion=$v"
t "без параметрів" 0 sv_t 'DATABASE_URL=mysql://u:p@mysql:3306/shop'
has "shop?serverVersion=$v"

echo "== store-ops: ok $pass, fail $fail"
[ "$fail" = 0 ]
