<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Account;

use App\Core\Customer\Auth\AuthAudit;
use App\Core\Customer\Entity\Customer;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Повторна автентифікація поточним паролем (консиліум П5) для зміни пароля/email, експорту ПД і видалення акаунта:
 * захист від того, хто має сесію чи remember_me, але не пароль. Лімітер `customer_reauth` 5 спроб / 15 хв на id покупця
 * (рев'ю #60 v2, M2: без нього це онлайн-оракул пароля, тоді як вхід має login_throttling 5/15 хв); вдала спроба скидає
 * лічильник. Перевищення — помилка на полі, 429 і `reauth_rate_limited`; невірний пароль — помилка на полі, `reauth_failed`.
 */
final class Reauth
{
    public function __construct(
        private readonly UserPasswordHasherInterface $hasher,
        private readonly AuthAudit $audit,
        #[Autowire(service: 'limiter.customer_reauth')] private readonly RateLimiterFactoryInterface $limiter,
    ) {
    }

    /** @param FormInterface<mixed> $form форма з полем `current` */
    public function check(FormInterface $form, Customer $customer): ReauthResult
    {
        $limiter = $this->limiter->create((string) $customer->getId());
        if (!$limiter->consume()->isAccepted()) {
            $form->get('current')->addError(new FormError('site.account.error.too_many'));
            $this->audit->log('reauth_rate_limited', $customer->getEmail(), $customer->getId());

            return ReauthResult::Limited;
        }
        if ($this->hasher->isPasswordValid($customer, (string) $form->get('current')->getData())) {
            $limiter->reset();

            return ReauthResult::Ok;
        }
        $form->get('current')->addError(new FormError('site.account.error.password_current'));
        $this->audit->log('reauth_failed', $customer->getEmail(), $customer->getId());

        return ReauthResult::Wrong;
    }

    /**
     * Код відповіді для сторінки з формою: 429 після ліміту, 422 на поданій формі з помилками, інакше 200.
     *
     * @param FormInterface<mixed> $form
     */
    public static function status(FormInterface $form, ?ReauthResult $result): int
    {
        return ReauthResult::Limited === $result ? 429 : ($form->isSubmitted() ? 422 : 200);
    }
}
