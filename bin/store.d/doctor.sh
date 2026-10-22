#!/usr/bin/env bash
# SPDX-License-Identifier: Apache-2.0
# Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.
# summary: стан магазину (версія, контейнери, бекап, TLS, черга, диск): doctor <key>|--all
# bin/store doctor <key>     bin/store doctor --all
#   Лише читає. Перевіряє: доступ ssh, маркер ARCHIVED проти status у реєстрі, версію рушія (release на сервері проти
#   engine у реєстрі), стан і health контейнерів, останній звичайний бекап (без страхових pre-*; старший за 36 год або
#   менший за 100K — WARN), cron бекапу, місце на диску,
#   чергу messenger (failed), строк сертифіката сайту (<14 днів — WARN).
#   Код виходу: 0 — усе OK/WARN, 1 — є FAIL. --all — по всіх записах реєстру (handed-over і стара схема — пропуск).
set -euo pipefail
# shellcheck source=bin/store.d/_lib.sh
source "$STORE_LIB"

FAILS=0 WARNS=0
ok() { printf '  [OK]   %s\n' "$*"; }
wrn() { printf '  [WARN] %s\n' "$*"; WARNS=$((WARNS + 1)); }
bad() { printf '  [FAIL] %s\n' "$*"; FAILS=$((FAILS + 1)); }

cert_check() {
    local host="$1" end days
    command -v openssl >/dev/null || { wrn "TLS: нема openssl на цій машині"; return; }
    end="$(echo | timeout 15 openssl s_client -servername "$host" -connect "$host:443" -verify_return_error 2>/dev/null \
        | openssl x509 -noout -enddate 2>/dev/null | sed 's/^notAfter=//')" || true
    if [ -z "$end" ]; then bad "TLS $host: сертифікат не отримано або не валідний"; return; fi
    days=$(( ($(date -d "$end" +%s) - $(date +%s)) / 86400 ))
    if [ "$days" -lt 0 ]; then bad "TLS $host: прострочений ($end)"
    elif [ "$days" -lt 14 ]; then wrn "TLS $host: лишилось $days дн."
    else ok "TLS $host: ще $days дн."; fi
}

