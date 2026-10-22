#!/usr/bin/env python3
# SPDX-License-Identifier: Apache-2.0
# Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.
"""Перевірка реєстру магазинів і паків (bin/store validate). Лише python3 + PyYAML, як і _lib.sh: працює без PHP і Docker.

    _validate.py registry <stores.yaml>
    _validate.py pack <engine-dir> <pack-dir> [<stores.yaml> [<очікуваний key>]]

Ключі теми звіряються зі схемою рушія config/store/theme-schema.json (генерує `bin/console store:validate --schema`,
синхронність із SiteTheme::DEFAULTS тримає PHPUnit). Помилки → код 1, попередження лише друкуються.
"""
import csv
import datetime
import json
import os
import re
import sys

import yaml

KEY_RE = re.compile(r"^[a-z0-9][a-z0-9-]{0,30}$")
DATE_RE = re.compile(r"^\d{4}-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$")
HOST_RE = re.compile(r"^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$")
LOCALE_RE = re.compile(r"^[a-z]{2}$")
STATUSES = ("active", "archived", "handed-over")
PROXIES = ("traefik", "standalone")
STORE_FIELDS = {"key", "name", "repo", "pack", "server", "domains", "locales", "engine", "status", "support", "backup_target", "notes"}
REQUIRED = STORE_FIELDS - {"pack", "notes"}

# Секрети: імена ключів і вигляд значень. Пак і реєстр ідуть у git — секретам там не місце (лише на сервері магазину, 600).
SECRET_KEY_RE = re.compile(r"(passw|secret|token|api[_-]?key|private[_-]?key|credential|dsn|salt)", re.I)
SECRET_VALUE_RES = [
    (re.compile(r"-----BEGIN [A-Z ]*PRIVATE KEY-----"), "приватний ключ"),
    (re.compile(r"\b[a-z][a-z0-9+.-]*://[^\s/:@]+:[^\s/@]+@"), "URL з логіном і паролем"),
    (re.compile(r"\b(sk|rk)_(live|test)_[0-9A-Za-z]{16,}"), "ключ платіжного API"),
    (re.compile(r"\bgh[pousr]_[0-9A-Za-z]{30,}"), "токен GitHub"),
    (re.compile(r"\bAIza[0-9A-Za-z_-]{30,}"), "ключ Google API"),
    (re.compile(r"\bsk-[0-9A-Za-z_-]{20,}"), "ключ AI API"),
    (re.compile(r"\b\d{8,10}:[0-9A-Za-z_-]{35}\b"), "токен Telegram-бота"),
]
FORBIDDEN_FILES = re.compile(r"(^|/)(\.env(\..*)?|\.secrets|id_(rsa|ed25519|ecdsa)[^/]*|[^/]+\.(pem|key|p12|pfx|sql|sql\.gz|dump))$")
TEXT_EXT = {".yaml", ".yml", ".md", ".csv", ".json", ".txt", ".css", ".html", ".twig", ".svg", ".php", ".js", ".xml", ".ini", ".sh"}

PACK_MAX_BYTES = 50 * 1024 * 1024
FILE_MAX_BYTES = 5 * 1024 * 1024
PACK_FORMAT = 1
PACK_FIELDS = {"format", "key", "name", "locales", "domains", "engine"}
# Необов'язкові поля store.yaml: addons — ключі config/addons/addons.yaml рушія (docs/addons.md)
PACK_OPTIONAL_FIELDS = {"addons"}
# Параметри, які пак НЕ задає: хости кладе деплой з domains реєстру (той самий пак піднімається на технічному домені/стенді)
DEPLOY_PARAMS = {"admin_host", "site_host"}
# …і службові параметри рушія (тека шаблонів паку в образі — її кладе деплой)
ENGINE_ONLY_PARAMS = {"app.store_templates_dir"}

