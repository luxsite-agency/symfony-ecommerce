<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Redirect;

use App\Core\Seo\Enum\RedirectCode;
use App\Core\Seo\LiveUrls;
use App\Core\Seo\NotFoundLog;
use App\Core\Seo\RedirectManager;
use App\Core\Seo\RedirectPath;
use Doctrine\DBAL\Exception as DbalException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Менеджер редиректів на сайті (епік E3): лише на 404 (маршрут не знайдено або контролер не знайшов сутність), GET/HEAD,
 * не на хості адмінки. Шлях запиту нормалізується (RedirectPath) і шукається одним запитом за унікальним індексом:
 * 301/302 — переадресація (query запиту переноситься, якщо в цілі свого нема), 410 — сторінка «видалено» сайту
 * (error410), 404 — звичайна 404 без запису в журнал. Правила нема, але нормалізована адреса інша (регістр, подвійні
 * слеші) і жива — 301 на неї, без журналу (/Tovar → /tovar). Інакше — шлях у журнал 404. Збій БД — звичайна 404.
 * Пріоритет вище за ErrorListener (-128) і його логування (0): переадресація не пише помилку в лог.
 * Лічильник спрацювань — після відповіді (kernel.terminate): блокування рядка (авто-301 пише в ту саму таблицю) не
 * затримує й не ламає переадресацію (рев'ю #37, п. 5).
 */
final class NotFoundRedirectListener implements ResetInterface
{
    private ?int $pendingHit = null;

    public function __construct(
        private readonly RedirectManager $redirects,
        private readonly NotFoundLog $log,
        private readonly LiveUrls $live,
        private readonly LoggerInterface $logger,
        #[Autowire('%admin_host%')] private readonly string $adminHost,
    ) {
    }

    #[AsEventListener(event: KernelEvents::EXCEPTION, priority: 16)]
    public function onException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$exception instanceof NotFoundHttpException || !\in_array($request->getMethod(), ['GET', 'HEAD'], true)
            || 0 === strcasecmp($request->getHost(), $this->adminHost)) {
            return;
        }
        $path = RedirectPath::fromRequest($request->getPathInfo());
        if (null === $path) {
            return;
        }
        try {
            $rule = $this->redirects->find($path);
            if (null === $rule) {
                if ($path !== rawurldecode($request->getPathInfo()) && $this->live->isLive($path)) {
                    $event->setResponse(new RedirectResponse($this->location($request, $path), RedirectCode::Permanent->value));
                } else {
                    $this->log->record($path, $request->headers->get('referer'));
                }

                return;
            }
        } catch (DbalException $e) {
            $this->logger->warning('Редиректи недоступні: {message}', ['message' => $e->getMessage()]);

            return;
        }
        $this->pendingHit = $rule['id'];
        if ($rule['code']->isRedirect() && null !== $rule['target']) {
            $event->setResponse(new RedirectResponse($this->location($request, $rule['target']), $rule['code']->value));
        } elseif (RedirectCode::Gone === $rule['code']) {
            $event->setThrowable(new GoneHttpException('Сторінку видалено', $exception));
        }
    }

    #[AsEventListener(event: KernelEvents::TERMINATE)]
    public function onTerminate(TerminateEvent $event): void
    {
        if (null === $this->pendingHit) {
            return;
        }
        $id = $this->pendingHit;
        $this->pendingHit = null;
        try {
            $this->redirects->hit($id);
        } catch (DbalException $e) {
            $this->logger->info('Лічильник редиректу не оновлено: {message}', ['message' => $e->getMessage()]);
        }
    }

    public function reset(): void
    {
        $this->pendingHit = null;
    }

    private function location(Request $request, string $target): string
    {
        $location = RedirectPath::isAbsoluteUrl($target) ? $target : $request->getBaseUrl().RedirectPath::encode($target);
        $query = (string) $request->getQueryString();

        return '' !== $query && !str_contains($location, '?') ? $location.'?'.$query : $location;
    }
}
