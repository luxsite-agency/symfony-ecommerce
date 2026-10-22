# Теми магазинів: своя верстка в паку (епік E14)

Рішення власника 11.10.2026: рушій має використовувати різні шаблони для магазинів, дизайн під клієнта робиться без
копіювання рушія і без «кожен магазин — свій форк Twig». Модель узгоджена з `service-engine` (`bin/site theme`, тег v0.9.7):
**тема живе в паку магазину, рушій дає базові шаблони.** Консиліум — `server/shop/consilium-2026-10-11-templates/critic.md`.

`template_contract: 1`

## Три рівні, від дешевого

1. **Кольори, лого, картинки, тексти банера й футера** — `app.theme` у `config/parameters.yaml` паку + файли в `themes/<key>/`
   (`SiteTheme::DEFAULTS`, схема `config/store/theme-schema.json`). Лого обов'язкове — без `logo.src` падають і `bin/store validate`,
   і сам рушій.
2. **CSS поверх типової верстки** — `app.theme.stylesheet: themes/<key>/theme.css` + клас `body.theme-<key>`. Порядок CSS:
   `assets/site.css` (база й компоненти рушія) → `theme.css` паку → inline `:root` з токенами теми (`site_theme_style()`).
   Шрифти — лише `@font-face` зі `swap`, без preload (PSI).
3. **Своя верстка** — `themes/<key>/templates/` у паку: файл з тим самим шляхом, що в рушії (`site/home.html.twig`,
   `site/base.html.twig`, `site/catalog/product_show.html.twig`, `site/partials/_product_card.html.twig`…), перекриває шаблон рушія.
   Частково: `{% extends '@engine/site/home.html.twig' %}` і свої блоки — виправлення рушія в інших блоках доходять самі.
   Повністю: свій файл без `extends` (змінні контролера ті самі — список у коментарі-контракті на початку шаблону рушія).
   Власні partial-и паку — у `site/partials/`.

Критерій переходу на рівень 3: треба змінити **розмітку** (прибрати/додати/переставити секцію, інша шапка), а не вигляд —
коли `theme.css` починає воювати з HTML (`display:none`, `order`, абсолютне позиціонування). Потрібні **інші дані** (новий блок,
пошук, таблиця пропозицій) — це PR у рушій у будь-якій моделі, тема не допоможе.

## Живий дизайн під токеном (`theme:write`, 20.10.2026)

Четвертий, оперативний рівень (рішення власника 20.10.2026 «як у сервісних сайтах», повний опис — `docs/admin-api.md` «Дизайн під
токеном»): менеджер або Claude Code з персональним токеном `theme:write` пишуть `site/**.twig` і `theme.css` через REST
`/api/admin/v1/theme` чи MCP `theme_*` — **одразу на сайті**, без деплою паку. Файли — `var/share/theme` (том `share`, переживає
релізи): `templates/site/**` Twig бере першими (`App\Site\Theme\Live\LiveThemeLoader`: live > пак > рушій; ключ кешу — хеш вмісту,
тож компільований кеш скидати не треба), `public/theme.css` підключає `base.html.twig` після стилів рушія, паку й токенів теми
(`site_theme_live_css()` → `/theme-live/theme.css?v=…`, віддає `LiveCssController`). Правила ті самі, що для теми паку (`site/**`,
заборонена зона `StoreTemplates::FORBIDDEN`), плюс Twig-страж з явним allowlist (`ThemeTemplateGuard` + `LiveThemePolicy`: лише
перелічені теги, фільтри, функції, тести й імена властивостей; з `app` — лише `app.request.locale|pathInfo|uri|query.get|…`, `app.user`,
`app.flashes`; без `app.request.server`, `_context`, `attribute()`, `render()`/`controller()`, `include_only()`, `source`, `dump`,
`constant` поза enum-ами, рядків-callable у `filter/map/sort`; кожен шаблон рушія `site/**` поза забороненою зоною проходить стража як є),
рендер ключових сторінок після запису (`ThemeSmoke`) і страж форм кошика/checkout (CSRF, `action`, `key`/`price`), один запис за раз
(блокування на магазин). Історія до 20 версій, `revert`, `delete`. Нова функція чи властивість у шаблоні рушія → додати до `LiveThemePolicy`
(тест `ThemeTemplateGuardTest::testEveryOverridableEngineTemplatePasses` нагадає). Живий `site/solution/index.html.twig` перевіряється
рендером лише коли в БД є рішення (`site_solutions` без них не потрапляє до ключових сторінок).