# Шаблони паку (епік E14, docs/themes.md): themes/<key>/templates/** → templates/store/ образу, перекривають однойменні
# шаблони рушія; рушій доступний як @engine. Лише site/** і лише *.twig; заборонена зона — кабінет, schema.org, sitemap
# (та сама перевірка в App\Site\Theme\Templates\StoreTemplates на компіляції контейнера). Контракт — StoreTemplates::CONTRACT.
TEMPLATE_CONTRACT = 1
TEMPLATE_ALLOWED = ("site/",)
TEMPLATE_FORBIDDEN = ("site/account/", "site/partials/_schema", "site/sitemap", "site/page/license")
THEME_MANIFEST_FIELDS = {"format", "key", "from", "engine", "contract", "created", "files"}
# Чутливі зони базового шаблону: повна копія site/base.html.twig без них тихо втрачає аналітику, SEO чи правила власника
SENSITIVE_BASE = [
    ("analytics_head()", "аналітика в <head> (GTM/GA4/Pixel, consent)"),
    ("analytics_body_start()", "аналітика на початку <body> (noscript GTM)"),
    ("analytics_body_end()", "аналітика в кінці <body> (події dataLayer)"),
    ("schema.website()", "JSON-LD WebSite"),
    ('rel="canonical"', "canonical"),
    ('hreflang=', "hreflang альтернатив"),
    ('name="referrer"', "Referrer-Policy кабінету (токени листів не йдуть у referrer)"),
    ("{% block head %}", "блок head (теги сторінок: скрипт НП на checkout)"),
    ("site_theme_style()", "CSS-змінні теми й @font-face"),
    ("brand-logo", "лого в шапці (правило власника 09.10.2026)"),
    ('class="lang"', "перемикач мов текстом UA/RU/EN (правило власника 09.10.2026)"),
]
REQUIRED_PATHS = ["store.yaml", "config/parameters.yaml", "docs/brief.md", "docs/CLAUDE.md", "catalog", "content", "seo", "studio"]


class Report:
    def __init__(self, label):
        self.label, self.errors, self.warnings = label, [], []

    def error(self, msg):
        self.errors.append(msg)

    def warn(self, msg):
        self.warnings.append(msg)

    def done(self):
        for w in self.warnings:
            print(f"!! {self.label}: {w}", file=sys.stderr)
        for e in self.errors:
            print(f"ПОМИЛКА {self.label}: {e}", file=sys.stderr)
        if not self.errors:
            print(f">> {self.label}: OK" + (f" (попереджень: {len(self.warnings)})" if self.warnings else ""), file=sys.stderr)
        return not self.errors


class AnyTagLoader(yaml.SafeLoader):
    """Конфіги Symfony мають теги (!tagged_iterator, !php/const…) — для читання імен параметрів вони не важливі."""


AnyTagLoader.add_multi_constructor("!", lambda loader, suffix, node: None)


def load_yaml(path, rep, loader=yaml.SafeLoader):
    try:
        with open(path, encoding="utf-8") as fh:
            return yaml.load(fh, Loader=loader)  # noqa: S506 — SafeLoader або його нащадок
    except FileNotFoundError:
        rep.error(f"нема файлу {path}")
    except yaml.YAMLError as e:
        rep.error(f"{path}: некоректний YAML: {e}")
    return None


def scan_secrets(node, where, rep, path=""):
    if isinstance(node, dict):
        for k, v in node.items():
            p = f"{path}.{k}" if path else str(k)
            if SECRET_KEY_RE.search(str(k)) and v not in (None, "", [], {}):
                rep.error(f"{where}: {p} схоже на секрет за назвою ключа — секрети лише на сервері магазину")
            scan_secrets(v, where, rep, p)
    elif isinstance(node, list):
        for i, v in enumerate(node):
            scan_secrets(v, where, rep, f"{path}[{i}]")
    elif isinstance(node, str):
        scan_text(node, f"{where}: {path}", rep)


def scan_text(text, where, rep):
    for rx, what in SECRET_VALUE_RES:
        if rx.search(text):
            rep.error(f"{where}: схоже на секрет ({what})")


# ---------------------------------------------------------------- реєстр

