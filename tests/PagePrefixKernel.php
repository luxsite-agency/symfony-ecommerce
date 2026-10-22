<?php

declare(strict_types=1);

namespace App\Tests;

use App\Kernel;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Ядро тестів режиму «сторінки з префіксом» (app.page_path_prefix: '/info'; типово сторінки в корені): той самий застосунок,
 * інший параметр і окремий кеш (контейнер і маршрути компілюються під параметр). Тест обирає його через getKernelClass().
 */
final class PagePrefixKernel extends Kernel
{
    public const string PREFIX = '/info';

    public function getCacheDir(): string
    {
        return parent::getCacheDir().'_page_prefix';
    }

    public function process(ContainerBuilder $container): void
    {
        $container->setParameter('app.page_path_prefix', self::PREFIX);
        parent::process($container);
    }
}
