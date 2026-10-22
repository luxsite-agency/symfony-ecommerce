# Admin API, MCP і персональні токени (E15 PR-2/4/5; живий дизайн 20.10.2026)

Адмінка керується ззовні — з Claude Code (MCP, PR-5) чи скриптом (REST, PR-4) — **персональними токенами** співробітників.
Токен дає рівно те, що може сам співробітник, і не більше: скоупи токена ∩ роль власника. Застосувати пакет змін через
API не можна — лише людина в адмінці (рішення власника 11.10.2026, `docs-adr.md` «E15 PR-1»).

## Для менеджера

1. Адмінка → меню **API → API-токени → Створити токен**: назва («Claude Code на ноутбуці»), дозволи, строк (до 90 днів).
2. Значення `shp_…` показується **один раз** — скопіюйте в змінну оточення, не у файл проєкту:
   ```bash
   export SHOP_TOKEN='shp_…'
   curl -sS -H "Authorization: Bearer $SHOP_TOKEN" https://<admin-host>/api/admin/v1/me
   claude mcp add --transport http shop https://<admin-host>/mcp --header "Authorization: Bearer ${SHOP_TOKEN}"
   ```
   У `.mcp.json` проєкту лишається лише `${SHOP_TOKEN}` — значення в оточенні, не у файлі.
3. Строк минув — створіть новий токен, старий відкликайте. Загубили — відкликайте одразу (рядок → «Відкликати»).
4. У списку видно лише свої токени: префікс, дозволи, стан, останній запит і адреса (анонімізована).

## Для адміністратора

- Той самий список показує токени **всіх** користувачів з колонкою «Власник»; відкликати можна будь-який.
- Деактивація користувача (Користувачі → Активний = ні) відкликає всі його токени; чинний токен вимкненого
  користувача й так не проходить (`ActiveUserChecker`).
- Події — у `security_audit` (`api_token.created|revoked|auth_failed|new_ip`, без значень токенів) і в «Журналі змін»
  (тип «API-токен»: створено / відкликано, без хешу).

## Дозволи (скоупи)

| Скоуп | Що дає | Потрібна роль |
|---|---|---|
| `catalog:read` | читання товарів, категорій, сторінок, постів (PR-4), інструменти `search_products`, `get_*` (PR-5) | ROLE_MANAGER |
| `content:write` | пакети змін контенту: тексти, SEO, slug, категорії товару, активність | ROLE_MANAGER |
| `prices:write` | пакети змін цін: ціна/стара ціна варіанта, пороги | ROLE_MANAGER |
| `theme:write` | **живий дизайн** (20.10.2026): шаблони `site/**` і `theme.css` у `var/share/theme`, застосовується одразу — розділ «Дизайн під токеном» | ROLE_MANAGER |

Нема й не буде у v1: замовлення, покупці, залишки, акції, медіа, застосування пакетів. `theme:write` — єдиний скоуп, що пише
одразу (не пакетом): це файли дизайну, не дані каталогу; запобіжники — нижче.
У коді: `#[IsGranted('scope:prices:write')]` на дії → `ScopeVoter` (скоуп у токені **і** роль власника через `role_hierarchy`).

## Протокол

- Firewall `admin_api`: хост адмінки, `^/(api/admin|mcp)`, `stateless` (без cookie й сесії; сесія адмінки на API не діє,
  токен не відкриває сторінки адмінки).
- `Authorization: Bearer shp_<43 символи base64url>`; у БД — sha256, у списку — перші 12 символів.
- Відповіді (RFC 6750, сумісні з MCP/OAuth на майбутнє):