def validate_registry(path):
    rep = Report(f"реєстр {path}")
    data = load_yaml(path, rep)
    if data is None:
        return rep.done(), {}
    if not isinstance(data, dict) or data.get("version") != 1 or not isinstance(data.get("stores"), list):
        rep.error("очікується `version: 1` і `stores:` — список записів")
        return rep.done(), {}
    unknown_top = set(data) - {"version", "stores"}
    if unknown_top:
        rep.error(f"невідомі ключі верхнього рівня: {', '.join(sorted(unknown_top))}")
    scan_secrets(data, "реєстр", rep)

    stores, seen_domains = {}, {}
    for i, s in enumerate(data["stores"]):
        where = f"stores[{i}]"
        if not isinstance(s, dict):
            rep.error(f"{where}: очікується мапа")
            continue
        key = str(s.get("key", ""))
        where = f"{where} ({key or '?'})"
        if not KEY_RE.match(key):
            rep.error(f"{where}: key має відповідати {KEY_RE.pattern}")
        elif key in stores:
            rep.error(f"{where}: ключ повторюється")
        stores[key] = s
        for f in sorted(REQUIRED - set(s)):
            rep.error(f"{where}: нема поля {f}")
        for f in sorted(set(s) - STORE_FIELDS):
            rep.error(f"{where}: невідоме поле {f}")
        if not isinstance(s.get("name"), str) or not s.get("name", "").strip():
            rep.error(f"{where}: name — непорожній рядок")
        if not re.match(r"^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$", str(s.get("repo", ""))):
            rep.error(f"{where}: repo — owner/name репозиторію паку")
        if "pack" in s and not (isinstance(s["pack"], str) and s["pack"].strip()):
            rep.error(f"{where}: pack — шлях до локальної копії паку (відносний — від теки рушія)")
        status = s.get("status")
        if status not in STATUSES:
            rep.error(f"{where}: status — один з {', '.join(STATUSES)}")
        if not isinstance(s.get("support"), bool):
            rep.error(f"{where}: support — true або false")
        elif status == "handed-over" and s["support"]:
            rep.error(f"{where}: handed-over означає без підтримки — support: false")
        local = isinstance(s.get("server"), dict) and s["server"].get("host") == "local"
        engine = str(s.get("engine", ""))
        if engine == "HEAD" and not local:
            rep.error(f"{where}: engine HEAD — лише для стенда (server.host: local), магазину потрібен тег або sha")
        elif engine != "HEAD" and not re.match(r"^(v\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?|[0-9a-f]{7,40})$", engine):
            rep.error(f"{where}: engine — тег vX.Y.Z або sha коміту")
        bt = s.get("backup_target")
        if bt is not None and not re.match(r"^([A-Za-z0-9_.@-]+:)?/\S+$", str(bt)):
            rep.error(f"{where}: backup_target — /шлях (ця машина), host:/шлях або null")
        if bt is None and s.get("support"):
            rep.warn(f"{where}: магазин на підтримці без backup_target — копії бекапів у нас не буде")

        server = s.get("server")
        if not isinstance(server, dict):
            rep.error(f"{where}: server — мапа host/user/path/proxy")
        else:
            for f in sorted({"host", "user", "path", "proxy"} - set(server)):
                rep.error(f"{where}: server.{f} обов'язкове")
            if server.get("host") != "local" and not str(server.get("user") or "").strip():
                rep.error(f"{where}: server.user порожній — дозволено лише для host: local (поточний користувач)")
            for f in sorted(set(server) - {"host", "user", "path", "proxy", "traefik_network", "certresolver"}):
                rep.error(f"{where}: server.{f} — невідоме поле")
            for f in ("traefik_network", "certresolver"):
                if server.get(f) in (None, ""):
                    continue  # не задано = типове (edge; TLS без резолвера)
                if not re.match(r"^[A-Za-z0-9_.-]+$", str(server[f])):
                    rep.error(f"{where}: server.{f} — ім'я (мережа docker / резолвер traefik)")
                if server.get("proxy") != "traefik":
                    rep.warn(f"{where}: server.{f} має сенс лише для proxy: traefik")
            if server.get("proxy") not in PROXIES:
                rep.error(f"{where}: server.proxy — traefik або standalone")
            spath = str(server.get("path", ""))
            if not spath.startswith("/"):
                rep.error(f"{where}: server.path — абсолютний шлях")
            new_layout = spath.rstrip("/").endswith(f"/stores/{key}")
            if status == "active":
                if not new_layout:
                    rep.error(f"{where}: активний магазин живе в /work/stores/{key} (нова схема), а не {spath}")
                if server.get("user") == "root":
                    rep.error(f"{where}: server.user root заборонено — користувач deploy у групі docker")
            elif not new_layout:
                rep.warn(f"{where}: стара схема ({spath}) — bin/store deploy/backup/restore/archive для нього відмовлять")

        domains = s.get("domains")
        if not isinstance(domains, dict) or set(domains) != {"site", "admin"}:
            rep.error(f"{where}: domains — рівно site і admin")
        else:
            for k, d in domains.items():
                if not HOST_RE.match(str(d)):
                    rep.error(f"{where}: domains.{k} '{d}' — не ім'я хоста")
                elif d in seen_domains and status != "archived" and seen_domains[d][1] != "archived":
                    rep.error(f"{where}: домен {d} уже в {seen_domains[d][0]}")
                seen_domains.setdefault(d, (key, status))
            if domains.get("site") == domains.get("admin"):
                rep.error(f"{where}: site і admin мають бути різними хостами (адмінка прив'язана до admin_host)")
        locales = s.get("locales")
        if not isinstance(locales, list) or not locales or not all(LOCALE_RE.match(str(l)) for l in locales) or len(set(locales)) != len(locales):
            rep.error(f"{where}: locales — непорожній список кодів мов без повторів, перша — за замовчуванням")
    return rep.done(), stores


