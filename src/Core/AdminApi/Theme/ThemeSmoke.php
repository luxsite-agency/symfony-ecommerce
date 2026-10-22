<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\AdminApi\Theme;

use App\Core\Cart\CartResolver;
use App\Site\Theme\Smoke\SmokePages;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * Рендер ключових сторінок після запису живого файлу (ThemeService::put): головна кожною мовою, категорія, товар, сторінка,
 * рішення, блог, кошик і checkout з тимчасовим кошиком (SmokePages::keyPages). Як у service-engine — через HTTP до nginx стека
 * (app.theme_smoke_url, Host = site_host: той самий шлях, що у відвідувача, з opcache і compose-мережею); 'kernel' — через ядро
 * без nginx (тести; стенд без тома). Провал = 5xx, 4xx, обірваний HTML, кошик/checkout з редиректом замість сторінки — і страж
 * форм (рішення власника 20.10.2026): жива тема не може прибрати CSRF-токен, підмінити action форми чи зняти з checkout поля
 * key/price (підтвердження ціни). Один рядок з причиною — ThemeService повертає файл як був і відповідає 422.
 */
final class ThemeSmoke
{
    public const string KERNEL = 'kernel';
    /** Таймаут однієї сторінки і загальний бюджет, с: nginx на /api/admin чекає 120 с, клієнт має отримати 422, а не 504 (L2). */
    public const int PAGE_TIMEOUT = 8;
    public const int BUDGET = 60;

    /** @param list<string> $locales */
    public function __construct(
        private readonly SmokePages $pages,
        private readonly HttpClientInterface $http,
        private readonly HttpKernelInterface $kernel,
        private readonly RequestStack $requests,
        private readonly TokenStorageInterface $tokens,
        private readonly CartResolver $carts,
        #[Autowire('%app.theme_smoke_url%')] private readonly string $smokeUrl,
        #[Autowire('%site_host%')] private readonly string $siteHost,
        #[Autowire('%app.locales%')] private readonly array $locales,
        #[Autowire('%app.default_locale%')] private readonly string $defaultLocale,
    ) {
    }

    /**
     * Перевірити ключові сторінки: [null, n] — усі n рендеряться; [«сторінка: причина», n] — перша проблема.
     *
     * @return array{?string, int}
     */
    public function run(): array
    {
        [$cartId, $cartToken] = $this->pages->temporaryCart();
        $checked = 0;
        $deadline = microtime(true) + self::BUDGET;
        try {
            foreach ($this->pages->keyPages($this->locales, $this->defaultLocale, $cartToken) as $label => $target) {
                if (microtime(true) >= $deadline) {
                    return [\sprintf('%s: перевищено бюджет %d с на рендер ключових сторінок — сайт відповідає надто повільно', $label, self::BUDGET), $checked];
                }
                ++$checked;
                $route = explode(' ', $label)[0];
                $problem = $this->check($route, $target['url'], $target['cookies'], null !== $cartToken);
                if (null !== $problem) {
                    return [$label.': '.$problem, $checked];
                }
            }
        } finally {
            $this->pages->dropCart($cartId);
        }

        return [null, $checked];
    }

    /** @param array<string, string> $cookies */
    private function check(string $route, string $url, array $cookies, bool $hasCart): ?string
    {
        try {
            [$status, $type, $html] = self::KERNEL === $this->smokeUrl ? $this->viaKernel($url, $cookies) : $this->viaHttp($url, $cookies);
        } catch (Throwable $e) {
            return $e::class.': '.$e->getMessage();
        }
        if ($status >= 400) {
            return 'HTTP '.$status;
        }
        $withCart = $hasCart && \in_array($route, ['site_cart', 'site_checkout'], true);
        if ($status >= 300) {
            return $withCart ? \sprintf('HTTP %d — редирект замість сторінки з товаром у кошику', $status) : null;
        }
        if (!str_contains($type, 'text/html')) {
            return null;
        }
        if (!str_contains($html, '<main id="main">')) {
            return 'HTML без <main id="main"> — базовий каркас не відрендерився';
        }
        if (!str_contains($html, '</html>')) {
            return 'HTML обірвано — нема </html>';
        }

        return $this->forms($route, $html, $withCart);
    }

