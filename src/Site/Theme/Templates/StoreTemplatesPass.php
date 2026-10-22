<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Theme\Templates;

use RuntimeException;
use Symfony\Component\Config\Resource\DirectoryResource;
use Symfony\Component\Config\Resource\FileExistenceResource;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Шаблони паку магазину попереду шаблонів рушія: тека %app.store_templates_dir% (templates/store/, її кладе bin/store deploy
 * з themes/<key>/templates/ паку) стає першою в головному Twig-namespace, тож site/home.html.twig паку перекриває
 * templates/site/home.html.twig рушія; базовий лишається як @engine/site/home.html.twig (twig.paths).
 *
 * Лише коли тека існує: FilesystemLoader::addPath на неіснуючу теку кидає LoaderError і валить будь-який рендер, lint:twig
 * і cache:warmup — тому не twig.paths з параметром, а pass з is_dir. Тека з порушеннями (не-twig, поза site/, заборонена
 * зона) валить компіляцію контейнера: збірка образу падає до перемикання релізу.
 */
final class StoreTemplatesPass implements CompilerPassInterface
{
    public const string PARAMETER = 'app.store_templates_dir';
    public const string LOADER = 'twig.loader.native_filesystem';

    public function process(ContainerBuilder $container): void
    {
        // параметр — у config/services.yaml, лоадер — TwigBundle: їх відсутність має впасти, не мовчати
        $dir = $container->getParameterBag()->resolveValue($container->getParameter(self::PARAMETER));
        if (!\is_string($dir) || '' === $dir) {
            return;
        }
        // dev: поява теки або зміна файлів у ній перекомпілює контейнер
        $container->addResource(new FileExistenceResource($dir));
        if (!is_dir($dir)) {
            return;
        }
        $container->addResource(new DirectoryResource($dir));
        if ([] !== $violations = StoreTemplates::violations($dir)) {
            throw new RuntimeException(\sprintf('Шаблони паку %s не відповідають правилам (docs/themes.md): %s', $dir, implode('; ', $violations)));
        }
        $container->getDefinition(self::LOADER)->addMethodCall('prependPath', [$dir]);
    }
}
