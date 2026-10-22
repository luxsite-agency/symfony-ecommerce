<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Admin\Form;

use Closure;
use DomainException;
use InvalidArgumentException;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

/**
 * Колекції в формах адмінки: нові елементи створює фабрика власника (Product::addVariant() тощо) у PRE_SUBMIT,
 * тому «сеттер» лише видаляє те, чого не стало у відправленій копії (by_reference=false → форма працює з клоном).
 * Помилка фабрики стає помилкою поля entry-форми, а не 500-ю; виняток з ключем перекладу (TranslatableInterface)
 * показується мовою адмінки, якщо передано перекладач.
 */
final class CollectionSync
{
    /**
     * @template T of object
     *
     * @param callable(T): iterable<object> $current
     * @param callable(T, object): void     $remove
     *
     * @return Closure(T, iterable<object|null>): void
     */
    public static function setter(callable $current, callable $remove): Closure
    {
        return static function (object $owner, iterable $submitted) use ($current, $remove): void {
            $kept = [];
            foreach ($submitted as $item) {
                if (null !== $item) {
                    $kept[spl_object_id($item)] = true;
                }
            }
            $existing = [];
            foreach ($current($owner) as $item) {
                $existing[] = $item;
            }
            foreach ($existing as $item) {
                if (!isset($kept[spl_object_id($item)])) {
                    $remove($owner, $item);
                }
            }
        };
    }

    /**
     * PRE_SUBMIT для entry-форми колекції: новий рядок ще без об'єкта отримує його від $factory(власник, сирі дані)
     * до сабміту дочірніх полів, тому далі форма мапить їх у справжню доменну сутність. Виняток фабрики →
     * помилка на $errorField (або на самій entry-формі), об'єкт не створюється, дочірні поля мапляться в null.
     * $translator перекладає винятки з ключем (TranslatableInterface); без нього — getMessage().
     *
     * @template T of object
     *
     * @param callable(T, array<string, mixed>): object $factory
     *
     * @return Closure(FormEvent): void
     */
    public static function preSubmitFactory(callable $factory, ?string $errorField = null, ?TranslatorInterface $translator = null): Closure
    {
        return static function (FormEvent $event) use ($factory, $errorField, $translator): void {
            $form = $event->getForm();
            if (null !== $form->getData()) {
                return;
            }
            $owner = $form->getParent()?->getParent()?->getData();
            $raw = $event->getData();
            if (!\is_object($owner) || !\is_array($raw)) {
                return;
            }
            try {
                $form->setData($factory($owner, $raw));
            } catch (InvalidArgumentException|DomainException $e) {
                $target = null !== $errorField && $form->has($errorField) ? $form->get($errorField) : $form;
                $target->addError(new FormError(self::errorMessage($e, $translator), null, [], null, $e));
            }
        };
    }

    /** Текст помилки форми з винятку: мовою адмінки, якщо виняток несе ключ перекладу, інакше getMessage(). */
    public static function errorMessage(Throwable $e, ?TranslatorInterface $translator): string
    {
        return null !== $translator && $e instanceof TranslatableInterface ? $e->trans($translator) : $e->getMessage();
    }
}
