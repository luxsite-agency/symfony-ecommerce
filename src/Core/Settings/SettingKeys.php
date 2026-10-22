<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Settings;

use InvalidArgumentException;

/**
 * Реєстр ключів налаштувань: код → підпис, підказка, тип поля в адмінці, група. Значення за замовчуванням —
 * у Settings (параметри контейнера). Ключі поза реєстром не зберігаються. label/help тут — українською (CLI, (string) сутності);
 * адмінка показує їх мовою інтерфейсу за ключами labelKey()/helpKey()/groupKey() (домен admin, setting.key.<код>.*).
 * Новий ключ — разом з перекладами в translations/admin+intl-icu.*.
 */
final class SettingKeys
{
    public const string TYPE_TEXT = 'text';
    public const string TYPE_BOOL = 'bool';
    public const string TYPE_LIST = 'list';
    public const string TYPE_HTML = 'html';
    /** Секрет: у БД — конверт SecretBox, в адмінці — маска й заміна без показу старого; Settings::get() віддає маску. */
    public const string TYPE_SECRET = 'secret';
    /** Вибір з переліку (SettingKeys::choices()): в адмінці перемикачі, у списку — підпис замість коду. */
    public const string TYPE_CHOICE = 'choice';

    public const string GTM_CONTAINER_ID = 'analytics.gtm_container_id';
    public const string GA4_MEASUREMENT_ID = 'analytics.ga4_measurement_id';
    public const string FB_PIXEL_ID = 'analytics.fb_pixel_id';
    public const string GOOGLE_SITE_VERIFICATION = 'seo.google_site_verification';
    public const string BING_SITE_VERIFICATION = 'seo.bing_site_verification';
    public const string TAGS_ON_DEV = 'analytics.tags_on_dev';
    public const string CONSENT_GRANTED_LOCALES = 'analytics.consent_granted_locales';
    public const string HTML_HEAD = 'html.head';
    public const string HTML_BODY_START = 'html.body_start';
    public const string HTML_BODY_END = 'html.body_end';
    public const string ORDERS_NOTIFY_EMAIL = 'orders.notify_email';
    /** E15 PR-4: кому лист про застосований ціновий пакет; порожньо — усім активним менеджерам без адміністраторів */
    public const string PRICES_NOTIFY_EMAIL = 'prices.notify_email';
    public const string CATALOG_FACET_COUNTS_ENABLED = 'catalog.facet_counts_enabled';
    public const string CATALOG_FACET_COUNTS_MAX_SET = 'catalog.facet_counts_max_set';
    public const string FEEDS_DEFAULT_BRAND = 'feeds.default_brand';
    public const string AI_OPENROUTER_KEY = 'ai.openrouter_key';
    public const string AI_MODEL = 'ai.model';
    public const string AI_MONTHLY_BUDGET_USD = 'ai.monthly_budget_usd';
    /** Вхід покупців через Google (рішення власника 13.10.2026: ключі вводить клієнт в адмінці, env — лише fallback стендів). */
    public const string GOOGLE_SIGN_IN_ENABLED = 'google.sign_in_enabled';
    public const string GOOGLE_CLIENT_ID = 'google.client_id';
    public const string GOOGLE_CLIENT_SECRET = 'google.client_secret';
    /** Рядок платформи у футері сайту: full|short|none (PlatformCredit, рішення власника 19.10.2026). */
    public const string PLATFORM_CREDIT = 'site.platform_credit';

