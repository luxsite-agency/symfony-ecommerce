<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Validator;

use App\Core\Catalog\Entity\CategoryTranslation;
use App\Core\Catalog\Entity\ProductTranslation;
use App\Core\Catalog\Url\CatalogSlugs;
use App\Core\Shared\Translation\TranslatableException;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class AvailableSiteSlugValidator extends ConstraintValidator
{
    public function __construct(private readonly CatalogSlugs $slugs)
    {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof AvailableSiteSlug) {
            throw new UnexpectedTypeException($constraint, AvailableSiteSlug::class);
        }
        $conflict = null;
        if ($value instanceof ProductTranslation && null !== $value->getSlug()) {
            $conflict = $this->slugs->productProblem($value->getSlug(), $value->getLocale(), $value->getProduct()->getId());
        } elseif ($value instanceof CategoryTranslation && null !== $value->getSlug()) {
            $parent = $value->getCategory()->getParent();
            $parentPath = null;
            if (null !== $parent) {
                $parentTranslation = $parent->getTranslation($value->getLocale());
                $parentPath = $parentTranslation instanceof CategoryTranslation ? $parentTranslation->getPath() : null;
                if (null === $parentPath) {
                    return; // батько без адреси цією мовою — у категорії теж її не буде, конфлікту нема
                }
            }
            $conflict = $this->slugs->categoryProblem($value->getSlug(), $value->getLocale(), $parentPath, $value->getCategory()->getId());
        }
        if (null !== $conflict) {
            // текст — з домену admin (error.catalog_slug.*), спільний з винятками каталогу
            $this->context->buildViolation($conflict->getMessageKey())->setParameters($conflict->getMessageParameters())
                ->setTranslationDomain(TranslatableException::DOMAIN)->atPath('slug')->addViolation();
        }
    }
}
