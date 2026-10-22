<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Seo\Validator;

use App\Core\Seo\Entity\Redirect;
use App\Core\Seo\RedirectManager;
use App\Core\Seo\RedirectPath;
use App\Core\Shared\Translation\TranslatableException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class ValidRedirectValidator extends ConstraintValidator
{
    public function __construct(private readonly RedirectManager $redirects, private readonly Security $security)
    {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidRedirect) {
            throw new UnexpectedTypeException($constraint, ValidRedirect::class);
        }
        if (!$value instanceof Redirect) {
            return;
        }
        if ('' === $value->getSource()) {
            if ('' !== trim((string) $value->getRawSource())) {
                $this->context->buildViolation('seo.redirect.source.invalid')->atPath('source')->addViolation();
            }

            return;
        }
        $code = $value->getCode();
        if (!$code->isRedirect()) {
            return;
        }
        if (null === $value->getTarget() && '' !== trim((string) $value->getRawTarget())) {
            $this->context->buildViolation('seo.redirect.target.invalid')->atPath('target')->addViolation();

            return;
        }
        $target = null === $value->getTarget() ? null : $this->redirects->localize($value->getTarget());
        if (null !== $target && RedirectPath::isAbsoluteUrl($target) && !$this->security->isGranted('ROLE_ADMIN')) {
            $this->context->buildViolation('seo.redirect.target.external_denied')->atPath('target')->addViolation();

            return;
        }
        if (null !== ($problem = $this->redirects->problem($value->getSource(), $code, $target))) {
            // текст — з домену admin (error.redirect.*), спільний з винятками RedirectManager
            $this->context->buildViolation($problem->getMessageKey())->setParameters($problem->getMessageParameters())
                ->setTranslationDomain(TranslatableException::DOMAIN)->atPath('target')->addViolation();
        }
    }
}