Живий рівень — для швидких правок і експериментів; **сталий дизайн переносять у пак** (`theme_get` віддає текст → `themes/<key>/templates/`
→ `bin/store validate` → деплой → `delete` живого перекриття), бо в git і в бекап `bin/store backup` живі файли не потрапляють.
Жива копія `site/home.html.twig` з `{% extends '@engine/site/home.html.twig' %}` перекриває й версію паку (пак не має свого namespace).

## Як це працює в рушії

| Що | Де | Механізм |
|---|---|---|
| Базові шаблони | `templates/site/**` | звичайний головний Twig-namespace **і** `@engine/…` (`config/packages/twig.yaml`, `twig.paths`) |
| Тема паку | `themes/<key>/templates/**` паку → `templates/store/` образу | `bin/store deploy` копіює теку в контекст збірки (у `public/themes/<key>` вона не потрапляє); `App\Site\Theme\Templates\StoreTemplatesPass` ставить `templates/store` **першою** в головному namespace (`FilesystemLoader::prependPath`) — лише коли тека існує, бо `addPath` на неіснуючу теку кидає `LoaderError`; тому не `twig.paths` з параметром |
| Контролери | `render('site/…')`, 30 викликів | не змінюються: `templates/store/site/home.html.twig` і є `site/home.html.twig` |
| Перевірка до збірки | `bin/store validate <пак>` (`_validate.py`, без PHP) | лише `site/**`, лише `*.twig`, заборонена зона — помилка; файл без відповідника в рушії — попередження (одрук); `extends` самого себе без `@engine` — помилка (рекурсія); маніфест `theme.yaml`; чутливі зони повної копії `base` — голосне попередження |
| Перевірка в збірці | `docker/php/Dockerfile.prod` | `cache:clear` компілює контейнер (pass перевіряє теку), `lint:twig templates` — зламаний Twig валить збірку образу, не прод |
| Перевірка кандидата | `bin/store.d/_remote.sh` | `store:validate` (тема) → `store:smoke` (ключові сторінки кожною мовою через ядро з живою БД) → health; провал = реліз не перемикається |
| Запобіжник | `docker/nginx/{store,prod}.conf` | `location ~ \.twig$ { return 404; }` — Twig у `public/` ніколи не віддається сирцем |
| Локально | `templates/store/` у робочій копії (git-ignored) | покласти туди `themes/<key>/templates/**` паку — dev-ядро перекомпілюється саме (`FileExistenceResource`/`DirectoryResource`) |

Дешевий шлях лишається: пак без `themes/<key>/templates/` = сьогоднішня поведінка (тека не створюється, лоадер нічого не додає,
`pack_hash` той самий). `demo`, `partner-shop`, `store-template` не змінюються.

## Контракт (`template_contract: 1`)

Контракт — те, на що може спиратися тема паку і що рушій змінює лише адитивно в межах мажорної версії (нове додається;
перейменування або видалення — підняття версії контракту, запис тут у розділі «Зміни контракту», валідатор попереджає про
тему зі старішим `contract`).

**Блоки `site/base.html.twig`:** `title`, `metaDescription`, `ogType`, `ogImage`, `schema`, `head`, `bodyClass`, `header`,
`header_actions`, `nav`, `body`, `footer`. `bodyClass` дописується в `<body class="theme-<key>…">` без пробілу: вміст починається з пробілу
(`{% block bodyClass %} page-x{% endblock %}`), інакше клас теми склеїться з класом сторінки і тема не застосується. Шапка й футер — partial-и: `header` виводить `site/partials/_header.html.twig`,
якому передаються готові HTML блоків `header_actions` (`_header_actions.html.twig`: телефон, кабінет, кошик, бургер) і `nav`
(`_nav.html.twig`: рядок сторінок і панель категорій); `footer` — `_footer.html.twig`. Типове розширення в темі:

