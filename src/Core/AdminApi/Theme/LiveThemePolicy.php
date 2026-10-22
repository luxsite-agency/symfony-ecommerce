<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\AdminApi\Theme;

/**
 * Явний allowlist для живих шаблонів (рев'ю C PR #108, M1–M2; рішення власника «пісочниця або явний allowlist»): що саме
 * живий шаблон має право вживати. Усе, чого тут нема, ThemeTemplateGuard відхиляє до запису — невідомий тег, фільтр, функція,
 * тест, метод чи властивість означають відмову, а не «мабуть, безпечно».
 *
 * Чому не Twig SandboxExtension: у Twig 3.30 він @internal, per-source режим (SourcePolicyInterface) deprecated з 3.27 без
 * заміни, а заміна — Twig\Sandbox\Sandbox — потребує окремого Environment, який не вміє «живий site/home.html.twig перекриває
 * ім'я рушія і робить extends @engine/…». Тому ті самі правила (теги, фільтри, функції, тести, методи/властивості), що їх
 * задають SecurityPolicy, перевіряються статично по AST — до запису, не під час рендера. Поверхня живого шаблону менша за
 * sandbox-поліси: імена атрибутів відомі на етапі парсингу (attribute() заборонено, тож динамічних імен нема).
 *
 * Базу списків дає `grep` по templates/site/** поза забороненою зоною: усе, що рушій там уживає (тест
 * ThemeTemplateGuardTest::testEveryOverridableEngineTemplatePasses стежить, щоб списки не відстали від шаблонів рушія).
 * Додати ім'я сюди — свідома дія з рев'ю, не побічний ефект.
 */
final class LiveThemePolicy
{
    /** Теги. Поза списком: sandbox, flush, guard, deprecated, cache, trans, form_theme, stopwatch. */
    public const array TAGS = ['apply', 'autoescape', 'block', 'do', 'embed', 'extends', 'for', 'from', 'if', 'import', 'include', 'macro', 'set', 'use', 'verbatim', 'with', 'types'];

    /**
     * Фільтри. Поза списком: invoke (виклик callable), data_uri, serialize, yaml_*, sanitize_html, ea_*, doctrine_*, file_*,
     * abbr_*, format_args*, format_log_message, imagine_filter_cache, form_encode_currency.
     */
    public const array FILTERS = [
        'abs', 'batch', 'capitalize', 'column', 'country_name', 'currency_name', 'currency_symbol', 'date', 'date_modify', 'date_short',
        'default', 'e', 'escape', 'filter', 'find', 'first', 'format', 'format_currency', 'format_date', 'format_datetime', 'format_number',
        'format_time', 'humanize', 'imagine_filter', 'join', 'json_encode', 'keys', 'language_name', 'last', 'length', 'locale_name', 'lower',
        'map', 'merge', 'money', 'nl2br', 'number_format', 'qty', 'raw', 'reduce', 'replace', 'reverse', 'round', 'shuffle', 'slice', 'sort',
        'spaceless', 'split', 'striptags', 'title', 'trans', 'trim', 'upper', 'url_encode',
    ];

    /**
     * Функції. Поза списком: attribute, render, render_*, controller, fragment_uri, include_only, source, dump, template_from_string,
     * expression, form_* (FormView тут не буває), workflow_*, logout_*, impersonation_*, ea*, profiler_*, component/provide/inject,
     * vich_uploader_asset. constant/enum/enum_cases — у списку, але лише літерал App\…\Enum\…::Case (ThemeTemplateGuard::ENUM_RE);
     * include — лише літерал site/… чи @engine/site/….
     */
    public const array FUNCTIONS = [
        'absolute_url', 'asset', 'block', 'constant', 'csrf_token', 'cycle', 'date', 'enum', 'enum_cases', 'field_choices', 'field_errors',
        'field_help', 'field_id', 'field_label', 'field_name', 'field_value', 'html_classes', 'include', 'is_granted', 'max', 'min', 'parent',
        'path', 'random', 'range', 'relative_path', 'url',
        // рушій магазину
        'analytics_body_end', 'analytics_body_start', 'analytics_head', 'analytics_push', 'analytics_select_attr', 'analytics_view_item',
        'analytics_view_item_list', 'gmb_aggregate_rating', 'gmb_rating', 'gmb_rating_badge', 'gmb_reviews', 'gmb_reviews_data',
        'gmb_reviews_jsonld', 'google_sign_in_enabled', 'quantity_display', 'site_alternates', 'site_asset', 'site_card_price',
        'site_cart_count', 'site_default_locale', 'site_has_solutions', 'site_home_seo', 'site_hreflang', 'site_latest_posts',
        'site_locales', 'site_nav_categories', 'site_nav_pages', 'site_og_locale', 'site_organization_schema', 'site_platform_credit',
        'site_reviews_enabled', 'site_theme', 'site_theme_live_css', 'site_theme_style', 'site_theme_text', 'site_url', 'tr', 'wishlist_has',
    ];

