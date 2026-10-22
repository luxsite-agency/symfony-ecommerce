<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Admin\Form\Extension;

use App\Admin\Form\DomainErrorDataMapper;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\Extension\Core\DataMapper\DataMapper;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Загортає data mapper кожної складеної форми в DomainErrorDataMapper (з перекладачем — текст винятку мовою адмінки). */
final class DomainErrorFormExtension extends AbstractTypeExtension
{
    public function __construct(private readonly ?TranslatorInterface $translator = null)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if (!$options['compound']) {
            return;
        }
        $mapper = $builder->getDataMapper() ?? new DataMapper();
        if (!$mapper instanceof DomainErrorDataMapper) {
            $builder->setDataMapper(new DomainErrorDataMapper($mapper, $this->translator));
        }
    }

    public static function getExtendedTypes(): iterable
    {
        return [FormType::class];
    }
}
