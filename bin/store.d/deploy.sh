#!/usr/bin/env bash
# SPDX-License-Identifier: Apache-2.0
# Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.
# summary: зібрати образ рушій+пак і атомарно розгорнути: deploy <key> [ref] [--pack <тека>]
# bin/store deploy <key> [ref] [--pack <тека паку>]
#   ref  — тег/коміт рушія (типово engine з реєстру, далі HEAD); деплоїться закомічене, не робоча копія.
#   пак  — --pack, або STORE_PACK, або pack (локальний шлях) з реєстру, або клон repo (owner/name на GitHub).
# Локально: git archive рушія + з паку config/parameters.yaml → config/store/parameters.local.yaml (хости звіряються з
# реєстром), themes/<key>/ → public/themes/<key>/ (без templates/ і theme.yaml), themes/<key>/templates/** (своя тема,
# docs/themes.md) → templates/store/, src/Store/ → src/Store/, catalog/demo-images/ (фото демо-даних) → config/store/demo-images/;
# файл STORE_RELEASE (версії) → на сервер у
# <path>/releases/<тег>, тег = <sha рушія 12>-<хеш паку 8>. На сервері (bin/store.d/_remote.sh deploy): образи
# store-<key>-{php,nginx}:<тег> → бекап БД → кандидат php-next/nginx-next → міграції → health → перемикання робочих
# контейнерів; провал до перемикання не чіпає робочі, провал після — відкат на попередній тег.
# Змінні: DRY_RUN=1, UNARCHIVE=1, STORE_CRON=0 (без cron бекапу), STORE_SKIP_BACKUP=1.
set -euo pipefail
# shellcheck source=bin/store.d/_lib.sh
source "$STORE_LIB"

key="" ref="" pack="${STORE_PACK:-}"
while [ $# -gt 0 ]; do
    case "$1" in
        --pack) pack="${2:?--pack <тека>}"; shift 2 ;;
        --pack=*) pack="${1#--pack=}"; shift ;;
        -h | --help) sed -n '2,12p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        -*) die "невідомий параметр $1" ;;
        *) if [ -z "$key" ]; then key="$1"; elif [ -z "$ref" ]; then ref="$1"; else die "зайвий аргумент $1"; fi; shift ;;
    esac
done
store_ctx "$key"
guard_write
if [ -z "$S_SITE" ] || [ -z "$S_ADMIN" ]; then die "$key: у реєстрі нема domains.site / domains.admin"; fi

# --- рушій ---
cd "$STORE_ENGINE_DIR"
ref="${ref:-${S_ENGINE:-HEAD}}"
if ! sha="$(git rev-parse --verify -q --short=12 "$ref^{commit}")"; then
    git fetch -q origin --tags || true
    sha="$(git rev-parse --verify -q --short=12 "$ref^{commit}")" || sha="$(git rev-parse --verify -q --short=12 "origin/$ref^{commit}")" || die "нема коміту '$ref' у рушії"
fi
if [ "$ref" = HEAD ] && [ -n "$(git status --porcelain --untracked-files=no)" ]; then
    warn "у робочій копії рушія є незакомічені зміни — деплоїться лише коміт $sha"
fi