| Код | Коли | Заголовок / тіло |
|---|---|---|
| 401 | нема заголовка | `WWW-Authenticate: Bearer realm="shop-admin-api"`, `{"error":"unauthorized"}` |
| 401 | невідомий, відкликаний, прострочений токен; вимкнений користувач | `…error="invalid_token", error_description="…"`, `{"error":"invalid_token","error_description":"…"}` |
| 403 | нема скоупу або ролі | `…error="insufficient_scope", scope="prices:write"`, `{"error":"insufficient_scope","scope":"prices:write"}` |
| 429 | > `app.admin_api_auth_rate_limit` невдалих автентифікацій з IP за хвилину; > `app.admin_api_token_rate_limit` запитів на токен за хвилину; > `app.admin_api_write_rate_limit` спроб створити пакет на токен за хвилину (PR-6); nginx: > 10 запитів/с з IP (сплеск 30) | `Retry-After`, `{"error":"too_many_requests"}` |
| 413 | тіло понад `app.admin_api_max_body_bytes` (1 МіБ; nginx ріже на `1m` ще до PHP) — PR-6 | `{"error":"payload_too_large","limit":1048576}` |
| 404 | хост сайту | маршрутів `/api/admin` там нема; nginx віддає 404 сам, до PHP (PR-6). Хост адмінки nginx бере з `SHOP_ADMIN_HOST` (dev/worktree) або `STORE_ADMIN_HOST` (магазини через `bin/store`); якщо змінна не задана — правило пропускає все до PHP, і 404 дає роутер Symfony |

- `GET /api/admin/v1/me` — користувач, токен (без значення), скоупи; скоупу не вимагає. `/mcp` — розділ нижче.

## Ресурси (PR-4)

Усе — JSON на хості адмінки, `Authorization: Bearer`. Відповіді містять ті самі ключі, що приймають операції пакета
(`price`, `old_price`, `name`, `slug`, `short_description`, `description`, `meta_*`, `categories`, `main`), щоб зібрати `expect`.

| Метод і шлях | Скоуп | Що |
|---|---|---|
| `GET /products?q=&category=&limit=20&page=1` | `catalog:read` | пошук: `q` — префікс SKU варіанта або підрядок назви; `limit` ≤ 50 |
| `GET /products/{id або SKU}` | `catalog:read` | картка: переклади, варіанти з цінами й порогами, категорії, бренд, `url` |
| `GET /categories` | `catalog:read` | дерево (батько перед дітьми, `depth`, `parent_id`), переклади з `path` |
| `GET /pages`, `GET /pages/{id або slug}` | `catalog:read` | сторінки (у списку без тексту) |
| `GET /posts?limit=&page=`, `GET /posts/{id або slug}` | `catalog:read` | записи блогу |
| `POST /changesets` | `content:write` і/або `prices:write` за складом пакета | dry-run і збереження як `proposed`: **201** + `Location`; тіло — пакет з `diff`, `warnings`, `risk_flags`, `url` сторінки в адмінці |
| `POST /changesets/dry-run` | ті самі | лише перевірка, нічого не зберігається; помилки — у тілі `errors` з 200 |
| `GET /changesets?status=&limit=&page=` | будь-який скоуп | свої пакети, найновіші перші; адміністратор `?all=1` — усі |
| `GET /changesets/{id}` | будь-який скоуп | свій пакет (адмін — будь-який); чужий — 404 |

Формат пакета й операцій — `docs-adr.md`, розділ «E15 PR-3» (шість операцій, `expect` на кожне змінюване поле). Коди помилок:
400 не JSON; 403 `insufficient_scope` (`scope=` каже, якого бракує); 413 `too_many_operations`; 422 `validation_failed` з
`errors[].{index, code, message, details}`; 429 `price_ops_limit` (ліміт цінових операцій на токен за добу, `Retry-After`).
`status` у відповідях — ефективний: прострочений `proposed` читається як `expired`.

**Застосувати пакет через API не можна.** `url` з відповіді — сторінка пакета в адмінці; там менеджер бачить diff, ризики
й попередження і натискає «Застосувати» (ризиковий — з позначкою підтвердження), «Відхилити» або «Відкотити» (на застосованому —
створює пакет-відкат). Після застосування цінового пакета відповідальним за ціни іде лист (`prices.notify_email`, порожньо —
усім активним менеджерам без адміністраторів).

