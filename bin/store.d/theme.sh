#!/usr/bin/env bash
# SPDX-License-Identifier: Apache-2.0
# Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.
# summary: своя тема магазину в паку (заготовки, маніфест, diff/status/update/prune проти рушія): theme list|new|diff|status|update|prune <key|тека-паку>
#
#   bin/store theme list                        які шаблони рушія можна перекрити в паку і які блоки має кожен
#   bin/store theme new <key|тека> [шаблон…]    створити тему в паку: themes/<key>/templates/<шаблон> як {% extends '@engine/…' %}
#                                               з усіма блоками = parent() (типово site/base.html.twig і site/home.html.twig),
#                                               themes/<key>/theme.css (якщо нема) і маніфест themes/<key>/theme.yaml
#   bin/store theme new <key> --copy <шаблон>…  повна копія шаблону рушія в пак (своя верстка з нуля; виправлення рушія
#                                               в цей файл більше не доходять — лише через theme diff)
#   bin/store theme diff <key|тека>             що змінилось у рушії в перекритих шаблонах з версії engine: маніфесту
#                                               (інакше store.yaml) паку — файл, рядки, блоки; звірка після оновлення рушія
#   bin/store theme diff <key|тека> <шаблон>    3-way (копія паку, база на момент копії, база зараз) через git merge-file -p
#                                               у stdout; нічого не пише. Код 0 — злилось чисто, інакше кількість конфліктів
#   bin/store theme status <key|тека>           таблиця по файлах теми: без змін у базі / оновити автоматично / ручне злиття /
#                                               заготовка extends / ідентична базі (prune) / застаріло / новий у базі / власний
#   bin/store theme update <key|тека>           застосувати лише авто-клас (база змінилась, копія ні), підняти engine і хеші
#                                               в маніфесті; конфліктні лишити з підказкою theme diff. --dry-run;
#                                               --ack-extends — зняти нагадування «перевірте блоки» із заготовок
#   bin/store theme prune <key|тека>            прибрати з копії файли, ідентичні поточній базі (fallback рушія їх підхопить),
#                                               оновити маніфест. --dry-run
#   --force                                     переписати наявні файли теми
#
# Модель узгоджена з service-engine (bin/site theme, 11.10.2026): тема живе в паку, деплой кладе themes/<key>/templates/**
# у templates/store/ образу, Twig бере їх перед шаблонами рушія (StoreTemplatesPass), рушій доступний як @engine.
# Перекривати можна лише site/** (*.twig) без забороненої зони (site/account/**, _schema*, sitemap) — docs/themes.md.
# Далі: правити файли в паку, закомітити, bin/store deploy (lint:twig у збірці образу + store:smoke кандидата).
set -euo pipefail
# shellcheck source=bin/store.d/_lib.sh
source "$STORE_LIB"

ENGINE_TPL="$STORE_ENGINE_DIR/templates"
DEFAULT_TEMPLATES="site/base.html.twig site/home.html.twig"
TEMPLATE_CONTRACT=1

usage() { sed -n '2,20p' "$0" | sed 's/^# \{0,1\}//'; }
python3 -c 'import yaml' 2>/dev/null || die "потрібні python3 і PyYAML (apt install python3-yaml)"

# пак за ключем реєстру або текою (як у validate.sh)
pack_dir_of() {
    local target="$1" dir
    if [ -d "$target" ]; then (cd "$target" && pwd); return; fi
    if [ -n "${STORE_PACK:-}" ]; then printf '%s' "$STORE_PACK"; return; fi
    need_key "$target"
    dir="$(reg_get "$target" pack)" || exit 1
    case "$dir" in "" | /*) ;; *) dir="$STORE_ENGINE_DIR/$dir" ;; esac
    if [ -z "$dir" ] || [ ! -d "$dir" ]; then die "theme: у реєстрі для '$target' нема локального pack — вкажіть теку паку"; fi
    printf '%s' "$dir"
}
pack_key() { sed -n 's/^key:[[:space:]]*//p' "$1/store.yaml" | head -n1 | tr -d "'\" \r"; }
pack_engine() { sed -n 's/^engine:[[:space:]]*//p' "$1/store.yaml" | head -n1 | tr -d "'\" \r"; }
manifest_engine() { if [ -f "$1" ]; then sed -n 's/^engine:[[:space:]]*//p' "$1" | head -n1 | sed 's/[[:space:]]*#.*$//' | tr -d "'\" \r"; fi; }
engine_version() { git -C "$STORE_ENGINE_DIR" describe --tags --exact-match 2>/dev/null || git -C "$STORE_ENGINE_DIR" rev-parse --short=12 HEAD; }