    /**
     * Страж форм: кожна POST-форма — з _token і дією на своєму сайті; checkout — форма з key і price (підтвердження ціни) та
     * кнопкою; кошик з товаром — форми зміни кількості/видалення на місці.
     */
    private function forms(string $route, string $html, bool $withCart): ?string
    {
        $doc = new DOMDocument();
        $loaded = @$doc->loadHTML('<?xml encoding="UTF-8">'.$html, \LIBXML_NOERROR | \LIBXML_NOWARNING | \LIBXML_NONET);
        if (!$loaded) {
            return 'HTML не розбирається';
        }
        $xpath = new DOMXPath($doc);
        $posts = 0;
        $checkout = false;
        foreach ($xpath->query('//form') ?: [] as $form) {
            if (!$form instanceof DOMElement || 'post' !== strtolower($form->getAttribute('method'))) {
                continue;
            }
            ++$posts;
            $action = $form->getAttribute('action');
            if (!$this->ownAction($action)) {
                return \sprintf('форма з action="%s" — лише адреси свого сайту', $action);
            }
            $token = $xpath->query('.//input[@name="_token"]', $form);
            $value = false !== $token && $token->length > 0 && $token->item(0) instanceof DOMElement ? $token->item(0)->getAttribute('value') : '';
            if ('' === $value) {
                return \sprintf('POST-форма (action="%s") без CSRF-токена _token', $action);
            }
            if ('site_checkout' === $route && str_contains(' '.$form->getAttribute('class').' ', ' checkout ') && !str_contains(' '.$form->getAttribute('class').' ', ' lead-form ')) {
                foreach (['key', 'price'] as $field) {
                    $input = $xpath->query('.//input[@name="'.$field.'"]', $form);
                    if (false === $input || 0 === $input->length) {
                        return \sprintf('форма checkout без поля %s (підтвердження ціни)', $field);
                    }
                }
                $submit = $xpath->query('.//button[not(@type) or @type="submit"] | .//input[@type="submit"]', $form);
                if (false === $submit || 0 === $submit->length) {
                    return 'форма checkout без кнопки надсилання';
                }
                $checkout = true;
            }
        }
        if ($withCart && 'site_checkout' === $route && !$checkout) {
            return 'на checkout нема форми form.checkout';
        }
        if ($withCart && 'site_cart' === $route && 0 === $posts) {
            return 'у кошику з товаром нема форм зміни кількості/видалення';
        }

        return null;
    }

    private function ownAction(string $action): bool
    {
        if ('' === $action) {
            return true;
        }
        if (str_starts_with($action, '/')) {
            return !str_starts_with($action, '//');
        }
        $host = parse_url($action, \PHP_URL_HOST);

        return \is_string($host) && 0 === strcasecmp($host, $this->siteHost);
    }

    /**
     * @param array<string, string> $cookies
     *
     * @return array{int, string, string} статус, Content-Type, тіло
     */
    private function viaHttp(string $url, array $cookies): array
    {
        $path = (string) parse_url($url, \PHP_URL_PATH);
        $query = parse_url($url, \PHP_URL_QUERY);
        $headers = ['Host' => $this->siteHost, 'X-Forwarded-Proto' => 'https', 'User-Agent' => 'shop-engine theme-check', 'Accept' => 'text/html'];
        if ([] !== $cookies) {
            $headers['Cookie'] = implode('; ', array_map(static fn (string $k, string $v): string => $k.'='.rawurlencode($v), array_keys($cookies), $cookies));
        }
        $response = $this->http->request('GET', rtrim($this->smokeUrl, '/').$path.(\is_string($query) ? '?'.$query : ''), ['headers' => $headers, 'max_redirects' => 0, 'timeout' => self::PAGE_TIMEOUT, 'max_duration' => self::PAGE_TIMEOUT]);
        $status = $response->getStatusCode();

        return [$status, implode(',', $response->getHeaders(false)['content-type'] ?? []), $response->getContent(false)];
    }

    /**
     * Через ядро, зсередини запиту API: на час рендера прибираємо з RequestStack зовнішній запит (CartResolver читає cookie
     * головного запиту) і токен адміна з TokenStorage (сторінка має рендеритись для гостя), після — повертаємо.
     *
     * @param array<string, string> $cookies
     *
     * @return array{int, string, string}
     */
    private function viaKernel(string $url, array $cookies): array
    {
        $outer = [];
        while (null !== $request = $this->requests->pop()) {
            array_unshift($outer, $request);
        }
        $token = $this->tokens->getToken();
        $this->tokens->setToken(null);
        $this->carts->reset();
        try {
            $response = $this->kernel->handle(Request::create($url, 'GET', [], $cookies), HttpKernelInterface::MAIN_REQUEST, false);
        } finally {
            $this->carts->reset();
            $this->tokens->setToken($token);
            foreach ($outer as $request) {
                $this->requests->push($request);
            }
        }

        return [$response->getStatusCode(), (string) $response->headers->get('Content-Type'), (string) $response->getContent()];
    }
}
