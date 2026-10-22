<?php

declare(strict_types=1);

namespace App\Tests\Core\Content;

use App\Core\Content\Entity\Solution;
use App\Core\Content\Entity\SolutionOption;
use App\Core\Content\Entity\SolutionTranslation;
use App\Core\Content\TextList;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/** Модель рішення: порядок варіантів, списки рядків і FAQ у текстовому вигляді адмінки, інші рішення без самого себе. */
final class SolutionModelTest extends TestCase
{
    public function testOptionsAreSortedByPositionThenIdThenInsertion(): void
    {
        $solution = new Solution();
        $a = $this->option($solution, 2, 10);
        $b = $this->option($solution, 1, 30);
        $c = $this->option($solution, 1, 20);
        $new1 = $this->option($solution, 1, null);
        $new2 = $this->option($solution, 1, null);
        $first = $this->option($solution, 0, 99);

        self::assertSame([$first, $c, $b, $new1, $new2, $a], $solution->getSortedOptions());
        self::assertSame($solution, $a->getSolution(), 'addOption прив\'язує варіант до рішення');
    }

    public function testTextListsAndFaq(): void
    {
        self::assertSame(['EUR', 'FIN 1200×1000'], TextList::fromText("  EUR \r\n\nFIN 1200×1000\nEUR\n"));
        self::assertSame([], TextList::fromText(null));
        self::assertCount(TextList::MAX_ITEMS, TextList::clean(array_map('strval', range(1, 50))));

        $faq = TextList::faqFromText("Скільки коштує?\nПісля розрахунку.\nДругий рядок.\n\n\nБез відповіді\n\nТермін?\n2 тижні");
        self::assertSame([
            ['q' => 'Скільки коштує?', 'a' => "Після розрахунку.\nДругий рядок."],
            ['q' => 'Термін?', 'a' => '2 тижні'],
        ], $faq);
        self::assertSame($faq, TextList::faqFromText(TextList::faqToText($faq)));

        $translation = new SolutionTranslation(new Solution(), 'uk');
        $translation->setLoadsText("a\nb")->setIndustriesText('')->setFaqText('');
        self::assertSame(['a', 'b'], $translation->getLoads());
        self::assertSame("a\nb", $translation->getLoadsText());
        self::assertSame([], $translation->getIndustries());
        self::assertTrue((new SolutionTranslation(new Solution(), 'en'))->setIndustriesText("\n")->isBlank(), 'порожній список не робить переклад непорожнім');
    }

    public function testRelatedSkipsSelfAndDuplicates(): void
    {
        $solution = new Solution();
        $other = new Solution();
        $solution->addRelatedSolution($solution);
        $solution->addRelatedSolution($other);
        $solution->addRelatedSolution($other);
        self::assertSame([$other], $solution->getRelatedSolutions()->toArray());
    }

    private function option(Solution $solution, int $position, ?int $id): SolutionOption
    {
        $option = new SolutionOption();
        $option->setPosition($position);
        if (null !== $id) {
            (new ReflectionProperty(SolutionOption::class, 'id'))->setValue($option, $id);
        }
        $solution->addOption($option);

        return $option;
    }
}