# ---------------------------------------------------------------- пак

def engine_parameters(engine):
    rep = Report("рушій")
    data = load_yaml(os.path.join(engine, "config/services.yaml"), rep, AnyTagLoader) or {}
    return set((data.get("parameters") or {}).keys())


def engine_addons(engine):
    path = os.path.join(engine, "config/addons/addons.yaml")
    data = load_yaml(path, Report("рушій")) if os.path.exists(path) else None
    return data if isinstance(data, dict) else {}


def check_addons(meta, params, engine, rep):
    """store.yaml addons (docs/addons.md): відомий ключ, пакет є в цій копії рушія; параметр аддона і аддон — разом."""
    addons = meta.get("addons", []) if isinstance(meta, dict) else []
    if not isinstance(addons, list) or not all(isinstance(a, str) for a in addons) or len(set(addons)) != len(addons):
        rep.error("store.yaml: addons — список ключів без повторів (config/addons/addons.yaml рушія)")
        return
    known = engine_addons(engine)
    for name in addons:
        spec = known.get(name)
        if not isinstance(spec, dict):
            rep.error(f"store.yaml: невідомий аддон {name} (відомі: {', '.join(sorted(known)) or 'нема'})")
        elif not os.path.isdir(os.path.join(engine, str(spec.get("path", "")))):
            rep.error(f"store.yaml: аддон {name} недоступний у цій копії рушія (нема {spec.get('path')}) — це окремий сервіс LuxSite")
        elif spec.get("parameter") and not params.get(spec["parameter"]):
            rep.warn(f"store.yaml: аддон {name} без {spec['parameter']} у config/parameters.yaml — нічого не покаже")
    for name, spec in sorted(known.items()):
        if isinstance(spec, dict) and spec.get("parameter") and params.get(spec["parameter"]) and name not in addons:
            rep.warn(f"config/parameters.yaml: {spec['parameter']} задано, але в store.yaml нема addons: [{name}] — пакет не встановиться, блок не покажеться")


def check_theme(theme, schema, key, pack, rep):
    def walk(node, sch, path):
        if not isinstance(node, dict):
            rep.error(f"app.theme{('.' + path) if path else ''}: очікується мапа ключів")
            return
        for k, v in node.items():
            p = f"{path}.{k}" if path else str(k)
            if k not in sch:
                rep.error(f"app.theme.{p}: невідомий ключ (дозволено: {', '.join(sch)})")
            elif isinstance(sch[k], dict) and v is not None:
                walk(v, sch[k], p)

    walk(theme, schema, "")
    if isinstance(theme, dict) and theme.get("key") != key:
        rep.error(f"app.theme.key має дорівнювати ключу магазину '{key}' (CSS body.theme-<key>, файли themes/<key>/)")
    # лого обов'язкове (правило власника 09.10.2026: у шапці кожного сайту — знак); SiteTheme рушія теж падає без logo.src
    logo = theme.get("logo") if isinstance(theme, dict) else None
    src = logo.get("src") if isinstance(logo, dict) else None
    if not (isinstance(src, str) and src.strip()):
        rep.error("app.theme.logo.src: обов'язковий — лого в шапці на кожному сайті (файл у themes/%s/, напр. logo.svg)" % key)
    elif not src.startswith(f"themes/{key}/"):
        rep.error(f"app.theme.logo.src: {src} — лого паку має лежати в themes/{key}/ (assets/logo.svg — нейтральний знак рушія лише для dev)")

    def assets(node, path=""):
        if isinstance(node, dict):
            for k, v in node.items():
                yield from assets(v, f"{path}.{k}" if path else str(k))
        elif isinstance(node, list):
            for i, v in enumerate(node):
                yield from assets(v, f"{path}[{i}]")
        elif isinstance(node, str) and re.match(r"^themes/\S+\.[A-Za-z0-9]+$", node):
            yield path, node

    for p, f in assets(theme):
        if not f.startswith(f"themes/{key}/"):
            rep.error(f"app.theme.{p}: файл {f} поза themes/{key}/")
        elif not os.path.isfile(os.path.join(pack, f)):
            rep.error(f"app.theme.{p}: нема файлу {f} у паку")