```twig
{% extends '@engine/site/base.html.twig' %}
{% block header_actions %}{{ parent() }}<a class="header-link" href="{{ path('site_account') }}#wishlist">Обране</a>{% endblock %}
```

**Partial-и** `site/partials/_*.html.twig`: у коментарі на початку кожного — перелік вхідних змінних, функцій і стабільних класів
(`_header`, `_header_actions`, `_nav`, `_footer`, `_product_card`, `_support`, `_add_to_cart`, `_lead_form`…). Partial без блоків перекривається
лише цілим файлом (`theme new --copy`).

**Змінні контролерів:** у коментарі-контракті на першому рядку кожного публічного шаблону рушія («Контракт теми … Змінні …»):
`site/home` (categories, tiles, cards, cards_featured, analytics_products, analytics_prices), `site/catalog/category_index`
(category, tr, crumbs, children, result, cards, item_list, facets, params, sorts, pages, prev_url, next_url, noindex, alternates, letters — A–Z за `app.catalog_letters`…),
`site/catalog/product_show` (product, tr, view, options, seo, schema_brand, schema_offers), `site/catalog/search` (q, result, cards, facets, params, sorts, pages…; завжди noindex), `site/cart/index` (cart, totals),
`site/checkout/index` (totals, data, errors, shippingMethods, paymentMethods, np), `site/blog/*` (posts, page, pages | post, tr),
`site/page/show` (page, tr; адреса — `path('site_page', {slug})`: типово `/{slug}/` у корені, не `/page/…`, docs/store-pack.md), `site/solution/*` (groups | solution, tr, options, related; docs/solutions.md), `site/order/*` (order, noindex). Глобальні: `app`, `site_name`, `powered_by`.

**Twig-функції:** `site_theme()`, `site_theme_text()`, `site_theme_style()`, `site_asset()`, `site_cart_count()`, `site_nav_categories()`,
`site_nav_pages()`, `site_has_solutions()`, `site_url()`, `tr()`, `site_alternates()`, `site_hreflang()`, `site_locales()`, `site_default_locale()`,
`site_og_locale()`, `site_home_seo()`, `gmb_reviews()`/`gmb_rating_badge()`/`gmb_aggregate_rating()` (віджет gbp-manager, docs/reviews.md; на головній — блок `store_reviews`; без аддона `gmb-reviews` — порожньо, docs/addons.md), `analytics_*()`, `imagine_filter`, `path()/url()`, `trans`.

**Стабільні CSS-класи** (`public/assets/site.css`, не перейменовуються без підняття контракту): `container`, `btn`, `btn-primary`,
`btn-outline`, `btn-small`, `site-header`, `header-bar`, `brand`, `brand-logo`, `brand-text`, `brand-name`, `brand-tagline`, `header-actions`,
`header-link`, `cart-link`, `cart-count`, `nav-toggle`, `nav-toggle-btn`, `nav-bar`, `nav-inline`, `site-nav`, `nav`, `nav-secondary`, `crumbs`,
`section`, `section-head`, `hero`, `hero-body`, `hero-text`, `hero-actions`, `tiles`, `tile`, `cards`, `product-grid`, `card`, `thumb`, `price`,
`old-price`, `support`, `listing`, `facets`, `toolbar`, `pager`, `az`, `product`, `gallery`, `buy-box`, `add-to-cart`, `product-perks`,
`cart-actions`, `checkout-layout`, `checkout-summary`, `site-footer`, `footer-grid`, `footer-title`, `footer-bottom`, `footer-powered`,
`footer-license`, `lang`, `visually-hidden`.
`theme.css` паків спирається на них (legacyshop переписує `.site-header`, `.tile`, `.card .price`), тож перейменування класу в рушії
ламає пак тихо — класи частина контракту.

## Заборонена зона і чутливі зони

**Заборонено перекривати** (помилка валідатора й компіляції контейнера): `site/account/` (кабінет: `no-store`, `noindex`,
Referrer-Policy, токени листів), `site/partials/_schema` (JSON-LD макроси), `site/sitemap` (sitemap), `site/page/license` (сторінка
ліцензії платформи: текст у `templates/license/` і перелік компонентів лишаються такими, як у рушії). Листи, адмінка, `bundles/`
поза `site/` — також ні. Символьні посилання в `themes/<key>/` (файл чи тека) — помилка валідатора, pass-у і `deploy.sh`:
`site/account -> ../_acc` обійшов би заборонену зону (обхід тек лінки не розкриває, а `cp -a`/`tar`/`COPY` їх зберігають і Twig
резолвить). Клієнтського PHP у паку нема (ADR v2): потрібна функція — PR у рушій.

