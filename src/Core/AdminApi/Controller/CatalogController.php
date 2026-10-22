<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\AdminApi\Controller;

use App\Core\AdminApi\Read\CatalogReader;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Читання каталогу й контенту (E15 PR-4, скоуп catalog:read): товари (пошук, картка за id чи SKU), дерево категорій,
 * сторінки й записи блогу за id чи slug. Відповіді — ті самі ключі, що приймає set_translation / set_variant_price,
 * щоб клієнт зібрав expect з них. Замовлень, покупців, залишків тут нема (ADR E15 §6).
 */
#[Route('/api/admin/v1', host: '%admin_host%', format: 'json', name: 'admin_api_catalog_')]
#[IsGranted('scope:catalog:read')]
final class CatalogController extends AbstractController
{
    public function __construct(private readonly CatalogReader $reader)
    {
    }

    /** ?q=<sku або назва>&category=<id>&limit=20&page=1 */
    #[Route('/products', name: 'products', methods: ['GET'])]
    public function products(Request $request): JsonResponse
    {
        $category = $request->query->get('category');

        return $this->json($this->reader->searchProducts(
            $request->query->get('q'),
            null === $category || '' === $category ? null : (int) $category,
            $request->query->getInt('limit', 20),
            $request->query->getInt('page', 1),
        ));
    }

    #[Route('/products/{ref}', name: 'product', methods: ['GET'], requirements: ['ref' => '[^/]+'])]
    public function product(string $ref): JsonResponse
    {
        return $this->json($this->reader->product(CatalogReader::ref($ref)) ?? throw $this->createNotFoundException('Товар не знайдено'));
    }

    #[Route('/categories', name: 'categories', methods: ['GET'])]
    public function categories(): JsonResponse
    {
        return $this->json(['items' => $this->reader->categories()]);
    }

    #[Route('/pages', name: 'pages', methods: ['GET'])]
    public function pages(): JsonResponse
    {
        return $this->json(['items' => $this->reader->pages()]);
    }

    #[Route('/pages/{ref}', name: 'page', methods: ['GET'], requirements: ['ref' => '[^/]+'])]
    public function page(string $ref): JsonResponse
    {
        return $this->json($this->reader->page(CatalogReader::ref($ref)) ?? throw $this->createNotFoundException('Сторінку не знайдено'));
    }

    #[Route('/posts', name: 'posts', methods: ['GET'])]
    public function posts(Request $request): JsonResponse
    {
        return $this->json(['items' => $this->reader->posts($request->query->getInt('limit', 20), $request->query->getInt('page', 1))]);
    }

    #[Route('/posts/{ref}', name: 'post', methods: ['GET'], requirements: ['ref' => '[^/]+'])]
    public function post(string $ref): JsonResponse
    {
        return $this->json($this->reader->post(CatalogReader::ref($ref)) ?? throw $this->createNotFoundException('Запис не знайдено'));
    }
}
