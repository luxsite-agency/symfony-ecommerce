<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Controller;

use App\Site\Customer\CustomerContext;
use App\Site\SiteNavigation;
use App\Site\Solution\SolutionFinder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Рішення (docs/solutions.md): список видимих мовою сторінки рішень за групами і сторінка рішення. Неопубліковане, без перекладу
 * чи слагу цією мовою — 404; список без жодного рішення — теж 404 (пункту меню тоді нема, адреса не має що показати).
 */
final class SolutionController extends AbstractController
{
    public function __construct(private readonly SolutionFinder $finder, private readonly CustomerContext $customers, private readonly SiteNavigation $navigation)
    {
    }

    public function index(Request $request): Response
    {
        $groups = $this->finder->grouped($request->getLocale());
        if ([] === $groups) {
            throw $this->createNotFoundException('Рішень нема');
        }

        // hreflang списку — лише мовами, де є видимі рішення (інакше /ru/solutions/ — 404; так само в sitemap; рев'ю #102, L2)
        return $this->render('site/solution/index.html.twig', ['groups' => $groups, 'solution_locales' => $this->navigation->solutionLocales()]);
    }

    public function show(string $slug, Request $request): Response
    {
        $locale = $request->getLocale();
        $solution = $this->finder->bySlug($slug, $locale);
        if (null === $solution) {
            throw $this->createNotFoundException('Рішення не знайдено');
        }

        return $this->render('site/solution/show.html.twig', [
            'solution' => $solution,
            'tr' => $solution->getTranslation($locale),
            'options' => $this->finder->options($solution, $locale, $this->customers->group()),
            'related' => $this->finder->related($solution, $locale),
        ]);
    }
}
