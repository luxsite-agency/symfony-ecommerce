#!/usr/bin/env bash
# Тести bin/store validate і new (агент B): реєстр, пак, шаблон. Лише bash + python3 + PyYAML, без PHP і Docker.
#   bash tests/store/registry-pack-test.sh
set -euo pipefail
ENGINE="$(cd "$(dirname "$0")/../.." && pwd)"
STORE="$ENGINE/bin/store"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
fails=0 n=0

ok() {   # ok <назва> <команда…> — має завершитись з 0
    n=$((n + 1))
    if "${@:2}" >"$TMP/out" 2>&1; then echo "ok  $1"; else echo "FAIL $1"; sed 's/^/     /' "$TMP/out"; fails=$((fails + 1)); fi
}
bad() {  # bad <назва> <очікуваний фрагмент> <команда…> — має впасти й надрукувати фрагмент
    n=$((n + 1))
    if "${@:3}" >"$TMP/out" 2>&1; then
        echo "FAIL $1 (очікувалась помилка)"; fails=$((fails + 1))
    elif ! grep -qF -- "$2" "$TMP/out"; then
        echo "FAIL $1 (нема «$2»)"; sed 's/^/     /' "$TMP/out"; fails=$((fails + 1))
    else echo "ok  $1"; fi
}
registry() { STORES_FILE="$TMP/stores.yaml" "$STORE" validate; }
write_registry() {
    cat >"$TMP/stores.yaml" <<YAML
version: 1
stores:
    - key: shop1
      name: 'Shop 1'
      repo: example-org/store-shop1
      server: { host: srv, user: ${1:-deploy}, path: ${2:-/work/stores/shop1}, proxy: traefik }
      domains: { site: shop1.example.com, admin: admin.shop1.example.com }
      locales: [uk, en]
      engine: v1.0.0
      status: ${3:-active}
      support: true
      backup_target: backup-host:/srv/backups/stores/shop1
${4:-}
YAML
}

# --- реєстр
ok "реєстр рушія валідний" "$STORE" validate
write_registry; ok "мінімальний активний магазин" registry
write_registry deploy /work/stores/shop1 active "      notes: x"
sed -i 's|backup_target: .*|backup_target: /srv/stores/_copies/shop1|; s|proxy: traefik }|proxy: traefik, traefik_network: edge, certresolver: "" }|' "$TMP/stores.yaml"
ok "локальний backup_target і порожній certresolver" registry
write_registry root; bad "root заборонено для active" "root заборонено" registry
write_registry deploy /work/shop-engine; bad "active лише в /work/stores/<key>" "нова схема" registry
write_registry root /work/shop-engine archived; ok "стара схема допустима для archived" registry
write_registry deploy /work/stores/shop1 active "      password: hunter2"
bad "невідоме поле й секрет за назвою" "невідоме поле password" registry
write_registry deploy /work/stores/shop1 active "      notes: 'mysql://shop:s3cret@db/shop'"
bad "секрет у значенні" "URL з логіном і паролем" registry
write_registry deploy /work/stores/shop1 handed-over; bad "handed-over без підтримки" "support: false" registry
write_registry deploy /work/stores/shop1 paused; bad "невідомий статус" "status — один з" registry
cat >>"$TMP/stores.yaml" <<'YAML'
    - key: shop1
      name: 'Dup'
YAML
bad "дубль ключа" "ключ повторюється" registry

