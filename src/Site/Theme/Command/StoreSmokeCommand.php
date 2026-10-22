<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Theme\Command;

use App\Site\Theme\Smoke\SmokePages;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\ServicesResetter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use Throwable;

/**
 * Димова перевірка публічного сайту через ядро, без nginx: головна, блог, кошик і checkout з товаром (тимчасовий кошик за
 * cookie, після перевірки видаляється), вхід/реєстрація/відновлення пароля, перша активна категорія, товар, опублікована
 * сторінка й запис, «прийнято» для простроченого замовлення (410) — кожною мовою (перелік — SmokePages, спільний з живим
 * дизайном ThemeSmoke). Для кандидата релізу (bin/store.d/_remote.sh): шаблони паку (templates/store/, епік E14) мають не лише
 * пройти lint:twig у збірці образу, а й відрендеритись з живою БД (змінні контролерів, strict-помилки, зламаний extends).
 * Провал = реліз не перемикається. Між запитами сервіси скидаються (services_resetter), як у довгоживучому рантаймі:
 * CartResolver і Doctrine не тягнуть стан попереднього запиту. Кожна сторінка — 2xx/3xx (або очікуваний 410) і, для HTML,
 * каркас <main id="main"> та </html>.
 */
#[AsCommand(name: 'store:smoke', description: 'Димова перевірка сторінок сайту через ядро (шаблони теми рендеряться з живою БД)')]
final class StoreSmokeCommand
{
    /** @param list<string> $locales */
    public function __construct(
        private readonly HttpKernelInterface $kernel,
        private readonly SmokePages $pages,
        #[Autowire(service: 'services_resetter')] private readonly ServicesResetter $resetter,
        #[Autowire('%app.locales%')] private readonly array $locales,
        #[Autowire('%app.default_locale%')] private readonly string $defaultLocale,
    ) {
    }

    public function __invoke(SymfonyStyle $io, #[Option('Лише мова за замовчуванням')] bool $defaultOnly = false): int
    {
        $failed = 0;
        $checked = 0;
        [$cartId, $cartToken] = $this->pages->temporaryCart();
        try {
            foreach ($this->pages->targets($defaultOnly ? [$this->defaultLocale] : $this->locales, $cartToken) as $label => $target) {
                ++$checked;
                $problem = $this->check($target['url'], $target['cookies'], $target['expect']);
                $this->resetter->reset();
                if (null === $problem) {
                    $io->writeln(\sprintf('<info>OK</info>   %s  %s', $label, $target['url']));
                } else {
                    ++$failed;
                    $io->writeln(\sprintf('<error>FAIL</error> %s  %s — %s', $label, $target['url'], $problem));
                }
            }
        } finally {
            $this->pages->dropCart($cartId);
        }
        if ($failed > 0) {
            $io->error(\sprintf('store:smoke: %d з %d сторінок не рендеряться', $failed, $checked));

            return Command::FAILURE;
        }
        $io->success(\sprintf('store:smoke: %d сторінок відповідають', $checked));

        return Command::SUCCESS;
    }

    /**
     * null — сторінка в порядку; інакше опис проблеми.
     *
     * @param array<string, string> $cookies
     */
    private function check(string $url, array $cookies, ?int $expect): ?string
    {
        $request = Request::create($url, 'GET', [], $cookies);
        try {
            $response = $this->kernel->handle($request, HttpKernelInterface::MAIN_REQUEST, true);
            if ($this->kernel instanceof TerminableInterface) {
                $this->kernel->terminate($request, $response);
            }
        } catch (Throwable $e) {
            return $e::class.': '.$e->getMessage();
        }
        $status = $response->getStatusCode();
        if (null !== $expect && $status !== $expect) {
            return \sprintf('HTTP %d (очікувалось %d)', $status, $expect);
        }
        if (null === $expect && $status >= 400) {
            return 'HTTP '.$status;
        }
        $content = (string) $response->getContent();
        if (($status < 300 || null !== $expect) && str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
            if (!str_contains($content, '<main id="main">')) {
                return 'HTML без <main id="main"> — базовий каркас не відрендерився';
            }
            if (!str_contains($content, '</html>')) {
                return 'HTML обірвано — нема </html>';
            }
        }

        return null;
    }
}