Приклад (Claude Code / curl):
```bash
curl -sS -H "Authorization: Bearer $SHOP_TOKEN" "https://<admin-host>/api/admin/v1/products/ML-14" | jq '.translations.uk'
curl -sS -X POST -H "Authorization: Bearer $SHOP_TOKEN" -H 'Content-Type: application/json' https://<admin-host>/api/admin/v1/changesets -d '{
  "title": "Короткий опис ML-14",
  "operations": [{"op": "set_translation", "entity": "product", "id": 7, "locale": "uk",
                  "fields": {"short_description": "Вхідні сталеві двері"}, "expect": {"short_description": "Сталеві двері"}}]
}' | jq '{id, status, url, risk_flags}'
```

## Дизайн під токеном (`theme:write`, 20.10.2026)

Рішення власника 20.10.2026: «живий дизайн — як у сервісних сайтах» (еталон — service-engine `docs/admin-api.md` «Дизайн під
токеном», `App\AdminApi\ThemeService`). Менеджер або Claude Code з токеном `theme:write` правлять шаблони сайту й CSS
**одразу на сайті**, без пакета змін і без деплою паку. Файли живуть у `var/share/theme` (том `share` стека — переживає релізи;
у service-engine це `<root>/shared/theme`):

| Що | Де | Як діє |
|---|---|---|
| `templates/site/**.twig` | `var/share/theme/templates/` | Twig бере їх **першими**: live > пак (`templates/store/`) > рушій (`LiveThemeLoader`, перший у ланцюжку `twig.loader`); базовий шаблон — `@engine/site/…` для `{% extends %}` |
| `theme.css` | `var/share/theme/public/theme.css` | `base.html.twig` підключає `/theme-live/theme.css?v=<хеш>` після `assets/site.css`, CSS паку й `site_theme_style()`; віддає `LiveCssController` (nginx тома не бачить), рік кешу за версією |
| історія | `var/share/theme/history/<шлях>/<YYYYMMDD-HHMMSS>` | до 20 версій на файл; `revert` — через той самий `put` з усіма перевірками |

**Запобіжники** (`ThemeService`, усі — до того, як відвідувач щось побачить):

1. Ім'я — лише `site/**.twig` поза забороненою зоною паку (`site/account/**`, `_schema*`, `sitemap`, `page/license` — `locked`
   у переліку, читати можна, перекривати ні) або `theme.css`; без `..`, `//`, символьних посилань; файл ≤ 256 КБ, файлів ≤ 200.
