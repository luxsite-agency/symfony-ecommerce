<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Theme\Smoke;

use App\Core\Cart\CartResolver;
use App\Core\Cart\Entity\Cart;
use App\Core\Catalog\Entity\Product;
use App\Core\Content\Entity\Page;
use App\Core\Content\Entity\Post;
use App\Core\Content\Entity\Solution;
use App\Core\Order\Entity\Order;
use App\Site\SiteNavigation;
use App\Site\SiteUrlGenerator;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Throwable;

/**
 * Сторінки для димової перевірки сайту з живою БД — спільне для store:smoke (кандидат релізу, StoreSmokeCommand) і живого
 * дизайну (ThemeSmoke після theme_put): статичні маршрути + перша сутність кожного типу (категорія, товар, сторінка, рішення,
 * запис), кошик і checkout з тимчасовим кошиком (cookie; після перевірки видалити dropCart). «Ключові» (keyPages) — підмножина
 * для живої теми: головна, категорія, товар, сторінка, рішення, блог, кошик, checkout.
 *
 * @phpstan-type Target array{url: string, cookies: array<string, string>, expect: ?int}
 */
final class SmokePages
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SiteUrlGenerator $urls,
        private readonly SiteNavigation $navigation,
        #[Autowire('%app.default_currency%')] private readonly string $currency,
        #[Autowire('%app.order_thanks_ttl%')] private readonly int $thanksTtl,
    ) {
    }

    /**
     * Тимчасовий кошик з першим активним товаром (щоб checkout рендерився, а не вів у порожній кошик): [id, cookie-токен];
     * [null, null], якщо товарів нема або товар не додається (крок кількості, опції).
     *
     * @return array{?int, ?string}
     */
    public function temporaryCart(): array
    {
        $product = $this->em->getRepository(Product::class)->findOneBy(['active' => true], ['id' => 'DESC']);
        if (null === $product) {
            return [null, null];
        }
        try {
            $token = bin2hex(random_bytes(32));
            $cart = new Cart(Cart::hashToken($token), $this->currency);
            $cart->addItem($product->getDefaultVariant(), '1');
            $this->em->persist($cart);
            $this->em->flush();

            return [$cart->getId(), $token];
        } catch (Throwable) {
            return [null, null];
        }
    }

    public function dropCart(?int $cartId): void
    {
        if (null === $cartId) {
            return;
        }
        $cart = $this->em->find(Cart::class, $cartId);
        if (null !== $cart) {
            $this->em->remove($cart);
            $this->em->flush();
        }
    }

    /**
     * Усі сторінки store:smoke: статичні маршрути, кошик і checkout (з cookie кошика), перша сутність кожного типу, «прийнято»
     * для простроченого замовлення (410) — кожною мовою.
     *
     * @param list<string> $locales
     *
     * @return array<string, Target>
     */
    public function targets(array $locales, ?string $cartToken): array
    {
        $targets = [];
        foreach ($locales as $locale) {
            foreach (['site_home', 'site_blog', 'site_search', 'site_license', 'site_account_login', 'site_account_register', 'site_account_password'] as $route) {
                $targets[$route.' '.$locale] = $this->target($this->urls->absolute($route, ['_locale' => $locale]));
            }
            $targets += $this->cartTargets($locale, $cartToken) + $this->entityTargets($locale);
            if (null !== $expired = $this->expiredOrder()) {
                $targets['site_order_accepted '.$locale] = $this->target($this->urls->absolute('site_order_thanks', ['token' => $expired->getSuccessToken(), '_locale' => $locale]), [], 410);
            }
        }

        return $targets;
    }

    /**
     * Ключові сторінки живої теми: головна кожною мовою; категорія, товар, сторінка, рішення, блог, кошик і checkout — мовою
     * за замовчуванням (різниця між мовами — лише переклади).
     *
     * @param list<string> $locales
     *
     * @return array<string, Target>
     */
    public function keyPages(array $locales, string $defaultLocale, ?string $cartToken): array
    {
        $targets = [];
        foreach ($locales as $locale) {
            $targets['site_home '.$locale] = $this->target($this->urls->absolute('site_home', ['_locale' => $locale]));
        }
        $targets['site_blog '.$defaultLocale] = $this->target($this->urls->absolute('site_blog', ['_locale' => $defaultLocale]));

        return $targets + $this->entityTargets($defaultLocale) + $this->cartTargets($defaultLocale, $cartToken);
    }

    /** @return array<string, Target> */
    private function cartTargets(string $locale, ?string $cartToken): array
    {
        $cookies = null === $cartToken ? [] : [CartResolver::COOKIE => $cartToken];
        $targets = [];
        foreach (['site_cart', 'site_checkout'] as $route) {
            $targets[$route.' '.$locale] = $this->target($this->urls->absolute($route, ['_locale' => $locale]), $cookies);
        }

        return $targets;
    }

    /** @return array<string, Target> */
    private function entityTargets(string $locale): array
    {
        $category = $this->navigation->rootCategories()[0] ?? null;
        $product = $this->em->getRepository(Product::class)->findOneBy(['active' => true], ['id' => 'DESC']);
        $page = $this->em->getRepository(Page::class)->findOneBy(['published' => true], ['id' => 'DESC']);
        $solution = $this->em->getRepository(Solution::class)->findOneBy(['published' => true], ['id' => 'DESC']);
        /** @var Post|null $post */
        $post = $this->em->createQueryBuilder()->select('p')->from(Post::class, 'p')
            ->where('p.publishedAt IS NOT NULL')->andWhere('p.publishedAt <= :now')->setParameter('now', new DateTimeImmutable())
            ->orderBy('p.publishedAt', 'DESC')->setMaxResults(1)->getQuery()->getOneOrNullResult();
        $targets = [];
        foreach ([
            'site_category' => null !== $category ? $this->urls->category($category, $locale) : null,
            'site_product' => null !== $product ? $this->urls->product($product, $locale) : null,
            'site_page' => null !== $page ? $this->urls->page($page, $locale) : null,
            'site_post' => null !== $post ? $this->urls->post($post, $locale) : null,
            'site_solution' => null !== $solution ? $this->urls->solution($solution, $locale) : null,
            'site_solutions' => null !== $solution && null !== $this->urls->solution($solution, $locale) ? $this->urls->absolute('site_solutions', ['_locale' => $locale]) : null,
        ] as $route => $url) {
            if (null !== $url) {
                $targets[$route.' '.$locale] = $this->target($url);
            }
        }

        return $targets;
    }

    /** Прострочене замовлення з токеном — сторінка «прийнято» (410). */
    private function expiredOrder(): ?Order
    {
        /** @var Order|null $order */
        $order = $this->em->createQueryBuilder()->select('o')->from(Order::class, 'o')
            ->where('o.successToken IS NOT NULL')->andWhere('o.placedAt < :before')
            ->setParameter('before', (new DateTimeImmutable())->modify('-'.$this->thanksTtl.' seconds'))
            ->orderBy('o.placedAt', 'DESC')->setMaxResults(1)->getQuery()->getOneOrNullResult();

        return $order;
    }

    /**
     * @param array<string, string> $cookies
     *
     * @return Target
     */
    private function target(string $url, array $cookies = [], ?int $expect = null): array
    {
        return ['url' => $url, 'cookies' => $cookies, 'expect' => $expect];
    }
}
