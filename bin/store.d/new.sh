#!/usr/bin/env bash
# SPDX-License-Identifier: Apache-2.0
# Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.
# summary: створити пак магазину з шаблону: new <key> <домен> [--from=template|<тека>] [--dir=…] [--github]
#
#   bin/store new <key> <домен> [опції]
#
#   <key>            ключ магазину (a-z, 0-9, '-'): compose-проєкт store-<key>, тема themes/<key>, репо store-<key>
#   <домен>          бойовий домен сайту (shop.example.com); адмінка — admin.<домен>, якщо не задано --admin
#   --from=SRC       template (типово: store-template/ цього рушія) або тека іншого паку як зразок
#   --name=TEXT      назва магазину (типово — ключ); підставляється в store.yaml, назву сайту, тексти-заготовки
#   --admin=HOST     хост адмінки
#   --locales=uk,en  мови (перша — за замовчуванням), типово uk,en
#   --dir=DIR        куди створити пак (типово $STORE_PACKS_DIR/store-<key>, STORE_PACKS_DIR типово — поточна тека)
#   --github         ще й створити приватний репо example-org/store-<key> (gh), дати доступ команді developers, push
#
# Створює теку з git init і першим комітом, перевіряє пак (bin/store validate) і друкує запис для stores.yaml —
# реєстр змінюється PR-ом у рушій, не цією командою. DRY_RUN=1 — лише показати дії.
set -euo pipefail
# shellcheck source=bin/store.d/_lib.sh
source "$STORE_LIB"

TEMPLATE_DIR="$STORE_ENGINE_DIR/store-template"
TEMPLATE_KEY=storetemplate
TEMPLATE_NAME='Store Template'
GITHUB_ORG="${STORE_GITHUB_ORG:-luxsite-agency}"
GITHUB_TEAM="${STORE_GITHUB_TEAM:-developers}"

usage() { sed -n '2,19p' "$0" | sed 's/^# \{0,1\}//'; }

key="" domain="" from=template name="" admin="" locales="uk,en" dir="" github=0
for arg in "$@"; do
    case "$arg" in
        -h | --help) usage; exit 0 ;;
        --from=*) from="${arg#*=}" ;;
        --name=*) name="${arg#*=}" ;;
        --admin=*) admin="${arg#*=}" ;;
        --locales=*) locales="${arg#*=}" ;;
        --dir=*) dir="${arg#*=}" ;;
        --github) github=1 ;;
        -*) die "new: невідома опція $arg (див. bin/store new --help)" ;;
        *)
            if [ -z "$key" ]; then key="$arg"
            elif [ -z "$domain" ]; then domain="$arg"
            else die "new: зайвий аргумент $arg"
            fi
            ;;
    esac
done
need_key "$key"
[ "$key" != "$TEMPLATE_KEY" ] || die "new: ключ '$key' зарезервовано шаблоном"
host_re='^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$'
[[ "$domain" =~ $host_re ]] || die "new: домен '$domain' — ім'я хоста малими літерами (shop.example.com)"
admin="${admin:-admin.$domain}"
[[ "$admin" =~ $host_re ]] || die "new: --admin '$admin' — ім'я хоста"
[ "$admin" != "$domain" ] || die "new: адмінка й сайт мають бути різними хостами"
[[ "$locales" =~ ^[a-z]{2}(,[a-z]{2})*$ ]] || die "new: --locales — коди мов через кому (uk,en)"
name="${name:-$key}"
case "$name" in *$'\n'* | *"'"*) die "new: --name без апострофів і переносів рядка (YAML у лапках)" ;; esac

case "$from" in
    template) src="$TEMPLATE_DIR"; src_key="$TEMPLATE_KEY"; src_name="$TEMPLATE_NAME" ;;
    *)
        [ -f "$from/store.yaml" ] || die "new: --from=$from — нема store.yaml (тека паку?)"
        src="$from"
        src_key="$(python3 -c 'import sys,yaml; print(yaml.safe_load(open(sys.argv[1]))["key"])' "$from/store.yaml")"
        src_name="$(python3 -c 'import sys,yaml; print(yaml.safe_load(open(sys.argv[1]))["name"])' "$from/store.yaml")"
        ;;
esac
[ -d "$src/themes/$src_key" ] || die "new: у зразку нема themes/$src_key/"
dir="${dir:-${STORE_PACKS_DIR:-$PWD}/store-$key}"
[ ! -e "$dir" ] || die "new: $dir уже існує — не перезаписую"
if reg_get "$key" key >/dev/null 2>&1; then
    die "new: магазин '$key' уже є в реєстрі $STORES_FILE"
fi
engine="$(git -C "$STORE_ENGINE_DIR" describe --tags --exact-match 2>/dev/null || git -C "$STORE_ENGINE_DIR" rev-parse --short=7 HEAD)"

log "пак store-$key з $( [ "$from" = template ] && echo "шаблону" || echo "$src" ) → $dir (рушій $engine)"
if [ "$DRY_RUN" = 1 ]; then
    run cp -a "$src/." "$dir/"
    run mv "$dir/themes/$src_key" "$dir/themes/$key"
    run python3 - "<підстановка $src_key→$key, домени, назва, мови, engine>"
    run git -C "$dir" init -q -b main
    [ "$github" = 1 ] && run gh repo create "$GITHUB_ORG/store-$key" --private --source "$dir" --push
    exit 0