# шаблони рушія, які можна перекрити (лише публічні site/** без забороненої зони), відносно templates/
forbidden() { case "$1" in site/account/* | site/partials/_schema* | site/sitemap*) return 0 ;; *) return 1 ;; esac; }
overridable() {
    local t
    for t in $(cd "$ENGINE_TPL" && find site -name '*.twig' -type f | sed 's#^\./##' | LC_ALL=C sort); do
        forbidden "$t" || echo "$t"
    done
}
# шлях лише з site/**, без ".." (вихід за templates/) і без забороненої зони
is_overridable() { case "$1" in *..* | /*) return 1 ;; site/*.twig) [ -f "$ENGINE_TPL/$1" ] && ! forbidden "$1" ;; *) return 1 ;; esac; }
# блоки, оголошені в самому файлі (їх і можна перевизначити через extends); Twig-коментарі {# … #} вирізаються, бо в них
# згадуються блоки-приклади. pipefail: grep без збігів (partial без блоків) не має валити скрипт
blocks_of() { perl -0pe 's/\{#.*?#\}//gs' "$1" | { grep -o '{%-\? *block [A-Za-z_][A-Za-z0-9_]* *-\?%}' 2>/dev/null || true; } | sed -E 's/.*block +([A-Za-z0-9_]+).*/\1/' | awk '!seen[$0]++'; }

cmd_list() {
    local t b
    echo "Шаблони рушія, які пак може перекрити (themes/<key>/templates/<шлях> у паку; блоки — для {% extends '@engine/<шлях>' %}):"
    for t in $(overridable); do
        b="$(blocks_of "$ENGINE_TPL/$t" | tr '\n' ' ')"
        if [ -n "$b" ]; then printf '  %-44s блоки: %s\n' "$t" "$b"; else printf '  %-44s (partial, без блоків — лише --copy або include у своїх шаблонах)\n' "$t"; fi
    done
    echo "Заборонена зона (лишається рушію): site/account/**, site/partials/_schema*, site/sitemap* — docs/themes.md."
}

write_stub() { # write_stub <пак> <ключ> <шаблон>
    local pack="$1" key="$2" t="$3" dst="$1/themes/$2/templates/$3" b blocks
    blocks="$(blocks_of "$ENGINE_TPL/$t")"
    [ -n "$blocks" ] || { warn "$t — partial без блоків: заготовка-extends неможлива, використайте --copy"; return 0; }
    if [ -e "$dst" ] && [ "$FORCE" != 1 ]; then warn "$dst уже є — пропущено (--force, щоб переписати)"; return 0; fi
    run mkdir -p "$(dirname "$dst")"
    [ "$DRY_RUN" = 1 ] && { log "[dry-run] заготовка $dst (блоки: $(echo "$blocks" | tr '\n' ' '))"; return 0; }
    {
        printf '{# Тема %s: успадковано з рушія (@engine/%s). Перевизначте потрібні блоки — решту лишіть як parent() або\n' "$key" "$t"
        printf '   видаліть. Змінні контролера — у шапці шаблону рушія (контракт docs/themes.md). Після оновлення рушія: bin/store theme diff %s. #}\n' "$key"
        printf "{%% extends '@engine/%s' %%}\n" "$t"
        # в один рядок: блоки title/bodyClass/ogImage виводяться всередині тегів, зайві переноси туди не потрібні
        for b in $blocks; do
            printf '{%% block %s %%}{{ parent() }}{%% endblock %%}\n' "$b"
        done
    } > "$dst"
    WRITTEN+=("$t")
    log "заготовка: themes/$key/templates/$t (блоки: $(echo "$blocks" | tr '\n' ' '))"
}
write_copy() { # write_copy <пак> <ключ> <шаблон>
    local dst="$1/themes/$2/templates/$3"
    if [ -e "$dst" ] && [ "$FORCE" != 1 ]; then warn "$dst уже є — пропущено (--force, щоб переписати)"; return 0; fi
    run mkdir -p "$(dirname "$dst")"
    [ "$DRY_RUN" = 1 ] && { log "[dry-run] копія $dst"; return 0; }
    {
        printf '{# Тема %s: ПОВНА КОПІЯ шаблону рушія %s (рушій %s). Виправлення рушія сюди не доходять —\n' "$2" "$3" "$(engine_version)"
        printf '   після оновлення рушія звіряти: bin/store theme diff %s. Чутливі зони (analytics_*, canonical/hreflang, лого, UA/RU/EN) не прибирати. #}\n' "$2"
        cat "$ENGINE_TPL/$3"
    } > "$dst"
    WRITTEN+=("$3")
    log "копія: themes/$2/templates/$3"
}
write_css() { # write_css <пак> <ключ>
    local css="$1/themes/$2/theme.css"
    [ -e "$css" ] && { log "themes/$2/theme.css уже є"; return 0; }
    run mkdir -p "$(dirname "$css")"
    [ "$DRY_RUN" = 1 ] && { log "[dry-run] $css"; return 0; }
    {
        printf '/* Тема %s — CSS поверх assets/site.css рушія. Підключення: config/parameters.yaml → app.theme.stylesheet:\n' "$2"
        printf '   themes/%s/theme.css. Кольори, шрифт, радіуси — app.theme (CSS-змінні --c-*, --font, --radius), тут — лише те,\n' "$2"
        printf '   чого змінними не зробити. Усі правила — під body.theme-%s; стабільні класи — docs/themes.md. */\n' "$2"
        printf 'body.theme-%s {\n}\n' "$2"
    } > "$css"
    log "створено themes/$2/theme.css"
    grep -q "stylesheet: *'\{0,1\}themes/$2/theme.css" "$1/config/parameters.yaml" 2>/dev/null \
        || warn "у config/parameters.yaml задайте app.theme.stylesheet: themes/$2/theme.css (зараз CSS не підключено)"
}
# маніфест themes/<key>/theme.yaml: з чого й з якої версії рушія зроблено тему, контракт, sha256 базових файлів рушія
# на момент копії (theme diff/status порівнюють з ними). Записуються лише файли, створені цією командою.
write_manifest() { # write_manifest <пак> <ключ> <шаблон…>
    local pack="$1" key="$2"; shift 2
    [ "$DRY_RUN" = 1 ] && { log "[dry-run] маніфест themes/$key/theme.yaml ($*)"; return 0; }
    python3 - "$pack/themes/$key/theme.yaml" "$key" "$(engine_version)" "$TEMPLATE_CONTRACT" "$ENGINE_TPL" "$@" <<'PY'
import datetime, hashlib, os, sys, yaml
path, key, engine, contract, engine_tpl, *files = sys.argv[1:]
data = {}
if os.path.isfile(path):
    data = yaml.safe_load(open(path, encoding="utf-8")) or {}
data = {"format": 1, "key": key, "from": "engine", "engine": engine, "contract": int(contract),
        "created": data.get("created") or datetime.date.today().isoformat(), "files": dict(data.get("files") or {})}
for f in files:
    with open(os.path.join(engine_tpl, f), "rb") as fh:
        data["files"][f] = hashlib.sha256(fh.read()).hexdigest()
data["files"] = dict(sorted(data["files"].items()))
with open(path, "w", encoding="utf-8") as fh:
    fh.write("# Маніфест теми паку (bin/store theme): from/engine — звідки й з якої версії рушія зроблено заготовки й копії,\n"
             "# contract — версія контракту шаблонів (docs/themes.md), files — sha256 базових файлів рушія на момент копії.\n"
             "# Пише bin/store theme new, читає theme diff і bin/store validate. Руками не правити.\n")
    yaml.safe_dump(data, fh, allow_unicode=True, sort_keys=False)
PY
    log "маніфест: themes/$key/theme.yaml (рушій $(engine_version), contract $TEMPLATE_CONTRACT)"
}

cmd_new() {
    local target="" mode=extend t pack key
    local -a tpls=()
    for arg in "$@"; do
        case "$arg" in
            --copy) mode=copy ;;
            --extend) mode=extend ;;
            --force) ;;
            -*) die "theme new: невідома опція $arg" ;;
            *) if [ -z "$target" ]; then target="$arg"; else tpls+=("$arg"); fi ;;
        esac
    done
    [ -n "$target" ] || die "theme new: вкажіть ключ магазину або теку паку"
    pack="$(pack_dir_of "$target")"; key="$(pack_key "$pack")"
    [ -n "$key" ] || die "theme: у $pack/store.yaml нема key"
    [ "${#tpls[@]}" -gt 0 ] || read -r -a tpls <<<"$DEFAULT_TEMPLATES"
    for t in "${tpls[@]}"; do
        t="${t#templates/}"
        is_overridable "$t" || die "theme: '$t' — не шаблон рушія з site/** або заборонена зона (bin/store theme list)"
    done
    log "тема $key у паку $pack"
    write_css "$pack" "$key"
    for t in "${tpls[@]}"; do
        t="${t#templates/}"
        if [ "$mode" = copy ]; then write_copy "$pack" "$key" "$t"; else write_stub "$pack" "$key" "$t"; fi
    done
    [ "${#WRITTEN[@]}" -gt 0 ] && write_manifest "$pack" "$key" "${WRITTEN[@]}"
    [ "$DRY_RUN" = 1 ] || python3 "$STORE_CMD_DIR/_validate.py" pack "$STORE_ENGINE_DIR" "$pack" "$STORES_FILE" >/dev/null 2>&1 \
        || warn "bin/store validate $pack — перевірте пак"
    log "далі: правити themes/$key/templates/ і themes/$key/theme.css у паку → commit → bin/store deploy $key <тег>"
}

