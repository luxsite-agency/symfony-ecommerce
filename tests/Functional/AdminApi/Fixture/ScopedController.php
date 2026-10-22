<?php

declare(strict_types=1);

namespace App\Tests\Functional\AdminApi\Fixture;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Тестові ресурси зі скоупами (config/routes/test/admin_api_fixture.yaml): перевірка ScopeVoter і формату 403 на firewall admin_api. */
#[Route('/api/admin/v1/_test', host: '%admin_host%', format: 'json', name: 'admin_api_test_')]
final class ScopedController extends AbstractController
{
    #[Route('/catalog', name: 'catalog', methods: ['GET'])]
    #[IsGranted('scope:catalog:read')]
    public function catalog(): JsonResponse
    {
        return $this->json(['ok' => 'catalog']);
    }

    #[Route('/prices', name: 'prices', methods: ['GET'])]
    #[IsGranted('scope:prices:write')]
    public function prices(): JsonResponse
    {
        return $this->json(['ok' => 'prices']);
    }
}