def check_templates(engine, pack, key, rep):
    """themes/<key>/templates/: дозволені шляхи, *.twig, заборонена зона, відповідник у рушії, маніфест theme.yaml,
    self-extends без @engine, чутливі зони повної копії base. Twig не парситься (без PHP) — синтаксис ловить lint:twig
    у збірці образу (Dockerfile.prod), рендер — store:smoke кандидата."""
    tpl = os.path.join(pack, "themes", key, "templates")
    manifest_path = os.path.join(pack, "themes", key, "theme.yaml")
    if not os.path.isdir(tpl):
        if os.path.isfile(manifest_path):
            rep.warn(f"themes/{key}/theme.yaml без themes/{key}/templates/ — маніфест теми без шаблонів")
        return
    engine_tpl = os.path.join(engine, "templates")
    files = []
    for dp, dirs, names in os.walk(tpl):
        # symlink (тека чи файл) — обхід забороненої зони: site/account -> ../_acc os.walk не розкриває, а збірка лінк зберігає
        for d in sorted(dirs):
            if os.path.islink(os.path.join(dp, d)):
                rel = os.path.relpath(os.path.join(dp, d), tpl).replace(os.sep, "/")
                rep.error(f"themes/{key}/templates/{rel}: символьне посилання в шаблонах паку заборонене")
        for f in names:
            rel = os.path.relpath(os.path.join(dp, f), tpl).replace(os.sep, "/")
            where = f"themes/{key}/templates/{rel}"
            if os.path.islink(os.path.join(dp, f)):
                rep.error(f"{where}: символьне посилання в шаблонах паку заборонене")
                continue
            if rel in ("README.md", ".gitkeep"):
                continue
            if not f.endswith(".twig"):
                rep.error(f"{where}: у шаблонах паку лише *.twig (PHP, CSS і картинки — не тут)")
                continue
            if not rel.startswith(TEMPLATE_ALLOWED):
                rep.error(f"{where}: перекривати можна лише {', '.join(a + '**' for a in TEMPLATE_ALLOWED)}")
                continue
            if rel.startswith(TEMPLATE_FORBIDDEN):
                rep.error(f"{where}: заборонена зона ({', '.join(TEMPLATE_FORBIDDEN)}) — кабінет, schema.org, sitemap і ліцензія лишаються рушію")
                continue
            files.append(rel)
            in_engine = os.path.isfile(os.path.join(engine_tpl, rel))
            if not in_engine:
                rep.warn(f"{where}: у рушії такого шаблону нема (власний partial паку — норма, одрук у назві — ні)")
            with open(os.path.join(dp, f), encoding="utf-8", errors="replace") as fh:
                text = fh.read()
            body = re.sub(r"\{#.*?#\}", "", text, flags=re.S)
            # розширення самого себе без @engine — нескінченна рекурсія лоадера
            for m in re.finditer(r"""\{%-?\s*(extends|include|embed|import|from)\s+['"]([^'"]+)['"]""", body):
                if m.group(2) == rel:
                    rep.error(f"{where}: {m.group(1)} '{rel}' — посилання на самого себе; базовий шаблон рушія — '@engine/{rel}'")
            extends_engine = re.search(r"""\{%-?\s*extends\s+['"]@engine/""", body) is not None
            if rel == "site/base.html.twig" and not extends_engine:
                for needle, what in SENSITIVE_BASE:
                    if needle not in text:
                        rep.warn(f"{where}: повна копія базового шаблону без «{needle}» — {what}. Перевірте свідомо (docs/themes.md, чутливі зони)")
    if not files:
        rep.warn(f"themes/{key}/templates/ є, але шаблонів у ній нема (лише README/.gitkeep) — тема не задіяна")
        return
    # маніфест теми: пише bin/store theme new, читає theme diff; валідатор звіряє поля, контракт і перелік файлів
    if not os.path.isfile(manifest_path):
        rep.error(f"themes/{key}/theme.yaml: нема маніфесту теми — створіть тему через bin/store theme new {key} (або додайте файли ним)")
        return
    man = load_yaml(manifest_path, rep)
    if not isinstance(man, dict):
        rep.error(f"themes/{key}/theme.yaml: очікується мапа format/key/from/engine/contract/files")
        return
    for f in sorted(THEME_MANIFEST_FIELDS - set(man)):
        rep.error(f"themes/{key}/theme.yaml: нема поля {f}")
    for f in sorted(set(man) - THEME_MANIFEST_FIELDS):
        rep.error(f"themes/{key}/theme.yaml: невідоме поле {f}")
    if man.get("format") != 1:
        rep.error(f"themes/{key}/theme.yaml: format має бути 1")
    if man.get("key") != key:
        rep.error(f"themes/{key}/theme.yaml: key має дорівнювати '{key}'")
    if man.get("from") != "engine":
        rep.error(f"themes/{key}/theme.yaml: from — лише engine (базові шаблони рушія @engine)")
    contract = man.get("contract")
    if not isinstance(contract, int) or contract < 1:
        rep.error(f"themes/{key}/theme.yaml: contract — ціле ≥ 1")
    elif contract > TEMPLATE_CONTRACT:
        rep.error(f"themes/{key}/theme.yaml: contract {contract} новіший за контракт рушія {TEMPLATE_CONTRACT} — оновіть рушій")
    elif contract < TEMPLATE_CONTRACT:
        rep.warn(f"themes/{key}/theme.yaml: тема зроблена під контракт {contract}, рушій — {TEMPLATE_CONTRACT}: bin/store theme diff {key} і docs/themes.md (зміни контракту)")
    if not re.match(r"^(HEAD|v\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?|[0-9a-f]{7,40})$", str(man.get("engine", ""))):
        rep.error(f"themes/{key}/theme.yaml: engine — тег vX.Y.Z або sha коміту рушія, з якого зроблено тему (HEAD — лише фікстури/стенд)")
    listed = man.get("files")
    if not isinstance(listed, dict) or not all(isinstance(v, str) and re.match(r"^[0-9a-f]{64}$", v) for v in listed.values()):
        rep.error(f"themes/{key}/theme.yaml: files — мапа шлях → sha256 базового файлу рушія")
        listed = {}
    for f in sorted(set(files) - set(listed)):
        rep.warn(f"themes/{key}/templates/{f}: нема в маніфесті theme.yaml — theme diff не знатиме, з чого його робили (власний partial — норма)")
    for f in sorted(set(listed) - set(files)):
        rep.warn(f"themes/{key}/theme.yaml: файл {f} у маніфесті, але в templates/ його нема")


