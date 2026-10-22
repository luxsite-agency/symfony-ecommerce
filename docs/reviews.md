# Відгуки на товари

Рішення власника 13.10.2026: відгуки пишуть покупці з кабінету (будь-який спосіб входу; Google — швидкий шлях, контракт з
`feat/google-sign-in` — внутрішня документація). Один відгук на товар від покупця, премодерація, відповідь
магазину, зірки на картці й у лістингу, schema.org лише зі справжніх схвалених.

## Параметри паку (`config/parameters.yaml`)
| параметр | типово | що |
|---|---|---|
| `app.reviews.enabled` | `true` | `false` — на сайті ні форми, ні списку, ні зірок, ні schema; маршрути `/account/reviews*` — 404; адмінка бачить наявні |
| `app.reviews.premoderation` | `true` | новий і відредагований відгук чекає схвалення; `false` — одразу опубліковано |
| `app.reviews.require_google` | `false` | писати можуть лише покупці, прив'язані до Google (демо-акаунти в демо-режимі — завжди) |
| `app.reviews.daily_limit` | `5` | нових відгуків на покупця за 24 год (редагування не рахується) |
| `app.reviews.rate_limit_ip` / `_interval` | `20` / `1 hour` | POST-и подання з IP (limiter `review_submit_ip`) |
| `app.reviews.page_size` | `10` | відгуків на сторінку картки (`?reviews_page=N`, canonical без параметра) |
| `app.gbp_reviews.token` | `''` | віджет відгуків gbp-manager (нижче); порожній — вимкнено |
| `app.gbp_reviews.base` | `https://gbp-manager.app` | адреса gbp-manager |

## Як це працює
- **Сутність** `Core\Catalog\Entity\ProductReview` (`catalog_product_review`): product, customer (CASCADE), rating 1–5, text
  (plain text, до 2000), locale, status `pending|approved|rejected`, `verified_purchase`, `demo`, відповідь магазину
  (`reply_text`, `reply_by`, `reply_user_id`, `replied_at`), хто/коли модерував, created/updated. `UNIQUE(product, customer)`.
- **Текст не правимо**: `ReviewText::problem()` відхиляє HTML-теги/сутності й посилання (помилка форми), зберігається текст як
  є після `trim` і CRLF→LF. На сайті — Twig-екранування + `nl2br`.
