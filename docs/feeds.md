# Товарні фіди: Google Merchant Center і Meta Commerce Manager

Рушій генерує два фіди кожною мовою магазину (`app.locales`): для Google (Shopping, Performance Max, динамічний
ремаркетинг Google Ads через Merchant Center) і для Meta (каталог Facebook/Instagram для Advantage+ catalog ads і
динамічного ремаркетингу). Формат обох — RSS 2.0 з простором імен `g:`.

## Адреси

| Канал | Адреса |
|---|---|
| Google Merchant Center | `https://<домен>/feeds/google-merchant-<мова>.xml` |
| Meta Commerce Manager | `https://<домен>/feeds/meta-<мова>.xml` |

Мова: `uk`, `ru`, `en` (те, що в `app.locales` магазину). Готові адреси для копіювання та час останньої
генерації показані в адмінці: **Налаштування сайту**, блок «Товарні фіди» під списком.

Файли лежать у `public/feeds/` (на магазинах — том `store-<key>_feeds`, у бекап не входить). nginx віддає їх як
статику; поки файлу немає, маршрут повертає 404.

## Коли оновлюються

- кожні 6 годин (`app.merchant_feed_interval`, розклад `App\Schedule`);
- після кожного імпорту каталогу;
- після `bin/store restore` (генерація ставиться в чергу `feeds`);
- вручну: `bin/console feed:merchant:generate` (усі мови й обидва канали; `--locale=uk` лише одна мова,
  `--async` поставити в чергу замість генерації в цьому процесі).

Генерує окремий воркер (`feed-worker`, черга `feeds`), один прохід по каталогу на мову пише обидва канали. Файл
замінюється атомарно: зовнішні системи бачать або старий фід, або новий цілком.

## Що у фіді

- **item = варіант товару.** `g:id` = SKU варіанта = `item_id` у подіях dataLayer (`view_item`, `add_to_cart`,
  `purchase`). За цим полем динамічний ремаркетинг знаходить товар у каталозі. Варіанти однієї картки мають спільний
  `g:item_group_id`.
- **Ціна** береться з тієї ж формули, що на сторінці товару (`Pricing::cardQuote`: пороги на мінімальній кількості,
  опції за замовчуванням, акції). Якщо є перекреслена ціна, то `g:price` = стара ціна, `g:sale_price` = ціна до сплати.
  Для акції з датою завершення додається `g:sale_price_effective_date`. Мінімальна кількість більша за 1: ціна
  вказується за мінімальну партію (`g:multipack` або `g:unit_pricing_measure`, лише Google).
- **Наявність:** «в наявності» лише тоді, коли залишок покриває мінімальну кількість (так само на сторінці).
  Під замовлення: у Google `backorder` з `availability_date`, у Meta `in stock` (Meta офіційно приймає лише
  `in stock` / `out of stock`).
- **Бренд:** бренд картки. Meta відхиляє товар без бренду, тому у фід Meta для карток без бренду йде значення
  налаштування «Бренд для товарів без бренду (фід Meta)», а якщо воно порожнє, то назва магазину. У Google товар без
  бренду й штрихкоду позначається `identifier_exists = no`.
- **Опис:** текст опису без HTML; порожній опис замінюється назвою товару. Обрізання: Google — назва 150 і опис 5000
  символів, Meta — 200 і 9999.
- **Фото:** спершу фото варіанта, потім фото картки: `g:image_link` і до 10 (Google) або 20 (Meta)
  `g:additional_image_link`.
- `g:product_type` — шлях основної категорії; `g:color` / `g:size` — з осей варіанта (`app.merchant_feed_axis_codes`);
  `g:gtin` — штрихкод з правильною контрольною цифрою; `g:mpn` — SKU, якщо є бренд.
