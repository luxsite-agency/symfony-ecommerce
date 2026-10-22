<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Seo\Validator;

use Attribute;
use Symfony\Component\Validator\Constraint;

/** Правило редиректу можна зберегти: валідні адреси, ціль для 301/302, без редиректу на себе й циклів (RedirectManager). */
#[Attribute(Attribute::TARGET_CLASS)]
final class ValidRedirect extends Constraint
{
    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
