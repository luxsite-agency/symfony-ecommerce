<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App;

use App\Site\Theme\Templates\StoreTemplatesPass;
use App\Site\Twig\GmbReviewsFallbackExtension;
use Luxsite\GmbReviewsBundle\GmbReviewsBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel implements CompilerPassInterface
{
    use MicroKernelTrait {
        configureContainer as private configureContainerDefaults;
        registerBundles as private registerBundlesDefaults;
    }

    /**
     * Необов'язкові пакети (docs/addons.md): бандл => конфіг у config/addons/. Реєструються, лише коли пакет встановлено
     * (store.yaml addons → composer require у збірці образу); без пакета — заглушки Twig-функцій, блоки просто не показуються.
     */
    private const array ADDON_BUNDLES = [
        GmbReviewsBundle::class => 'gmb_reviews.yaml',
    ];

    private const string FRAMEWORK_DEFAULT_TRUSTED_HOSTS = '%env(default::SYMFONY_TRUSTED_HOSTS)%';

    /**
     * @return list<string> An array of allowed values for APP_ENV
     */
    private function getAllowedEnvs(): array
    {
        return ['prod', 'dev', 'test'];
    }

    /** config/bundles.php + встановлені необов'язкові пакети (ADDON_BUNDLES). */
    public function registerBundles(): iterable
    {
        $registered = [];
        foreach ($this->registerBundlesDefaults() as $bundle) {
            $registered[$bundle::class] = true;
            yield $bundle;
        }
        // уже в bundles.php (Flex дописав при composer require без --no-plugins) — вдруге не реєструємо
        foreach (self::addonBundles() as $class) {
            if (!isset($registered[$class])) {
                yield new $class();
            }
        }
    }

    /**
     * Встановлені необов'язкові бандли: class_exists, тож залежить лише від vendor/, без окремого прапорця.
     *
     * @return list<string>
     */
    public static function addonBundles(): array
    {
        $installed = [];
        foreach (array_keys(self::ADDON_BUNDLES) as $class) {
            if (class_exists($class)) {
                $installed[] = $class;
            }
        }

        return $installed;
    }

    /**
     * Параметри конкретного розгортання (admin_host, site_host, app.site_name…) — у config/store/parameters.local.yaml
     * поза git. Імпорт після services.yaml, щоб перекрити його дефолти (import усередині services.yaml
     * програв би параметрам самого файлу). Env у маршрутах Symfony заборонений, тому саме параметри, а не %env()%.
     * Конфіг встановлених необов'язкових пакетів — config/addons/ (ADDON_BUNDLES).
     */
    private function configureContainer(ContainerConfigurator $container): void
    {
        $this->configureContainerDefaults($container);

        $addons = self::addonBundles();
        foreach ($addons as $class) {
            $container->import($this->getConfigDir().'/addons/'.self::ADDON_BUNDLES[$class]);
        }
        // без бандла gbp-manager — заглушки gmb_* (порожньо), щоб шаблони рушія й тем компілювались; з бандлом функції дає він
        if (!\in_array(GmbReviewsBundle::class, $addons, true)) {
            $container->services()->set(GmbReviewsFallbackExtension::class)->tag('twig.extension');
        }

        if ('test' !== $this->environment) {
            $container->import($this->getConfigDir().'/store/parameters.local.yaml', null, 'not_found');
        }
    }

    /** Шаблони паку магазину (templates/store/) попереду шаблонів рушія — лише коли тека існує (E14, docs/themes.md). */
    protected function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new StoreTemplatesPass());
    }

    /**
     * framework.trusted_hosts у prod (рев'ю безпеки #45, п. 5): посилання в листах (підтвердження, новий пароль, вхід)
     * будуються з хоста запиту, і запит з чужим Host дав би лист з посиланням на чужий домен. Хости беруться з тих самих
     * параметрів site_host і admin_host, що їх пише деплой (плюс app.extra_hosts для www чи другого домену), тож окремої
     * env, яку можна забути, нема. Якщо framework.trusted_hosts задано в конфігу явно, лишається воно. dev і test не
     * обмежуються: там worktree-хости й localhost тестового клієнта.
     */
    public function process(ContainerBuilder $container): void
    {
        if ('prod' !== $this->environment) {
            return;
        }
        // дефолт FrameworkBundle — env SYMFONY_TRUSTED_HOSTS (тут уже плейсхолдер env_…, тому назад у %env()%); інше
        // значення задано в конфігу явно, його не чіпаємо
        $current = $container->resolveEnvPlaceholders($container->getParameter('kernel.trusted_hosts'));
        if (self::FRAMEWORK_DEFAULT_TRUSTED_HOSTS !== (\is_array($current) && 1 === \count($current) ? reset($current) : $current)) {
            return;
        }
        // параметри — services.yaml (дефолти) і config/store/parameters.local.yaml розгортання
        $hosts = [$container->getParameter('site_host'), $container->getParameter('admin_host'), ...$container->getParameter('app.extra_hosts')];
        $container->setParameter('kernel.trusted_hosts', self::trustedHostPatterns($hosts));
    }

    /**
     * Точні імена хостів → регулярні вирази Request::setTrustedHosts (крапка екранована: `shop.example.com` не пропустить
     * `shopXexample.com`).
     *
     * @param array<mixed> $hosts
     *
     * @return list<string>
     */
    public static function trustedHostPatterns(array $hosts): array
    {
        $patterns = [];
        foreach ($hosts as $host) {
            if (\is_string($host) && '' !== $host = strtolower(trim($host))) {
                $patterns[] = '^'.preg_quote($host).'$';
            }
        }

        return array_values(array_unique($patterns));
    }
}
