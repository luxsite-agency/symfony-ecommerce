<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Changeset\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/** {"op":"set_product_categories","product":7,"categories":[3,8],"main":3,"expect":{"categories":[3],"main":3}} — повний список категорій і головна. */
final readonly class SetProductCategories extends Operation
{
    public const string TYPE = 'set_product_categories';

    /**
     * @param list<int>            $categories
     * @param array<string, mixed> $expect
     */
    public function __construct(
        #[Assert\NotBlank(message: 'changeset.operation.product.not_blank')]
        public int|string $product,
        #[Assert\Count(min: 1, max: 50, minMessage: 'changeset.set_product_categories.categories.min_count', maxMessage: 'changeset.set_product_categories.categories.max_count')]
        #[Assert\All([new Assert\Type('int'), new Assert\Positive()])]
        public array $categories,
        #[Assert\Positive(message: 'changeset.set_product_categories.main.positive')]
        public int $main,
        array $expect,
    ) {
        parent::__construct($expect);
    }

    public function type(): string
    {
        return self::TYPE;
    }

    public function toArray(): array
    {
        return ['op' => self::TYPE, 'product' => $this->product, 'categories' => $this->categories, 'main' => $this->main, 'expect' => $this->expect];
    }
}
