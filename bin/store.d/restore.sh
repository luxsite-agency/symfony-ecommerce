#!/usr/bin/env bash
# SPDX-License-Identifier: Apache-2.0
# Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.
# summary: відновити БД і media з архіву: restore <key> <архів> [--yes]
# bin/store restore <key> <архів> [--yes]
#   <архів> — локальний файл (завантажується на сервер у <path>/backups/) або шлях на сервері (server:/шлях).
#   .tar з bin/store backup → БД + media; .sql.gz (дамп однієї БД без CREATE DATABASE/USE, напр. стара схема) → лише БД.
#   Стек уже має бути розгорнутий (bin/store deploy <key>). Перед відновленням — страховий бекап (pre-restore),
#   стек зупиняється, БД shop перестворюється, media замінюється, далі міграції до версії рушія й health.
#   Без --yes (або STORE_YES=1) питає підтвердження — потрібно ввести ключ магазину.
set -euo pipefail
# shellcheck source=bin/store.d/_lib.sh
source "$STORE_LIB"

key="" src="" yes="${STORE_YES:-0}"
for a in "$@"; do
    case "$a" in
        --yes | -y) yes=1 ;;
        -h | --help) sed -n '2,8p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        -*) die "невідомий параметр $a" ;;
        *) if [ -z "$key" ]; then key="$a"; elif [ -z "$src" ]; then src="$a"; else die "зайвий аргумент $a"; fi ;;
    esac
done
[ -n "$src" ] || die "вкажіть архів: restore <key> <архів>"
store_ctx "$key"
guard_write

if [ "$yes" != 1 ]; then
    [ -t 0 ] || die "без термінала потрібне --yes"
    printf 'Відновлення ПЕРЕЗАПИШЕ БД і media магазину %s на %s:%s.\nВведіть ключ магазину для підтвердження: ' "$key" "$S_HOST" "$S_PATH" >&2
    read -r answer
    [ "$answer" = "$key" ] || die "скасовано"
fi

case "$src" in
    server:*) file="${src#server:}" ;;
    *)
        [ -f "$src" ] || die "нема файлу $src (шлях на сервері — server:/шлях)"
        file="$S_PATH/backups/incoming-$(basename "$src")"
        log "завантаження $(basename "$src") → $(ssh_dest):$file"
        put "$src" "$file"
        ;;
esac
remote restore "$file"
