<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Customer\MessageHandler;

use App\Core\Customer\Message\SendCustomerAuthMail;
use App\Core\Shared\Site\SiteName;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsMessageHandler]
final class SendCustomerAuthMailHandler
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly TranslatorInterface $translator,
        private readonly SiteName $siteName,
        #[Autowire('%app.mail_from%')] private readonly string $from,
    ) {
    }

    public function __invoke(SendCustomerAuthMail $message): void
    {
        $site = $this->siteName->forLocale($message->locale);
        $this->mailer->send((new TemplatedEmail())
            ->from(new Address($this->from, $site))
            ->to($message->email)
            ->locale($message->locale)
            ->subject($this->translator->trans('email.account.'.$message->kind.'.subject', ['site' => $site], null, $message->locale))
            ->htmlTemplate('emails/account/'.$message->kind.'.html.twig')
            ->textTemplate('emails/account/'.$message->kind.'.txt.twig')
            ->context(['name' => $message->name, 'url' => $message->url, 'ttl' => $message->ttlMinutes, 'siteName' => $site, 'l' => $message->locale, 'detail' => $message->detail]));
    }
}
