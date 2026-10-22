# shellcheck shell=bash
# SPDX-License-Identifier: Apache-2.0
# Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.
# Спільні функції bin/store. Підключення в підкоманді: source "$STORE_LIB".
# Не виконується сам; змінні STORE_ENGINE_DIR, STORE_CMD_DIR, STORES_FILE експортує bin/store.
set -euo pipefail

: "${STORE_ENGINE_DIR:?запускати через bin/store}"
: "${STORES_FILE:=$STORE_ENGINE_DIR/stores.yaml}"
DRY_RUN="${DRY_RUN:-0}"

log() { printf '>> %s\n' "$*" >&2; }
warn() { printf '!! %s\n' "$*" >&2; }
die() { printf 'store: %s\n' "$*" >&2; exit 1; }

# run <команда…>: виконати або (DRY_RUN=1) лише надрукувати
run() {
    if [ "$DRY_RUN" = 1 ]; then
        printf '[dry-run] %s\n' "$(printf '%q ' "$@")" >&2
        return 0
    fi
    "$@"
}

# Ключ магазину: латиниця/цифри/дефіс, з літери або цифри (стає частиною імен compose-проєкту, томів, шляху)
valid_key() { [[ "$1" =~ ^[a-z0-9][a-z0-9-]{0,30}$ ]]; }
need_key() {
    [ -n "${1:-}" ] || die "не вказано ключ магазину"
    valid_key "$1" || die "некоректний ключ '$1' (дозволено a-z, 0-9, '-', до 31 символу)"
}

# reg_get <key> <поле.через.крапку> [дефолт] — значення з реєстру stores.yaml (python3 + PyYAML).
# Реєстр: `stores:` — список записів з `key:` (або мапа key → запис). Порожньо, якщо поля нема і дефолт не задано.
reg_get() {
    local key="$1" path="$2" def="${3-}"
    [ -f "$STORES_FILE" ] || die "нема реєстру $STORES_FILE"
    python3 - "$STORES_FILE" "$key" "$path" "$def" <<'PY'
import sys, yaml
f, key, path, default = sys.argv[1:5]
data = yaml.safe_load(open(f, encoding="utf-8")) or {}
stores = data.get("stores", data) if isinstance(data, dict) else data
if isinstance(stores, dict):
    rec = stores.get(key)
    if isinstance(rec, dict):
        rec = {"key": key, **rec}
else:
    rec = next((s for s in stores or [] if isinstance(s, dict) and str(s.get("key")) == key), None)
if rec is None:
    sys.stderr.write(f"store: магазину '{key}' нема в реєстрі {f}\n"); sys.exit(3)
cur = rec
for part in path.split("."):
    cur = cur.get(part) if isinstance(cur, dict) else None
    if cur is None:
        break
if cur is None:
    print(default)
elif isinstance(cur, bool):
    print("yes" if cur else "no")
elif isinstance(cur, (list, dict)):
    print(" ".join(map(str, cur)) if isinstance(cur, list) else yaml.safe_dump(cur).strip())
else:
    print(cur)
PY
}

# reg_keys [статус] — ключі всіх магазинів реєстру (за статусом, якщо задано)
reg_keys() {
    [ -f "$STORES_FILE" ] || die "нема реєстру $STORES_FILE"
    python3 - "$STORES_FILE" "${1-}" <<'PY'
import sys, yaml
f, status = sys.argv[1], sys.argv[2]
data = yaml.safe_load(open(f, encoding="utf-8")) or {}
stores = data.get("stores", data) if isinstance(data, dict) else data
items = [{"key": k, **v} for k, v in stores.items()] if isinstance(stores, dict) else (stores or [])
for s in items:
    if isinstance(s, dict) and (not status or s.get("status") == status):
        print(s["key"])
PY
}

