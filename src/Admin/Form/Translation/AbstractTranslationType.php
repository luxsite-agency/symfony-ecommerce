<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Admin\Form\Translation;

use App\Core\Shared\Html\ContentHtml;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Спільне для entry-форм перекладу: опція required_locale робить головне поле обов'язковим; слаг — з назви.
 * Підписи й підказки — ключі домену admin (catalog_form.translation.*).
 *
 * @extends AbstractType<object>
 */
abstract class AbstractTranslationType extends AbstractType
{
    /**
     * Опції поля з HTML, який сайт виводить |raw (опис, текст сторінки): білий список ContentHtml при збереженні
     * (консиліум безпеки 10.10 п. 2) — script, iframe, on*, style, javascript: з адмінки на сайт не потрапляють.
     */
    protected const array HTML = ['sanitize_html' => true, 'sanitizer' => ContentHtml::SANITIZER];

    public function __construct(
        #[Target('urlSlugger')] protected readonly SluggerInterface $slugger,
        protected readonly TranslatorInterface $translator,
    ) {
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        // empty_data null: переклад завжди приходить від власника (translate()), форма сама його не створює.
        $resolver->setDefaults(['required_locale' => false, 'empty_data' => null, 'translation_domain' => 'admin']);
        $resolver->setAllowedTypes('required_locale', 'bool');
    }

    /**
     * Текст NotBlank перекладається тут, у домені admin, мовою адмінки на момент побудови форми; валідатор потім
     * шукає його в домені validators, не знаходить і лишає як є.
     *
     * @param string $messageKey ключ домену admin
     *
     * @return array<string, mixed>
     */
    protected function requiredIf(bool $required, string $messageKey = 'catalog_form.error.required_default_locale'): array
    {
        return $required ? ['required' => true, 'constraints' => [new NotBlank(message: $this->translator->trans($messageKey, [], 'admin'))]] : ['required' => false];
    }

    /**
     * Порожній слаг заповнюється транслітерацією назви (uk/ru → латиниця), щоб URL був у кожній мові.
     *
     * @param FormBuilderInterface<mixed> $builder
     */
    protected function addSlugFromName(FormBuilderInterface $builder): void
    {
        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
            $translation = $event->getData();
            if (!\is_object($translation) || !method_exists($translation, 'getSlug') || !method_exists($translation, 'setSlug') || !method_exists($translation, 'getName') || !method_exists($translation, 'getLocale')) {
                return;
            }
            if (null === $translation->getSlug() && '' !== $translation->getName()) {
                $slug = $this->slugger->slug($translation->getName(), '-', $translation->getLocale())->lower()->toString();
                if ('' !== $slug) {
                    $translation->setSlug(mb_substr($slug, 0, 190));
                }
            }
        }, -10);
    }
}