# --- пак ---
work="$(mktemp -d "${TMPDIR:-/tmp}/store-deploy-$key.XXXXXX")"
trap 'rm -rf "$work"' EXIT
pack="${pack:-$S_PACK}"
if [ -z "$pack" ]; then
    [ -n "$S_REPO" ] || die "$key: нема паку (--pack, STORE_PACK, pack або repo у реєстрі)"
    url="$S_REPO"; case "$url" in *:* | /*) ;; *) url="git@github.com:$S_REPO.git" ;; esac
    log "клон паку $url"
    git clone -q --depth 1 "$url" "$work/pack" || die "не вдалося клонувати пак $url"
    pack="$work/pack"
fi
[ -d "$pack" ] || die "нема теки паку $pack"
pack="$(cd "$pack" && pwd)"
[ -f "$pack/config/parameters.yaml" ] || die "у паку нема config/parameters.yaml ($pack)"
[ -d "$pack/themes/$key" ] || warn "у паку нема themes/$key/ — тема лише з параметрів"
# symlink у темі (файл чи тека) — обхід забороненої зони шаблонів (site/account -> ../_acc): валідатор лінк не розкриває, cp -a зберігає
if [ -d "$pack/themes/$key" ] && [ -n "$(find "$pack/themes/$key" -type l -print -quit)" ]; then
    die "у themes/$key/ є символьні посилання ($(find "$pack/themes/$key" -type l | head -n3 | tr '\n' ' ')) — у паку вони заборонені"
fi
pack_git="$(git -C "$pack" rev-parse --short=12 HEAD 2>/dev/null || echo -)"
[ "$pack_git" != - ] && [ -n "$(git -C "$pack" status --porcelain 2>/dev/null)" ] && pack_git="$pack_git+dirty"
# Хеш вмісту того, що йде в образ: однаковий пак → той самий тег (повторний деплой не перезбирає); themes/<key> включає
# templates/** — зміна Twig теми теж перезбирає образ
pack_hash="$(cd "$pack" && { find store.yaml config/parameters.yaml "themes/$key" src/Store catalog/demo-images \( -type f -o -type l \) 2>/dev/null || true; } | LC_ALL=C sort | xargs -r sha256sum | sha256sum | cut -c1-8)"
tag="$sha-$pack_hash"
log "магазин $key: рушій $sha ($ref), пак $pack ($pack_git) → тег $tag, сервер $(ssh_dest):$S_PATH"

# Валідатор паку (bin/store validate, задача B) — якщо вже є в цій версії рушія
if [ -f "$STORE_CMD_DIR/validate.sh" ]; then
    log "bin/store validate $key"
    STORE_PACK="$pack" bash "$STORE_CMD_DIR/validate.sh" "$key" || die "пак не пройшов bin/store validate"
fi
# Тема паку проти рушія (E14 PR-4): попередження, не блокер — копії шаблонів могли відстати від виправлень рушія
if [ -f "$STORE_CMD_DIR/_theme.py" ]; then
    if theme_state="$(python3 "$STORE_CMD_DIR/_theme.py" status "$STORE_ENGINE_DIR" "$pack" --summary 2>&1)"; then
        [ -n "$theme_state" ] && log "$theme_state"
    else
        warn "$theme_state → bin/store theme status $key, theme update"
    fi
fi

# --- контекст збірки ---
ctx="$work/ctx"
mkdir -p "$ctx"
git archive --format=tar "$sha" | tar -x -C "$ctx"
python3 - "$pack/config/parameters.yaml" "$ctx/config/store/parameters.local.yaml" "$S_SITE" "$S_ADMIN" "$key" <<'PY' || exit 1
import sys, yaml
src, dst, site, admin, key = sys.argv[1:6]
data = yaml.safe_load(open(src, encoding="utf-8")) or {}
params = data.setdefault("parameters", {})
for name, want in (("site_host", site), ("admin_host", admin)):
    have = params.get(name)
    if have is not None and have != want:
        sys.exit(f"store: у паку {name}='{have}', а в реєстрі '{want}' — виправте одне з двох")
    params[name] = want
with open(dst, "w", encoding="utf-8") as f:
    f.write(f"# Згенеровано bin/store deploy для магазину {key} з паку (config/parameters.yaml) + хости з реєстру.\n")
    yaml.safe_dump(data, f, allow_unicode=True, sort_keys=False)
PY
if [ -d "$pack/themes/$key" ]; then
    rm -rf "$ctx/public/themes/$key"
    cp -a "$pack/themes/$key" "$ctx/public/themes/$key"
    # Twig паку — не в public/ (nginx віддав би сирцем; на всяк випадок там ще й deny *.twig), а в templates/store/:
    # StoreTemplatesPass ставить теку першою в головному namespace, lint:twig у збірці образу й store:smoke кандидата перевіряють
    rm -rf "$ctx/public/themes/$key/templates" "$ctx/public/themes/$key/theme.yaml"
    if [ -d "$pack/themes/$key/templates" ]; then
        mkdir -p "$ctx/templates/store"
        cp -a "$pack/themes/$key/templates/." "$ctx/templates/store/"
    fi
fi
# Необов'язкові пакети (store.yaml addons → config/addons/addons.yaml рушія, docs/addons.md): аргументи composer require
# у packages/addons.require — збірка образу ставить їх поверх composer.lock
python3 - "$pack/store.yaml" "$ctx" <<'PY' || exit 1
import os, sys, yaml
meta_path, ctx = sys.argv[1:3]
addons = (yaml.safe_load(open(meta_path, encoding="utf-8")) or {}).get("addons") or []
reg_path = os.path.join(ctx, "config/addons/addons.yaml")
known = (yaml.safe_load(open(reg_path, encoding="utf-8")) or {}) if os.path.exists(reg_path) else {}
req = []
for name in addons:
    spec = known.get(name)
    if not isinstance(spec, dict):
        sys.exit(f"store: невідомий аддон {name} у store.yaml")
    if not os.path.isdir(os.path.join(ctx, spec["path"])):
        sys.exit(f"store: аддон {name} недоступний у цій версії рушія (нема {spec['path']})")
    req += spec["packages"]
if req:
    os.makedirs(os.path.join(ctx, "packages"), exist_ok=True)
    with open(os.path.join(ctx, "packages/addons.require"), "w", encoding="utf-8") as f:
        f.write("\n".join(req) + "\n")
    print(">> аддони: " + ", ".join(addons) + " → " + " ".join(req), file=sys.stderr)
PY
if [ -d "$pack/src/Store" ]; then mkdir -p "$ctx/src/Store" && cp -a "$pack/src/Store/." "$ctx/src/Store/"; fi
# Справжні фото демо-даних (app:demo:seed, DemoPhotos): catalog/demo-images/ паку → config/store/demo-images/ образу
# симлінк у паку опублікував би будь-який файл образу — відмова
if [ -d "$pack/catalog/demo-images" ] && [ -n "$(find "$pack/catalog/demo-images" -type l -print -quit)" ]; then die "catalog/demo-images паку містить симлінки"; fi
if [ -d "$pack/catalog/demo-images" ]; then mkdir -p "$ctx/config/store/demo-images" && cp -a "$pack/catalog/demo-images/." "$ctx/config/store/demo-images/"; fi
{
    echo "key=$key"
    echo "engine_ref=$ref"
    echo "engine_sha=$sha"
    echo "pack_hash=$pack_hash"
    echo "pack_git=$pack_git"
    echo "built_at=$(date -Is)"
    echo "built_by=$(id -un)@$(hostname -s)"
} > "$ctx/STORE_RELEASE"

# --- завантаження контексту на сервер (якщо цього тегу там ще нема) ---
rel="$S_PATH/releases/$tag"
# shellcheck disable=SC2016  # виконується на сервері, $1 — шлях релізу
upload='set -e; d="$1"; [ -d "$d" ] && { cat >/dev/null; echo exists; exit 0; }; mkdir -p "$d.part"; tar -x -C "$d.part"; mv "$d.part" "$d"; echo uploaded'
if [ "$DRY_RUN" = 1 ]; then
    log "[dry-run] контекст $(du -sh "$ctx" | cut -f1) → $(ssh_dest):$rel"
elif is_local_host; then
    tar -C "$ctx" -cf - . | bash -c "$upload" _ "$rel" | sed 's/^/>> контекст: /' >&2
else
    tar -C "$ctx" -cf - . | ssh -o BatchMode=yes "$(ssh_dest)" "bash -c $(printf '%q' "$upload") _ $(printf '%q' "$rel")" | sed 's/^/>> контекст: /' >&2
fi

remote deploy "$tag" "$S_SITE" "$S_ADMIN" "$S_PROXY" "$S_TRAEFIK_NETWORK" "$S_CERTRESOLVER"
