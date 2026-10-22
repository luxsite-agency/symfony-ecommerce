<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Order\Mail;

use App\Admin\I18n\AdminLocales;
use App\Core\Order\Entity\Order;
use App\Core\Settings\SettingKeys;
use App\Core\Settings\Settings;
use App\Core\Shared\Format\DisplayFormat;
use App\Core\Shared\Site\SiteName;
use App\Site\SiteUrlGenerator;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Листи про нове замовлення (ADR: листи uk/ru): покупцю — мовою замовлення, якщо є email; магазину — на
 * orders.notify_email із Settings. Два окремі Messenger-повідомлення: збій одного адресата не дублює лист іншому
 * при ретраї (консиліум PR #6 п. 7). Відправник — параметр app.mail_from. На dev листи ловить mailpit.
 * Назва магазину (app.site_name, рядок або мапа за мовою) — мовою замовлення для покупця, мовою магазину для служби.
 * Лист службі — мовою адмінки (AdminLocales::default(), домен admin): його читають ті ж люди, що працюють в адмінці.
 */
final class OrderMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly Settings $settings,
        private readonly TranslatorInterface $translator,
        private readonly SiteUrlGenerator $siteUrls,
        #[Autowire('%app.mail_from%')] private readonly string $from,
        private readonly SiteName $siteName,
        private readonly AdminLocales $adminLocales,
    ) {
    }

    /** Покупцю — мовою замовлення, якщо вказав email. true — лист відправлено. */
    public function sendCustomerEmail(Order $order): bool
    {
        if (null === $order->getEmail()) {
            return false;
        }
        $this->mailer->send((new TemplatedEmail())
            ->from(new Address($this->from, $this->siteName->forLocale($order->getLocale())))
            ->to($order->getEmail())
            ->locale($order->getLocale())
            ->subject($this->translator->trans('email.order.customer_subject', ['number' => $order->getNumber(), 'site' => $this->siteName->forLocale($order->getLocale())], null, $order->getLocale()))
            ->htmlTemplate('emails/order/customer.html.twig')
            ->textTemplate('emails/order/customer.txt.twig')
            ->context($this->context($order)));

        return true;
    }

    /** Магазину — на всі адреси з orders.notify_email. @return int скільки листів відправлено */
    public function sendShopEmail(Order $order): int
    {
        $sent = 0;
        $notify = trim((string) $this->settings->get(SettingKeys::ORDERS_NOTIFY_EMAIL));
        if ('' === $notify) {
            return 0;
        }
        $locale = $this->adminLocales->default();
        $subject = $this->translator->trans('email_order.admin.subject', [
            'site' => $this->siteName->forDefaultLocale(),
            'number' => $order->getNumber(),
            'total' => DisplayFormat::money($order->getTotal(), $locale),
        ], 'admin', $locale);
        foreach (array_filter(array_map(trim(...), explode(',', $notify))) as $to) {
            $this->mailer->send((new TemplatedEmail())
                ->from(new Address($this->from, $this->siteName->forDefaultLocale()))
                ->to($to)
                ->locale($locale)
                ->subject($subject)
                ->htmlTemplate('emails/order/admin.html.twig')
                ->textTemplate('emails/order/admin.txt.twig')
                ->context($this->context($order) + ['adminLocale' => $locale]));
            ++$sent;
        }

        return $sent;
    }

    /** Обидва листи (для CLI/адмінки «надіслати ще раз»). */
    public function sendNewOrder(Order $order): int
    {
        return ($this->sendCustomerEmail($order) ? 1 : 0) + $this->sendShopEmail($order);
    }

    /** @return array<string, mixed> */
    private function context(Order $order): array
    {
        return ['order' => $order, 'siteName' => $this->siteName->forLocale($order->getLocale()), 'siteUrl' => $this->siteUrls->home($order->getLocale())];
    }
}
