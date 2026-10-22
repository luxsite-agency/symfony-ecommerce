<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Admin\Form;

use App\Core\Catalog\Exception\CatalogInvariantViolation;
use App\Core\Shared\Translation\TranslatableException;
use ArrayIterator;
use DomainException;
use InvalidArgumentException;
use Symfony\Component\Form\DataMapperInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Traversable;

/**
 * Інваріанти живуть у методах сутностей і кидають винятки. Форма мапить поля по одному, і виняток сеттера
 * стає помилкою саме того поля, а не 500-ю. Інші поля мапляться далі. Текст — мовою адмінки, якщо виняток має ключ
 * перекладу (TranslatableException, домен admin), інакше getMessage().
 */
final class DomainErrorDataMapper implements DataMapperInterface
{
    public function __construct(private readonly DataMapperInterface $inner, private readonly ?TranslatorInterface $translator = null)
    {
    }

    /** @param Traversable<FormInterface<mixed>> $forms */
    public function mapDataToForms(mixed $viewData, Traversable $forms): void
    {
        $this->inner->mapDataToForms($viewData, $forms);
    }

    /** @param Traversable<FormInterface<mixed>> $forms */
    public function mapFormsToData(Traversable $forms, mixed &$viewData): void
    {
        /** @var FormInterface<mixed> $form */
        foreach ($forms as $form) {
            try {
                $this->inner->mapFormsToData(new ArrayIterator([$form]), $viewData);
            } catch (InvalidArgumentException|DomainException|CatalogInvariantViolation $e) {
                $form->addError(self::error($e, $this->translator));
            }
        }
    }

    /** Помилка форми з винятку: перекладений текст, шаблон — ключ (або той самий текст), параметри — з винятку. */
    public static function error(InvalidArgumentException|DomainException $e, ?TranslatorInterface $translator): FormError
    {
        if (null === $translator || !$e instanceof TranslatableException || '' === $e->getMessageKey()) {
            return new FormError($e->getMessage(), null, [], null, $e);
        }

        return new FormError($e->trans($translator), $e->getMessageKey(), $e->getMessageParameters(), null, $e);
    }
}
