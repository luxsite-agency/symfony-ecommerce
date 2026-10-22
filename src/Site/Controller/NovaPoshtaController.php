<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Controller;

use App\Core\Shipping\NovaPoshta\NovaPoshtaCityView;
use App\Core\Shipping\NovaPoshta\NovaPoshtaDirectory;
use App\Core\Shipping\NovaPoshta\NovaPoshtaWarehouseView;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/** JSON для автодоповнення на checkout (fetch без бібліотек): міста за префіксом, відділення міста. 404 — довідник вимкнено. */
final class NovaPoshtaController extends AbstractController
{
    public function __construct(private readonly NovaPoshtaDirectory $directory)
    {
    }

    public function cities(Request $request): JsonResponse
    {
        $this->assertEnabled();
        $locale = $request->getLocale();
        $items = array_map(static fn (NovaPoshtaCityView $c): array => ['ref' => $c->ref, 'name' => $c->name($locale), 'label' => $c->label($locale)], $this->directory->searchCities($request->query->getString('q'), $locale));

        return $this->cached(new JsonResponse(['items' => $items]));
    }

    public function warehouses(Request $request): JsonResponse
    {
        $this->assertEnabled();
        $locale = $request->getLocale();
        $cityRef = trim($request->query->getString('city'));
        $query = $request->query->getString('q');
        $items = '' === $cityRef ? [] : array_map(static fn (NovaPoshtaWarehouseView $w): array => ['ref' => $w->ref, 'number' => $w->number, 'type' => $w->type, 'label' => $w->label($locale)], $this->directory->warehouses($cityRef, $locale, '' === $query ? null : $query));

        return $this->cached(new JsonResponse(['items' => $items]));
    }

    private function assertEnabled(): void
    {
        if (!$this->directory->isEnabled()) {
            throw $this->createNotFoundException('Довідники Нової пошти вимкнено');
        }
    }

    private function cached(JsonResponse $response): JsonResponse
    {
        // довідник міняється раз на добу — 5 хв у браузері/проксі знімають повторні запити при наборі
        $response->setPublic()->setMaxAge(300);

        return $response;
    }
}
