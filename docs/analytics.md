# Аналітика: GA4 ecommerce і Meta Pixel

Код: `src/Core/Analytics`. Налаштування: адмінка → Налаштування → «Аналітика» (`SettingKeys`).

## Одне джерело подій

Сторінки й контролери збирають події через `EcommerceEventBuilder` → `DataLayerCollector` (Twig `analytics_push`,
`DataLayerCollector::queue` для подій після POST → redirect). Перед `</body>` `DataLayerEventsProvider` виводить їх
JSON-ом у `<script type="application/json" data-analytics-events>` і пушить у `window.dataLayer`.

GA4-події: `view_item_list`, `select_item`, `view_item`, `add_to_cart`, `remove_from_cart`, `view_cart`,
`begin_checkout`, `add_shipping_info`, `add_payment_info`, `purchase` (один раз на замовлення, `PurchaseDedup`),
`generate_lead`. `item_id` = SKU варіанта, ціни з фасаду Pricing (те саме число, що на картці й у кошику).

Події, що мають відповідник у Meta, при push/queue отримують ключ `meta_pixel` (`MetaPixelEventMapper`):

| GA4 | Meta | Параметри |
|---|---|---|
| `view_item` | `ViewContent` | content_ids, content_type, contents, value, currency |
| `add_to_cart` | `AddToCart` | content_ids, content_type, contents, value, currency |
| `begin_checkout` | `InitiateCheckout` | + num_items |
| `purchase` | `Purchase` | + num_items, order_id |

```json
{"event": "view_item", "ecommerce": {"currency": "UAH", "value": 4400, "items": [{"item_id": "DOOR-B", "...": "..."}]},
 "meta_pixel": {"event": "ViewContent", "event_id": "viewcontent.3f9a0c1b2d4e5f60",
   "params": {"content_ids": ["DOOR-B"], "content_type": "product",
              "contents": [{"id": "DOOR-B", "quantity": 1, "item_price": 4400}], "currency": "UAH", "value": 4400}}}
```

- `content_ids` / `contents[].id` = SKU варіанта = `g:id` у фідах `meta-<locale>.xml`, тому `content_type` = `product`
  (не `product_group`). Так працюють Advantage+ catalog ads і динамічний ремаркетинг.
- `value`, `currency` такі самі, як у GA4: сума товарів без доставки. Для «ціни за запитом» value немає (у Purchase 0).
- Дробова кількість (метри, кг) йде одним рядком: `quantity: 1`, `item_price` = сума рядка.
- `event_id`: для Purchase це номер замовлення. Для решти подій сервер генерує унікальний id у момент push/queue.
  Коли з'явиться Conversions API, серверна подія має йти з тим самим `event_id` і тією ж назвою: для Purchase з
  обробника замовлення, для інших з того ж місця, де подію ставлять у `DataLayerCollector`.
- `view_item_list`, `select_item`, `view_cart`, `remove_from_cart`, `add_shipping_info`, `add_payment_info`,
  `generate_lead` у Meta не мапляться. Пошуку й списку бажань у рушії поки немає, тому немає й подій Search та
  AddToWishlist.

## Режим 1: піксель від рушія (id пікселя в налаштуваннях)

`FacebookPixelProvider` виводить офіційний сніпет (`init` + `PageView`), noscript-піксель і невеликий JS, який після
розбору DOM читає `data-analytics-events` і для кожного `meta_pixel` викликає
`fbq('track', event, params, {eventID: event_id})`.

Згода: усе це виводиться лише при `ConsentState::granted` (cookie `shop_consent=granted` або мова зі списку
«Згода на cookie: мови, де вона діє одразу»). Без згоди в HTML немає ні `fbevents.js`, ні noscript, ні подій.
Після «Прийняти» в банері піксель починає працювати з наступної сторінки.

Якщо задано і GTM, і id пікселя, рушій шле події пікселя сам. Тоді в GTM тегів Meta бути не повинно, інакше
події задвояться.

## Режим 2: піксель у GTM (id пікселя порожній, GTM задано)

Рушій не виводить `fbq` взагалі, у dataLayer залишаються ті самі `meta_pixel`. Налаштування в GTM:

1. **Змінні** (Data Layer Variable, версія 2): `DLV meta_pixel.event`, `DLV meta_pixel.event_id`,
   `DLV meta_pixel.params`.