Межа захисту: заборонена зона береже лише **власні шаблони** кабінету, schema й sitemap. Обгортку сторінок кабінету — `base`,
шапку, футер, partial-и — тема паку перекриває як і для решти сайту, тож `/account/*` рендериться в темі паку. Саме тому чутливі
зони `base` (нижче) важливі й для кабінету: копія `base` без `<meta name="referrer">`, без `{% block head %}` або з
`app.user` у шапці зламає правила кабінету на всіх його сторінках, хоч `site/account/**` і не чіпали.

**Чутливі зони** `site/base.html.twig` — повна копія без них працює, але тихо втрачає аналітику, SEO чи правило власника,
тому валідатор попереджає голосно, а `PackRenderTest` перевіряє наявність на фікстурі: `analytics_head()`, `analytics_body_start()`,
`analytics_body_end()`, `schema.website()`, `rel="canonical"` і цикл `hreflang`, `<meta name="referrer">` для `/account`,
`{% block head %}`, `site_theme_style()`, лого в шапці (`brand-logo`; правило власника 09.10.2026), перемикач мов текстом
UA/RU/EN (`class="lang"`, `uk → UA`). Після зміни дизайну на проді — `/analytics-healthcheck`.

## Команда `bin/store theme`

```
bin/store theme list                         які шаблони рушія можна перекрити і які блоки має кожен
bin/store theme new <key|тека> [шаблон…]     заготовки themes/<key>/templates/<шаблон> як {% extends '@engine/…' %} з усіма
                                             блоками = parent() (типово site/base.html.twig і site/home.html.twig),
                                             themes/<key>/theme.css (якщо нема) і маніфест themes/<key>/theme.yaml
bin/store theme new <key> --copy <шаблон>…   повна копія шаблону рушія (своя верстка з нуля; виправлення рушія сюди не доходять
                                             самі — їх приносить theme update)
bin/store theme status <key|тека>            таблиця по файлах теми: клас кожного (нижче), підсумок; --json для скриптів
bin/store theme diff <key|тека>              що змінилось у рушії в перекритих шаблонах з версії engine: маніфесту — файл, рядки, блоки
bin/store theme diff <key|тека> <шаблон>     3-way злиття копії (копія паку, база на момент копії, база зараз) через
                                             git merge-file -p у stdout; нічого не пише. Код 0 — чисто (вивід можна зберегти
                                             в копію), інакше кількість конфліктів і маркери у виводі
bin/store theme update <key|тека>            застосувати лише клас auto (база змінилась, копія ні): копія = нова база,
                                             engine і хеші в маніфесті оновлено; конфліктні лишаються з підказкою diff. --dry-run;
                                             --ack-extends знімає нагадування «перевірте блоки» із заготовок
bin/store theme prune <key|тека>             прибрати з копії файли, ідентичні поточній базі (fallback рушія їх підхопить),
                                             маніфест оновлено. --dry-run
--force                                      переписати наявні файли теми
```

Маніфест `themes/<key>/theme.yaml` (пишуть `theme new/update/prune`, читають `status/diff` і валідатор, руками не правити):
`format: 1`, `key`, `from: engine`, `engine` (тег/sha рушія на момент копії), `contract`, `created`, `files` (шлях → sha256
базового файлу рушія на момент копії). Базу на момент копії зберігати не треба: `git -C <рушій> show <engine>:templates/<шлях>`
її відтворює; якщо хеш у маніфесті не з того коміту (маніфест оновлювали окремо), та сама версія шукається в історії файлу.
Службовий заголовок `{# Тема … #}`, який `theme new` пише в копію, при порівнянні не враховується.

Класи `theme status` (для кожного файлу три версії: B0 — база на момент копії, B1 — база зараз, C — копія):