# --- шаблон і new
ok "store-template валідний" "$STORE" validate "$ENGINE/store-template"
ok "new: пак із шаблону" env STORE_PACKS_DIR="$TMP/packs" GIT_AUTHOR_NAME=t GIT_AUTHOR_EMAIL=t@t "$STORE" new demo-shop shop.demo.example.com --name='Демо' --locales=uk
P="$TMP/packs/store-demo-shop"
ok "new: тема перейменована" test -f "$P/themes/demo-shop/theme.css"
ok "new: токенів шаблону не лишилось" sh -c "! grep -rqE 'storetemplate|Store Template' '$P' --exclude-dir=.git"
ok "new: мови підставлені" grep -q "app.locales: \['uk'\]" "$P/config/parameters.yaml"
ok "new: git-коміт" git -C "$P" log --oneline -1
ok "новий пак валідний" "$STORE" validate "$P"
bad "new: тека вже є" "уже існує" env STORE_PACKS_DIR="$TMP/packs" "$STORE" new demo-shop shop.demo.example.com
# реєстр-фікстура, а не справжній stores.yaml: тест не залежить від того, які магазини є в реєстрі (і публічної копії без них)
bad "new: ключ із реєстру" "уже є в реєстрі" env STORES_FILE="$ENGINE/tests/store/stores.yaml" STORE_PACKS_DIR="$TMP/packs2" "$STORE" new demo x.example.com
bad "new: поганий ключ" "некоректний ключ" "$STORE" new Bad_Key x.example.com
bad "new: поганий домен" "ім'я хоста" "$STORE" new ok-key 'not a host'
ok "new: DRY_RUN нічого не створює" env DRY_RUN=1 STORE_PACKS_DIR="$TMP/dry" "$STORE" new dry-shop dry.example.com
ok "new: DRY_RUN — теки нема" test ! -e "$TMP/dry/store-dry-shop"

# --- пак: зламані варіанти нового паку
cp -a "$P" "$TMP/b1"; sed -i 's/^        color_scheme:/        colour_scheme:/' "$TMP/b1/config/parameters.yaml"
bad "невідомий ключ теми" "app.theme.colour_scheme: невідомий ключ" "$STORE" validate "$TMP/b1"
cp -a "$P" "$TMP/b2"; rm "$TMP/b2/themes/demo-shop/logo.svg"
bad "нема файлу теми" "нема файлу themes/demo-shop/logo.svg" "$STORE" validate "$TMP/b2"
cp -a "$P" "$TMP/b3"; printf "    admin_host: 'a.example.com'\n" >>"$TMP/b3/config/parameters.yaml"
bad "хости в паку заборонені" "admin_host задає деплой" "$STORE" validate "$TMP/b3"
cp -a "$P" "$TMP/b4"; printf "    app.unknown_param: 1\n" >>"$TMP/b4/config/parameters.yaml"
bad "невідомий параметр рушія" "невідомий параметр рушія app.unknown_param" "$STORE" validate "$TMP/b4"
cp -a "$P" "$TMP/b5"; echo "APP_SECRET=x" >"$TMP/b5/.env.local"
bad ".env у паку" ".env.local: файлам такого типу" "$STORE" validate "$TMP/b5"
cp -a "$P" "$TMP/b6"; echo "-----BEGIN OPENSSH PRIVATE KEY-----" >"$TMP/b6/docs/notes.md"
bad "приватний ключ у тексті" "приватний ключ" "$STORE" validate "$TMP/b6"
cp -a "$P" "$TMP/b7"; sed -i "s/app.locales: \['uk'\]/app.locales: ['uk', 'en']/" "$TMP/b7/config/parameters.yaml"
bad "мови параметрів ≠ store.yaml" "≠ store.yaml locales" "$STORE" validate "$TMP/b7"
cp -a "$P" "$TMP/b8"; head -c $((6 * 1024 * 1024)) /dev/zero >"$TMP/b8/themes/demo-shop/hero.webp"
bad "файл понад ліміт" "hero.webp" "$STORE" validate "$TMP/b8"
bad "STORE_PACK з чужим ключем" "не той пак" env STORE_PACK="$P" "$STORE" validate legacyshop
# лого обов'язкове (правило власника 09.10.2026): без logo.src і з лого поза themes/<key>/ — помилка
cp -a "$P" "$TMP/b9"; sed -i '/^        logo: /d' "$TMP/b9/config/parameters.yaml"
bad "тема без лого" "app.theme.logo.src: обов'язковий" "$STORE" validate "$TMP/b9"
cp -a "$P" "$TMP/b10"; sed -i "s|^        logo: .*|        logo: { src: 'assets/logo.svg' }|" "$TMP/b10/config/parameters.yaml"
bad "лого рушія замість лого паку" "лого паку має лежати в themes/demo-shop/" "$STORE" validate "$TMP/b10"
# ліцензія платформи (app.license): невідомий тип і дата без лапок — помилка; без app.license — лише попередження
cp -a "$P" "$TMP/b11"; sed -i 's/^        type: subscription/        type: rental/' "$TMP/b11/config/parameters.yaml"
bad "ліцензія: невідомий тип" "app.license.type" "$STORE" validate "$TMP/b11"
cp -a "$P" "$TMP/b12"; sed -i "s/^        since: .*/        since: 2026-01-01/" "$TMP/b12/config/parameters.yaml"
bad "ліцензія: дата без лапок" "app.license.since" "$STORE" validate "$TMP/b12"
cp -a "$P" "$TMP/b13"; sed -i '/^    app.license:/,/^        since:/d' "$TMP/b13/config/parameters.yaml"
ok "пак без app.license — лише попередження" "$STORE" validate "$TMP/b13"
ok "пак без app.license — …але голосне" sh -c "'$STORE' validate '$TMP/b13' 2>&1 | grep -q 'нема app.license'"
cp -a "$P" "$TMP/b14"; printf "    app.license: { licensee: 'X', owner: 'Y' }\n" >>"$TMP/b14/config/parameters.yaml"
sed -i '/^    app.license:$/,/^        since:/d' "$TMP/b14/config/parameters.yaml"
bad "ліцензія: невідомий ключ" "app.license.owner: невідомий ключ" "$STORE" validate "$TMP/b14"