- **Мітки для кампаній** `g:custom_label_0..4` (обидва канали), налаштовуються в `app.feed_custom_labels`:

  ```yaml
  app.feed_custom_labels:
      custom_label_0: top_category   # корінь шляху основної категорії
      custom_label_1: brand          # бренд картки (без бренду мітки немає)
      custom_label_2: sale           # app.feed_sale_labels.sale / .regular
  app.feed_sale_labels: { sale: 'sale', regular: 'regular' }
  ```

  Джерела: `top_category`, `category` (сама основна категорія), `brand`, `sale`. Магазин клієнта перевизначає мапу
  у своєму конфігу; `{}` вимикає мітки. Google дозволяє до 1000 унікальних значень на мітку, довжина значення до 100 символів.

**У фід не потрапляють:** вимкнені товари й варіанти, «ціна за запитом», послуги й товари під розрахунок, картки без
перекладу або слага цією мовою (бо немає сторінки), ціна 0.

## Підключення в Google Merchant Center

1. Merchant Center → **Products → Add products → Add products from a file** → «Enter a link to your file».
2. Адреса: `https://<домен>/feeds/google-merchant-<мова>.xml`; країна продажу й мова мають відповідати мові фіду
   (для `uk`: Україна, українська).
3. Розклад (**Scheduled fetch**): щодня, у нічний час. Фід оновлюється кожні 6 годин, частіше раз на добу Merchant не забирає.
4. Для кожної мови окреме джерело даних.
5. Після першої вибірки перевірити **Products → Needs attention**: найчастіше там «Mismatched price/availability»
   (кеш сторінки, ціна змінилась між вибіркою й переглядом сторінки) і «Missing image».

### Зв'язати з Google Ads

1. Merchant Center → **Settings → Apps and services** (або **Linked accounts**) → Google Ads → вказати ID акаунта Ads
   → надіслати запит.
2. Google Ads → **Tools → Data manager → Google Merchant Center** → прийняти запит.
3. Shopping / Performance Max: обрати Merchant Center як джерело товарів кампанії; групи товарів можна ділити за
   `custom_label_*`.
4. Динамічний ремаркетинг: Google Ads зіставляє `items[].item_id` (GA4, коли GA4 зв'язано з Ads) або `items[].id`
   (тег ремаркетингу Ads) з `g:id` фіду. У dataLayer магазину `item_id` уже дорівнює `g:id`. У GTM у тезі Google Ads
   Remarketing увімкнути «Send dynamic remarketing event data» з dataLayer і, якщо в акаунті кілька вертикалей,
   задати `google_business_vertical: retail`.

## Підключення в Meta Commerce Manager

1. Commerce Manager → **Catalog → Data sources → Add items → Data feed** → **Scheduled feed**.
2. Адреса: `https://<домен>/feeds/meta-<мова>.xml`; валюта — як у фіді (UAH); розклад — щодня (або щогодини, якщо
   залишки змінюються часто).
3. Режим оновлення — заміна (replace): товари, яких більше немає у фіді, Meta прибирає з каталогу.
4. Каталог зв'язати з пікселем (**Catalog → Events → Connect tracking**). Щоб працювали Advantage+ catalog ads і
   ретаргетинг, події пікселя `ViewContent`, `AddToCart` і `Purchase` мають передавати `content_ids` = `item_id` з
   dataLayer і `content_type: product`. Рушій сам шле лише `PageView`. Ці події треба налаштувати в GTM з
   ecommerce-подій dataLayer.
5. Перевірити **Catalog → Issues**: товари без фото Meta не показує.

## Джерела вимог (перевірено 08.10.2026)

- Google Merchant Center, специфікація даних товарів: https://support.google.com/merchants/answer/7052112
- `sale_price_effective_date`: https://support.google.com/merchants/answer/6324460
- `availability`: https://support.google.com/merchants/answer/6324448
- Google Ads, події й параметри динамічного ремаркетингу: https://support.google.com/google-ads/answer/7305793
- Google Ads, налаштування подій з `item_id` (GA4): https://support.google.com/google-ads/answer/7688538
- Meta, поля каталогу: https://developers.facebook.com/docs/commerce-platform/catalog/fields/
- Meta, специфікація даних товарів у Commerce Manager: https://www.facebook.com/business/help/120325381656392
