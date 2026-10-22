<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Routing;

use App\Core\Analytics\AnalyticsTags;
use App\Core\Customer\Auth\AuthTokens;
use App\Site\Account\Controller\AccountController;
use App\Site\Account\Controller\AddressController;
use App\Site\Account\Controller\BulkOrderController;
use App\Site\Account\Controller\LoginController;
use App\Site\Account\Controller\OrderHistoryController;
use App\Site\Account\Controller\PasswordResetController;
use App\Site\Account\Controller\PriceListController;
use App\Site\Account\Controller\PrivacyController;
use App\Site\Account\Controller\ProfileController;
use App\Site\Account\Controller\RegistrationController;
use App\Site\Account\Controller\ReviewController;
use App\Site\Account\Controller\WholesaleController;
use App\Site\Account\Controller\WishlistController;
use App\Site\Account\Google\GoogleSignInController;
use App\Site\Controller\BlogController;
use App\Site\Controller\CartController;
use App\Site\Controller\CategoryPageController;
use App\Site\Controller\CheckoutController;
use App\Site\Controller\HomeController;
use App\Site\Controller\LicenseController;
use App\Site\Controller\NovaPoshtaController;
use App\Site\Controller\OrderController;
use App\Site\Controller\PageController;
use App\Site\Controller\ProductPageController;
use App\Site\Controller\QuickOrderController;
use App\Site\Controller\SearchController;
use App\Site\Controller\SiteCatalogController;
use App\Site\Controller\SitemapController;
use App\Site\Controller\SolutionController;
use InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Публічні маршрути з параметрів (ADR Rev 8: жодних буквальних мов у коді): для кожної мови з app.locales —
 * маршрут «<name>.<locale>»; мова за замовчуванням без префікса, решта під /{locale}/. Завдяки _canonical_route
 * генерація path('site_page', {slug, _locale}) працює як для локалізованих маршрутів Symfony.
 * Без host: сайт працює на будь-якому домені (prod клієнта, worktree shop-engine-<name>, тести); до хоста прив'язана
 * лише адмінка (admin_host + AdminHostGuard), тому публічні маршрути мають умову «не admin_host» і пріоритет над
 * маршрутами EasyAdmin, які перетинаються шляхами (/page/{slug} ↔ /page/{entityId}).
 * Каталог без префіксів (власник, 2026-09-28): товар — /{slug}, категорія — /{шлях за деревом}/ з кінцевим слешем.
 * Це catch-all з найнижчим пріоритетом: статичні маршрути сайту й маршрути-атрибути (price-request, feeds, profiler)
 * перевіряються раніше, а їхні перші сегменти зарезервовані для слагів (RouterReservedPathSegments). Слаг і шлях — лише
 * [a-z0-9-] (без крапок: cart.json, sitemap.xml не перехоплюються).
 * Сторінки (Page): типово (app.page_path_prefix: '') у корені /{slug}/ — їх розбирає той самий catch-all (SiteCatalogController:
 * сторінка раніше за категорію); з '/<слаг>' — /<слаг>/{slug}. Стара /page/{slug} лишається 301-редиректом (посилання в пошуку).
 * Реєструється в config/routes/site.php (type: service).
 */
#[AutoconfigureTag('routing.route_loader')]
final class SiteRouteLoader
{
    public const string SLUG = '[a-z0-9]+(?:-[a-z0-9]+)*';
    public const string PATH = self::SLUG.'(?:/'.self::SLUG.')*';
    /** Пріоритет за замовчуванням — вище за маршрути EasyAdmin без host; catch-all каталогу — нижче за все. */
    private const int PRIORITY = 10;
    private const int CATCH_ALL_PRIORITY = -100;