cat >"$TMP/stand.yaml" <<YAML
version: 1
stores:
    - { key: demo-shop, name: Demo, repo: o/store-demo-shop, pack: $P, server: { host: local, user: deploy, path: /srv/stores/demo-shop, proxy: traefik }, domains: { site: demo.dev.example.com, admin: demo-admin.dev.example.com }, locales: [uk], engine: v1.0.0, status: active, support: false, backup_target: null }
YAML
ok "стенд з іншими доменами — лише попередження" env STORES_FILE="$TMP/stand.yaml" "$STORE" validate demo-shop
sed -i 's/locales: \[uk\], engine/locales: [uk, en], engine/' "$TMP/stand.yaml"
bad "мови реєстру ≠ пак" "locales ≠ реєстр" env STORES_FILE="$TMP/stand.yaml" "$STORE" validate demo-shop

# --- своя тема в паку (bin/store theme, E14): заготовки extends @engine, маніфест, --copy, diff, заборонена зона
ok "theme list" "$STORE" theme list
ok "theme list: base з блоками контракту" sh -c "'$STORE' theme list | grep -q 'site/base.html.twig .*header_actions'"
ok "theme list: кабінет не перекривається" sh -c "! '$STORE' theme list | grep -q '^  site/account/'"
T="$TMP/packs/store-demo-shop"
ok "theme new: заготовки base+home і маніфест" "$STORE" theme new "$T"
ok "theme new: заготовка успадковує @engine" grep -q "extends '@engine/site/home.html.twig'" "$T/themes/demo-shop/templates/site/home.html.twig"
ok "theme new: блок header_actions у base-заготовці" grep -q "{% block header_actions %}{{ parent() }}{% endblock %}" "$T/themes/demo-shop/templates/site/base.html.twig"
ok "theme new: маніфест theme.yaml" grep -q "^contract: 1" "$T/themes/demo-shop/theme.yaml"
ok "theme new: маніфест знає файли" grep -Eq "site/base.html.twig: [0-9a-f]{64}" "$T/themes/demo-shop/theme.yaml"
ok "theme new: повторно без --force — пропуск, не перезапис" sh -c "'$STORE' theme new '$T' 2>&1 | grep -q 'уже є'"
ok "theme new --copy partial" "$STORE" theme new "$T" --copy site/partials/_product_card.html.twig
ok "theme new: копія містить картку" grep -q 'class="card"' "$T/themes/demo-shop/templates/site/partials/_product_card.html.twig"
ok "theme new: копія позначена" grep -q "ПОВНА КОПІЯ" "$T/themes/demo-shop/templates/site/partials/_product_card.html.twig"
bad "theme new: кабінет перекрити не можна" "заборонена зона" "$STORE" theme new "$T" site/account/login.html.twig
bad "theme new: адмінку перекрити не можна" "не шаблон рушія" "$STORE" theme new "$T" admin/dashboard.html.twig
ok "пак із темою проходить validate" "$STORE" validate "$T"
ok "theme diff (engine з маніфесту = HEAD рушія)" "$STORE" theme diff "$T"
ok "theme diff: перекриті файли перелічено" sh -c "'$STORE' theme diff '$T' 2>&1 | grep -q 'site/home.html.twig: без змін'"
ok "фікстурна тема pack-demo валідна" env STORES_FILE="$ENGINE/tests/store/stores.yaml" "$STORE" validate "$ENGINE/tests/store/pack-demo"
ok "theme diff на фікстурі (engine: HEAD)" "$STORE" theme diff "$ENGINE/tests/store/pack-demo"
cp -a "$T" "$TMP/t1"; mkdir -p "$TMP/t1/themes/demo-shop/templates/site/account"; echo x >"$TMP/t1/themes/demo-shop/templates/site/account/login.html.twig"
bad "тема: заборонена зона (кабінет)" "заборонена зона" "$STORE" validate "$TMP/t1"
cp -a "$T" "$TMP/t1s"; mkdir -p "$TMP/t1s/themes/demo-shop/_acc"; echo x >"$TMP/t1s/themes/demo-shop/_acc/index.html.twig"
ln -s ../_acc "$TMP/t1s/themes/demo-shop/templates/site/account"; ln -s home.html.twig "$TMP/t1s/themes/demo-shop/templates/site/link.html.twig"
bad "тема: symlink-тека (обхід забороненої зони)" "templates/site/account: символьне посилання" "$STORE" validate "$TMP/t1s"
bad "тема: symlink-файл" "templates/site/link.html.twig: символьне посилання" "$STORE" validate "$TMP/t1s"
cp -a "$T" "$TMP/t2"; echo x >"$TMP/t2/themes/demo-shop/templates/site/x.php"
bad "тема: лише *.twig" "лише *.twig" "$STORE" validate "$TMP/t2"
cp -a "$T" "$TMP/t3"; mkdir -p "$TMP/t3/themes/demo-shop/templates/admin"; echo x >"$TMP/t3/themes/demo-shop/templates/admin/dashboard.html.twig"
bad "тема: поза site/" "перекривати можна лише site/" "$STORE" validate "$TMP/t3"
cp -a "$T" "$TMP/t4"; rm "$TMP/t4/themes/demo-shop/theme.yaml"
bad "тема без маніфесту" "нема маніфесту теми" "$STORE" validate "$TMP/t4"
cp -a "$T" "$TMP/t5"; printf "{%% extends 'site/home.html.twig' %%}" >"$TMP/t5/themes/demo-shop/templates/site/home.html.twig"
bad "тема: extends самого себе без @engine" "посилання на самого себе" "$STORE" validate "$TMP/t5"
cp -a "$T" "$TMP/t6"; sed -i 's/^contract: 1/contract: 99/' "$TMP/t6/themes/demo-shop/theme.yaml"
bad "тема: контракт новіший за рушій" "новіший за контракт рушія" "$STORE" validate "$TMP/t6"
cp -a "$T" "$TMP/t7"; printf "    app.store_templates_dir: '/x'\n" >>"$TMP/t7/config/parameters.yaml"
bad "тема: службовий параметр рушія в паку" "службовий параметр рушія" "$STORE" validate "$TMP/t7"
cp -a "$T" "$TMP/t8"; printf '<html><body>%s</body></html>' "{% block body %}{% endblock %}" >"$TMP/t8/themes/demo-shop/templates/site/base.html.twig"
ok "тема: повна копія base без чутливих зон — лише попередження" "$STORE" validate "$TMP/t8"
ok "тема: …але голосне" sh -c "'$STORE' validate '$TMP/t8' 2>&1 | grep -q 'analytics_head()'"