    /** Тести. Поза списком: constant. */
    public const array TESTS = ['defined', 'divisible by', 'empty', 'even', 'false', 'iterable', 'mapping', 'none', 'null', 'odd', 'same as', 'sequence', 'true'];

    /**
     * Ланцюжки від глобального app — точні (app.request.locale, не app.request): app.request сам по собі дав би server/headers/
     * cookies/session (секрети оточення в $_SERVER — рев'ю C, M1), app.session і app.token — сесію й автентифікацію. Бо об'єкт
     * app.user далі перевіряють ATTRIBUTES, password/googleSub/roles там нема.
     */
    public const array APP_CHAINS = [
        'request.locale', 'request.pathInfo', 'request.uri', 'request.requestUri', 'request.schemeAndHttpHost', 'request.hasPreviousSession',
        'request.query.all', 'request.query.get', 'request.query.has',
        'flashes', 'user', 'current_route', 'current_route_parameters', 'locale',
    ];

    /** Змінні контексту, яких живому шаблону не дати: _context — увесь контекст разом з app; _self — лише в {% import _self %}. */
    public const array DENIED_VARIABLES = ['_context', '_self'];

    /**
     * Імена властивостей і методів (x.name, x.name()) — усе, що рушій уживає в templates/site/** поза забороненою зоною, крім
     * того, що в живому шаблоні є лише через app.* (request, all, pathInfo, uri, hasPreviousSession, flashes — їх дає
     * APP_CHAINS) і крім clear (Cart::clear(); фасети категорії беруть block['clear'] масивом). Сетерів, add…/remove…/flush
     * тут нема і не буде: це поверхня зміни даних (ADR «Прийняті ризики»).
     */
    public const array ATTRIBUTES = [
        '_token', 'a', 'access', 'action', 'active', 'address', 'alt', 'amount', 'ancestors', 'attributes', 'author', 'authorName',
        'availability', 'available', 'average', 'axes', 'axisValues', 'body', 'brand', 'by', 'card', 'categories', 'category', 'checked',
        'city', 'code', 'comment', 'count', 'cover', 'coverCaption', 'crumbs', 'current', 'customerGroup', 'customerName', 'date', 'default', 'defaultVariant',
        'demo', 'description', 'email', 'error', 'errors', 'excerpt', 'faq', 'first', 'form', 'format', 'from', 'google_path', 'group',
        'groups', 'hasFilters', 'heading', 'height', 'hidden', 'hint', 'id', 'image', 'imageAlt', 'images', 'index', 'index0', 'industries',
        'intro', 'item', 'items', 'itemsTotal', 'key', 'kind', 'label', 'labels', 'last', 'leadTimeDays', 'length', 'letter', 'lineTotal', 'lines',
        'listPrice', 'loads', 'locale', 'login_path', 'max', 'message', 'metaDescription', 'metaTitle', 'min', 'minAmount', 'minQuantity',
        'mine', 'missingAmount', 'missingQuantity', 'multiple', 'name', 'note', 'np_city_ref', 'np_warehouse_ref', 'number', 'oldPrice',
        'options', 'optionsSnapshot', 'page', 'pages', 'parent', 'path', 'payment', 'paymentMethod', 'phone', 'price', 'priceFingerprint',
        'priceFrom', 'priceMode', 'problem', 'product', 'pros', 'publishedAt', 'q', 'quantity', 'quantityStep', 'query', 'quote', 'range',
        'rangeFormFields', 'rating', 'ref', 'related', 'reply', 'required', 'retailPrice', 'revindex', 'revindex0', 'selected', 'shipping',
        'shippingAddress', 'shippingCity', 'shippingMethod', 'shortDescription', 'sku', 'slug', 'solutions', 'source', 'stars', 'status',
        'step', 'stock', 'stockPolicy', 'studioCoverShown', 'summary', 'tagList', 'task', 'text', 'title', 'to', 'toDecimalString', 'total',
        'tr', 'trail', 'translation', 'unit', 'unitPrice', 'url', 'value', 'values', 'variant', 'variants', 'vars', 'verified',
        'warehouses', 'width',
    ];

    /** Короткий опис правил для list().rules і повідомлень MCP. */
    public static function summary(): string
    {
        return 'Лише дозволені теги, фільтри, функції й властивості (allowlist, LiveThemePolicy): без app.request/app.session (лише app.request.locale|pathInfo|uri|query.get|…, app.user, app.flashes), без _context, attribute(), render(), controller(), include з виразом, source(), dump(), constant() поза enum-ами магазину, рядків-callable у filter/map/sort.';
    }
}