# status|update|prune|merge — bin/store.d/_theme.py (три версії файлу: база на момент копії з git, база зараз, копія паку)
cmd_py() { # cmd_py <режим> <ціль> [опції…]
    local mode="$1" target="${2:-}" pack; shift 2 || true
    [ -n "$target" ] || die "theme $mode: вкажіть ключ магазину або теку паку"
    pack="$(pack_dir_of "$target")"
    [ "$DRY_RUN" = 1 ] && set -- "$@" --dry-run
    python3 "$STORE_CMD_DIR/_theme.py" "$mode" "$STORE_ENGINE_DIR" "$pack" "$@"
}

cmd_diff() {
    local target="${1:-}" pack key ref base f rel tpl changed=0 total=0 stat blocks
    [ -n "$target" ] || die "theme diff: вкажіть ключ магазину або теку паку"
    if [ -n "${2:-}" ]; then cmd_py merge "$target" "$2"; return; fi
    pack="$(pack_dir_of "$target")"; key="$(pack_key "$pack")"; tpl="$pack/themes/$key/templates"
    [ -d "$tpl" ] || { log "$key: у паку нема themes/$key/templates/ — своєї теми нема, порівнювати нічого"; return 0; }
    ref="$(manifest_engine "$pack/themes/$key/theme.yaml")"; [ -n "$ref" ] || ref="$(pack_engine "$pack")"
    [ -n "$ref" ] || die "theme diff: ні в themes/$key/theme.yaml, ні в store.yaml паку нема engine: (тег/коміт рушія, з якого робилась тема)"
    base="$(git -C "$STORE_ENGINE_DIR" rev-parse --verify --quiet "$ref^{commit}" || git -C "$STORE_ENGINE_DIR" rev-parse --verify --quiet "origin/$ref^{commit}" || true)"
    [ -n "$base" ] || die "theme diff: рушій не знає '$ref' (git fetch --tags у $STORE_ENGINE_DIR)"
    log "тема $key: перекриті шаблони проти рушія $ref ($(git -C "$STORE_ENGINE_DIR" rev-parse --short "$base")) → HEAD ($(git -C "$STORE_ENGINE_DIR" rev-parse --short HEAD))"
    while IFS= read -r f; do
        rel="${f#"$tpl/"}"
        case "$rel" in README.md | .gitkeep) continue ;; esac
        total=$((total + 1))
        if [ ! -f "$ENGINE_TPL/$rel" ]; then echo "  $rel: у рушії такого шаблону нема (власний partial паку або видалено з рушія)"; continue; fi
        stat="$(git -C "$STORE_ENGINE_DIR" diff --numstat "$base" HEAD -- "templates/$rel" | awk '{print "+"$1" -"$2}')"
        if [ -z "$stat" ]; then echo "  $rel: без змін у рушії"; continue; fi
        changed=$((changed + 1))
        blocks="$(git -C "$STORE_ENGINE_DIR" diff "$base" HEAD -- "templates/$rel" | { grep -E '^[-+].*\{%-? *block ' || true; } | sed -E 's/.*block +([A-Za-z0-9_]+).*/\1/' | sort -u | tr '\n' ' ')"
        echo "  $rel: ЗМІНЕНО у рушії ($stat рядків)${blocks:+; блоки: $blocks} → git -C $STORE_ENGINE_DIR diff $ref HEAD -- templates/$rel"
    done < <(find "$tpl" -type f | LC_ALL=C sort)
    log "перекрито $total, змінилось у рушії $changed. Класи й авто-оновлення: bin/store theme status $target; 3-way копії: bin/store theme diff $target <шаблон>"
}

FORCE=0
declare -a WRITTEN=()
for a in "$@"; do [ "$a" = --force ] && FORCE=1; done
sub="${1:-}"; shift || true
case "$sub" in
    -h | --help | "") usage; [ -n "$sub" ] && exit 0 || exit 1 ;;
    list) cmd_list ;;
    new) cmd_new "$@" ;;
    diff) cmd_diff "$@" ;;
    status | update | prune) cmd_py "$sub" "$@" ;;
    *) die "theme: підкоманда list|new|diff|status|update|prune (див. bin/store theme --help)" ;;
esac