# --- store.yaml addons (docs/addons.md): необов'язкові пакети рушія з config/addons/addons.yaml
cp -a "$T" "$TMP/a1"; echo 'addons: [gmb-reviews]' >>"$TMP/a1/store.yaml"; printf "    app.gbp_reviews.token: '0123456789abcdef0123456789abcdef'\n" >>"$TMP/a1/config/parameters.yaml"
if [ -d "$ENGINE/packages/symfony/gmb-reviews-bundle" ]; then
    ok "addons: gmb-reviews з токеном" "$STORE" validate "$TMP/a1"
else # публічна копія рушія — без приватного пакета
    bad "addons: gmb-reviews без пакета в рушії" "недоступний у цій копії рушія" "$STORE" validate "$TMP/a1"
fi
cp -a "$T" "$TMP/a2"; echo 'addons: [nope]' >>"$TMP/a2/store.yaml"
bad "addons: невідомий" "невідомий аддон nope" "$STORE" validate "$TMP/a2"
cp -a "$T" "$TMP/a3"; echo 'addons: [gmb-reviews]' >>"$TMP/a3/store.yaml"
[ -d "$ENGINE/packages/symfony/gmb-reviews-bundle" ] && ok "addons: без токена — попередження" sh -c "'$STORE' validate '$TMP/a3' 2>&1 | grep -q 'нічого не покаже'"
cp -a "$T" "$TMP/a4"; printf "    app.gbp_reviews.token: '0123456789abcdef0123456789abcdef'\n" >>"$TMP/a4/config/parameters.yaml"
ok "токен без addons — попередження" sh -c "'$STORE' validate '$TMP/a4' 2>&1 | grep -q 'пакет не встановиться'"
cp -a "$T" "$TMP/a5"; echo 'addons: gmb-reviews' >>"$TMP/a5/store.yaml"
bad "addons: не список" "addons — список" "$STORE" validate "$TMP/a5"

