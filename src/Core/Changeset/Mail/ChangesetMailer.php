<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Changeset\Mail;

use App\Admin\I18n\AdminLocales;
use App\Core\Changeset\Dto\Operation;
use App\Core\Changeset\Entity\Changeset;
use App\Core\Security\Entity\User;
use App\Core\Security\Enum\UserRole;
use App\Core\Settings\SettingKeys;
use App\Core\Settings\Settings;
use App\Core\Shared\Site\SiteName;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Лист про застосований ціновий пакет (E15 PR-4, рішення власника 11.10 п. 4): не адмінам, а відповідальним за ціни —
 * адреси з prices.notify_email, порожньо → усі активні користувачі з роллю менеджера (без адміністраторів). Один лист на
 * всі адреси; зміст — хто створив і застосував, прапорці й підтвердження, перші рядки цінового diff, посилання на пакет
 * і журнал. HTML-полів у листі нема — лише ціни. Мова листа — типова мова адмінки (AdminLocales::default()), тексти —
 * email_changeset.* домену admin.
 */
final class ChangesetMailer
{
    private const int MAX_ROWS = 20;

    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly Settings $settings,
        private readonly EntityManagerInterface $em,
        private readonly SiteName $siteName,
        private readonly UrlGeneratorInterface $urls,
        private readonly TranslatorInterface $translator,
        private readonly AdminLocales $adminLocales,
        #[Autowire('%app.mail_from%')] private readonly string $from,
        #[Autowire('%admin_host%')] private readonly string $adminHost,
    ) {
    }

    /** @return int скільки адресатів */
    public function sendPriceApplied(Changeset $changeset): int
    {
        $to = $this->recipients();
        if ([] === $to || !$changeset->hasPriceOperations()) {
            return 0;
        }
        $rows = [];
        foreach ($changeset->getDiff() as $diff) {
            if (!\in_array($diff['op'], Operation::PRICE_TYPES, true)) {
                continue;
            }
            foreach ($diff['changes'] as $change) {
                $rows[] = ['target' => (string) ($diff['target']['label'] ?? '#'.$diff['target']['id']), 'field' => (string) $change['field'], 'before' => $change['before'], 'after' => $change['after'], 'percent' => self::percent($change['before'], $change['after'])];
            }
        }
        $id = (int) $changeset->getId();
        $site = $this->siteName->forDefaultLocale();
        $locale = $this->adminLocales->default();
        $this->mailer->send((new TemplatedEmail())
            ->from(new Address($this->from, $site))
            ->to(...$to)
            ->locale($locale)
            ->subject($this->translator->trans('email_changeset.price_applied.subject', ['site' => $site, 'id' => (string) $id, 'title' => $changeset->getTitle()], 'admin', $locale))
            ->htmlTemplate('emails/changeset/price_applied.html.twig')
            ->textTemplate('emails/changeset/price_applied.txt.twig')
            ->context([
                'cs' => $changeset,
                'rows' => \array_slice($rows, 0, self::MAX_ROWS),
                'more' => max(0, \count($rows) - self::MAX_ROWS),
                'url' => $this->adminUrl('admin_changeset_detail', ['entityId' => $id]),
                'audit_url' => $this->adminUrl('admin_admin_audit_index', ['filters' => ['changesetId' => ['comparison' => '=', 'value' => $id]]]),
            ]));

        return \count($to);
    }

    /** @return list<string> */
    public function recipients(): array
    {
        $configured = array_values(array_filter(array_map(trim(...), explode(',', (string) $this->settings->get(SettingKeys::PRICES_NOTIFY_EMAIL)))));
        if ([] !== $configured) {
            return $configured;
        }
        /** @var list<User> $managers */
        $managers = $this->em->getRepository(User::class)->findBy(['active' => true, 'role' => UserRole::Manager]);

        return array_values(array_unique(array_map(static fn (User $u): string => $u->getEmail(), $managers)));
    }

    /** @param array<string, mixed> $params */
    private function adminUrl(string $route, array $params): string
    {
        return 'https://'.$this->adminHost.$this->urls->generate($route, $params, UrlGeneratorInterface::ABSOLUTE_PATH);
    }

    private static function percent(mixed $before, mixed $after): ?string
    {
        if (!\is_string($before) || !\is_string($after) || !is_numeric($before) || !is_numeric($after) || (float) $before <= 0) {
            return null;
        }
        $pct = (int) round(((float) $after - (float) $before) * 100 / (float) $before);

        return ($pct >= 0 ? '+' : '−').abs($pct).' %';
    }
}
