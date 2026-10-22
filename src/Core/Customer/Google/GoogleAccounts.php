<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Customer\Google;

use App\Core\Customer\Auth\AuthTokens;
use App\Core\Customer\Consent\Consents;
use App\Core\Customer\Entity\Customer;
use App\Core\Customer\Enum\ConsentPurpose;
use App\Core\Customer\Repository\CustomerRepository;
use App\Core\Demo\DemoAccounts;
use App\Core\Security\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Зв'язування акаунта Google з покупцем (рішення власника 13.10.2026: один кабінет, Google — ще один спосіб входу).
 *
 * Вхід (`signIn`): 1) покупець з таким `google_sub` — впускаємо; 2) інакше потрібен email_verified=true від Google, бо
 * лише він доводить володіння адресою; 3) адреса вільна — новий покупець з підтвердженим email без пароля (кабінет працює,
 * пароль — через «забули пароль»); 4) адреса за підтвердженим покупцем без Google — прив'язуємо; 5) адреса за
 * НЕПІДТВЕРДЖЕНИМ рядком (заготовка з реєстрації: її міг створити будь-хто, і в неї нема ні пароля, ні імені) — Google
 * довів, що адреса належить тому, хто входить, тож рядок стає його: підтверджуємо, прив'язуємо, гасимо всі невикористані
 * токени з листів і піднімаємо authVersion (рев'ю #60: заготовка не є власником адреси, і той, хто її створив, не має
 * отримати нічого). Демо-адреси й адреси адміністраторів (Security\User) через Google не входять ніколи.
 * Покупець з Google, у якого в Google інша адреса, ніж у кабінеті, — впускаємо за sub, адресу не чіпаємо (зміна email —
 * лише листом з підтвердженням, як для всіх).
 *
 * Прив'язка з кабінету (`link`): лише до поточного покупця, лише якщо цей sub ще не має іншого покупця; email у Google і
 * в кабінеті можуть різнитись (ідентичність для входу — sub).
 */
final class GoogleAccounts
{
    public function __construct(
        private readonly CustomerRepository $customers,
        private readonly EntityManagerInterface $em,
        private readonly AuthTokens $tokens,
        private readonly Consents $consents,
        private readonly DemoAccounts $demo,
    ) {
    }

    /** @throws GoogleSignInRefused */
    public function signIn(GoogleIdentity $identity, string $locale): GoogleSignInResult
    {
        $this->refuseReserved($identity->email);
        $existing = $this->customers->findOneBy(['googleSub' => $identity->sub]);
        if (null !== $existing) {
            if (!$existing->isActive()) {
                throw new GoogleSignInRefused('disabled');
            }

            return new GoogleSignInResult($existing, false, false, false);
        }
        if (!$identity->emailVerified) {
            throw new GoogleSignInRefused('email_unverified');
        }
        $customer = $this->customers->findOneByEmail($identity->email);
        if (null === $customer) {
            $customer = new Customer($identity->email, '', $locale);
            $customer->verifyEmailByProvider($identity->name);
            $customer->linkGoogle($identity->sub, $identity->email);
            $this->em->persist($customer);
            // згода на обробку ПД: під кнопкою Google — текст згоди, джерело google (як чекбокс реєстрації)
            $this->consents->record($customer, ConsentPurpose::PdProcessing, true, 'google', $locale);
            $this->em->flush();

            return new GoogleSignInResult($customer, true, true, false);
        }
        if (!$customer->isActive()) {
            throw new GoogleSignInRefused('disabled');
        }
        if ($customer->isGoogleLinked()) {
            // та сама адреса, інший акаунт Google (адреса переїхала між акаунтами Workspace): прив'язка лишається старою,
            // змінити її можна лише з кабінету
            throw new GoogleSignInRefused('already_linked');
        }
        $placeholder = !$customer->isEmailVerified();
        if ($placeholder) {
            $customer->verifyEmailByProvider($identity->name);
            $this->tokens->revokeAll($customer);
            $this->consents->record($customer, ConsentPurpose::PdProcessing, true, 'google', $locale);
        }
        $customer->linkGoogle($identity->sub, $identity->email);
        $this->em->flush();

        return new GoogleSignInResult($customer, false, true, $placeholder);
    }

    /** Прив'язка до поточного покупця з кабінету. @throws GoogleSignInRefused */
    public function link(Customer $customer, GoogleIdentity $identity): void
    {
        if ($this->demo->isDemo($customer)) {
            throw new GoogleSignInRefused('demo');
        }
        if ($customer->isGoogleLinked()) {
            throw new GoogleSignInRefused('already_linked');
        }
        $other = $this->customers->findOneBy(['googleSub' => $identity->sub]);
        if (null !== $other && $other->getId() !== $customer->getId()) {
            throw new GoogleSignInRefused('sub_taken');
        }
        $customer->linkGoogle($identity->sub, $identity->email);
        $this->em->flush();
    }

    /** Відв'язати можна лише з іншим способом входу (паролем); інакше покупець лишився б без входу. */
    public function canUnlink(Customer $customer): bool
    {
        return $customer->isGoogleLinked() && null !== $customer->getPassword();
    }

    public function unlink(Customer $customer): void
    {
        if (!$this->canUnlink($customer)) {
            throw new GoogleSignInRefused('no_other_method');
        }
        $customer->unlinkGoogle();
        $this->em->flush();
    }

    /** @throws GoogleSignInRefused */
    private function refuseReserved(string $email): void
    {
        if ($this->demo->isDemoEmail($email)) {
            throw new GoogleSignInRefused('demo');
        }
        if (null !== $this->em->getRepository(User::class)->findOneBy(['email' => Customer::normalizeEmail($email)])) {
            throw new GoogleSignInRefused('admin');
        }
    }
}