fi

mkdir -p "$(dirname "$dir")"
tmp="$(mktemp -d "$(dirname "$dir")/.store-new-$key.XXXXXX")"
trap 'rm -rf "$tmp"' EXIT
# зразок без .git і без локальних файлів (секрети в паку не живуть, але зразок — чужа робоча копія)
tar -C "$src" --exclude=.git --exclude='.env*' --exclude=.secrets -cf - . | tar -C "$tmp" -xf -
mv "$tmp/themes/$src_key" "$tmp/themes/$key"

# Підстановки: старий домен адмінки → новий, старий домен сайту → новий, ключ і назва зразка → нові.
# Для шаблону токени унікальні (storetemplate, storetemplate.example.com); для іншого паку — його ключ, домени й назва з store.yaml.
python3 - "$tmp" "$src_key" "$key" "$src_name" "$name" "$domain" "$admin" "$locales" "$engine" <<'PY'
import os, re, sys, yaml
root, src_key, key, src_name, name, domain, admin, locales, engine = sys.argv[1:10]
meta = yaml.safe_load(open(os.path.join(root, "store.yaml"), encoding="utf-8"))
old_site, old_admin = meta["domains"]["site"], meta["domains"]["admin"]
locs = locales.split(",")
pairs = [(old_admin, admin), (old_site, domain), (src_name, name)]
key_re = re.compile(r"(?<![a-z0-9])" + re.escape(src_key) + r"(?![a-z0-9])")
TEXT = {".yaml", ".yml", ".md", ".csv", ".json", ".txt", ".css", ".html", ".twig", ".svg", ".gitignore"}
for dp, _, files in os.walk(root):
    for f in files:
        p = os.path.join(dp, f)
        if os.path.splitext(f)[1] not in TEXT and f not in TEXT:
            continue
        s = open(p, encoding="utf-8").read()
        t = s
        for a, b in pairs:
            t = t.replace(a, b)
        t = key_re.sub(key, t)
        if t != s:
            open(p, "w", encoding="utf-8").write(t)
# мови й версія рушія — рядки store.yaml і parameters.yaml
sy = os.path.join(root, "store.yaml")
s = open(sy, encoding="utf-8").read()
s = re.sub(r"(?m)^locales: \[.*\]$", "locales: [" + ", ".join(locs) + "]", s)
s = re.sub(r"(?m)^engine: .*$", "engine: " + engine, s)
open(sy, "w", encoding="utf-8").write(s)
py = os.path.join(root, "config/parameters.yaml")
s = open(py, encoding="utf-8").read()
s = re.sub(r"(?m)^(\s*app\.locales: )\[.*\]$", lambda m: m.group(1) + "[" + ", ".join(f"'{l}'" for l in locs) + "]", s)
s = re.sub(r"(?m)^(\s*app\.default_locale: ).*$", lambda m: m.group(1) + f"'{locs[0]}'", s)
open(py, "w", encoding="utf-8").write(s)
PY

python3 "$STORE_CMD_DIR/_validate.py" pack "$STORE_ENGINE_DIR" "$tmp" || die "new: пак не пройшов перевірку (тека не створена)"
mv "$tmp" "$dir"
trap - EXIT

git -C "$dir" init -q -b main
git -C "$dir" add -A
git -C "$dir" -c user.name="${GIT_AUTHOR_NAME:-$(git -C "$STORE_ENGINE_DIR" config user.name || echo store)}" \
    -c user.email="${GIT_AUTHOR_EMAIL:-$(git -C "$STORE_ENGINE_DIR" config user.email || echo store@localhost)}" \
    commit -q -m "Пак магазину $key з $( [ "$from" = template ] && echo "store-template" || echo "$src_key" ) (рушій $engine)"
log "пак створено: $dir"

if [ "$github" = 1 ]; then
    command -v gh >/dev/null || die "new: нема gh CLI — репо створіть вручну (приватний, команда $GITHUB_TEAM)"
    # Лише приватний репо в організації і одразу команда developers (правила dev-сервер: публічний репо = витік коду клієнтів)
    run gh repo create "$GITHUB_ORG/store-$key" --private --source "$dir" --remote origin --push
    run gh api -X PUT "orgs/$GITHUB_ORG/teams/$GITHUB_TEAM/repos/$GITHUB_ORG/store-$key" -f permission=push
    log "репо: https://github.com/$GITHUB_ORG/store-$key (приватний)"
fi

loc_list="${locales//,/, }"
cat <<EOF

Запис для реєстру ($STORES_FILE, додати PR-ом у рушій; server і backup_target — уточнити):
    - key: $key
      name: '$name'
      repo: $GITHUB_ORG/store-$key
      pack: $dir
      server:
          host: <ssh-аліас сервера>
          user: deploy
          path: /work/stores/$key
          proxy: traefik
      domains:
          site: $domain
          admin: $admin
      locales: [$loc_list]
      engine: $engine
      status: active
      support: true
      backup_target: <host:/шлях сховища бекапів>

Далі: docs/launch-checklist.md у паку.
EOF
