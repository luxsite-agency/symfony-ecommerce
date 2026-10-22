<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Solution;

use App\Core\Catalog\Entity\Product;
use App\Core\Content\Entity\Solution;
use App\Core\Content\Entity\SolutionGroup;
use App\Core\Content\Entity\SolutionOptionTranslation;
use App\Core\Customer\Entity\CustomerGroup;
use App\Site\Catalog\ProductCardsByIds;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/**
 * Рішення для сайту (docs/solutions.md). Видиме мовою L = опубліковане й з назвою та слагом мовою L (ті самі правила, що
 * SiteUrlGenerator::solution — 404, hreflang і sitemap узгоджені). Варіанти — картки ProductCardsByIds: лише товари, видимі в
 * каталозі цією мовою, ціна/«від»/«ціна за запитом» за групою покупця, як у лістингу (ціни рішення не зберігає).
 */
final class SolutionFinder
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly ProductCardsByIds $cards)
    {
    }

    public function bySlug(string $slug, string $locale): ?Solution
    {
        /** @var list<Solution> $found */
        // без setMaxResults: з fetch-join колекції ліміт ріже рядки, а не рішення; слаг і так унікальний у межах мови
        $found = $this->visible($locale)->andWhere('t.slug = :slug')->setParameter('slug', $slug)->getQuery()->getResult();

        return $found[0] ?? null;
    }

    /**
     * Список за групами в порядку груп (position, id), без групи — останніми; усередині — position, id.
     *
     * @return list<array{group: ?SolutionGroup, solutions: list<Solution>}>
     */
    public function grouped(string $locale): array
    {
        /** @var list<Solution> $solutions */
        $solutions = $this->visible($locale)->leftJoin('s.group', 'g')
            ->orderBy('CASE WHEN g.id IS NULL THEN 1 ELSE 0 END', 'ASC')->addOrderBy('g.position', 'ASC')->addOrderBy('g.id', 'ASC')
            ->addOrderBy('s.position', 'ASC')->addOrderBy('s.id', 'ASC')->getQuery()->getResult();
        $groups = [];
        foreach ($solutions as $solution) {
            $key = $solution->getGroup()?->getId() ?? 0;
            $groups[$key] ??= ['group' => $solution->getGroup(), 'solutions' => []];
            $groups[$key]['solutions'][] = $solution;
        }

        return array_values($groups);
    }

    /**
     * Рішення, де товар — варіант (блок «Рішення з цим продуктом» на картці товару).
     *
     * @return list<Solution>
     */
    public function forProduct(Product $product, string $locale, int $limit = 6): array
    {
        /** @var list<Solution> $solutions */
        $solutions = $this->visible($locale)->join('s.options', 'o')->andWhere('o.product = :product')->setParameter('product', $product)
            ->orderBy('s.position', 'ASC')->addOrderBy('s.id', 'ASC')->getQuery()->getResult();

        return \array_slice($solutions, 0, $limit); // ліміт у PHP — див. bySlug()
    }

    /**
     * «Інші рішення»: спершу задані вручну (relatedSolutions), далі з тієї ж групи, далі решта — лише видимі цією мовою.
     *
     * @return list<Solution>
     */
    public function related(Solution $solution, string $locale, int $limit = Solution::RELATED_LIMIT): array
    {
        $visible = $this->visible($locale)->andWhere('s != :self')->setParameter('self', $solution)
            ->orderBy('s.position', 'ASC')->addOrderBy('s.id', 'ASC');
        /** @var list<Solution> $candidates */
        $candidates = $visible->getQuery()->getResult();
        $chosen = [];
        $explicit = $solution->getRelatedSolutions()->toArray();
        $group = $solution->getGroup();
        foreach ([
            static fn (Solution $s): bool => \in_array($s, $explicit, true),
            static fn (Solution $s): bool => null !== $group && $s->getGroup() === $group,
            static fn (Solution $s): bool => true,
        ] as $rule) {
            foreach ($candidates as $candidate) {
                if (\count($chosen) >= $limit) {
                    break 2;
                }
                if ($rule($candidate) && !\in_array($candidate, $chosen, true)) {
                    $chosen[] = $candidate;
                }
            }
        }

        return $chosen;
    }

    /**
     * Варіанти в порядку рішення: картка товару (ProductCards) + переваги й абзац мовою сторінки. Товар, невидимий у каталозі
     * цією мовою (неактивний, без перекладу, без продажних варіантів), пропускається.
     *
     * @return list<array{option: \App\Core\Content\Entity\SolutionOption, tr: ?SolutionOptionTranslation, card: array<string, mixed>}>
     */
    public function options(Solution $solution, string $locale, ?CustomerGroup $group): array
    {
        $options = [];
        foreach ($solution->getSortedOptions() as $option) {
            $productId = $option->getProduct()?->getId();
            if (null !== $productId && !isset($options[$productId])) {
                $options[$productId] = $option;
            }
        }
        $rows = [];
        foreach ($this->cards->load(array_keys($options), $locale, $group) as $card) {
            $option = $options[(int) $card['id']];
            $translation = $option->getTranslation($locale);
            $rows[] = ['option' => $option, 'tr' => $translation instanceof SolutionOptionTranslation ? $translation : null, 'card' => $card];
        }

        return $rows;
    }

    private function visible(string $locale): QueryBuilder
    {
        // фільтр — за перекладом мовою сторінки (t), а підвантажуються всі переклади (tAll) і обкладинка: без N+1 на картках
        // і hreflang. Fetch-join лише з WITH locale лишив би колекцію з одним перекладом (рев'ю #102, L1)
        return $this->em->createQueryBuilder()->select('s', 'tAll', 'c')->from(Solution::class, 's')
            ->join('s.translations', 't', 'WITH', 't.locale = :locale')
            ->leftJoin('s.translations', 'tAll')->leftJoin('s.cover', 'c')
            ->where('s.published = true')->andWhere('t.slug IS NOT NULL')->andWhere("t.title <> ''")
            ->setParameter('locale', $locale);
    }
}
