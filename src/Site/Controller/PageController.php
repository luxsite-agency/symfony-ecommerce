<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Controller;

use App\Core\Content\Entity\PageTranslation;
use App\Core\Seo\RedirectManager;
use App\Core\Seo\RedirectPath;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Статична сторінка за слагом поточної мови; неопублікована або без перекладу — 404. У корені сайту (app.page_path_prefix: '')
 * сторінку розбирає SiteCatalogController через find() раніше за категорію, а стара /page/{slug} — 301 (legacy()).
 */
final class PageController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly RedirectManager $redirects)
    {
    }

    public function show(string $slug, Request $request): Response
    {
        $translation = $this->find($slug, $request->getLocale());
        if (null === $translation) {
            throw $this->createNotFoundException('Сторінку не знайдено');
        }

        return $this->page($translation);
    }

    /** Рендер уже знайденої сторінки (SiteCatalogController у корені — без другого запиту, рев'ю #104 L3). */
    public function page(PageTranslation $translation): Response
    {
        return $this->render('site/page/show.html.twig', ['page' => $translation->getPage(), 'tr' => $translation]);
    }

    /**
     * Стара адреса /page/{slug} (префікс до 20.10.2026): 301 на чинну адресу сторінки тієї ж мови. Слаг відтоді змінено — за
     * редиректом чинної адреси старого слага (SlugChangeRecorder пише його вже без /page), одним кроком; інакше 404.
     */
    public function legacy(string $slug, Request $request): Response
    {
        $current = $this->generateUrl('site_page', ['slug' => $slug, '_locale' => $request->getLocale()]);
        if (null !== $this->find($slug, $request->getLocale())) {
            return new RedirectResponse($current, Response::HTTP_MOVED_PERMANENTLY);
        }
        $rule = $this->redirects->find((string) RedirectPath::fromRequest($current));
        if (null !== $rule && $rule['code']->isRedirect() && null !== $rule['target']) {
            return new RedirectResponse($rule['target'], Response::HTTP_MOVED_PERMANENTLY);
        }

        throw $this->createNotFoundException('Сторінку не знайдено');
    }

    /** Опублікована сторінка мовою $locale за слагом або null. */
    public function find(string $slug, string $locale): ?PageTranslation
    {
        $translation = $this->em->getRepository(PageTranslation::class)->findOneBy(['locale' => $locale, 'slug' => $slug]);

        return $translation instanceof PageTranslation && $translation->getPage()->isPublished() ? $translation : null;
    }
}