- **Хто пише** — `ProductReviews::access()`: `Disabled` (вимкнено), `LoginRequired` (гість), `GoogleRequired`
  (`require_google` і не прив'язаний; демо — виняток), `Allowed`.
- **Подання** `ProductReviews::submit()`: свій відгук на товар редагується, не дублюється; `verified_purchase` — є виконане
  (`completed`) замовлення покупця з варіантом товару на момент збереження; `resubmit()` — назад на модерацію (або одразу approved
  без премодерації). Демо-покупець (`DemoAccounts`) — `demo = true`.
- **Агрегат** `catalog_product_rating` (`ProductRatings`, DBAL, без сутності — у `schema_filter`): рядок на товар зі схваленими;
  `review_count/rating_sum` — усі схвалені (зірки на картці, у лістингу, в обраному), `real_count/real_sum` — без демо (schema.org).
  Перераховується після кожної зміни стану; картки читають його живцем (`ProductCards`, четвертий запит), кешу карток нема —
  інвалідувати нічого (кешуються лише id хітів `CatalogQuery`).
- **Картка товару**: `ProductReviewsBlock` → partial `site/partials/_product_reviews.html.twig` (`#reviews`): агрегат, схвалені
  сторінками (нові зверху, «Ім'я П.» — `AuthorName::short`, «Купив цей товар», «Демо-відгук», відповідь магазину), далі
  inline-форма покупцю або «Увійти, щоб залишити відгук» → `/account/reviews/new?product=<id>` (захищений маршрут: після входу
  покупець повертається на форму) і кнопка Google, коли `GoogleSignIn::isAvailable()`. Гість на картці сесію не стартує.
- **schema.org**: `ReviewSchema` додає в `Product` `aggregateRating` (`ratingValue` — середня справжніх, `reviewCount`) і
  `review` (останні 10 справжніх схвалених); без них — нічого.
- **Відгуки про магазин (gbp-manager)** — рішення власника 18.10.2026: загальна оцінка компанії (рейтинг + кількість) у
  кожному блоці відгуків, джерело — налаштування віджета в gbp-manager («Яку оцінку показувати»: Google або Google + відгуки
  сайту); `aggregateRating` магазину на сайті один і з тими ж числами. Пакет `luxsite/gmb-reviews-bundle` (+ core) — **необов'язковий**
  (аддон `gmb-reviews`, docs/addons.md): копія з репо gmb-manager у `packages/` (composer path, оновлення — `local/sync-gmb-reviews.sh`),
  у `composer.json` рушія — лише `suggest`. Пак вмикає його `addons: [gmb-reviews]` у `store.yaml` і задає `app.gbp_reviews.token`
  (gbp-manager → картка → «Відгуки на сайті»); без пакета блоків нема, решта відгуків працює. Тоді:
  - головна — блок `store_reviews` (`gmb_reviews()`, тема `site` — кольори з токенів теми) з відгуками й оцінкою, без власної розмітки;
  - `OnlineStore` головної (`OrganizationSchema`) отримує `aggregateRating` з тих самих чисел — єдиний aggregateRating магазину;
  - картка товару — у `#reviews` рядок «Оцінка магазину: 4.9 ★★★★★ · 102 відгуки» (`gmb_rating_badge()`) без розмітки. `Product.aggregateRating`
    лишається власним (відгуки на товар ≠ оцінка компанії; LocalBusiness/OnlineStore на картці товару не дублюємо).
  Оцінку або мікророзмітку вимкнено у віджеті — рядка/розмітки нема. gbp-manager недоступний — 7 діб показується остання
  відповідь з кешу (`cache.gbp_reviews`), далі блок зникає, сторінка працює. Тема паку, що замінює `body` головної цілком,
  мусить лишити `{% block store_reviews %}`, інакше розмітка буде без видимої оцінки. Тести — `GbpRatingTest` (з пакетом), `GmbReviewsOptionalTest` (в обох станах).
- **Кабінет**: «Мої відгуки» (`/account/reviews`: статус, редагування), форма (`/account/reviews/new`, `/account/reviews/{id}`).
  Експорт даних покупця містить відгуки; видалення акаунта стирає їх (`AccountDeletion` → `ProductReviews::deleteAllOf`).
- **Адмінка** «Продажі → Відгуки» (`ProductReviewCrudController`): фільтри (стан, оцінка, купив, демо), масове схвалення/відхилення,
  сторінка перегляду зі схвалити/відхилити й відповіддю магазину, видалення (перераховує агрегат). Текст покупця не редагується.
  Дашборд: віджет «Нові відгуки на модерації» з лічильником (`[data-reviews-count]`).
- **Демо** (`DemoCustomers::seedRetailExtras`): 3 схвалені відгуки роздрібного демо-покупця з `demo = true` і текстом, що починається
  з «Демо-відгук» — на сторінці з позначкою, у schema.org не йдуть. Нічне скидання видаляє їх каскадом з покупцем і перераховує агрегати.

## Google
Інтерфейс `Core\Customer\Auth\GoogleSignIn` (`isAvailable`, `isLinked`, `startPath`), реалізація —
`Site\Account\Google\GoogleSignInAdapter`: ключі є → кнопка; `Customer::isGoogleLinked()`; шлях старту —
`site_account_google_start?next=` гостю, покупцю без прив'язки — сторінка прив'язки (`docs/google-sign-in.md`).

## Тести
`tests/Core/Catalog/Review/*` (текст, ім'я), `tests/Integration/Core/Catalog/Review/ProductReviewsTest` (доступ, один на товар,
ліміт, купив, агрегат, стирання), `tests/Functional/Site/ProductReviewsTest` (гість без cookie, подання → модерація → картка/лістинг/schema,
XSS і посилання, демо, пагінація, відповідь), `tests/Functional/Admin/ProductReviewCrudTest` (дашборд, фільтр, схвалення, відповідь, CSRF, масово).
