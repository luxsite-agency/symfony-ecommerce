<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Account\Form;

use App\Core\Customer\Entity\Customer;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Сторінка підтвердження email з листа: імʼя й пароль задає той, хто відкрив лист, тобто власник скриньки. Нічого з
 * форми реєстрації (її міг заповнити будь-хто) сюди не підставляється (рев'ю безпеки #45, п. 2 і 7).
 *
 * @extends AbstractType<array{name: string, password: string}>
 */
final class VerifyEmailType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['empty_data' => '', 'constraints' => [
                new Assert\NotBlank(message: 'site.account.error.name_blank', normalizer: 'trim'),
                new Assert\Length(max: Customer::NAME_MAX),
            ]])
            ->add('password', PasswordType::class, ['empty_data' => '', 'constraints' => [
                new Assert\NotBlank(message: 'site.account.error.password_short'),
                new Assert\Length(min: 10, max: 4096, minMessage: 'site.account.error.password_short'),
            ]]);
    }
}
