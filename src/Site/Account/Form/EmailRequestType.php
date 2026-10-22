<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Account\Form;

use App\Core\Customer\Entity\Customer;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

/** Лише email: «забули пароль» і «увійти посиланням». Відповідь однакова для наявної й відсутньої адреси. */
/** @extends AbstractType<array{email: string}> */
final class EmailRequestType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('email', EmailType::class, ['empty_data' => '', 'constraints' => [
            new Assert\NotBlank(message: 'site.account.error.email_blank'),
            new Assert\Email(message: 'site.account.error.email_invalid'),
            new Assert\Length(max: Customer::EMAIL_MAX),
        ]]);
    }
}
