<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Account\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Лише поточний пароль: експорт даних і видалення акаунта (консиліум П5: повторна автентифікація).
 *
 * @extends AbstractType<array{current: string}>
 */
final class ReauthType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('current', PasswordType::class, ['empty_data' => '', 'constraints' => [new Assert\NotBlank(message: 'site.account.error.password_current')]]);
    }
}
