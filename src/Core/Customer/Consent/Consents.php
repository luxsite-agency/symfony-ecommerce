<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Customer\Consent;

use App\Core\Customer\Entity\ConsentLog;
use App\Core\Customer\Entity\Customer;
use App\Core\Customer\Enum\ConsentPurpose;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Запис і читання згод (E12 PR-6). Версія політики — параметр app.consent_policy_version, хеш — sha256 тексту згоди
 * мовою покупця (site.account.consent.<purpose>): зміна тексту змінює хеш, і в журналі видно, на що саме погодились.
 * Маркетинг — окремий непоставлений чекбокс у профілі, не умова реєстрації.
 */
final class Consents
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
        private readonly RequestStack $requests,
        #[Autowire('%app.consent_policy_version%')] private readonly string $policyVersion,
        #[Autowire('%kernel.secret%')] private readonly string $secret,
    ) {
    }

    /** Нова подія в журналі, якщо стан змінився (або це перший запис); persist без flush. @return bool чи записано */
    public function record(Customer $customer, ConsentPurpose $purpose, bool $granted, string $source, ?string $locale = null): bool
    {
        $current = $this->latest($customer, $purpose);
        // без подій стан «не надано»: відкликати нема чого; повтор того самого стану теж не пишеться
        if (null === $current ? !$granted : $current->isGranted() === $granted) {
            return false;
        }
        $ip = $this->requests->getMainRequest()?->getClientIp();
        $this->em->persist(new ConsentLog($customer, $purpose, $granted, $this->policyVersion, $this->policyHash($purpose, $locale ?? $customer->getLocale()), $source, null === $ip ? null : substr(hash_hmac('sha256', $ip, $this->secret), 0, 32)));

        return true;
    }

    /** Поточний стан — остання подія; без подій — не надано. */
    public function isGranted(Customer $customer, ConsentPurpose $purpose): bool
    {
        return $this->latest($customer, $purpose)?->isGranted() ?? false;
    }

    /** @return list<ConsentLog> уся історія покупця (експорт даних) */
    public function history(Customer $customer): array
    {
        /** @var list<ConsentLog> $rows */
        $rows = $this->em->getRepository(ConsentLog::class)->findBy(['customer' => $customer], ['id' => 'ASC']);

        return $rows;
    }

    public function policyVersion(): string
    {
        return $this->policyVersion;
    }

    public function policyHash(ConsentPurpose $purpose, string $locale): string
    {
        return hash('sha256', $this->translator->trans('site.account.consent.'.$purpose->value, [], null, $locale));
    }

    private function latest(Customer $customer, ConsentPurpose $purpose): ?ConsentLog
    {
        return $this->em->getRepository(ConsentLog::class)->findOneBy(['customer' => $customer, 'purpose' => $purpose], ['id' => 'DESC']);
    }
}