    /** Формати id: у поле id не має потрапити код — невалідне значення провайдери не виводять, список показує бейдж. */
    private const array PATTERNS = [
        self::GTM_CONTAINER_ID => '/^GTM-[A-Z0-9]{4,12}$/i',
        self::GA4_MEASUREMENT_ID => '/^G-[A-Z0-9]{6,14}$/i',
        self::FB_PIXEL_ID => '/^\d{6,20}$/',
        self::GOOGLE_SITE_VERIFICATION => '/^[A-Za-z0-9_=\-]{8,128}$/',
        self::BING_SITE_VERIFICATION => '/^[A-Za-z0-9_=\-]{8,128}$/',
        self::CATALOG_FACET_COUNTS_MAX_SET => '/^\d{1,9}$/',
        self::AI_MODEL => '/^[a-z0-9][a-z0-9.\-]*\/[A-Za-z0-9][A-Za-z0-9._:\-]*$/',
        self::AI_MONTHLY_BUDGET_USD => '/^\d{1,7}([.,]\d{1,2})?$/',
        // OAuth client id Google: «<номер проєкту>-<рядок>.apps.googleusercontent.com»; чужий рядок (secret, API key) сюди не пройде
        self::GOOGLE_CLIENT_ID => '/^\d{6,}-[a-z0-9]{8,}\.apps\.googleusercontent\.com$/',
        self::PLATFORM_CREDIT => '/^(full|short|none)$/',
    ];

