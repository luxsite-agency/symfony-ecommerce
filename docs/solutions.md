# Рішення (Solution)

Розділ для B2B-магазинів (складське обладнання, меблі, інженерія): **задача клієнта → кілька товарів каталогу як варіанти →
розрахунок**. Перший клієнт — B2B-каталог (стелажі, конвеєри, сортери), але модель загальна. Магазин без опублікованих рішень
виглядає як раніше: пункту меню «Рішення», сторінок і блоку на картці товару нема.

## Модель даних

| Таблиця | Що | Ключові обмеження |
|---|---|---|
| `content_solution` | рішення: `published`, `position`, `group_id` (SET NULL), `cover_id` (Media, SET NULL), `created_at`, `updated_at` | індекс `(published, position)` |
| `content_solution_translation` | мовою: `title`, `slug`, `intro`, `task`, `body` (HTML, необов'язково), `meta_title`, `meta_description`, JSON `loads`, `industries` (рядки), `faq` (`[{q, a}]`) | unique `(solution_id, locale)`, unique `(locale, slug)` |
| `content_solution_group` (+ `_translation`) | групи списку («Системи зберігання»): `position`, назва мовами | unique `(group_id, locale)` |
| `content_solution_option` | варіант: `solution_id` (CASCADE), `product_id` (CASCADE), `position` | unique `(solution_id, product_id)`, індекс `product_id` |
| `content_solution_option_translation` | мовою: JSON `pros` (переваги), `note` (абзац) | unique `(option_id, locale)` |
| `content_solution_related` | «Інші рішення», задані вручну | PK `(solution_id, related_id)` |

- **Ціни не зберігаються.** Варіант показує ціну товару так само, як картка в лістингу (`ProductCardsByIds`: група покупця, «від»,
  `price_mode on_request` → «Ціна за запитом» і кнопка «Запитати ціну» → форма товару `#price-request`).
- **Видиме мовою L** = `published` + переклад мовою L з назвою й слагом. Ті самі правила для 404, hreflang, sitemap, меню й блоку на
  картці товару (`SolutionFinder`, `SiteUrlGenerator::solution`). Слаг порожній — формує `SlugListener` з назви.
- Порядок варіантів — `position`, далі порядок додавання (`Solution::getSortedOptions`). Товар, невидимий у каталозі цією мовою
  (неактивний, без перекладу), на сторінці пропускається.
- «Інші рішення» (до 3): спершу задані вручну, далі з тієї ж групи, далі решта.

## Маршрути

| Маршрут | Шлях | Що |
|---|---|---|
| `site_solutions` | `/solutions/`, `/ru/solutions/`, `/en/solutions/` | список за групами (без групи — останньою); нема видимих — 404 |
| `site_solution` | `/solutions/{slug}/`, `/ru/solutions/{slug}/` | сторінка рішення; неопубліковане чи без перекладу — 404 |

Префікс `solutions` однаковий для всіх мов (мова за замовчуванням — без префікса мови), слаг перекладний і унікальний у межах
мови. Без кінцевого слеша — 301 на адресу зі слешем. Сегмент `solutions` зарезервований від слагів каталогу автоматично (`RouterReservedPathSegments`). Обидва маршрути — у
`sitemap.xml` (список — лише мовами, де є рішення) і в `bin/store smoke`.

## Шаблони (контракт тем, `docs/themes.md`)

- `site/solution/index` — `groups` (`[{group: ?SolutionGroup, solutions: list<Solution>}]`).
- `site/solution/show` — `solution`, `tr`, `options` (`[{option, tr: ?SolutionOptionTranslation, card}]`, `card` — рядок
  `ProductCards`), `related`. Блоки: `solution_hero`, `solution_task`, `solution_options`, `solution_after_options` (порожній —
  місце для «Як ми працюємо» і цифр компанії з паку), `solution_body`, `solution_request`, `solution_faq`, `solution_related`.
- `site/solution/_card` — картка рішення (список, «Інші рішення», блок на картці товару).
- `site/catalog/product_show` — нова змінна `solutions` і блок `.product-solutions` («Рішення з цим продуктом»).
- Twig: `site_has_solutions()` — пункт меню в `_nav` і `_footer`. Мови з рішеннями — один ключ кешу (`App\Core\Content\SolutionLocales`, TTL 1 год): скидається після змін через ORM (postFlush) і в `demo:seed --purge`; hreflang списку — лише цими мовами.
- schema.org: `BreadcrumbList`, `ItemList` (адреси товарів-варіантів), `FAQPage`, якщо FAQ заповнено.

## Адмінка

«Контент → Рішення» і «Контент → Групи рішень» (права як у сторінок — ROLE_MANAGER). Контент мовами — однією формою, як у
Page; «Вантажі», «Галузі», «Переваги» — рядок на пункт; FAQ — блоки через порожній рядок (перший рядок — питання). Варіанти —
колекція: товар з пошуком на сервері (`admin_solution_products`, назва мовою магазину або SKU), порядок, переваги й абзац мовами.
Усі підписи — ключі `translations/admin+intl-icu.*.yaml` (`solution.*`, `solution_group.*`, `solution_form.*`).

## Як наповнити

1. Групи рішень (необов'язково): «Системи зберігання», «Процеси складу»…
2. Рішення: назва, вступ, задача, вантажі, галузі, обкладинка з «Медіатеки», варіанти-товари з перевагами; FAQ — за потреби.
3. «Опубліковано» → пункт «Рішення» з'являється в меню, рішення — у sitemap і на картках товарів-варіантів.

Демо: `bin/console app:demo:seed` додає одне рішення «Демо: палетне зберігання» з демо-стелажів; `--purge` прибирає.

## Далі (не в цьому PR)

- Форма «Розрахунок» на сторінці рішення (лід у CRM тим самим outbox, що «Запитати ціну») — окремий PR; зараз блок
  `solution_request` показує телефон і email магазину.
- Вкладення плану складу до запиту.
