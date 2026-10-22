<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Validator;

use Attribute;
use Symfony\Component\Validator\Constraint;

/**
 * Слаг перекладу товару/категорії дає вільну адресу сайту (CatalogSlugs): не службове слово, не зайнятий товаром чи
 * кореневою категорією цією мовою, у категорії — унікальний серед сестер. Помилка — на полі slug (форма адмінки).
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class AvailableSiteSlug extends Constraint
{
    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
