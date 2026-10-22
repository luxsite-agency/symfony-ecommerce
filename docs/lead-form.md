# Заявка без товару: `POST /request` (лід)

Для B2B-каталогів «усе через запит» (бриф B2B-клієнта 20.10.2026): покупець лишає контакти, компанію, коментар і за бажання файл
(план складу, креслення, фото) — без кошика і без прив'язки до товару. Запит ціни з картки (`/price-request`) лишається окремою формою.

## Модель: чому `Order(source=lead)`, а не окрема сутність
Лід іде **тим самим каналом, що й запит ціни**: `Order` із джерелом `lead`, без рядків (сума 0, `ShippingMethod`/`PaymentMethod`
= `manager_contact`), компанія — колонка `sales_order.company`, контекст сторінки / обраний варіант / адреса сторінки — рядками
в коментарі (CRM отримує `buyer_comment`, іншого поля для цього в контракті KeyCRM нема). Що це дає безкоштовно: список і фільтр
«Джерело» в адмінці, історія, outbox `PushOrderToCrm` + лист магазину, ліміти `OrderSubmitGuard`, демо й звіти без нового коду.
Окрема сутність «Заявка» означала б свій CRUD, свій конектор CRM, свій лист і другу чергу для менеджера — не варте того, поки
лід не має власного життєвого циклу. Вкладення (`OrderAttachment`) зроблено **на замовленні**, не на ліді: E16 (рецепти до
замовлення) використає ту саму таблицю й сховище.

## Потік
`site/partials/_lead_form.html.twig` → `POST /request` (`LeadRequestController`, без префікса мови; мова — приховане поле `locale`,
як у `/price-request`) → `LeadRequestService::submit(LeadRequest)` → `Order` + `OrderAttachment` в одній транзакції → outbox
`PushOrderToCrm`, `SendShopOrderEmail` (покупцю листа нема) → flash на сторінці, з якої прийшли (Referer цього ж хоста, інакше
`source_url`, інакше головна) → `generate_lead` у dataLayer (`lead_source: lead`).

Контракт partial (змінні необов'язкові; усе описано в коментарі на початку файлу): `context` — рядок, прихований, іде в коментар
(«Рішення: Палетне зберігання»); `choices` — список назв варіантів для `<select name="choice">`, до нього додається «Не знаю,
порадьте»; `source_url` — адреса сторінки (лише цього сайту; чужу контролер відкидає); `show_title`. Без змінних — звичайна форма
для сторінки контактів/послуг. Тема паку перекриває partial цілим файлом (`docs/themes.md`).

## Безпека (вимоги координатора 20.10.2026)
- **CSRF** stateless `lead_request` (Origin / Sec-Fetch-Site, без сесії) — як `price_request`.
- **Ліміти** `OrderSubmitGuard`: з IP і на телефон (`app.order_rate_limit_*`).
- **Honeypot** `website`: поле поза екраном (`.lf-hp` у `site.css`); заповнене → редирект з «успіхом», нічого не пишемо.
- **Файл: тип за вмістом**, не за розширенням чи Content-Type браузера (`AttachmentPolicy`): сигнатура перших байтів
  (`%PDF-`, JPEG `FF D8 FF`, PNG, AutoCAD `AC10xx`) **і** finfo (`symfony/mime`) мають погодитись; DWG libmagic знає не всюди, тому для
  нього finfo може сказати `octet-stream`. Дозволено лише PDF, DWG, JPG, PNG; SVG, HTML, PE тощо — `bad_type`. Ліміт 10 МБ.
- **Розмір на рівні nginx**: `location = /request { client_max_body_size 11m; }` у всіх 4 конфігах (страж `NginxLeadFormRuleTest`,
  синтаксис — `nginx -t` у `tests/store/run.sh`), решта сайту лишається 64m (медіа адмінки). PHP: `post_max_size`/`upload_max_filesize`
  64M не змінюємо — `AttachmentPolicy` ріже за 10 МБ, `UPLOAD_ERR_INI_SIZE` → `too_large`.
- **Зберігання поза public/**: `app.order_attachment_dir` = `var/share/private/attachments/<рік>/<місяць>/<32 hex>` — спільний том
  php/worker (як `exports`), права 0640, **без розширення**: nginx цієї теки не бачить, PHP не виконує, зображення не рендеряться.
- **Віддача лише адміну/менеджеру**: `GET <admin_host>/order-attachment/{id}` (`OrderAttachmentController`, `ROLE_MANAGER`,
  `BinaryFileResponse` з `Content-Disposition: attachment` і безпечним іменем `<номер>-<id>-<назва>.<ext>`, `X-Content-Type-Options: nosniff`,
  `Cache-Control: no-store`, CSP `sandbox`). Кожне скачування — у журнал `security_audit` (`order.attachment.download`: user_id,
  номер замовлення, id файлу). Картка замовлення показує список (`admin/order/_attachments.html.twig`).
- **Ретенція**: `app.order_attachment_retention_days` (180) → `PurgeOrderAttachments` щоночі о 04:05 (`Schedule`): файл стирається,
  рядок лишається з `purged_at` (менеджер бачить «стерто за строком зберігання»). Видалення замовлення каскадом видаляє рядки;
  файли-сироти (якщо такі з'являться) — за тією ж ретенцією через ту ж теку.
- Нічого з завантаженого не виконується, не рендериться і не перекодовується (перекодування зображень — у E16 для рецептів).

## Налаштування паку
`parameters.yaml`: `app.order_attachment_retention_days` (дні), `app.order_rate_limit_*`. Тексти — `site.lead.*` у `messages`
(uk/ru/en), адмінка — `lead.*` в `admin`. CRM: конектор `none` лише логує; KeyCRM отримує замовлення без `products` (лід) —
перевірити на першому магазині з KeyCRM і формою заявки, чи KeyCRM приймає порожній список (інакше — товар-заглушка в паку).

## Тести
`LeadRequestTest` (контракт partial, CSRF, honeypot, валідація, чужий вміст під `.pdf`, успіх з PDF: файл поза public, 0640, outbox),
`AttachmentPolicyTest` (сигнатури, відмови, розмір), `OrderAttachmentDownloadTest` (анонім → логін, менеджер → attachment+nosniff,
картка, після ретенції 404), `PurgeOrderAttachmentsTest`, `NginxLeadFormRuleTest`, `SiteUrlsTest` (маршрут).