2. Twig-страж до запису (`ThemeTemplateGuard` + явний allowlist `LiveThemePolicy`; рев'ю C PR #108 M1–M2): синтаксис + прохід
   по AST, у якому дозволене лише перелічене — теги, фільтри, функції, тести, імена властивостей/методів (`x.name`), ланцюжки від
   `app` (`app.request.locale|pathInfo|uri|schemeAndHttpHost|hasPreviousSession`, `app.request.query.get|all|has`, `app.user[.email…]`,
   `app.flashes`, `app.locale`, `app.current_route`); невідоме → 422. Тобто без `app.request.server|headers|cookies|session`, `app.session`,
   `app.token`, `_context`, `attribute()`, `render()`/`render_*`/`controller()`/`fragment_uri()`, `include_only()`, `source()`, `dump()`,
   `template_from_string`, `|invoke`, `is constant`, сетерів і `clear()`; `extends`/`include`/`embed`/`import`/`use`/`block(name, template)`
   лише літералом `site/…` або `@engine/site/…`; `constant()`/`enum()` — лише літерал `App\…\Enum\…::Case`; `filter|map|reduce|sort|find`
   — лише зі стрілочною функцією. Базу списків дає `grep` по `templates/site/**`, тест `testEveryOverridableEngineTemplatePasses` стежить,
   щоб кожен шаблон рушія, який можна перекрити, проходив стража як є. Чому не `SandboxExtension`: у Twig 3.30 він `@internal`,
   per-source режим deprecated без заміни, а `Twig\Sandbox\Sandbox` потребує окремого Environment і не вміє «live перекриває ім'я рушія».
3. Після запису — рендер ключових сторінок **через HTTP до nginx стека** (`ThemeSmoke`, `app.theme_smoke_url`, `Host` = `site_host`;
   `kernel` — через ядро, у тестах): головна кожною мовою, блог, категорія, товар, сторінка, рішення, кошик і checkout з тимчасовим
   кошиком. 5xx/4xx, обірваний HTML, редирект кошика чи checkout замість сторінки → файл повертається, 422.
4. Страж форм на тих самих сторінках: кожна POST-форма має `_token` і `action` на своєму сайті; checkout — `form.checkout` з `key`
   і `price` (підтвердження ціни) та кнопкою; кошик з товаром — форми кількості/видалення. Тобто жива тема може перекрити
   `site/cart/**` і `site/checkout/**`, але не може прибрати CSRF, підмінити адресу чи зняти підтвердження ціни.
5. Запис (`PUT`, `DELETE`, `revert`) рахує лімітер спроб запису на токен (`ApiWriteLimiter`, 20/хв) плюс загальні 120/хв; журнал
   `admin_api`: `theme.put` / `theme.put_rejected` / `theme.delete` з `path`, `bytes`, `user_id`, `token_id` — **без вмісту**.
6. `theme.css` не рендерить сторінок (не ламає HTML) — пишеться одразу.
7. Один запис за раз: `PUT`/`DELETE`/`revert` під блокуванням на магазин (`LockFactory`, ключ `theme-live`, `LOCK_DSN`; рев'ю C M3) —
   інакше два одночасні записи знімали б одне `previous`, і провал smoke одного повертав би старе поверх успішного іншого. Чекання
   до `app.theme_lock_wait` с (60; smoke триває секунди), далі `409 locked` з `retry_after` — клієнт повторює. Рендер: 8 с на сторінку,
   60 с на все (nginx на `/api/admin` чекає 120 с — клієнт отримує 422, не 504).

| Метод і шлях | Що |
|---|---|
| `GET /api/admin/v1/theme` | перелік шаблонів рушія `site/**` з позначкою `source` (`engine` \| `pack` \| `live`), `locked`, власні живі файли, `theme.css`, `history`, `rules` |
| `GET /api/admin/v1/theme/{path}` | поточний текст (звідки — `source`) і версії в історії; `path` — `site/home.html.twig`, `theme.css` |
| `PUT /api/admin/v1/theme/{path}` | тіло — текст файлу (`text/plain`) або JSON `{"content": …}`; 200 `{source, bytes, smoke, pages, history}`; 422 `validation_failed` з `errors[]`; 413 `payload_too_large`; 409 `locked` (інший запис триває, `retry_after`) |
| `DELETE /api/admin/v1/theme/{path}` | прибрати живе перекриття (далі пак або рушій); версія — в історії |
| `POST /api/admin/v1/theme/{path}/revert` | `{"version": "YYYYMMDD-HHMMSS"}` |

MCP: `theme_list`, `theme_get`, `theme_put`, `theme_delete`, `theme_revert` (розділ нижче). Порада Claude Code: `{% extends '@engine/…' %}`
з перевизначенням блоків, не повна копія — виправлення рушія доходитимуть у неперекриті блоки; лого й перемикач мов зберігати.

**Чим відрізняється від service-engine:**

| | service-engine | shop-engine |
|---|---|---|
| Де файли | `<root>/shared/theme` (симлінк `var/theme`), `public/theme-live` → симлінк, nginx віддає CSS сам | том `share` → `var/share/theme`; nginx тома не бачить — `theme.css` віддає PHP (`/theme-live/theme.css`, кеш за `?v=`) |
| Як Twig бере live | ще один шлях `FilesystemLoader` + ручне скидання компільованого шаблону й opcache після запису | окремий лоадер з **ключем кешу за хешем вмісту**: кожна версія — новий клас, скидати нічого не треба (і стенд без тома не падає на `addPath`) |
| Дозволені шаблони | `services/**`, `partials/**` | увесь `site/**` мінус заборонена зона паку (та сама, що для тем паку) |
| Перевірка до запису | синтаксис Twig (`app.request.server`, `render()`, `attribute()` відкриті — повідомлено координатору) | синтаксис + AST-страж з явним allowlist (п. 2) |
| Рендер після запису | HTTP до публічного хоста сайту | HTTP до nginx стека з `Host` (`app.theme_smoke_url`) або ядро (`kernel`); плюс страж форм кошика/checkout (п. 4) |
| `site` у виклику | так | ні (один магазин — один стек) |
| Ліміти | — | 256 КБ, 200 файлів, лімітер записів на токен |
| Одночасні записи | без блокування | блокування на магазин, 409 після 60 с (п. 7) |

У git паку ці правки не потрапляють, у бекап `bin/store backup` том `share` теж не входить: сталий дизайн розробник переносить у
`themes/<key>/templates/` паку (`theme_get` віддає поточний текст) і після деплою паку знімає живе перекриття (`delete`).

## MCP `/mcp` (PR-5; лише на хості адмінки, той самий firewall і токен)

Та сама модель, що `/mcp` у service-engine: **JSON-RPC 2.0 поверх POST без сесій і SSE** — кожен запит самодостатній, тож
PHP-FPM і Cloudflare (таймаут 100 с) його не помічають. `GET`/`DELETE /mcp` → 405. Методи: `initialize` (повертає
`protocolVersion` клієнта, `capabilities.tools`, `instructions`), `notifications/*` → 202, `ping`, `tools/list`, `tools/call`.
Batch-запитів нема (прибрані у ревізії 2025-06-18). `Origin`, якщо надіслано, мусить бути хостом адмінки — інакше 403
(`forbidden_origin`; DNS rebinding). Без `mcp/sdk`: п'ять методів не варті десятка PSR-залежностей pre-1.0 бібліотеки,
а код збігається з service-engine (ADR «E15 PR-5»).

Код: `src/Core/AdminApi/Mcp/` — `McpController` (транспорт), `McpTools` (реєстр і диспетчер; скоуп кожного інструмента —
`McpTools::SCOPES`, страж — `ScopeGuardTest::testEveryMcpToolDeclaresScope`), `UntrustedText`, `McpToolError`.
Інструменти — тонкі обгортки над тими самими сервісами, що REST: `CatalogReader`, `ChangesetPlanner`, `ChangesetListing`, `ChangesetView`.

| Інструмент | Скоуп | Що | REST-аналог |
|---|---|---|---|
| `me` | — | користувач, токен (без значення), скоупи | `GET /me` |
| `search_products(q?, category?, limit?, page?)` | `catalog:read` | короткі рядки товарів | `GET /products` |
| `get_product(ref, fields?)` | `catalog:read` | картка за id чи SKU | `GET /products/{ref}` |
| `list_categories()` | `catalog:read` | дерево | `GET /categories` |
| `list_pages()`, `get_page(ref, fields?)` | `catalog:read` | сторінки | `GET /pages[/{ref}]` |
| `list_posts(limit?, page?)`, `get_post(ref, fields?)` | `catalog:read` | записи блогу | `GET /posts[/{ref}]` |
| `dry_run_changes(title, operations)` | за складом пакета | план без збереження; `errors` — у результаті, не isError | `POST /changesets/dry-run` |
| `propose_changes(title, operations)` | за складом пакета | dry-run + збереження `proposed`; результат — пакет з `url` сторінки в адмінці і `next_step` | `POST /changesets` |
| `list_my_changesets(status?, all?, limit?, page?)` | будь-який | свої пакети; адміністратор з `all` — усі | `GET /changesets` |
| `get_changeset(id)` | будь-який | свій пакет; чужий — «не знайдено» | `GET /changesets/{id}` |
| `theme_list()` | `theme:write` | дизайн: що можна перекрити, `source`, `locked`, `history`, правила | `GET /theme` |
| `theme_get(path)` | `theme:write` | поточний текст файлу (живий, з паку або рушія) і версії | `GET /theme/{path}` |
| `theme_put(path, content)` | `theme:write` | записати живий файл — **одразу на сайті**, після Twig-стража й рендера ключових сторінок (провал = файл повернуто, `isError`) | `PUT /theme/{path}` |
| `theme_delete(path)` | `theme:write` | прибрати живе перекриття | `DELETE /theme/{path}` |
| `theme_revert(path, version)` | `theme:write` | повернути версію з історії | `POST /theme/{path}/revert` |

**Нема й не буде:** `apply_changeset`, `revert_changeset`, `set_stock`, `update`, нічого про замовлення й покупців. Застосувати
пакет може лише менеджер на сторінці пакета (сесія + CSRF); `instructions` сервера й опис `propose_changes` кажуть моделі
передати `url` користувачу.

**Помилки.** 401/429 firewall — HTTP, як у REST (RFC 6750). Усе, що стається всередині інструмента (нема скоупу, не знайдено,
`validation_failed` 422 з `errors[]`, `price_ops_limit` 429, невідомий інструмент), — **результат з `isError: true`**: текст
для моделі (повідомлення + JSON подробиць) і `structuredContent` `{error, status, message, …}`. HTTP 403 моделі не дійшов би,
тому скоуп перевіряється в `McpTools`, не атрибутом на маршруті; `error: insufficient_scope, scope: prices:write` — той самий
словник, що в заголовку REST. Помилки протоколу (не JSON-RPC, невідомий метод) — `error` JSON-RPC (`-32600` з HTTP 400, `-32601`).

**Недовірений текст.** Описи товарів приходять з фідів постачальників — для моделі це дані, не інструкції. Довгі поля
(`description`, `short_description`, `body`, `excerpt`) інструменти читання віддають як plain text (HTML → текст), обрізаним до
4 000 символів і загорнутим у `<untrusted_content source="product:7:uk:description">…</untrusted_content>`. Для `expect`
пакета потрібне значення як у БД — його дає параметр `fields: ["description"]` (повернеться без змін і без маркера; що це
теж дані каталогу, нагадують опис інструмента та `instructions`). Той самий маркер — на поточних значеннях з БД у плані й
пакеті: `diff[].changes[].before` і `details.current` знахідок (`errors`/`warnings`/`risks`) у `dry_run_changes`, `propose_changes`,
`get_changeset` і в тексті помилки інструмента; `after` і `expected` — значення моделі, як є (рев'ю C #81 M1). Назви, слаги,
SEO-поля і ціни — як є.

**Чим відрізняється від service-engine** (`McpController` там; власник хоче спільну модель, різниця — лише у предметі):

| | service-engine | shop-engine |
|---|---|---|
| Транспорт, методи, формат відповідей, Origin, `isError` для помилок інструментів, `me` | однаково | однаково (+ Origin-перевірка, якої там нема) |
| Інструменти | узагальнені `list/get(site, kind)` + `update` пише в БД одразу | типізовані `search_products/get_product/…`: у магазині сутності різні за формою (варіанти, пороги, дерево), а **запису напряму нема** — лише `propose_changes` → пакет → людина в адмінці (рішення власника 11.10.2026 п. 1) |
| `site` у кожному виклику | так (один рушій — багато сайтів) | ні: один магазин — один стек |
| Скоупи | `content:read/write`, `prices:write`, `theme:write` | `catalog:read`, `content:write`, `prices:write`, `theme:write`; скоуп запису пакета — за його складом |
| «Живий дизайн» `theme_*` | є | є з 20.10.2026 (розділ «Дизайн під токеном»): ті самі п'ять інструментів, строгіші запобіжники (AST-страж, страж форм кошика/checkout, ліміти) |
| Довгі тексти | як є | plain, обрізано, `<untrusted_content>`; повні — `fields` |

Експлуатація: nginx і журнал — розділ «Захист /api/admin і /mcp (PR-6)» нижче; ліміт запитів на токен — той самий
`ApiRateLimitListener`, `propose_changes` додатково рахується лімітером записів (`too_many_requests` як помилка інструмента з `retry_after`).
Тести: `tests/Functional/AdminApi/McpTest.php`.

## Захист `/api/admin` і `/mcp` (PR-6)

Чотири рубежі, однакові для REST і MCP (шлях — `ApiPaths::PATTERN` = pattern firewall = `location` nginx):

| Рубіж | nginx (`docker/nginx/*.conf`, усі чотири) | PHP |
|---|---|---|
| Хост | `map $host $api_host_ok` з `${SHOP_ADMIN_HOST}` / `${STORE_ADMIN_HOST}` (compose передає, обов'язкові); на іншому хості `location ~ ^/(api/admin\|mcp)(/\|$)` → 404 без PHP | маршрути й firewall прив'язані до `admin_host` (як і було) |
| Розмір тіла | `client_max_body_size 1m` у location → 413 JSON `payload_too_large` | `ApiRequestGuard` (пріоритет 9, до firewall): `Content-Length` чи фактичне тіло > `app.admin_api_max_body_bytes` → 413 без пошуку токена; ловить chunked і стеки без нашого nginx |
| Частота | `limit_req_zone $binary_remote_addr zone=shop_api rate=10r/s`, `burst=30 nodelay` → 429 JSON з `Retry-After: 1`; IP — реальна (realip, як для всього сайту) | на токен: 120/хв усіх запитів (`ApiRateLimitListener`) + 20/хв спроб створити пакет (`ApiWriteLimiter`: `POST /changesets`, `propose_changes`; dry-run не рахується); невдалі автентифікації з IP 30/хв |
| Журнал | для цих шляхів `main`-формат вимкнено (там URI з SKU і query з пошуком), пишеться `shop_api`: IP, час, метод, вид (`mcp` / `api_admin`), статус, байти, тривалість; `Authorization` nginx не пише ніколи; `limit_req_log_level info` — рядок запиту не потрапляє в error_log | канал `admin_api` (`var/log/admin_api.log`, JSON, 366 днів; `ApiAccessLog`): `method`, `route`, `rpc`, `tool`, `token_id`, `status`, `ms` — **без** query, тіла, аргументів інструмента, відповіді, ідентифікаторів ресурсів і IP (хто/звідки — `token_id` → `security_audit`, `last_used_ip`) |

- **Відкликання діє з наступного запиту.** Firewall stateless, токен читається з БД на кожен запит (`AdminApiTokens::findByPlain`,
  без result cache), `revoked_at`/`expires_at` перевіряє автентифікатор, активність власника — `ActiveUserChecker`. Жодного
  кешу токенів чи сесій нема — тож «Відкликати» в адмінці або деактивація користувача зупиняють Claude Code на наступному ж виклику
  (401 `invalid_token` / «Токен відкликано»). Тест: `ApiHardeningTest::testRevocationAndDeactivationTakeEffectOnNextRequest`.
- Кожна відповідь API — `Cache-Control: no-store` (вміст з токеном не осідає в проксі).
- Перевірки: `tests/Core/Infra/NginxApiRulesTest.php` (правило є в усіх чотирьох конфігах і формат журналу без URI/query),
  `tests/store/run.sh` → `nginx -t` кожного конфігу в образі `nginx:1.27-alpine` (шаблони рендерить entrypoint, upstream — `--add-host`),
  `tests/Functional/AdminApi/ApiHardeningTest.php` (413 до автентифікації, журнал, відкликання), `ApiRateLimitsTest::testWriteAttemptsPerTokenAreThrottled`.

## Параметри (`config/services.yaml`, пак — `config/store`)

```yaml
app.admin_api_token_max_days: 90      # найдовший строк токена
app.admin_api_auth_rate_limit: 30     # невдалих автентифікацій з IP за хвилину
app.admin_api_token_rate_limit: 120   # запитів на токен за хвилину
app.admin_api_write_rate_limit: 20    # спроб створити пакет змін на токен за хвилину (REST і MCP), PR-6
app.admin_api_max_body_bytes: 1048576 # найбільше тіло запиту до API/MCP; nginx — 1m у location, PR-6
app.theme_live_dir: '%kernel.project_dir%/var/share/theme'   # живий дизайн (theme:write): templates/, public/theme.css, history/
app.theme_smoke_url: '%env(default:app.theme_smoke_url_default:THEME_SMOKE_URL)%' # рендер після запису: http://nginx (store), env THEME_SMOKE_URL (dev/worktree/prod.yml), 'kernel' — через ядро
```

Бізнес-ліміт цінових операцій на токен за добу — `app.ai_price_ops_per_day` (1000; 0 = без межі): застосовані за добу (журнал змін,
`token_id` рядків `priceMinor` і порогів) **плюс** цінові операції у чинних `proposed` пакетах цього токена (відхилені й прострочені
квоту вивільняють); перевищення → 429 `price_ops_limit` на `POST /changesets` з `used`, `applied`, `pending`, `requested`. IP-allowlist токена —
поле в БД (`ip_allowlist`, JSON), без UI у v1.

Ліміт невдалих автентифікацій рахується **з IP**: за NAT офісу один клієнт з простроченим токеном і ретраями здатен на хвилину
заблокувати API колегам з тієї ж адреси (свідомий компроміс, як `login_throttling`). Якщо це заважає — підняти
`app.admin_api_auth_rate_limit` у `config/store` і полагодити клієнт; відповідь 429 несе `Retry-After`.

## Експлуатація

- nginx: `/api/admin` і `/mcp` — лише хост адмінки, 1m, 10 запитів/с з IP, журнал без URI/query (розділ «Захист», PR-6);
  `SHOP_ADMIN_HOST` / `STORE_ADMIN_HOST` тепер потрібні й контейнеру nginx (compose передає з того самого env).
- Журнал запитів API/MCP — `var/log/admin_api.log` (канал `admin_api`), без вмісту запитів; журнал безпеки — `security_audit.log`.
- Токен у логах не буває: автентифікатор пише лише префікс і причину; `AdminApiTokens` — id, префікс, скоупи.
- Таблиця `admin_api_token` (міграція `Version20261012210016`): `user_id` FK CASCADE, `token_hash` unique, `scopes` JSON,
  `expires_at`, `revoked_at`, `revoked_by_id`, `last_used_at`, `last_used_ip` (анонімізований), `ip_allowlist`, `created_at`.
  `last_used_*` оновлюється не частіше ніж раз на хвилину (DBAL, без flush в автентифікації).
- Тести: `tests/Functional/AdminApi/ApiTokenAuthTest.php` (401/403/stateless/хост), `tests/Functional/Admin/ApiTokenCrudTest.php`
  (сторінка, ізоляція, відкликання, деактивація), `tests/Integration/Core/AdminApi/*` (сервіс, ліміти), `tests/Unit/Core/AdminApi/*`.
  Маршрути зі скоупами для тестів 403 — лише в `APP_ENV=test` (`config/routes/test/admin_api_fixture.yaml`).
