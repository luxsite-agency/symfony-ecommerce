<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Admin\Controller\Crud\AdminApiTokenCrudController;
use App\Admin\Controller\Crud\AdminAuditCrudController;
use App\Admin\Controller\Crud\AiUsageCrudController;
use App\Admin\Controller\Crud\AttributeCrudController;
use App\Admin\Controller\Crud\BrandCrudController;
use App\Admin\Controller\Crud\CategoryCrudController;
use App\Admin\Controller\Crud\ChangesetCrudController;
use App\Admin\Controller\Crud\CompanyCrudController;
use App\Admin\Controller\Crud\CustomerGroupCrudController;
use App\Admin\Controller\Crud\MediaCrudController;
use App\Admin\Controller\Crud\NotFoundCrudController;
use App\Admin\Controller\Crud\OrderCrudController;
use App\Admin\Controller\Crud\PageCrudController;
use App\Admin\Controller\Crud\PostCategoryCrudController;
use App\Admin\Controller\Crud\PostCrudController;
use App\Admin\Controller\Crud\ProductCrudController;
use App\Admin\Controller\Crud\ProductReviewCrudController;
use App\Admin\Controller\Crud\PromotionCrudController;
use App\Admin\Controller\Crud\RedirectCrudController;
use App\Admin\Controller\Crud\SettingCrudController;
use App\Admin\Controller\Crud\SolutionCrudController;
use App\Admin\Controller\Crud\SolutionGroupCrudController;
use App\Admin\Controller\Crud\UserCrudController;
use App\Admin\Controller\Crud\WholesaleApplicationCrudController;
use App\Admin\Dashboard\DashboardRange;
use App\Admin\Dashboard\DashboardStats;
use App\Core\Security\Entity\User;
use App\Core\Shared\Site\SiteName;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Config\UserMenu;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Logout\LogoutUrlGenerator;

/** Адмінка (ADR D10): корінь хоста admin_host = дашборд. Меню: Каталог і Продажі для всіх, Налаштування — лише ROLE_ADMIN. */
#[AdminDashboard(routePath: '/', routeName: 'admin', routeOptions: ['host' => '%admin_host%'])]
final class DashboardController extends AbstractDashboardController
{
    public function __construct(
        private readonly DashboardStats $stats,
        private readonly RequestStack $requests,
        private readonly SiteName $siteName,
        #[Autowire(service: 'security.logout_url_generator')] private readonly LogoutUrlGenerator $logoutUrls,
        #[Autowire('%app.dashboard_low_stock_threshold%')] private readonly string $lowStockThreshold = '5',
    ) {
    }

    /**
     * Дашборд — перша сторінка після входу: віджети за діапазон дат (?period=пресет або ?from=&to=, типово 30 днів),
     * агрегати з кешу. Некоректні дати — повідомлення і типовий період (DashboardRange).
     */
    public function index(): Response
    {
        $query = $this->requests->getCurrentRequest()?->query;
        $range = DashboardRange::fromQuery($query?->getString('period'), $query?->getString('from'), $query?->getString('to'));

        return $this->render('admin/dashboard.html.twig', [
            'snapshot' => $this->stats->snapshot($range),
            'range' => $range,
            'presets' => DashboardRange::PRESETS,
            'low_stock_threshold' => $this->lowStockThreshold,
        ]);
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            // логотип і <title> адмінки — назва магазину з паку (app.site_name), платформа — у рядку ліцензії внизу (аудит UX A21)
            ->setTitle($this->siteName->forLocale($this->requests->getCurrentRequest()?->getLocale()))
            // без setLocales: мову перемикає «UA / EN» у верхній панелі (admin/_locale_switcher.html.twig), не список у меню налаштувань
            ->setTranslationDomain('admin');
    }

    /** Дати в усіх списках і картках — як на вітрині й у листах (DisplayFormat): 18.10.2026 11:07, без секунд і назв місяців. */
    public function configureCrud(): Crud
    {
        return parent::configureCrud()
            ->setDateFormat('dd.MM.yyyy')
            ->setDateTimeFormat('dd.MM.yyyy HH:mm');
    }