2. **Тригер** «Meta ecommerce»: Custom Event, назва події (regex) `^(view_item|add_to_cart|begin_checkout|purchase)$`,
   умова `DLV meta_pixel.event` matches RegEx `^(ViewContent|AddToCart|InitiateCheckout|Purchase)$` (не «does not equal undefined»:
   після скидання `meta_pixel:null` така умова пропускає `null`, і тег викличе `fbq('track', null, …)`).
3. **Базовий тег** Meta Pixel (`init` + `PageView`) на All Pages (Initialization). Розширені налаштування згоди:
   «Require additional consent» → `ad_storage`. Рушій сам ставить `gtag('consent','default', …)` до GTM, а банер
   робить `consent update` і пушить подію `consent_update`.
4. **Тег подій** (Custom HTML, тригер «Meta ecommerce», та сама вимога `ad_storage`, Tag Sequencing: спершу базовий тег):

   ```html
   <script>
     fbq('track', {{DLV meta_pixel.event}}, {{DLV meta_pixel.params}}, {eventID: {{DLV meta_pixel.event_id}}});
   </script>
   ```

   Шаблон галереї «Facebook Pixel» теж підходить: Event Name = `{{DLV meta_pixel.event}}`,
   Event ID = `{{DLV meta_pixel.event_id}}`, Object Properties = змінна `{{DLV meta_pixel.params}}`.

Перед кожною подією з `ecommerce` рушій пушить `{ecommerce: null, meta_pixel: null}`, щоб GTM не злив параметри з
попередньою подією.

## Перевірка

- Meta Events Manager → Test events, або розширення Meta Pixel Helper: на картці товару ViewContent з
  `content_ids` = SKU обраного варіанта, у кошику після додавання AddToCart, на `/checkout/` InitiateCheckout,
  на «дякуємо» Purchase один раз. Після перезавантаження «дякуємо» повтору немає.
- Events Manager → каталог → «Event source match rate»: ID подій мають збігатися з товарами фіду.
- Доказ роботи — живий запит `facebook.com/tr?…&ev=…` з браузера зі згодою. Наявність коду в HTML доказом не є
  (правило `/analytics-healthcheck`).
- Тести: `tests/Core/Analytics/MetaPixelEventMapperTest.php`, `tests/Functional/Site/MetaPixelEventsTest.php`.

## Обмеження (рев'ю #47)

- Purchase не дійде в Meta, якщо згоду дали лише на сторінці «дякуємо»: `PurchaseDedup` вже позначив замовлення, наступної сторінки для цієї події нема.
- Картки товарів і «дякуємо» — без публічного кешу HTML (Cloudflare Cache Everything тощо): event_id ViewContent генерується на сервері, а HTML залежить від згоди.
- Кожне перезавантаження `/checkout/` дає новий InitiateCheckout (як begin_checkout у GA4).

## Сторінки без тегів (рев'ю безпеки #45)

- Кабінет покупця (усі маршрути `site_account*`: вхід, реєстрація, підтвердження email, новий пароль, вхід за
  посиланням, сам кабінет) не отримує жодного тегу: ні GTM, ні GA4, ні Meta Pixel, ні «Коду в head/body». Причина:
  адреси з листів несуть токени (`/account/verify/{token}`, `/account/password/{token}`, `login-link/check?…&hash=`),
  і будь-який тег відправив би їх стороннім сервісам як `page_location` навіть без згоди (cookieless-пінги Consent Mode).
- Механізм: `SiteRouteLoader` ставить маршрутам кабінету атрибут `AnalyticsTags::NO_TRACKING`, `AnalyticsTags` тоді
  вимикає всіх провайдерів; так само за шляхом головного запиту `/(мова/)account…` (`AccountPath`), тож і 404 з обрізаним токеном без тегів. Новий провайдер нічого робити не мусить. Тест: `CustomerAuthTest::testAccountPagesRenderNoAnalyticsOrCustomCode`.
- Наслідок для звітів: переглядів сторінок входу, реєстрації й кабінету в GA4 і Meta нема. Подію `sign_up` чи `login`,
  якщо знадобиться, ставити в `DataLayerCollector::queue` і віддавати на наступній сторінці сайту поза кабінетом.
