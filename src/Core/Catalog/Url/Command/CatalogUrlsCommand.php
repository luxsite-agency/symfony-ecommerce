<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Url\Command;

use App\Core\Catalog\Query\CatalogCacheVersion;
use App\Core\Catalog\Url\CategoryPaths;
use App\Core\Catalog\Url\ReservedPathSegments;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Адреси каталогу: перерахувати шляхи всіх категорій (після масових змін поза ORM, на свіжому деплої) і показати
 * конфлікти наявних даних, яких валідатор ще не бачив: слаг товару = службове слово або шлях кореневої категорії.
 */
#[AsCommand(name: 'catalog:urls:rebuild', description: 'Перерахувати шляхи категорій і показати конфлікти адрес товарів')]
final class CatalogUrlsCommand
{
    public function __construct(
        private readonly CategoryPaths $paths,
        private readonly ReservedPathSegments $reserved,
        private readonly Connection $db,
        private readonly CatalogCacheVersion $cacheVersion,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $changed = $this->paths->rebuildAll();
        $this->cacheVersion->bump();
        $io->writeln(\sprintf('Шляхів категорій змінено: %d', $changed));

        // фільтр у SQL: на 500k товарів × мови вибірка всіх перекладів не влазить у пам'ять
        $conflicts = [];
        foreach ($this->db->fetchAllAssociative(
            'SELECT t.locale, t.slug, t.product_id, c.category_id FROM catalog_product_translation t
             JOIN catalog_category_translation c ON c.locale = t.locale AND c.path = t.slug',
        ) as $row) {
            $conflicts[] = [$row['locale'], $row['slug'], 'товар #'.$row['product_id'], 'збігається з кореневою категорією #'.$row['category_id']];
        }
        foreach ($this->db->fetchAllAssociative(
            'SELECT locale, slug, product_id FROM catalog_product_translation WHERE slug IN (?)',
            [$this->reserved->all()],
            [ArrayParameterType::STRING],
        ) as $row) {
            $conflicts[] = [$row['locale'], $row['slug'], 'товар #'.$row['product_id'], 'службова адреса сайту'];
        }
        foreach ($this->db->fetchAllAssociative('SELECT locale, path, category_id FROM catalog_category_translation WHERE path IS NOT NULL AND path NOT LIKE ?', ['%/%']) as $row) {
            if ($this->reserved->isReserved((string) $row['path'])) {
                $conflicts[] = [$row['locale'], $row['path'], 'категорія #'.$row['category_id'], 'службова адреса сайту'];
            }
        }
        if ([] === $conflicts) {
            $io->success('Конфліктів адрес нема');

            return Command::SUCCESS;
        }
        $io->table(['мова', 'слаг', 'що', 'конфлікт'], $conflicts);
        $io->warning('Змініть слаги в адмінці: такі адреси відкривають не те або не відкриваються');

        return Command::FAILURE;
    }
}