doctor_one() {
    store_ctx "$1"
    echo "== $S_KEY ($S_STATUS) $(ssh_dest):$S_PATH"
    if ! is_new_layout; then echo "  [SKIP] стара схема (не …/stores/$S_KEY) — bin/store не обслуговує"; return 0; fi
    if [ "$S_STATUS" = handed-over ]; then echo "  [SKIP] handed-over — доступу нема"; return 0; fi
    # тема паку проти рушія (E14 PR-4), лише коли пак є локально; читає git рушія, сервера не торкається
    local pack="${STORE_PACK:-$S_PACK}" theme_state
    case "$pack" in "" | /*) ;; *) pack="$STORE_ENGINE_DIR/$pack" ;; esac
    if [ -n "$pack" ] && [ -d "$pack" ] && [ -f "$STORE_CMD_DIR/_theme.py" ]; then
        if theme_state="$(python3 "$STORE_CMD_DIR/_theme.py" status "$STORE_ENGINE_DIR" "$pack" --summary 2>&1)"; then ok "${theme_state:-тема: своєї теми в паку нема}"
        else wrn "$theme_state"; fi
    fi
    local out
    if ! out="$(remote doctor 2>&1)"; then bad "сервер недоступний або помилка: $(echo "$out" | tail -n1)"; return 0; fi
    [ "$DRY_RUN" = 1 ] && { echo "  [dry-run]"; return 0; }
    val() { echo "$out" | sed -n "s/^$1=//p" | head -n1; }

    local archived; archived="$(val archived)"
    if [ "$S_STATUS" = archived ]; then
        if [ "$archived" = yes ]; then ok "архів: маркер ARCHIVED є"; else bad "status archived, але маркера ARCHIVED на сервері нема"; fi
    else
        if [ "$archived" = yes ]; then bad "на сервері маркер ARCHIVED, а status $S_STATUS"; fi
    fi

    local tag sha want
    tag="$(val tag)"; sha="$(val release.engine_sha)"
    if [ -z "$tag" ]; then bad "не розгорнуто (нема release.env)"; return 0; fi
    want="$(git -C "$STORE_ENGINE_DIR" rev-parse --verify -q --short=12 "${S_ENGINE:-HEAD}^{commit}" 2>/dev/null || true)"
    if [ -n "$want" ] && [ "$want" != "${sha:-${tag%%-*}}" ]; then
        wrn "рушій: на сервері ${sha:-${tag%%-*}}, у реєстрі ${S_ENGINE} ($want) — потрібен deploy"
    else
        ok "рушій: ${sha:-${tag%%-*}} (тег $tag)"
    fi

    local line svc state health
    while read -r line; do
        [ -n "$line" ] || continue
        read -r svc state health <<<"$line"
        if [ "$archived" = yes ]; then
            [ "$state" = running ] && wrn "$svc працює, хоча магазин в архіві"
            continue
        fi
        if [ "$state" != running ]; then bad "$svc: $state"
        elif [ -n "${health:-}" ] && [ "$health" != healthy ]; then bad "$svc: running, health=$health"
        else ok "$svc: running${health:+ ($health)}"; fi
    done <<<"$(echo "$out" | sed -n 's/^svc=//p')"
    [ "$archived" = yes ] && return 0

    # Лише звичайні бекапи (страхові pre-deploy/pre-restore сервер не показує): старший за 36 год або менший
    # за 100K (порожня БД/media — підозріло для робочого магазину) → WARN
    local age bytes; age="$(val backup.age_h)"; bytes="$(val backup.bytes)"
    if [ -z "$age" ]; then bad "звичайних бекапів на сервері нема (страхові pre-* не рахуються)"
    else
        local bok=1
        if [ "$age" -gt 36 ]; then wrn "останній бекап $(val backup.last): $age год тому (> 36 год)"; bok=0; fi
        if [ -n "$bytes" ] && [ "$bytes" -lt 102400 ]; then wrn "останній бекап $(val backup.last): $(val backup.size) (< 100K — порожній?)"; bok=0; fi
        if [ "$bok" = 1 ]; then ok "бекап: $(val backup.last), $age год тому, $(val backup.size)"; fi
    fi
    if [ "$(val cron)" = yes ]; then ok "cron бекапу є"; else wrn "cron бекапу на сервері нема"; fi
    case "$(val settings_key)" in
        yes) ok "SETTINGS_SECRET_KEY є (секретні налаштування, AI)" ;;
        invalid) wrn "SETTINGS_SECRET_KEY у .env.local некоректний (має бути 32 байти base64, 44 символи одним рядком) — секретні налаштування й AI вимкнені" ;;
        *) wrn "SETTINGS_SECRET_KEY у .env.local нема — секретні налаштування й AI вимкнені (deploy додає сам)" ;;
    esac
    [ -n "$S_BACKUP_TARGET" ] || wrn "backup_target не задано — копій поза сервером нема"

    local disk use; disk="$(val disk.root)"; use="${disk%%%*}"
    if [ -z "$use" ]; then wrn "диск: невідомо"
    elif [ "$use" -ge 95 ]; then bad "диск: $disk"
    elif [ "$use" -ge 85 ]; then wrn "диск: $disk"
    else ok "диск: $disk"; fi

    local failed; failed="$(val queue.failed)"
    if [ -n "$failed" ] && [ "$failed" -gt 0 ]; then wrn "messenger: у failed $failed повідомл. (bin/console messenger:failed:show)"
    elif [ -n "$(val queue.async)" ]; then ok "messenger: async $(val queue.async), failed ${failed:-0}"
    else wrn "messenger: статистику не отримано"; fi

    [ -n "$S_SITE" ] && cert_check "$S_SITE"
    [ -n "$S_ADMIN" ] && [ "$S_ADMIN" != "$S_SITE" ] && cert_check "$S_ADMIN"
    return 0
}

case "${1-}" in
    -h | --help) sed -n '2,7p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    --all) for k in $(reg_keys ''); do doctor_one "$k"; done ;;
    *) doctor_one "${1-}" ;;
esac
echo "== підсумок: FAIL $FAILS, WARN $WARNS"
[ "$FAILS" = 0 ]