    /** @var array<string, array{path: string, controller: string, requirements?: array<string, string>, methods?: list<string>, priority?: int, generate_only?: bool}> */
    private const array ROUTES = [
        'site_home' => ['path' => '/', 'controller' => HomeController::class],
        // шлях залежить від app.page_path_prefix (routes()): '' → /{slug}/ через catch-all каталогу, '/page' → /page/{slug}
        'site_page' => ['path' => '/page/{slug}', 'controller' => PageController::class.'::show', 'requirements' => ['slug' => '[a-z0-9-]+']],
        'site_blog' => ['path' => '/blog/', 'controller' => BlogController::class.'::index'],
        'site_post' => ['path' => '/blog/{slug}', 'controller' => BlogController::class.'::show', 'requirements' => ['slug' => '[a-z0-9-]+']],
        // рішення (docs/solutions.md): список за групами й сторінка; префікс solutions однаковий для всіх мов, слаг — перекладний;
        // кінцевий слеш обов'язковий (без нього — 301, як /blog/); «solutions» — зарезервований сегмент
        'site_solutions' => ['path' => '/solutions/', 'controller' => SolutionController::class.'::index', 'methods' => ['GET', 'HEAD']],
        'site_solution' => ['path' => '/solutions/{slug}/', 'controller' => SolutionController::class.'::show', 'requirements' => ['slug' => '[a-z0-9-]+'], 'methods' => ['GET', 'HEAD']],
        // ліцензія платформи (посилання «Умови ліцензії» у підвалі): noindex, не в sitemap; «license» стає зарезервованим сегментом
        'site_license' => ['path' => '/license', 'controller' => LicenseController::class, 'methods' => ['GET', 'HEAD']],
        'site_search' => ['path' => '/search', 'controller' => SearchController::class, 'methods' => ['GET', 'HEAD']],
        'site_cart' => ['path' => '/cart/', 'controller' => CartController::class.'::index', 'methods' => ['GET']],
        'site_cart_add' => ['path' => '/cart/add', 'controller' => CartController::class.'::add', 'methods' => ['POST']],
        'site_cart_update' => ['path' => '/cart/update', 'controller' => CartController::class.'::update', 'methods' => ['POST']],
        'site_cart_remove' => ['path' => '/cart/remove', 'controller' => CartController::class.'::remove', 'methods' => ['POST']],
        'site_cart_json' => ['path' => '/cart.json', 'controller' => CartController::class.'::summary', 'methods' => ['GET']],
        'site_checkout' => ['path' => '/checkout/', 'controller' => CheckoutController::class, 'methods' => ['GET', 'POST']],
        // довідники Нової пошти для автодоповнення на checkout (Ф3, D9)
        'site_np_cities' => ['path' => '/np/cities.json', 'controller' => NovaPoshtaController::class.'::cities', 'methods' => ['GET']],
        'site_np_warehouses' => ['path' => '/np/warehouses.json', 'controller' => NovaPoshtaController::class.'::warehouses', 'methods' => ['GET']],
        'site_quick_order' => ['path' => '/quick-order', 'controller' => QuickOrderController::class, 'methods' => ['POST']],
        // «дякуємо»: посилання з токеном лише кладе замовлення в сесію і веде на адресу без токена (вона і йде в аналітику)
        'site_order_thanks' => ['path' => '/order/{token}/thanks', 'controller' => OrderController::class.'::thanks', 'requirements' => ['token' => '[a-f0-9]{48}'], 'methods' => ['GET']],
        'site_order_thanks_page' => ['path' => '/order/thanks', 'controller' => OrderController::class.'::page', 'methods' => ['GET']],
        // кабінет покупця (E12): усе під /account; site_account* — no-store/noindex (AccountResponseHeaders), публічні
        // сторінки входу — PUBLIC_ACCESS у security.yaml, решта — ROLE_CUSTOMER
        'site_account' => ['path' => '/account/', 'controller' => AccountController::class.'::index', 'methods' => ['GET']],
        'site_account_login' => ['path' => '/account/login', 'controller' => LoginController::class.'::login', 'methods' => ['GET', 'POST']],
        'site_account_logout' => ['path' => '/account/logout', 'controller' => LoginController::class.'::logout', 'methods' => ['POST']],
        'site_account_login_link' => ['path' => '/account/login-link', 'controller' => LoginController::class.'::loginLink', 'methods' => ['GET', 'POST']],
        'site_account_login_link_check' => ['path' => '/account/login-link/check', 'controller' => LoginController::class.'::loginLinkCheck', 'methods' => ['GET', 'POST']],
        'site_account_register' => ['path' => '/account/register', 'controller' => RegistrationController::class.'::register', 'methods' => ['GET', 'POST']],
        'site_account_verify' => ['path' => '/account/verify/{token}', 'controller' => RegistrationController::class.'::verify', 'requirements' => ['token' => AuthTokens::PATTERN], 'methods' => ['GET', 'POST']],
        'site_account_password' => ['path' => '/account/password', 'controller' => PasswordResetController::class.'::request', 'methods' => ['GET', 'POST']],
        'site_account_wholesale' => ['path' => '/account/wholesale', 'controller' => WholesaleController::class.'::apply', 'methods' => ['GET', 'POST']],
        'site_account_price_list' => ['path' => '/account/wholesale/price-list', 'controller' => PriceListController::class.'::download', 'methods' => ['GET']],
        'site_account_orders' => ['path' => '/account/orders', 'controller' => OrderHistoryController::class.'::index', 'methods' => ['GET']],
        'site_account_orders_claim' => ['path' => '/account/orders/claim', 'controller' => OrderHistoryController::class.'::claim', 'methods' => ['POST']],
        'site_account_order' => ['path' => '/account/orders/{id}', 'controller' => OrderHistoryController::class.'::show', 'requirements' => ['id' => '\\d+'], 'methods' => ['GET']],
        'site_account_order_reorder' => ['path' => '/account/orders/{id}/reorder', 'controller' => OrderHistoryController::class.'::reorder', 'requirements' => ['id' => '\\d+'], 'methods' => ['POST']],
        'site_account_bulk' => ['path' => '/account/bulk-order', 'controller' => BulkOrderController::class.'::index', 'methods' => ['GET', 'POST']],
        'site_account_bulk_add' => ['path' => '/account/bulk-order/add', 'controller' => BulkOrderController::class.'::add', 'methods' => ['POST']],
        'site_account_wishlist' => ['path' => '/account/wishlist', 'controller' => WishlistController::class.'::index', 'methods' => ['GET']],
        'site_account_wishlist_add' => ['path' => '/account/wishlist/add', 'controller' => WishlistController::class.'::add', 'methods' => ['POST']],
        'site_account_wishlist_remove' => ['path' => '/account/wishlist/remove', 'controller' => WishlistController::class.'::remove', 'methods' => ['POST']],
        // відгуки покупця (рішення власника 13.10.2026): «Мої відгуки», новий (GET ?product= / POST з картки й сторінки), редагування
        'site_account_reviews' => ['path' => '/account/reviews', 'controller' => ReviewController::class.'::index', 'methods' => ['GET']],
        'site_account_review_new' => ['path' => '/account/reviews/new', 'controller' => ReviewController::class.'::new', 'methods' => ['GET', 'POST']],
        'site_account_review_edit' => ['path' => '/account/reviews/{id}', 'controller' => ReviewController::class.'::edit', 'requirements' => ['id' => '\\d+'], 'methods' => ['GET', 'POST']],
        // E12 PR-6: профіль, адреси, персональні дані (шляхи поза public-списком access_control: /account/password/… публічний, тому profile/…)
        'site_account_profile' => ['path' => '/account/profile', 'controller' => ProfileController::class.'::edit', 'methods' => ['GET', 'POST']],
        'site_account_profile_password' => ['path' => '/account/profile/password', 'controller' => ProfileController::class.'::password', 'methods' => ['GET', 'POST']],
        'site_account_profile_email' => ['path' => '/account/profile/email', 'controller' => ProfileController::class.'::email', 'methods' => ['GET', 'POST']],
        'site_account_email_confirm' => ['path' => '/account/email/{token}', 'controller' => ProfileController::class.'::emailConfirm', 'requirements' => ['token' => AuthTokens::PATTERN], 'methods' => ['GET', 'POST']],
        'site_account_email_revert' => ['path' => '/account/email-revert/{token}', 'controller' => ProfileController::class.'::emailRevert', 'requirements' => ['token' => AuthTokens::PATTERN], 'methods' => ['GET', 'POST']],
        'site_account_addresses' => ['path' => '/account/addresses', 'controller' => AddressController::class.'::index', 'methods' => ['GET']],
        'site_account_address_new' => ['path' => '/account/addresses/new', 'controller' => AddressController::class.'::new', 'methods' => ['GET', 'POST']],
        'site_account_address_edit' => ['path' => '/account/addresses/{id}', 'controller' => AddressController::class.'::edit', 'requirements' => ['id' => '\\d+'], 'methods' => ['GET', 'POST']],
        'site_account_address_delete' => ['path' => '/account/addresses/{id}/delete', 'controller' => AddressController::class.'::delete', 'requirements' => ['id' => '\\d+'], 'methods' => ['POST']],
        'site_account_address_default' => ['path' => '/account/addresses/{id}/default', 'controller' => AddressController::class.'::makeDefault', 'requirements' => ['id' => '\\d+'], 'methods' => ['POST']],
        'site_account_privacy' => ['path' => '/account/privacy', 'controller' => PrivacyController::class.'::index', 'methods' => ['GET']],
        'site_account_export' => ['path' => '/account/privacy/export', 'controller' => PrivacyController::class.'::export', 'methods' => ['GET', 'POST']],
        'site_account_export_download' => ['path' => '/account/export/{token}', 'controller' => PrivacyController::class.'::download', 'requirements' => ['token' => AuthTokens::PATTERN], 'methods' => ['GET', 'POST']],
        'site_account_delete' => ['path' => '/account/privacy/delete', 'controller' => PrivacyController::class.'::delete', 'methods' => ['GET', 'POST']],
        'site_account_password_reset' => ['path' => '/account/password/{token}', 'controller' => PasswordResetController::class.'::reset', 'requirements' => ['token' => AuthTokens::PATTERN], 'methods' => ['GET', 'POST']],
        // вхід через Google (13.10.2026): start і callback публічні (access_control), link/unlink — лише покупцю; callback у режимі
        // входу перехоплює GoogleAuthenticator, redirect_uri — лише мовою за замовчуванням (GoogleFlow::redirectUri)
        'site_account_google_start' => ['path' => '/account/google/start', 'controller' => GoogleSignInController::class.'::start', 'methods' => ['GET']],
        'site_account_google_callback' => ['path' => '/account/google/callback', 'controller' => GoogleSignInController::class.'::callback', 'methods' => ['GET']],
        'site_account_google_link' => ['path' => '/account/google/link', 'controller' => GoogleSignInController::class.'::link', 'methods' => ['GET', 'POST']],
        'site_account_google_unlink' => ['path' => '/account/google/unlink', 'controller' => GoogleSignInController::class.'::unlink', 'methods' => ['POST']],
        // каталог без префіксів. site_category / site_product — лише для генерації URL (умова false: не матчаться), бо
        // пара /{path}/ і /{slug} змушує матчер Symfony редиректити /x → /x/ до того, як спробує товар. Матчить один
        // catch-all site_catalog: кінцевий слеш — категорія, без нього — товар (SiteCatalogController).
        'site_category' => ['path' => '/{path}/', 'controller' => CategoryPageController::class, 'requirements' => ['path' => self::PATH], 'methods' => ['GET', 'HEAD'], 'priority' => self::CATCH_ALL_PRIORITY, 'generate_only' => true],
        'site_product' => ['path' => '/{slug}', 'controller' => ProductPageController::class, 'requirements' => ['slug' => self::SLUG], 'methods' => ['GET', 'HEAD'], 'priority' => self::CATCH_ALL_PRIORITY, 'generate_only' => true],
        'site_catalog' => ['path' => '/{path}', 'controller' => SiteCatalogController::class, 'requirements' => ['path' => self::PATH.'/?'], 'methods' => ['GET', 'HEAD'], 'priority' => self::CATCH_ALL_PRIORITY],
    ];

