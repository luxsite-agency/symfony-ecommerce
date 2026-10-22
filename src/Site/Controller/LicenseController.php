<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Controller;

use App\Core\License\PlatformLicense;
use App\Core\License\ThirdPartyComponents;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Публічна сторінка ліцензії платформи (site_license, посилання «Ліцензія» у підвалі): текст ліцензії мовою запиту
 * (мови без тексту → en), під ним — сторонні компоненти. noindex, у sitemap не входить, e-commerce подій аналітики не має.
 */
final class LicenseController extends AbstractController
{
    public function __construct(private readonly PlatformLicense $license, private readonly ThirdPartyComponents $components)
    {
    }

    public function __invoke(Request $request): Response
    {
        return $this->render('site/page/license.html.twig', [
            'license' => $this->license,
            'text_locale' => $this->license->textLocale($request->getLocale()),
            'components' => $this->components->all(),
            'noindex' => true,
        ]);
    }
}
