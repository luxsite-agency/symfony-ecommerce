<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Changeset\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * {"op":"set_translation","entity":"product|category|page|post","id":7,"locale":"uk","fields":{…},"expect":{…ті самі ключі}}
 * Білий список полів на сутність — TranslationFields. Переклад мовою, якої ще нема: expect — усі null, у fields — name/title.
 */
final readonly class SetTranslation extends Operation
{
    public const string TYPE = 'set_translation';

    /**
     * @param array<string, mixed> $fields
     * @param array<string, mixed> $expect
     */
    public function __construct(
        #[Assert\Choice(choices: ['product', 'category', 'page', 'post'], message: 'changeset.set_translation.entity.choice')]
        public string $entity,
        #[Assert\Positive(message: 'changeset.set_translation.id.positive')]
        public int $id,
        #[Assert\Regex('/^[a-z]{2}(-[A-Za-z]{2,4})?$/', message: 'changeset.set_translation.locale.format')]
        public string $locale,
        #[Assert\Count(min: 1, minMessage: 'changeset.set_translation.fields.min_count')]
        public array $fields,
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
        return ['op' => self::TYPE, 'entity' => $this->entity, 'id' => $this->id, 'locale' => $this->locale, 'fields' => $this->fields, 'expect' => $this->expect];
    }
}
