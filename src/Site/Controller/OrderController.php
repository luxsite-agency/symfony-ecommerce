<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Controller;

use App\Core\Analytics\AnalyticsTags;
use App\Core\Analytics\DataLayerCollector;
use App\Core\Analytics\Ecommerce\EcommerceEventBuilder;
use App\Core\Analytics\Ecommerce\PurchaseDedup;
use App\Core\Order\Entity\Order;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;

/**
 * «Дякуємо» (ADR D6, консиліум безпеки 10.10.2026 п. 4). Посилання після оформлення — за opaque-токеном
 * (/order/{token}/thanks), але сам токен на екран не потрапляє: контролер кладе id замовлення в сесію і робить 302 на
 * /order/thanks без токена, і лише ця адреса йде в GA4/GTM/Meta як page_location. Токен діє app.order_thanks_ttl (24 год):
 * далі — сторінка «замовлення прийнято» без ПД і без тегів аналітики (токен у її адресі), бо посилання з ПД інакше
 * жило б вічно в історії браузера, листах і журналах. purchase у dataLayer — один раз на замовлення (PurchaseDedup).
 */
final class OrderController extends AbstractController
{
    private const string SESSION_KEY = 'order.thanks';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DataLayerCollector $dataLayer,
        private readonly EcommerceEventBuilder $events,
        private readonly PurchaseDedup $dedup,
        #[Autowire('%app.order_thanks_ttl%')] private readonly int $ttl,
    ) {
    }

    /** Посилання з токеном: живе — id у сесію і 302 без токена; прострочене — «прийнято» без деталей і без аналітики. */
    public function thanks(string $token, Request $request): Response
    {
        $order = $this->em->getRepository(Order::class)->findOneBy(['successToken' => $token]) ?? throw $this->createNotFoundException();
        if ($this->expired($order)) {
            $request->attributes->set(AnalyticsTags::NO_TRACKING, true);

            return self::private($this->render('site/order/accepted.html.twig', ['noindex' => true], new Response(null, 410)));
        }
        $request->getSession()->set(self::SESSION_KEY, $order->getId());

        return self::private($this->redirectToRoute('site_order_thanks_page', ['_locale' => $request->getLocale()]));
    }

    /** Сторінка без токена в адресі: замовлення — з сесії; без нього (нова сесія, прострочено) — на головну. */
    public function page(Request $request): Response
    {
        $id = $request->hasPreviousSession() ? $request->getSession()->get(self::SESSION_KEY) : null;
        $order = \is_int($id) ? $this->em->find(Order::class, $id) : null;
        if (null === $order || $this->expired($order)) {
            return $this->redirectToRoute('site_home', ['_locale' => $request->getLocale()]);
        }
        if ($this->dedup->firstTime($order->getNumber())) {
            $items = [];
            foreach ($order->getItems() as $item) {
                if (null !== $item->getParent()) {
                    continue;
                }
                if (null !== $item->getVariant()) {
                    // ціна і знижка зі снепшота замовлення, не поточні
                    $payload = $this->events->item($item->getVariant(), $item->getQuantity(), null, null, $request->getLocale(), $item->getUnitPrice(), $item->getListPrice() ?? $item->getUnitPrice());
                } else {
                    $payload = ['item_id' => $item->getSku(), 'item_name' => $item->getName(), 'price' => (float) $item->getUnitPrice()->toDecimalString(), 'quantity' => (float) $item->getQuantity(), 'currency' => $order->getCurrency()];
                }
                $items[] = $payload;
            }
            $this->dataLayer->push($this->events->purchase($order->getNumber(), $items, $order->getShipping()->amount));
        }

        return self::private($this->render('site/order/thanks.html.twig', ['order' => $order, 'noindex' => true]));
    }

    private function expired(Order $order): bool
    {
        return $order->getPlacedAt()->modify('+'.$this->ttl.' seconds') < new DateTimeImmutable();
    }

    /** Не в Referer сторонніх скриптів і не в кеші браузера/проксі (консиліум PR #6, low). */
    private static function private(Response $response): Response
    {
        $response->headers->set('Referrer-Policy', 'same-origin');
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true'); // інакше слухач сесії підмінить на private, must-revalidate

        return $response;
    }
}
