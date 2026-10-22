<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Admin\Form;

use App\Core\AdminApi\ApiScope;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

use function Symfony\Component\Translation\t;

use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Component\Validator\Constraints\Count;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Range;

/**
 * Форма «Створити API-токен» (E15 PR-2): назва, скоупи (лише дозволені ролі власника — опція scopes), строк у днях ≤ max_days.
 * Дані — масив {name, scopes: list<ApiScope>, days}. Підписи — ключі домену admin (api_token.form.*).
 *
 * @extends AbstractType<array{name: string, scopes: list<ApiScope>, days: int}>
 */
final class NewApiTokenType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var list<ApiScope> $scopes */
        $scopes = $options['scopes'];
        /** @var int $maxDays */
        $maxDays = $options['max_days'];
        $builder
            ->add('name', TextType::class, [
                'label' => 'api_token.form.name',
                'help' => 'api_token.form.name_help',
                'constraints' => [new NotBlank(message: 'security.api_token.name.not_blank'), new Length(max: 100, maxMessage: 'security.api_token.name.max_length')],
            ])
            ->add('scopes', ChoiceType::class, [
                'label' => 'api_token.form.scopes',
                'choices' => $scopes,
                // людська назва першою, код дозволу — дрібно сірим (аудит UX A19); розмітка — у перекладі api_token.form.scope_choice
                'choice_label' => static fn (ApiScope $s): TranslatableMessage => t('api_token.form.scope_choice', ['scope' => $s->value, 'label' => $s], 'admin'),
                'label_html' => true,
                'choice_value' => static fn (?ApiScope $s): string => null === $s ? '' : $s->value,
                'multiple' => true,
                'expanded' => true,
                'constraints' => [new Count(min: 1, minMessage: 'security.api_token.scopes.min_count')],
            ])
            ->add('days', IntegerType::class, [
                'label' => 'api_token.form.days',
                'data' => $maxDays,
                'help' => 'api_token.form.days_help',
                'help_translation_parameters' => ['max' => $maxDays],
                'constraints' => [new NotBlank(message: 'security.api_token.days.not_blank'), new Range(min: 1, max: $maxDays, notInRangeMessage: 'security.api_token.days.range')],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setRequired(['scopes', 'max_days'])
            ->setAllowedTypes('scopes', 'array')
            ->setAllowedTypes('max_days', 'int')
            ->setDefault('csrf_token_id', 'api-token-issue')
            ->setDefault('translation_domain', 'admin');
    }
}
