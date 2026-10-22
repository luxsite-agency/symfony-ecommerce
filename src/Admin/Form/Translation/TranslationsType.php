<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Admin\Form\Translation;

use App\Admin\I18n\LanguageName;
use App\Core\Shared\Translation\AbstractTranslation;
use App\Core\Shared\Translation\TranslatableInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Усі мови магазину (app.locales) однією формою: під-форма на локаль, дані — translate($locale) власника
 * (створює переклад, якого ще нема). Мова за замовчуванням обов'язкова (entry отримує required_locale=true),
 * інші — якщо нічого не заповнено, переклад знімає BlankTranslationRemover перед flush.
 *
 * @extends AbstractType<object>
 */
final class TranslationsType extends AbstractType
{
    /** @param list<string> $locales */
    public function __construct(
        #[Autowire('%app.locales%')] private readonly array $locales,
        #[Autowire('%app.default_locale%')] private readonly string $defaultLocale,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        foreach ($this->locales as $locale) {
            $builder->add($locale, $options['entry_type'], [
                // назва мови — мовою адмінки з ICU (Ukrainian / Українська), не ключ перекладу
                'label' => new LanguageName($locale),
                'required_locale' => $locale === $this->defaultLocale,
                'property_path' => \sprintf('translations[%s]', $locale),
                'getter' => static fn (TranslatableInterface $owner): AbstractTranslation => $owner->translate($locale),
                // переклад редагується на місці; порожні неголовні знімає BlankTranslationRemover на flush
                'setter' => static function (): void {},
            ] + $options['entry_options']);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'inherit_data' => true,
            'label' => false,
            'entry_options' => [],
            'translation_domain' => 'admin',
        ]);
        $resolver->setRequired('entry_type');
        $resolver->setAllowedTypes('entry_type', 'string');
        $resolver->setAllowedTypes('entry_options', 'array');
    }
}
