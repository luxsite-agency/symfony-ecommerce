<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Admin\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Пошук товару для варіанта рішення (автокомпліт EasyAdmin у SolutionOptionType): назва мовою магазину або SKU варіанта,
 * формат відповіді EasyAdmin ({results: [{entityId, entityAsString}], next_page}). Окремий маршрут, бо вбудований autocomplete
 * EasyAdmin шукає лише за полем самого CRUD, а не всередині колекції. Права — як у редагування рішень (ROLE_MANAGER).
 */
#[Route(host: '%admin_host%')]
final class SolutionProductLookupController extends AbstractController
{
    private const int LIMIT = 20;

    public function __construct(
        private readonly Connection $db,
        private readonly TranslatorInterface $translator,
        #[Autowire('%app.default_locale%')] private readonly string $defaultLocale,
    ) {
    }

    #[Route('/solution-products', name: 'admin_solution_products', methods: ['GET'])]
    #[IsGranted('ROLE_MANAGER')]
    public function __invoke(Request $request): JsonResponse
    {
        $query = trim((string) $request->query->get('query', ''));
        $page = max(1, $request->query->getInt('page', 1));
        $like = '%'.addcslashes($query, '%_\\').'%';
        $rows = $this->db->fetchAllAssociative(
            'SELECT p.id, p.active, COALESCE(NULLIF(t.name, \'\'), MIN(v.sku)) AS name, MIN(v.sku) AS sku
             FROM catalog_product p
             LEFT JOIN catalog_product_translation t ON t.product_id = p.id AND t.locale = :locale
             LEFT JOIN catalog_product_variant v ON v.product_id = p.id
             WHERE :q = \'\' OR t.name LIKE :like OR v.sku LIKE :like
             GROUP BY p.id, p.active, t.name
             ORDER BY t.name, p.id
             LIMIT '.(self::LIMIT + 1).' OFFSET '.(($page - 1) * self::LIMIT),
            ['locale' => $this->defaultLocale, 'q' => $query, 'like' => $like],
        );
        $more = \count($rows) > self::LIMIT;
        // неактивний товар можна додати наперед (на сайті рядок варіанта не показується, доки товар вимкнено) — з позначкою (рев'ю #102, L4)
        $inactive = $this->translator->trans('solution.lookup_inactive', [], 'admin');
        $results = array_map(static fn (array $r): array => [
            'entityId' => (int) $r['id'],
            'entityAsString' => trim((string) $r['name']).(null !== $r['sku'] && $r['sku'] !== $r['name'] ? ' · '.$r['sku'] : '').($r['active'] ? '' : ' · '.$inactive),
        ], \array_slice($rows, 0, self::LIMIT));

        return new JsonResponse([
            'results' => $results,
            'next_page' => $more ? $this->generateUrl('admin_solution_products', ['page' => $page + 1, 'query' => $query]) : null,
        ]);
    }
}