LICENSE_KEYS = {"licensee", "type", "contract", "since"}
LICENSE_TYPES = ("subscription", "perpetual")


def check_license(params, rep):
    """app.license паку (App\\Core\\License\\PlatformLicense): нема — попередження (сторінка ліцензії покаже назву магазину й договір «не вказано»);
    є — ключі, тип, дата й власник у тому вигляді, що приймає рушій (інакше він кидає виняток на сторінці ліцензії)."""
    if "app.license" not in params:
        rep.warn("config/parameters.yaml: нема app.license (licensee, type, contract, since) — на сторінці ліцензії власником буде app.site_name, договір з LuxSite «не вказано»")
        return
    lic = params["app.license"]
    if not isinstance(lic, dict):
        rep.error("app.license: мапа licensee/type/contract/since")
        return
    for k in sorted(set(lic) - LICENSE_KEYS):
        rep.error(f"app.license.{k}: невідомий ключ (дозволено: {', '.join(sorted(LICENSE_KEYS))})")
    licensee = lic.get("licensee")
    if licensee is not None and not isinstance(licensee, str) and not (isinstance(licensee, dict) and licensee and all(isinstance(k, str) and isinstance(v, str) for k, v in licensee.items())):
        rep.error("app.license.licensee: рядок або мапа {мова: назва}")
    if licensee in (None, "", {}):
        rep.warn("app.license.licensee порожній — власником на сторінці ліцензії буде app.site_name")
    if lic.get("type") not in (None, "") and lic.get("type") not in LICENSE_TYPES:
        rep.error(f"app.license.type: {' | '.join(LICENSE_TYPES)} або порожньо («не вказано»)")
    since = lic.get("since")
    if since not in (None, ""):
        if isinstance(since, datetime.date):
            rep.error("app.license.since: дата в лапках ('YYYY-MM-DD') — без лапок YAML робить з неї не рядок")
        elif not isinstance(since, str) or not DATE_RE.match(since):
            rep.error("app.license.since: 'YYYY-MM-DD'")
    contract = lic.get("contract")
    if contract not in (None, "") and not isinstance(contract, (str, int)):
        rep.error("app.license.contract: рядок")


