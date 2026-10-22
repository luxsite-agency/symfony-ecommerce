<?php

declare(strict_types=1);

use App\Site\Routing\SiteRouteLoader;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

// Публічні маршрути будуються з параметрів мов (ADR Rev 8), тому не атрибутами, а сервісом-завантажувачем.
return static function (RoutingConfigurator $routes): void {
    $routes->import(SiteRouteLoader::class.'::__invoke', 'service');
};
