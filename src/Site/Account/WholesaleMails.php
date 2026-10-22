<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Account;

use App\Core\B2b\Entity\WholesaleApplication;
use App\Core\B2b\Message\SendWholesaleDecisionMail;
use App\Core\Customer\Auth\AuthAudit;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Листи покупцю про рішення по заявці оптовика (транспорт auth_mail). Викликається з адмінки (admin_host), тож
 * посилання на кабінет будується не з хоста запиту, а з site_host (AccountMails бере хост запиту, бо її викликає
 * сам сайт). Мова листа — мова покупця; коментар менеджера йде в обох листах (при схваленні за бажанням).
 */
final class WholesaleMails
{
    public function __construct(
        private readonly MessageBusInterface $bus,
        private readonly UrlGeneratorInterface $urls,
        private readonly AuthAudit $audit,
        #[Autowire('%site_host%')] private readonly string $siteHost,
    ) {
    }

    public function approved(WholesaleApplication $application): void
    {
        $this->send(SendWholesaleDecisionMail::APPROVED, $application, (string) $application->getReviewComment());
    }

    public function rejected(WholesaleApplication $application): void
    {
        $this->send(SendWholesaleDecisionMail::REJECTED, $application, (string) $application->getReviewComment());
    }

    private function send(string $kind, WholesaleApplication $application, string $reason): void
    {
        $customer = $application->getCustomer();
        // хост — сайту (ми на admin_host), схема — з контексту роутера (та сама, що в запиту адмінки)
        $context = $this->urls->getContext();
        $host = $context->getHost();
        $context->setHost($this->siteHost);
        try {
            $url = $this->urls->generate('site_account_wholesale', ['_locale' => $customer->getLocale()], UrlGeneratorInterface::ABSOLUTE_URL);
        } finally {
            $context->setHost($host);
        }
        $this->bus->dispatch(new SendWholesaleDecisionMail($kind, $customer->getEmail(), $customer->getName(), $customer->getLocale(), $url, $reason));
        $this->audit->log('mail_'.$kind, $customer->getEmail(), $customer->getId(), ['application_id' => $application->getId()]);
    }
}