def validate_pack(engine, pack, registry_path=None, expected_key=None):
    pack = os.path.abspath(pack)
    rep = Report(f"пак {pack}")
    if not os.path.isdir(pack):
        rep.error("теки нема")
        return rep.done()
    for p in REQUIRED_PATHS:
        if not os.path.exists(os.path.join(pack, p)):
            rep.error(f"нема {p}")

    # store.yaml
    meta = load_yaml(os.path.join(pack, "store.yaml"), rep) or {}
    key = str(meta.get("key", "")) if isinstance(meta, dict) else ""
    if isinstance(meta, dict) and meta:
        if meta.get("format") != PACK_FORMAT:
            rep.error(f"store.yaml: format має бути {PACK_FORMAT}")
        for f in sorted(PACK_FIELDS - set(meta)):
            rep.error(f"store.yaml: нема поля {f}")
        for f in sorted(set(meta) - PACK_FIELDS - PACK_OPTIONAL_FIELDS):
            rep.error(f"store.yaml: невідоме поле {f} (сервер, статус, бекапи — у реєстрі stores.yaml)")
        if not KEY_RE.match(key):
            rep.error(f"store.yaml: key '{key}' має відповідати {KEY_RE.pattern}")
        d = meta.get("domains")
        if not isinstance(d, dict) or set(d) != {"site", "admin"} or not all(HOST_RE.match(str(v)) for v in d.values()):
            rep.error("store.yaml: domains — site і admin, імена хостів")
        if not isinstance(meta.get("locales"), list) or not meta.get("locales"):
            rep.error("store.yaml: locales — непорожній список")
    if os.path.isdir(os.path.join(pack, "src")):
        rep.warn("src/ у паку: клієнтський PHP у пак v1 не входить (ADR v2) — без міграцій і Twig-override; краще PR у рушій")
    if expected_key and key != expected_key:
        rep.error(f"store.yaml: key '{key}', а перевіряється магазин '{expected_key}' — не той пак")
    if key and not os.path.isdir(os.path.join(pack, "themes", key)):
        rep.error(f"нема теки теми themes/{key}/")
    if os.path.isdir(os.path.join(pack, "themes")):
        for other in sorted(set(os.listdir(os.path.join(pack, "themes"))) - {key}):
            rep.error(f"themes/{other}: у паку лише тема themes/{key}/ (збірка кладе її в public/themes/{key})")
    if key:
        check_templates(engine, pack, key, rep)

    # config/parameters.yaml → config/store/parameters.local.yaml
    params_doc = load_yaml(os.path.join(pack, "config/parameters.yaml"), rep) or {}
    if not isinstance(params_doc, dict) or set(params_doc) != {"parameters"} or not isinstance(params_doc.get("parameters"), dict):
        rep.error("config/parameters.yaml: лише ключ parameters: з мапою параметрів (без services, imports, when@)")
        params = {}
    else:
        params = params_doc["parameters"]
    known = engine_parameters(engine)
    for name in params:
        if name in DEPLOY_PARAMS:
            rep.error(f"config/parameters.yaml: {name} задає деплой з domains реєстру — у паку не потрібен")
        elif name in ENGINE_ONLY_PARAMS:
            rep.error(f"config/parameters.yaml: {name} — службовий параметр рушія, пак його не задає (шаблони — у themes/{key}/templates/)")
        elif name not in known:
            rep.error(f"config/parameters.yaml: невідомий параметр рушія {name}")
    if params.get("app.powered_by") is False:
        rep.warn("app.powered_by: false — без посилання на luxsite.agency і meta generator; рядок про Apache 2.0 і NOTICE лишаються")
    check_license(params, rep)
    check_addons(meta, params, engine, rep)
    if isinstance(meta, dict) and meta.get("locales") and "app.locales" in params and list(params["app.locales"]) != list(meta["locales"]):
        rep.error(f"app.locales {params['app.locales']} ≠ store.yaml locales {meta['locales']}")
    if isinstance(meta, dict) and meta.get("locales") and "app.locales" not in params:
        rep.error("config/parameters.yaml: задайте app.locales (дефолт рушія — інші мови)")
    if "app.default_locale" in params and isinstance(meta, dict) and meta.get("locales") and params["app.default_locale"] != meta["locales"][0]:
        rep.error("app.default_locale має бути першою мовою store.yaml locales")
    schema_path = os.path.join(engine, "config/store/theme-schema.json")
    try:
        with open(schema_path, encoding="utf-8") as fh:
            schema = json.load(fh)
    except (OSError, ValueError) as e:
        rep.error(f"схема теми рушія {schema_path}: {e}")
        schema = None
    if schema is not None and key:
        if "app.theme" not in params:
            rep.error("config/parameters.yaml: нема app.theme (щонайменше key: <ключ магазину>)")
        else:
            check_theme(params["app.theme"], schema, key, pack, rep)

    # каталог: CSV з заголовком і external_id (облік карток імпортом)
    catalogs = sorted(f for f in os.listdir(os.path.join(pack, "catalog")) if f.endswith(".csv")) if os.path.isdir(os.path.join(pack, "catalog")) else []
    if not catalogs:
        rep.warn("catalog/: нема CSV — каталог доведеться завантажувати інакше")
    for f in catalogs:
        with open(os.path.join(pack, "catalog", f), encoding="utf-8-sig", newline="") as fh:
            sample = fh.read(65536)
            fh.seek(0)
            try:
                dialect = csv.Sniffer().sniff(sample.split("\n", 1)[0], delimiters=",;\t")
            except csv.Error:
                dialect = csv.excel
            header = next(csv.reader(fh, dialect), [])
            if "external_id" not in header and "sku" not in header:
                rep.error(f"catalog/{f}: у заголовку нема external_id/sku (імпорт catalog:import:feed)")

    # реєстр: пак і запис узгоджені
    if registry_path and key:
        ok, stores = validate_registry(registry_path)
        rec = stores.get(key)
        if rec is None:
            rep.warn(f"магазину {key} ще нема в реєстрі {registry_path} — додайте запис PR-ом (друкує bin/store new)")
        elif isinstance(meta, dict):
            if rec.get("locales") != meta.get("locales"):
                rep.error("store.yaml locales ≠ реєстр locales")
            if rec.get("domains") != meta.get("domains"):
                # стенд/технічний домен: деплой бере хости з реєстру, пак лишається з бойовими
                rep.warn(f"domains реєстру {rec.get('domains')} ≠ store.yaml {meta.get('domains')} — деплой візьме хости з реєстру")
            if str(rec.get("engine")) != str(meta.get("engine")):
                rep.warn(f"engine: пак {meta.get('engine')}, у реєстрі {rec.get('engine')} — після оновлення рушія вирівняти")

    # файли: заборонені, секрети в тексті, розмір
    total = 0
    for root, dirs, files in os.walk(pack):
        dirs[:] = [d for d in dirs if d not in (".git",)]
        for d in dirs:
            if os.path.islink(os.path.join(root, d)):
                rep.error(f"{os.path.relpath(os.path.join(root, d), pack)}: символьне посилання (тека) в паку")
        for f in files:
            full = os.path.join(root, f)
            rel = os.path.relpath(full, pack)
            if os.path.islink(full):
                rep.error(f"{rel}: символьне посилання в паку")
                continue
            size = os.path.getsize(full)
            total += size
            if FORBIDDEN_FILES.search(rel) and not rel.endswith((".gitkeep",)):
                rep.error(f"{rel}: файлам такого типу (env, ключі, дампи) у паку не місце")
            if size > FILE_MAX_BYTES:
                rep.error(f"{rel}: {size // 1024} КБ > {FILE_MAX_BYTES // 1024 // 1024} МБ (фото товарів — імпортом, не в паку)")
            if os.path.splitext(f)[1].lower() in TEXT_EXT and size <= FILE_MAX_BYTES:
                with open(full, encoding="utf-8", errors="replace") as fh:
                    scan_text(fh.read(), rel, rep)
    if total > PACK_MAX_BYTES:
        rep.error(f"розмір паку {total // 1024 // 1024} МБ > {PACK_MAX_BYTES // 1024 // 1024} МБ")
    return rep.done()


def main(argv):
    if len(argv) >= 2 and argv[0] == "registry":
        return 0 if validate_registry(argv[1])[0] else 1
    if len(argv) >= 3 and argv[0] == "pack":
        return 0 if validate_pack(argv[1], argv[2], argv[3] if len(argv) > 3 else None, argv[4] if len(argv) > 4 else None) else 1
    print(__doc__, file=sys.stderr)
    return 2


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
