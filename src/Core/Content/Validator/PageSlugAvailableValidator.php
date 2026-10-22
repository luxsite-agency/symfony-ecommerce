<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Content\Validator;

use App\Core\Content\Entity\PageTranslation;
use App\Core\Content\Url\PageSlugs;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class PageSlugAvailableValidator extends ConstraintValidator
{
    public function __construct(private readonly PageSlugs $slugs)
    {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof PageSlugAvailable) {
            throw new UnexpectedTypeException($constraint, PageSlugAvailable::class);
        }
        if (!$value instanceof PageTranslation || null === $value->getSlug()) {
            return;
        }
        $reason = $this->slugs->conflict($value->getSlug(), $value->getLocale());
        if (null !== $reason) {
            $this->context->buildViolation($reason)->setParameter('{{ slug }}', $value->getSlug())->atPath('slug')->addViolation();
        }
    }
}