| клас | умова | що робити |
|---|---|---|
| `unchanged` | B1 = B0 | нічого |
| `auto` | B1 ≠ B0, C = B0 | `theme update` перепише копію на B1 |
| `conflict` | B1 ≠ B0, C ≠ B0 | `theme diff <пак> <файл>` → злити руками → `theme update` |
| `extends` | заготовка `{% extends '@engine/…' %}` | нічого: зміни бази підхоплюються; після зміни бази — `theme diff` (блоки), потім `theme update --ack-extends` |
| `prunable` | C = B1 | `theme prune` прибере, fallback рушія підхопить |
| `removed` | у рушії файлу більше нема | застаріло: тепер це власний шаблон паку, перевірте, хто його include-ить |
| `added` | новий шаблон у базі з версії engine | додати? `theme new <пак> --copy <шлях>` або лишити fallback |
| `untracked` | є в паку й рушії, нема в маніфесті | зроблено не через `theme new`; лише `prune`, якщо ідентичний |
| `own` | власний partial паку | нічого |
| `missing` | у маніфесті є, файлу нема | `theme update` прибере запис |

`theme update` не піднімає `engine` у маніфесті, поки є хоч один `conflict` або заготовка зі зміненою базою без `--ack-extends`:
їм потрібна стара база для 3-way і для нагадування. Маніфест із зіпсованим YAML — попередження і робота як без маніфесту
(`update`/`prune` перепишуть його); зіпсований `store.yaml` — помилка з назвою файлу.
Той самий підсумок («тема відстала від рушія на N файлів, M конфліктів») друкують `bin/store deploy` після validate і `bin/store doctor`,
коли пак є локально — попередження, не блокер. Маніфест без поля чи без маніфесту — попередження; пак без теми — «теми нема», код 0.

Порядок: `bin/store theme new <key>` → правити `themes/<key>/templates/**` і `theme.css` у паку → `bin/store validate <пак>` →
commit → `bin/store deploy <key> <тег>` (збірка: pass + `lint:twig`; кандидат: `store:validate`, `store:smoke`, health) →
`/analytics-healthcheck`. Після оновлення рушія: `bin/store theme status <key>` → `theme update` (auto) → `theme diff <key> <файл>`
для конфліктів (зберігати вивід у копію лише при коді 0) → `theme prune` для копій, які так і не правили.

## Тести в CI рушія

- `tests/Site/Theme/Templates/StoreTemplatesPassTest` — pass: нема теки → лоадер не чіпаємо; фікстура → `prependPath`; порушення → компіляція падає.
- `tests/Site/Theme/Templates/TemplateContractTest` — блоки base, коментарі partial-ів і сторінок, одна версія контракту й ті самі
  правила в PHP, `_validate.py` і цьому файлі, маніфест фікстури.
- `tests/Functional/Site/PackRenderTest` — у test `app.store_templates_dir` вказує на фікстурну тему `tests/store/pack-demo/themes/demo/templates`
  (тобто **кожен** функціональний тест сайту рендерить крізь тему паку на реальному шляху пасу): усі публічні маршрути × мови зі
  `strict_variables`, кошик і checkout з товаром, «дякуємо» із сесії і «прийнято» (410), перекриття й чутливі зони на кожній
  сторінці; без перекриття (`@engine`) база рендериться ідентично — «classic» = нинішній вигляд.
- `tests/Functional/Site/StoreSmokeCommandTest` — `store:smoke` на фікстурі каталогу.
- `tests/store/registry-pack-test.sh` — `theme list|new|diff`, `--copy`, маніфест, заборонена зона, валідатор; `theme status|update|prune|diff <файл>` на окремому клоні рушія з контрольованими комітами (усі класи, update з конфліктом, prune, маніфест без поля, пак без теми); `lint:twig templates` у CI лінтить рушій.

Паки перевіряє їхній деплой (збірка образу + кандидат), не CI рушія: у реєстрі `stores.yaml` є demo, partner-shop і архівний legacyshop, але локальних копій паків у CI нема (приватні репо), тож `bin/store validate --all` їх пропускає з попередженням.

## Зміни контракту

- **1** (11.10.2026, PR «E14 теми в паку»): перші блоки `header`, `header_actions`, `nav`, `footer`; шапка й футер у partial-ах;
  `@engine`; `templates/store/`; маніфест `theme.yaml`.