    public const string PAGE_PREFIX_DEFAULT = '';
    /** Префікс сторінок до 20.10.2026: з будь-яким іншим app.page_path_prefix /page/{slug} — 301 на чинну адресу. */
    public const string PAGE_PREFIX_LEGACY = '/page';

    /** @param list<string> $locales */
    public function __construct(
        #[Autowire('%app.locales%')] private readonly array $locales,
        #[Autowire('%app.default_locale%')] private readonly string $defaultLocale,
        #[Autowire('%admin_host%')] private readonly string $adminHost,
        #[Autowire('%app.page_path_prefix%')] private readonly string $pagePrefix = self::PAGE_PREFIX_DEFAULT,
    ) {
        if ('' !== $pagePrefix && 1 !== preg_match('#^/'.self::SLUG.'$#', $pagePrefix)) {
            throw new InvalidArgumentException(\sprintf("app.page_path_prefix: '' (сторінки в корені) або '/<слаг>', отримано '%s'", $pagePrefix));
        }
    }

    /** Сторінки в корені сайту (app.page_path_prefix: ''): /{slug}/ розбирає catch-all каталогу — сторінка раніше за категорію. */
    public function pagesAtRoot(): bool
    {
        return '' === $this->pagePrefix;
    }

    /** @return array<string, array{path: string, controller: string, requirements?: array<string, string>, methods?: list<string>, priority?: int, generate_only?: bool}> */
    private function routes(): array
    {
        $routes = self::ROUTES;
        if ($this->pagesAtRoot()) {
            // у корені: лише генерація /{slug}/ (матчить site_catalog)
            $routes['site_page'] = ['path' => '/{slug}/', 'controller' => PageController::class.'::show', 'requirements' => ['slug' => self::SLUG], 'methods' => ['GET', 'HEAD'], 'priority' => self::CATCH_ALL_PRIORITY, 'generate_only' => true];
        } else {
            $routes['site_page']['path'] = $this->pagePrefix.'/{slug}';
        }
        // старі /page/{slug} (у Google, в закладках) — 301 на чинну адресу, хай який префікс зараз
        if (self::PAGE_PREFIX_LEGACY !== $this->pagePrefix) {
            $routes['site_page_legacy'] = ['path' => self::PAGE_PREFIX_LEGACY.'/{slug}', 'controller' => PageController::class.'::legacy', 'requirements' => ['slug' => self::SLUG], 'methods' => ['GET', 'HEAD']];
        }

        return $routes;
    }

