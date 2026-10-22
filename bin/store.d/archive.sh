#!/usr/bin/env bash
# SPDX-License-Identifier: Apache-2.0
# Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.
# summary: бекап + зупинка + маркер ARCHIVED + вимкнення cron: archive <key>
# bin/store archive <key>
#   1. bin/store backup <key> з міткою archive і копією в backup_target (без копії поза сервером — відмова,
#      хіба STORE_NO_COPY=1). 2. Перевірка архіву (контрольні суми). 3. На сервері: зупинка стека (томи й образи
#      лишаються), вимкнення cron бекапу, маркер <path>/ARCHIVED (deploy/restore після нього — лише з UNARCHIVE=1).
#   Після — змінити status на archived у stores.yaml (PR у рушій).
set -euo pipefail
# shellcheck source=bin/store.d/_lib.sh
source "$STORE_LIB"

key="${1-}"
case "$key" in -h | --help) sed -n '2,7p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;; esac
store_ctx "$key"
guard_write
[ -n "$S_BACKUP_TARGET" ] || [ "${STORE_NO_COPY:-0}" = 1 ] || die "$key: нема backup_target — архів лише на сервері магазину; свідомо: STORE_NO_COPY=1"

name="$(basename "$(remote backup archive | tail -n1)")"
[ "$DRY_RUN" = 1 ] && [ -z "$name" ] && name="$key-dry-run-archive.tar"
[ -n "$name" ] || die "$key: бекап не повернув імені архіву"
if [ -n "$S_BACKUP_TARGET" ]; then
    "$STORE_ENGINE_DIR/bin/store" backup "$key" --copy-only >/dev/null
fi
remote archive "$S_PATH/backups/$name"
log "$key заархівовано, архів $name. Далі: status: archived у stores.yaml"