- **1, адитивно** (11.10.2026, PR «ліцензія платформи»): у `_footer.html.twig` рядок «Платформа Symfony ecommerce LuxSite
  © luxsite.agency, використовується за ліцензією» + посилання `site_license` (замість рядка `powered_by`; з 19.10.2026 — за налаштуванням, нижче;
  `powered_by` лишає лише посилання на luxsite.agency і meta generator); ключ перекладу `site.footer.powered_by` прибрано. Тема з
  повною копією `_footer` має перенести рядок — `bin/store theme update <key>` (3-way злиття) або `theme diff`. Заборонена зона: `site/page/license`.
- **1, адитивно** (17.10.2026, PR «Apache 2.0»): тексти рядка підвалу й `/license` — під Apache 2.0 («відкритий код під ліцензією Apache 2.0,
  розробники платформи: LuxSite»), розмітка `_footer` та сама. У `site/base.html.twig` meta generator обгорнуто в блок `meta_generator`:
  тема може його перекрити.
- **1, адитивно** (19.10.2026, PR «рядок платформи у футері»): рядок платформи винесено в `site/partials/_platform_credit.html.twig`
  (функція `site_platform_credit()`: `full`|`short`|`none`), `_footer` його include-ить. Режим — налаштування «Рядок платформи у
  футері» (`site.platform_credit`, «Налаштування сайту», лише адміністратор): «Повний» — як після Apache 2.0, з посиланням «Ліцензія»;
  «Коротко» (типове) — «Ecommerce LuxSite» (однаково uk/ru/en; з посиланням на luxsite.agency, якщо `powered_by`) без посилання на `/license`; «Не показувати» — рядка нема. `/license`
  лишається за адресою (noindex). На `.footer-powered` — `data-platform-credit`.
  Тема з повною копією `_footer` має замінити свій рядок на `{% include 'site/partials/_platform_credit.html.twig' %}`, інакше
  налаштування на неї не діє: `bin/store theme update <key>` / `theme diff`. Паки demo і legacyshop копії `_footer` не мають.
- **1, адитивно** (19.10.2026, PR «підпис рушія»): блок `meta_generator` у `site/base.html.twig` порожній — meta generator,
  коментар після `<!DOCTYPE html>`, атрибут `data-engine` на `<html>` і заголовок `X-Powered-By` додає `App\Core\Shared\Http\ResponseMarker`
  до кожної HTML-відповіді сайту й адмінки (200 і 4xx), також коли тема перекрила `base.html.twig`; від `powered_by` і футера не залежить.
  Тема може вивести в блоці свій generator — підпис рушія додасться поруч. Назву рушія в теми не копіювати.
- **1, адитивно** (20.10.2026, PR «Рішення»): нові шаблони `site/solution/index`, `site/solution/show` (блоки `solution_*`),
  `site/solution/_card`; у `site/catalog/product_show` — змінна `solutions` і блок `.product-solutions`; у `_nav` і `_footer` —
  пункт «Рішення» за `site_has_solutions()`; на формі `_price_request` — `id="price-request"` (посилання «Запитати ціну» з рішення).
  Тема з повною копією `_nav`/`_footer` пункт не отримає — `bin/store theme diff`. Докладно — `docs/solutions.md`.
- **1, адитивно** (20.10.2026, PR «заявка /request»): новий partial `site/partials/_lead_form.html.twig` (загальна заявка без товару;
  змінні `context`, `choices`, `source_url`, `show_title` — контракт у коментарі файлу, `docs/lead-form.md`), класи `form.lead-form`, `.lf-hp`.
  Існуючих partial-ів і блоків не змінено.
- **1, адитивно** (20.10.2026, PR «живий дизайн»): у `site/base.html.twig` після `site_theme_style()` — `{% set live_css = site_theme_live_css() %}`
  і `<link>` на `/theme-live/theme.css?v=…`, коли живий CSS є (розділ «Живий дизайн під токеном»). Тема з повною копією `base` без цього
  рядка живого CSS не побачить — `bin/store theme diff`. Нова функція `site_theme_live_css()`; шлях `/theme-live/` зарезервований.
