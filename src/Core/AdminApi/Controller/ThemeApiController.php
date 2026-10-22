<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\AdminApi\Controller;

use App\Core\AdminApi\Auth\ApiTokenContext;
use App\Core\AdminApi\Auth\ApiWriteLimiter;
use App\Core\AdminApi\Theme\ThemeException;
use App\Core\AdminApi\Theme\ThemeService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * REST живого дизайну (скоуп theme:write; docs/admin-api.md «Дизайн під токеном»). Логіка — ThemeService (спільна з MCP theme_*).
 *
 *   GET    /api/admin/v1/theme                  що можна перекрити, що перекрито (live|pack|engine), theme.css, історія
 *   GET    /api/admin/v1/theme/{path}           поточний текст файлу (живий, з паку або рушія) + версії
 *   PUT    /api/admin/v1/theme/{path}           тіло — текст файлу (text/plain) або JSON {"content": …}
 *   DELETE /api/admin/v1/theme/{path}           прибрати живе перекриття
 *   POST   /api/admin/v1/theme/{path}/revert    JSON {"version": "YYYYMMDD-HHMMSS"}
 * Запис (PUT, DELETE, revert) рахує той самий лімітер спроб запису на токен, що й пакети змін (ApiWriteLimiter).
 */
#[Route('/api/admin/v1/theme', host: '%admin_host%', format: 'json', name: 'admin_api_theme_')]
#[IsGranted(ThemeService::SCOPE)]
final class ThemeApiController extends AbstractController
{
    private const string PATH_RE = 'site/[A-Za-z0-9_][A-Za-z0-9_./-]*\.twig|theme\.css';

    public function __construct(private readonly ThemeService $theme, private readonly ApiTokenContext $tokens, private readonly ApiWriteLimiter $writes)
    {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return $this->run(fn (): array => $this->theme->list());
    }

    #[Route('/{path}', name: 'get', methods: ['GET'], requirements: ['path' => self::PATH_RE])]
    public function get(string $path): JsonResponse
    {
        return $this->run(fn (): array => $this->theme->get($path));
    }

    #[Route('/{path}', name: 'put', methods: ['PUT'], requirements: ['path' => self::PATH_RE])]
    public function put(Request $request, string $path): JsonResponse
    {
        return $this->run(function () use ($request, $path): array {
            $this->throttle();
            $raw = (string) $request->getContent();
            if (str_starts_with((string) $request->headers->get('Content-Type', ''), 'application/json')) {
                $data = json_decode($raw, true);
                if (!\is_array($data) || !\is_string($data['content'] ?? null)) {
                    throw new ThemeException('JSON має містити поле content (рядок)', 400, 'bad_request');
                }
                $raw = $data['content'];
            }

            return $this->theme->put($path, $raw);
        });
    }

    #[Route('/{path}', name: 'delete', methods: ['DELETE'], requirements: ['path' => self::PATH_RE])]
    public function delete(string $path): JsonResponse
    {
        return $this->run(function () use ($path): array {
            $this->throttle();

            return $this->theme->delete($path);
        });
    }

    #[Route('/{path}/revert', name: 'revert', methods: ['POST'], requirements: ['path' => self::PATH_RE])]
    public function revert(Request $request, string $path): JsonResponse
    {
        return $this->run(function () use ($request, $path): array {
            $this->throttle();
            $data = json_decode((string) $request->getContent(), true);

            return $this->theme->revert($path, \is_array($data) && \is_scalar($data['version'] ?? null) ? (string) $data['version'] : '');
        });
    }

    private function throttle(): void
    {
        $token = $this->tokens->current() ?? throw new AccessDeniedException('Лише з API-токеном');
        $retryAfter = $this->writes->consume($token);
        if (null !== $retryAfter) {
            throw new ThemeException('Забагато спроб запису, спробуйте пізніше', Response::HTTP_TOO_MANY_REQUESTS, ApiWriteLimiter::ERROR, ['retry_after' => $retryAfter]);
        }
    }

    /** @param callable(): array<string, mixed> $op */
    private function run(callable $op): JsonResponse
    {
        try {
            return $this->json($op());
        } catch (ThemeException $e) {
            $headers = Response::HTTP_TOO_MANY_REQUESTS === $e->status ? ['Retry-After' => (string) ($e->details['retry_after'] ?? 1)] : [];

            return $this->json($e->payload(), $e->status, $headers);
        }
    }
}