# ---------- Контекст магазину з реєстру (A: deploy/backup/restore/archive/doctor) ----------
# store_ctx <key> — заповнює S_* з реєстру одним викликом python:
#   S_HOST S_USER S_PATH S_PROXY S_SITE S_ADMIN S_ENGINE S_STATUS S_SUPPORT S_BACKUP_TARGET S_PACK S_REPO
#   S_TRAEFIK_NETWORK S_CERTRESOLVER (необов'язкові server.traefik_network, server.certresolver)
store_ctx() {
    need_key "$1"
    local out
    out="$(python3 - "$STORES_FILE" "$1" <<'PY'
import shlex, sys, yaml
f, key = sys.argv[1:3]
data = yaml.safe_load(open(f, encoding="utf-8")) or {}
stores = data.get("stores", data) if isinstance(data, dict) else data
items = [{"key": k, **v} for k, v in stores.items()] if isinstance(stores, dict) else (stores or [])
rec = next((s for s in items if isinstance(s, dict) and str(s.get("key")) == key), None)
if rec is None:
    sys.stderr.write(f"store: магазину '{key}' нема в реєстрі {f}\n"); sys.exit(3)
srv = rec.get("server") or {}; dom = rec.get("domains") or {}
def b(v): return ("yes" if v else "no") if isinstance(v, bool) else ("" if v is None else str(v))
vals = {
    "S_HOST": srv.get("host"), "S_USER": srv.get("user"), "S_PATH": srv.get("path"), "S_PROXY": srv.get("proxy", "traefik"),
    "S_TRAEFIK_NETWORK": srv.get("traefik_network"), "S_CERTRESOLVER": srv.get("certresolver"),
    "S_SITE": dom.get("site"), "S_ADMIN": dom.get("admin"), "S_ENGINE": rec.get("engine"), "S_STATUS": rec.get("status", "active"),
    "S_SUPPORT": rec.get("support"), "S_BACKUP_TARGET": rec.get("backup_target"), "S_PACK": rec.get("pack"), "S_REPO": rec.get("repo"),
}
for k, v in vals.items():
    print(f"{k}={shlex.quote(b(v))}")
PY
)" || exit 1
    eval "$out"
    S_KEY="$1"
    if [ -z "$S_HOST" ] || [ -z "$S_PATH" ]; then die "$S_KEY: у реєстрі нема server.host або server.path"; fi
}

# Магазин за новою схемою: server.path = …/stores/<key>. Стара схема (legacyshop на старому прод-сервері: /work/shop-engine) — ніколи.
is_new_layout() { case "$S_PATH" in */stores/"$S_KEY") return 0 ;; *) return 1 ;; esac; }

# guard_write — для команд, що змінюють магазин (deploy/backup/restore/archive)
guard_write() {
    is_new_layout || die "$S_KEY: server.path '$S_PATH' не за схемою …/stores/$S_KEY — стара схема, bin/store її не чіпає"
    case "$S_STATUS" in
        archived) [ "${UNARCHIVE:-0}" = 1 ] || die "$S_KEY: status archived — лише з UNARCHIVE=1 і рішенням власника" ;;
        handed-over) die "$S_KEY: status handed-over — магазин передано клієнту, нашого доступу нема" ;;
    esac
}

is_local_host() { case "$S_HOST" in local | localhost | "$(hostname -s)") return 0 ;; *) return 1 ;; esac; }
ssh_dest() { if [ -n "$S_USER" ]; then echo "$S_USER@$S_HOST"; else echo "$S_HOST"; fi; }

# remote <дія> [аргументи] — виконати bin/store.d/_remote.sh на сервері магазину (stdout — його stdout).
# Скрипт передається в тимчасовий файл на сервері, тож його stdin вільний; змінні DRY_RUN/UNARCHIVE/STORE_* — через env.
remote() {
    local action="$1"; shift
    local envs=(DRY_RUN="$DRY_RUN" UNARCHIVE="${UNARCHIVE:-0}" STORE_CRON="${STORE_CRON:-1}"
        STORE_SKIP_BACKUP="${STORE_SKIP_BACKUP:-0}" STORE_BACKUP_KEEP_DAYS="${STORE_BACKUP_KEEP_DAYS:-14}")
    if is_local_host; then
        env "${envs[@]}" bash "$STORE_CMD_DIR/_remote.sh" "$action" "$S_KEY" "$S_PATH" "$@" </dev/null
        return
    fi
    local cmd
    cmd="f=\$(mktemp) && cat > \"\$f\" && env $(printf '%q ' "${envs[@]}") bash \"\$f\" $(printf '%q ' "$action" "$S_KEY" "$S_PATH" "$@"); rc=\$?; rm -f \"\$f\"; exit \$rc"
    if [ "$DRY_RUN" = 1 ]; then
        printf '[dry-run] ssh %s %s <_remote.sh\n' "$(ssh_dest)" "$action $*" >&2
        return 0
    fi
    ssh -o BatchMode=yes "$(ssh_dest)" "$cmd" < "$STORE_CMD_DIR/_remote.sh"
}

# put <локальний файл> <шлях на сервері> / get <шлях на сервері> <локальна тека>
put() {
    if is_local_host; then run cp "$1" "$2"; else run rsync -a --partial -e "ssh -o BatchMode=yes" "$1" "$(ssh_dest):$2"; fi
}
get() {
    if is_local_host; then run cp -p "$1" "$2/"; else run rsync -a --partial -e "ssh -o BatchMode=yes" "$(ssh_dest):$1" "$2/"; fi
}
