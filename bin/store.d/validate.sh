#!/usr/bin/env bash
# SPDX-License-Identifier: Apache-2.0
# Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.
# summary: перевірити реєстр і паки: validate [--all | <key> | <тека-паку>]
#
#   bin/store validate              реєстр stores.yaml (схема, без секретів)
#   bin/store validate <key>        реєстр + пак магазину (STORE_PACK=<тека> або поле pack реєстру)
#   bin/store validate <тека>       пак у теці (store-template, новий пак до запису в реєстр)
#   bin/store validate --all        реєстр + усі паки з локальною копією (CI рушія: оновлення рушія не ламає паки)
#
# Пак: обов'язкові файли, store.yaml, параметри — лише відомі рушію, тема — ключі за config/store/theme-schema.json
# і файли в themes/<key>/, CSV каталогу, жодних секретів і файлів .env/ключів/дампів, ліміти розміру. Детально: docs/store-pack.md.
set -euo pipefail
# shellcheck source=bin/store.d/_lib.sh
source "$STORE_LIB"

VALIDATOR="$STORE_CMD_DIR/_validate.py"
python3 -c 'import yaml' 2>/dev/null || die "потрібні python3 і PyYAML (apt install python3-yaml)"

# Тека паку: STORE_PACK (deploy --pack) перекриває поле pack реєстру
pack_of() {
    local dir
    if [ -n "${STORE_PACK:-}" ]; then printf '%s' "$STORE_PACK"; return; fi
    dir="$(reg_get "$1" pack)" || exit 1
    case "$dir" in "" | /*) ;; *) dir="$STORE_ENGINE_DIR/$dir" ;; esac  # відносний — від теки рушія
    [ -n "$dir" ] || die "у реєстрі для '$1' нема локального pack — клонуйте $(reg_get "$1" repo) і вкажіть теку: bin/store validate <тека>"
    printf '%s' "$dir"
}

target="${1:-}"
case "$target" in
    -h | --help) sed -n '2,12p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    "") exec python3 "$VALIDATOR" registry "$STORES_FILE" ;;
    --all)
        rc=0
        python3 "$VALIDATOR" registry "$STORES_FILE" || rc=1
        for key in $(reg_keys ""); do  # "" — усі статуси
            dir="$(reg_get "$key" pack)"
            case "$dir" in "" | /*) ;; *) dir="$STORE_ENGINE_DIR/$dir" ;; esac
            if [ -z "$dir" ] || [ ! -d "$dir" ]; then
                warn "$key: локальної копії паку нема (${dir:-pack не задано}) — пропущено"
                continue
            fi
            python3 "$VALIDATOR" pack "$STORE_ENGINE_DIR" "$dir" "$STORES_FILE" "$key" || rc=1
        done
        exit "$rc"
        ;;
    *)
        if [ -d "$target" ]; then
            exec python3 "$VALIDATOR" pack "$STORE_ENGINE_DIR" "$target" "$STORES_FILE"
        fi
        need_key "$target"
        dir="$(pack_of "$target")"
        exec python3 "$VALIDATOR" pack "$STORE_ENGINE_DIR" "$dir" "$STORES_FILE" "$target"
        ;;
esac
