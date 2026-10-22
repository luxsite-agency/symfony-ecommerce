<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Core\License\EngineRelease;
use App\Core\License\PlatformLicense;
use App\Core\License\ThirdPartyComponents;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Сторінка «Ліцензія» в адмінці (меню «Налаштування», посилання у підвалі кожної сторінки адмінки): власник, домени, версія
 * рушія (sha релізу зі STORE_RELEASE), тип ліцензії, договір і дата з app.license; далі той самий текст ліцензії, що на сайті, і
 * перелік сторонніх компонентів. Маршрут admin_license під дашбордом (#[AdminRoute]), лише ROLE_ADMIN; анонім — на вхід.
 */
#[AdminRoute(path: '/license', name: 'license', options: ['host' => '%admin_host%'])]
#[IsGranted('ROLE_ADMIN')]
final class LicenseController extends AbstractController
{
    public function __construct(
        private readonly PlatformLicense $license,
        private readonly ThirdPartyComponents $components,
        private readonly EngineRelease $release,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        return $this->render('admin/license/index.html.twig', [
            'license' => $this->license,
            'release' => $this->release,
            'text_locale' => $this->license->textLocale($request->getLocale()),
            'components' => $this->components->all(),
        ]);
    }
}
