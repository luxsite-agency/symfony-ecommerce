<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Content\Validator;

use Attribute;
use Symfony\Component\Validator\Constraint;

/** Слаг сторінки в корені сайту не збігається з категорією, товаром чи службовою адресою (PageSlugs). */
#[Attribute(Attribute::TARGET_CLASS)]
final class PageSlugAvailable extends Constraint
{
    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