    public function configureAssets(): Assets
    {
        // не класти в public/admin/: реальна тека затіняла б маршрут (nginx try_files $uri/)
        return Assets::new()
            ->addJsFile('assets/admin-tabs.js')
            ->addJsFile('assets/admin-collection.js')
            ->addJsFile('assets/admin-copy.js')
            ->addJsFile('assets/admin-a11y.js')
            ->addJsFile('assets/admin-search.js');
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('dashboard.menu', 'fa fa-gauge-high');

        yield MenuItem::section('menu.catalog');
        yield MenuItem::linkTo(ProductCrudController::class, 'menu.products', 'fa fa-box');
        yield MenuItem::linkTo(CategoryCrudController::class, 'menu.categories', 'fa fa-sitemap');
        yield MenuItem::linkTo(BrandCrudController::class, 'menu.brands', 'fa fa-tag');
        yield MenuItem::linkTo(AttributeCrudController::class, 'menu.attributes', 'fa fa-list');
        yield MenuItem::linkTo(MediaCrudController::class, 'menu.media', 'fa fa-image');

        yield MenuItem::section('menu.sales');
        yield MenuItem::linkTo(OrderCrudController::class, 'menu.orders', 'fa fa-receipt');
        yield MenuItem::linkTo(PromotionCrudController::class, 'menu.promotions', 'fa fa-percent')->setPermission('ROLE_ADMIN');
        yield MenuItem::linkTo(ProductReviewCrudController::class, 'menu.reviews', 'fa fa-star-half-stroke');

        yield MenuItem::section('menu.wholesale');
        yield MenuItem::linkTo(WholesaleApplicationCrudController::class, 'menu.wholesale_applications', 'fa fa-file-signature');
        yield MenuItem::linkTo(CompanyCrudController::class, 'menu.companies', 'fa fa-building');

        yield MenuItem::section('menu.content');
        yield MenuItem::linkTo(PageCrudController::class, 'menu.pages', 'fa fa-file-lines');
        yield MenuItem::linkTo(PostCrudController::class, 'menu.blog', 'fa fa-newspaper');
        yield MenuItem::linkTo(PostCategoryCrudController::class, 'menu.blog_categories', 'fa fa-folder-open');
        yield MenuItem::linkTo(SolutionCrudController::class, 'menu.solutions', 'fa fa-diagram-project');
        yield MenuItem::linkTo(SolutionGroupCrudController::class, 'menu.solution_groups', 'fa fa-layer-group');

        yield MenuItem::section('menu.seo');
        yield MenuItem::linkTo(RedirectCrudController::class, 'menu.redirects', 'fa fa-share');
        yield MenuItem::linkTo(NotFoundCrudController::class, 'menu.not_found', 'fa fa-triangle-exclamation');

        // E15 PR-1: журнал змін бачать усі (за ціни й асортимент відповідають менеджери — рішення власника 11.10.2026)
        yield MenuItem::section('menu.log');
        yield MenuItem::linkTo(AdminAuditCrudController::class, 'menu.audit', 'fa fa-clock-rotate-left');
        yield MenuItem::linkTo(ChangesetCrudController::class, 'menu.changesets', 'fa fa-layer-group');

        // E15 PR-2: персональні токени для Claude Code / REST — кожен бачить свої, адмін — усі
        yield MenuItem::section('menu.api');
        yield MenuItem::linkTo(AdminApiTokenCrudController::class, 'menu.api_tokens', 'fa fa-key');

        yield MenuItem::section('menu.ai');
        yield MenuItem::linkTo(AiUsageCrudController::class, 'menu.ai_usage', 'fa fa-robot');
        // ключ OpenRouter власник шукав тут, а не в «Налаштуваннях сайту»
        yield MenuItem::linkTo(SettingCrudController::class, 'menu.ai_key', 'fa fa-key')->setAction('aiKey')->setPermission('ROLE_ADMIN');

        // ADR D10: менеджер не бачить Налаштування/Users
        yield MenuItem::section('menu.settings')->setPermission('ROLE_ADMIN');
        yield MenuItem::linkTo(CustomerGroupCrudController::class, 'menu.customer_groups', 'fa fa-users')->setPermission('ROLE_ADMIN');
        yield MenuItem::linkTo(UserCrudController::class, 'menu.users', 'fa fa-user-shield')->setPermission('ROLE_ADMIN');
        yield MenuItem::linkTo(SettingCrudController::class, 'menu.site_settings', 'fa fa-sliders')->setPermission('ROLE_ADMIN');
        // ліцензія платформи: власник, домени, версія рушія, текст і сторонні компоненти (App\Admin\Controller\LicenseController)
        yield MenuItem::linkToRoute('menu.license', 'fa fa-file-contract', 'admin_license')->setPermission('ROLE_ADMIN');
    }

    public function configureUserMenu(UserInterface $user): UserMenu
    {
        return parent::configureUserMenu($user)
            ->setName($user instanceof User ? $user->getDisplayName() : $user->getUserIdentifier())
            ->displayUserAvatar(false)
            // logout з enable_csrf: LogoutUrlGenerator додає _csrf_token до посилання; linkToLogout дав би голий /logout → 403
            ->setMenuItems([MenuItem::linkToUrl('menu.logout', 'fa fa-sign-out', $this->logoutUrls->getLogoutPath('admin'))]);
    }
}