    /** @var array<string, array{label: string, help: string, type: string, group: string}> group — код групи (setting.group.<код>) */
    private const array KEYS = [
        self::GTM_CONTAINER_ID => ['label' => 'Ідентифікатор контейнера Google Tag Manager', 'help' => 'GTM-XXXXXXX. Якщо задано, GA4 і Pixel краще підключати всередині GTM.', 'type' => self::TYPE_TEXT, 'group' => 'analytics'],
        self::GA4_MEASUREMENT_ID => ['label' => 'Ідентифікатор потоку Google Analytics 4', 'help' => 'G-XXXXXXXXXX. Виводиться gtag.js лише коли GTM не задано.', 'type' => self::TYPE_TEXT, 'group' => 'analytics'],
        self::FB_PIXEL_ID => ['label' => 'Ідентифікатор пікселя Meta (Facebook)', 'help' => 'Числовий id пікселя Meta. Рушій шле PageView і ecommerce-події (ViewContent, AddToCart, InitiateCheckout, Purchase; content_ids = SKU варіанта, як у фіді) лише за згоди на cookie. Якщо піксель налаштовано в GTM — залиште порожнім, інакше події задвояться (docs/analytics.md).', 'type' => self::TYPE_TEXT, 'group' => 'analytics'],
        self::GOOGLE_SITE_VERIFICATION => ['label' => 'Google Search Console — код верифікації', 'help' => 'Значення meta google-site-verification (без тегу).', 'type' => self::TYPE_TEXT, 'group' => 'seo'],
        self::BING_SITE_VERIFICATION => ['label' => 'Bing Webmaster — код верифікації', 'help' => 'Значення meta msvalidate.01 (без тегу).', 'type' => self::TYPE_TEXT, 'group' => 'seo'],
        self::TAGS_ON_DEV => ['label' => 'Вмикати теги на dev', 'help' => 'На prod теги й HTML-коди виводяться завжди; на dev/test — лише з цим перемикачем (типово вимкнено, щоб тестові перегляди не потрапляли в аналітику).', 'type' => self::TYPE_BOOL, 'group' => 'analytics'],
        self::CONSENT_GRANTED_LOCALES => ['label' => 'Згода на cookie: мови, де вона діє одразу', 'help' => 'Через кому. Для цих мов consent = granted без банера; для решти — denied і банер згоди (EU).', 'type' => self::TYPE_LIST, 'group' => 'analytics'],
        self::HTML_HEAD => ['label' => 'Свій код у заголовку сторінки', 'help' => 'Довільний HTML/JS, виводиться сирим у <head> кожної сторінки сайту. Лише для адміністратора.', 'type' => self::TYPE_HTML, 'group' => 'html'],
        self::HTML_BODY_START => ['label' => 'Свій код на початку сторінки', 'help' => 'Виводиться сирим одразу після <body>.', 'type' => self::TYPE_HTML, 'group' => 'html'],
        self::HTML_BODY_END => ['label' => 'Свій код у кінці сторінки', 'help' => 'Виводиться сирим перед </body>.', 'type' => self::TYPE_HTML, 'group' => 'html'],
        self::ORDERS_NOTIFY_EMAIL => ['label' => 'Пошта для нових замовлень', 'help' => 'Куди надсилати лист про кожне нове замовлення; кілька адрес через кому. Порожньо — не надсилати.', 'type' => self::TYPE_TEXT, 'group' => 'orders'],
        self::PRICES_NOTIFY_EMAIL => ['label' => 'Пошта для застосованих цінових пакетів', 'help' => 'Кому надсилати зведення, коли застосовано пакет змін із цінами (AI, CLI). Кілька адрес через кому. Порожньо — усім активним користувачам з роллю менеджера (без адміністраторів).', 'type' => self::TYPE_TEXT, 'group' => 'prices'],
        self::CATALOG_FACET_COUNTS_ENABLED => ['label' => 'Показувати лічильники у фільтрах', 'help' => 'Кількість товарів біля значень фільтрів категорії. Вимкнено — значення показуються без цифр (ADR Rev 11). На великих каталогах лічильники дорогі: див. поріг нижче.', 'type' => self::TYPE_BOOL, 'group' => 'catalog'],
        self::CATALOG_FACET_COUNTS_MAX_SET => ['label' => 'Поріг лічильників фільтрів, карток', 'help' => 'Якщо у відфільтрованому наборі більше карток, лічильники не рахуються і не виводяться (значення лишаються, без цифри). Ціле число; порожньо — 20000.', 'type' => self::TYPE_TEXT, 'group' => 'catalog'],
        self::FEEDS_DEFAULT_BRAND => ['label' => 'Бренд для товарів без бренду (фід Meta)', 'help' => 'Meta відхиляє товар без бренду: для карток без бренду у фід Meta йде це значення, порожньо — назва магазину. Google-фід його не бере (там товар без бренду позначається identifier_exists = no).', 'type' => self::TYPE_TEXT, 'group' => 'feeds'],
        self::AI_OPENROUTER_KEY => ['label' => 'Ключ OpenRouter', 'help' => 'Ключ API з openrouter.ai/keys (sk-or-v1-…). AI-функції адмінки працюють на цьому ключі й за рахунок його кредитів. Зберігається шифровано, показується лише маскою; порожнє поле при збереженні — лишити чинний ключ. Без ключа AI-кнопок в адмінці нема (docs/ai.md).', 'type' => self::TYPE_SECRET, 'group' => 'ai'],
        self::AI_MODEL => ['label' => 'Модель AI', 'help' => 'Рядок vendor/model з openrouter.ai/models, напр. deepseek/deepseek-v4-pro або anthropic/claude-sonnet-4.5. Порожньо — модель за замовчуванням рушія.', 'type' => self::TYPE_TEXT, 'group' => 'ai'],
        self::AI_MONTHLY_BUDGET_USD => ['label' => 'Місячний ліміт витрат AI, $', 'help' => 'Коли сума витрат за календарний місяць (за даними OpenRouter) досягає ліміту, AI-функції до кінця місяця не викликаються. 0 або порожньо — без ліміту.', 'type' => self::TYPE_TEXT, 'group' => 'ai'],
        self::GOOGLE_SIGN_IN_ENABLED => ['label' => 'Увімкнено', 'help' => 'Кнопка «Продовжити з Google» на вході, реєстрації та у вікні відгуку. Працює лише разом із Client ID і Client Secret нижче; інструкція й адреси для Google Cloud Console — під списком налаштувань.', 'type' => self::TYPE_BOOL, 'group' => 'google'],
        self::GOOGLE_CLIENT_ID => ['label' => 'Client ID', 'help' => 'З Google Cloud Console → Credentials → OAuth client ID (Web application): рядок виду 123456789012-abc…xyz.apps.googleusercontent.com.', 'type' => self::TYPE_TEXT, 'group' => 'google'],
        self::GOOGLE_CLIENT_SECRET => ['label' => 'Client Secret', 'help' => 'Звідти ж (GOCSPX-…). Зберігається шифровано, показується лише маскою; порожнє поле при збереженні — лишити чинний. Ротація: у Cloud Console створіть новий secret у тому ж клієнті, вставте сюди, потім вимкніть старий.', 'type' => self::TYPE_SECRET, 'group' => 'google'],
        self::PLATFORM_CREDIT => ['label' => 'Рядок платформи у футері', 'help' => '«Повний» — платформа, відкритий код під ліцензією Apache 2.0, розробники LuxSite і посилання «Ліцензія»; «Коротко» — «Ecommerce LuxSite»; «Не показувати» — рядка нема. Під Apache 2.0 показ на сайті не обов\'язковий: вимога NOTICE стосується лише поширення коду. Сторінка /license лишається доступною за адресою (закрита від індексації).', 'type' => self::TYPE_CHOICE, 'group' => 'site'],
    ];

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(self::KEYS);
    }

    public static function assertKnown(string $code): string
    {
        if (!isset(self::KEYS[$code])) {
            throw new InvalidArgumentException(\sprintf('Невідомий ключ налаштування "%s"', $code));
        }

        return $code;
    }

    public static function label(string $code): string
    {
        return self::KEYS[$code]['label'] ?? $code;
    }

    public static function help(string $code): string
    {
        return self::KEYS[$code]['help'] ?? '';
    }

    /** Ключ перекладу підпису (домен admin): setting.key.<код налаштування>.label. */
    public static function labelKey(string $code): string
    {
        return 'setting.key.'.$code.'.label';
    }

    /** Ключ перекладу підказки поля (домен admin): setting.key.<код налаштування>.help. */
    public static function helpKey(string $code): string
    {
        return 'setting.key.'.$code.'.help';
    }

    /** Ключ перекладу назви групи (домен admin): setting.group.<код групи>. */
    public static function groupKey(string $code): string
    {
        return 'setting.group.'.self::group($code);
    }

    public static function type(string $code): string
    {
        return self::KEYS[$code]['type'] ?? self::TYPE_TEXT;
    }

    /** Код групи: analytics, seo, html, orders, prices, catalog, feeds, ai, google, site. */
    public static function group(string $code): string
    {
        return self::KEYS[$code]['group'] ?? '';
    }

    /** @return array<string, string> ключ підпису (домен admin) → значення; порожньо — ключ не TYPE_CHOICE */
    public static function choices(string $code): array
    {
        return self::PLATFORM_CREDIT === $code ? PlatformCredit::choices() : [];
    }

    /** Ключ підпису значення вибору (домен admin; сам код, якщо підпису нема). */
    public static function choiceLabel(string $code, ?string $value): ?string
    {
        $label = array_search((string) $value, self::choices($code), true);

        return false === $label ? $value : $label;
    }

    public static function isSecret(string $code): bool
    {
        return self::TYPE_SECRET === self::type($code);
    }

    /** @return list<string> */
    public static function secretCodes(): array
    {
        return array_values(array_filter(self::all(), self::isSecret(...)));
    }

    /** true, якщо для ключа нема формату або значення порожнє/відповідає формату. */
    public static function isValidValue(string $code, ?string $value): bool
    {
        $value = trim((string) $value);
        if ('' === $value || !isset(self::PATTERNS[$code])) {
            return true;
        }

        return 1 === preg_match(self::PATTERNS[$code], $value);
    }

    /** Нормалізоване значення для виводу або null, якщо порожнє чи невалідне. */
    public static function validValue(string $code, ?string $value): ?string
    {
        $value = trim((string) $value);
        if ('' === $value || !self::isValidValue($code, $value)) {
            return null;
        }

        return \in_array($code, [self::GTM_CONTAINER_ID, self::GA4_MEASUREMENT_ID], true) ? strtoupper($value) : $value;
    }
}