    public function __invoke(): RouteCollection
    {
        $collection = new RouteCollection();
        $notAdmin = \sprintf("request.getHost() !== '%s'", addslashes($this->adminHost));
        foreach ($this->routes() as $name => $definition) {
            foreach ($this->locales as $locale) {
                $path = $locale === $this->defaultLocale ? $definition['path'] : '/'.$locale.('/' === $definition['path'] ? '/' : $definition['path']);
                $defaults = ['_controller' => $definition['controller'], '_locale' => $locale, '_canonical_route' => $name];
                // кабінет покупця: без аналітики й сторонніх кодів (токени листів в адресі; рев'ю безпеки #45, п. 1)
                if (str_starts_with($name, 'site_account')) {
                    $defaults[AnalyticsTags::NO_TRACKING] = true;
                }
                $route = new Route(
                    $path,
                    $defaults,
                    ($definition['requirements'] ?? []) + ['_locale' => preg_quote($locale, '/')],
                    [],
                    '',
                    [],
                    $definition['methods'] ?? [],
                    ($definition['generate_only'] ?? false) ? 'false' : $notAdmin,
                );
                // пріоритет вище за маршрути EasyAdmin без host (/page/{entityId} тощо): на site_host виграють публічні
                // catch-all з мовним префіксом (/ru/{path}) — раніше за catch-all мови за замовчуванням (/{path}), інакше
                // той забирає /ru/… як шлях «ru/…»
                $priority = $definition['priority'] ?? self::PRIORITY;
                if (self::CATCH_ALL_PRIORITY === $priority && $locale !== $this->defaultLocale) {
                    ++$priority;
                }
                $collection->add($name.'.'.$locale, $route, $priority);
            }
        }
        $collection->add('site_sitemap', new Route('/sitemap.xml', ['_controller' => SitemapController::class, '_stateless' => true], [], [], '', [], [], $notAdmin), 10);

        return $collection;
    }
}
