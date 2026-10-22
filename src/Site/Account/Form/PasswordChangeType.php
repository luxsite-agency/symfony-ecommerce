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
 * Зміна пароля в профілі: поточний пароль (повторна автентифікація) і новий.
 *
 * @extends AbstractType<array{current: string, password: string}>
 */
final class PasswordChangeType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('current', PasswordType::class, ['empty_data' => '', 'constraints' => [new Assert\NotBlank(message: 'site.account.error.password_current')]])
            ->add('password', PasswordType::class, ['empty_data' => '', 'constraints' => [
                new Assert\NotBlank(message: 'site.account.error.password_short'),
                new Assert\Length(min: 10, max: 4096, minMessage: 'site.account.error.password_short'),
            ]]);
    }
}