# --- theme status / update / prune / diff <файл> (E14 PR-4): окремий клон рушія, щоб керувати «базою на момент копії» і «базою зараз»
E2="$TMP/engine2"
git clone -q "$ENGINE" "$E2" 2>/dev/null
cp -a "$ENGINE/bin/." "$E2/bin/" # bin/store з робочої копії (незакомічені правки теж тестуються)
git -C "$E2" add -A && git -C "$E2" -c user.name=t -c user.email=t@t commit -q --allow-empty -m "E0" # HEAD, з якого робиться тема
printf '{# tmp #}\n<p>tmp</p>\n' >"$E2/templates/site/tmp.html.twig"
git -C "$E2" add -A && git -C "$E2" -c user.name=t -c user.email=t@t commit -q -m "E0: tmp"
S2="$E2/bin/store"
TP="$TMP/packs/store-theme"; cp -a "$P" "$TP"; rm -rf "$TP/themes/demo-shop/templates" "$TP/themes/demo-shop/theme.yaml"
ok "status: пак без теми — дешевий шлях" "$S2" theme status "$TP"
ok "status: пак без теми каже про це" sh -c "'$S2' theme status '$TP' | grep -q 'своєї теми нема'"
ok "theme new --copy base, home, картка, tmp" "$S2" theme new "$TP" --copy site/base.html.twig site/home.html.twig site/partials/_product_card.html.twig site/tmp.html.twig
ok "theme new: заготовка cart (extends)" "$S2" theme new "$TP" site/cart/index.html.twig
echo '{# own #}' >"$TP/themes/demo-shop/templates/site/partials/_own.html.twig"
echo '{# локальна правка #}' >>"$TP/themes/demo-shop/templates/site/home.html.twig"
ok "status: одразу після копії" "$S2" theme status "$TP"
st() { "$S2" theme status "$TP" 2>&1 | grep -F -- "$1" | grep -q " $2 "; }
ok "status: копія без змін і база без змін — prunable" st site/partials/_product_card.html.twig prunable
ok "status: власний partial — own" st site/partials/_own.html.twig own
ok "status: заготовка — extends" st site/cart/index.html.twig extends
ok "status: копія змінена, база ні — unchanged" st site/home.html.twig unchanged
# рушій рухається: base змінено (копія не чіпалась → auto), home змінено (копія теж → conflict), tmp прибрано (removed), новий файл (added)
echo '{# engine change #}' >>"$E2/templates/site/base.html.twig"
echo '{# engine change #}' >>"$E2/templates/site/home.html.twig"
git -C "$E2" rm -q templates/site/tmp.html.twig
printf '{# new #}\n' >"$E2/templates/site/new.html.twig"
git -C "$E2" add -A && git -C "$E2" -c user.name=t -c user.email=t@t commit -q -m "E1"
ok "status після зміни рушія" "$S2" theme status "$TP"
ok "status: база змінилась, копія ні — auto" st site/base.html.twig auto
ok "status: змінились обидві — conflict" st site/home.html.twig conflict
ok "status: прибрано з бази — removed" st site/tmp.html.twig removed
ok "status: новий у базі — added" st site/new.html.twig added
ok "status: картка далі prunable" st site/partials/_product_card.html.twig prunable
ok "status --json" sh -c "'$S2' theme status '$TP' --json | python3 -c 'import json,sys; d=json.load(sys.stdin); assert d[\"key\"]==\"demo-shop\" and any(f[\"class\"]==\"conflict\" for f in d[\"files\"])'"
ok "status --summary: відстала" sh -c "python3 '$E2/bin/store.d/_theme.py' status '$E2' '$TP' --summary; [ \$? = 2 ]"
ok "status --summary: текст" sh -c "python3 '$E2/bin/store.d/_theme.py' status '$E2' '$TP' --summary | grep -q 'відстала від рушія на 3 файлів, 1 конфліктів'"
ok "diff <файл>: 3-way з маркерами, нічого не пише" sh -c "'$S2' theme diff '$TP' site/home.html.twig | grep -q '^<<<<<<< копія паку' && grep -q 'локальна правка' '$TP/themes/demo-shop/templates/site/home.html.twig' && ! grep -q 'engine change' '$TP/themes/demo-shop/templates/site/home.html.twig'"
ok "diff <файл> для auto — не конфлікт" sh -c "'$S2' theme diff '$TP' site/base.html.twig 2>&1 | grep -q 'auto'"
bad "diff <файл> при конфлікті — код ≠ 0 (редирект у файл з && не піде далі)" "конфліктів: 1" "$S2" theme diff "$TP" site/home.html.twig
echo '{# engine change 2 #}' >>"$E2/templates/site/cart/index.html.twig"
git -C "$E2" add -A && git -C "$E2" -c user.name=t -c user.email=t@t commit -q -m "E1b: cart"
ok "status: заготовка зі зміненою базою — нагадування про блоки" sh -c "'$S2' theme status '$TP' | grep 'site/cart/index.html.twig' | grep -q 'перевірте блоки'"
ok "update --dry-run нічого не пише" sh -c "'$S2' theme update '$TP' --dry-run | grep -q 'оновлено з бази: site/base.html.twig' && ! grep -q 'engine change' '$TP/themes/demo-shop/templates/site/base.html.twig'"
ok "update: авто застосовано, конфлікт лишився" "$S2" theme update "$TP"
ok "update: заготовку зі зміненою базою не «підтверджено» мовчки" sh -c "'$S2' theme status '$TP' | grep 'site/cart/index.html.twig' | grep -q 'перевірте блоки'"
ok "update --ack-extends знімає нагадування" sh -c "'$S2' theme update '$TP' --ack-extends | grep -q 'перевірити блоки 0' && ! '$S2' theme status '$TP' | grep 'site/cart/index.html.twig' | grep -q 'перевірте блоки'"
ok "update: base = нова база із заголовком" sh -c "grep -q 'engine change' '$TP/themes/demo-shop/templates/site/base.html.twig' && head -n1 '$TP/themes/demo-shop/templates/site/base.html.twig' | grep -q 'ПОВНА КОПІЯ'"
ok "update: home не чіпано" sh -c "! grep -q 'engine change' '$TP/themes/demo-shop/templates/site/home.html.twig'"
ok "update: після — base ідентична новій базі (prunable)" st site/base.html.twig prunable
ok "update: після — home далі conflict" st site/home.html.twig conflict
ok "update: engine у маніфесті не піднято через конфлікт" sh -c "grep -q \"^engine: $(git -C "$E2" rev-parse --short=12 HEAD~2)\" '$TP/themes/demo-shop/theme.yaml'"
ok "update: хеш base у маніфесті новий" sh -c "grep -q \"site/base.html.twig: $(sha256sum "$E2/templates/site/base.html.twig" | cut -c1-64)\" '$TP/themes/demo-shop/theme.yaml'"
ok "пак після update валідний" "$S2" validate "$TP"
# конфлікт розвʼязано руками → update піднімає engine
cp "$E2/templates/site/home.html.twig" "$TP/themes/demo-shop/templates/site/home.html.twig"
ok "update після ручного злиття" sh -c "'$S2' theme update '$TP' | grep -q 'конфліктів 0'"
ok "update: engine піднято до HEAD" sh -c "grep -q \"^engine: $(git -C "$E2" rev-parse --short=12 HEAD)\" '$TP/themes/demo-shop/theme.yaml'"
ok "prune --dry-run" sh -c "'$S2' theme prune '$TP' --dry-run | grep -q 'site/partials/_product_card.html.twig' && test -f '$TP/themes/demo-shop/templates/site/partials/_product_card.html.twig'"
ok "prune" "$S2" theme prune "$TP"
ok "prune: ідентичні базі прибрано (картка, home після злиття)" sh -c "! test -e '$TP/themes/demo-shop/templates/site/partials/_product_card.html.twig' && ! test -e '$TP/themes/demo-shop/templates/site/home.html.twig'"
ok "prune: маніфест без прибраних" sh -c "! grep -q '_product_card' '$TP/themes/demo-shop/theme.yaml'"
ok "prune: власний partial і заготовка лишились" sh -c "test -f '$TP/themes/demo-shop/templates/site/partials/_own.html.twig' && test -f '$TP/themes/demo-shop/templates/site/cart/index.html.twig'"
ok "пак після prune валідний" "$S2" validate "$TP"
# маніфест без поля files / без engine — попередження, не збій
cp -a "$TP" "$TMP/tm1"; sed -i '/^files:/,$d' "$TMP/tm1/themes/demo-shop/theme.yaml"
ok "status: маніфест без files — працює з попередженням" sh -c "'$S2' theme status '$TMP/tm1' | grep -q 'нема поля files'"
cp -a "$TP" "$TMP/tm2"; sed -i '/^engine:/d' "$TMP/tm2/themes/demo-shop/theme.yaml"
ok "status: маніфест без engine — працює" "$S2" theme status "$TMP/tm2"
cp -a "$TP" "$TMP/tm3"; rm "$TMP/tm3/themes/demo-shop/theme.yaml"
ok "status: без маніфесту — працює з попередженням" sh -c "'$S2' theme status '$TMP/tm3' | grep -q 'маніфесту нема'"
cp -a "$TP" "$TMP/tm4"; printf 'files: [\n' >>"$TMP/tm4/themes/demo-shop/theme.yaml"
ok "status: зіпсований theme.yaml — попередження, не трейсбек" sh -c "'$S2' theme status '$TMP/tm4' 2>&1 | grep -q 'зіпсований YAML' && ! '$S2' theme status '$TMP/tm4' 2>&1 | grep -q Traceback"
cp -a "$TP" "$TMP/tm5"; printf 'key: [\n' >>"$TMP/tm5/store.yaml"
bad "status: зіпсований store.yaml — помилка з назвою файлу" "store.yaml: зіпсований YAML" "$S2" theme status "$TMP/tm5"
ok "status: кирилиця в pipe з кодуванням локалі C" sh -c "LC_ALL=C LANG=C PYTHONIOENCODING= '$S2' theme status '$TP' | grep -q 'тема demo-shop'"

echo "--- $((n - fails))/$n"
[ "$fails" = 0 ]
