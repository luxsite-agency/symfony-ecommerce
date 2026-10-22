<?php

declare(strict_types=1);

namespace App\Tests\Core\Catalog\Option;

use App\Core\Catalog\Enum\NumberOptionRule;
use App\Core\Catalog\Enum\OptionInputType;
use App\Core\Catalog\Enum\OptionSelection;
use App\Core\Catalog\Enum\PriceMode;
use App\Core\Catalog\Exception\CatalogInvariantViolation;
use App\Core\Catalog\Option\InvalidOptionsException;
use App\Core\Catalog\Option\OptionConfiguration;
use App\Core\Catalog\Option\OptionPriceCalculator;
use App\Core\Shared\Money\Money;
use App\Tests\Core\Pricing\TestPricing;
use LogicException;
use PHPUnit\Framework\TestCase;

/** Формула ADR Rev 9 Q2: база + % від бази + абсолютні + число; компоненти окремо; снепшот; валідація вибору. */
final class OptionPriceCalculatorTest extends TestCase
{
    private DoorWithOptions $fx;
    private OptionPriceCalculator $calculator;

    protected function setUp(): void
    {
        $this->fx = new DoorWithOptions();
        $this->calculator = TestPricing::options();
    }

    public function testFullFormulaAndSnapshot(): void
    {
        $config = $this->configure(['leaf' => 'oak', 'hinges' => ['hidden', 'discount'], 'height' => '2100', 'trim' => 'kit']);

        // 1000 + 15 % (150) + 123 − 5 % (50) + 0,50 × 100 мм (50) = 1273,00; лиштва 2,5 × 200 = 500 — окремо
        $pricing = $config->snapshot['pricing'];
        self::assertSame(['baseMinor' => 100_000, 'modifiersMinor' => 22_300, 'numberMinor' => 5_000, 'subjectMinor' => 127_300, 'discountMinor' => 0, 'componentsMinor' => 50_000, 'listMinor' => 177_300, 'unitPriceMinor' => 177_300], array_intersect_key($pricing, array_flip(['baseMinor', 'modifiersMinor', 'numberMinor', 'subjectMinor', 'discountMinor', 'componentsMinor', 'listMinor', 'unitPriceMinor'])));
        self::assertSame(177_300, $config->quote->price->amount, 'покупець платить за одиницю рядка разом із компонентами');
        self::assertSame(OptionPriceCalculator::SCHEMA_VERSION, $config->snapshot['schemaVersion']);
        self::assertSame(['height' => '2100', 'hinges' => ['hidden', 'discount'], 'leaf' => 'oak', 'trim' => 'kit'], $config->snapshot['selection']);
        self::assertSame(['Полотно', 'hinges', 'Висота', 'trim'], array_column($config->snapshot['groups'], 'label'));
        self::assertSame(['Дуб'], $config->snapshot['groups'][0]['labels']);
        self::assertSame(['code' => 'height', 'inputType' => 'number', 'label' => 'Висота', 'value' => '2100', 'unit' => 'мм', 'rule' => 'above_threshold', 'contributionMinor' => 5_000], $config->snapshot['groups'][2]);
        self::assertCount(1, $config->components);
        self::assertSame($this->fx->trim, $config->components[0]['variant']);
        self::assertSame('2.500', $config->components[0]['quantity']);
        self::assertSame(['group' => 'trim', 'code' => 'kit', 'variantId' => null, 'sku' => 'TRIM', 'quantity' => '2.500', 'priceMinor' => 50_000], $config->snapshot['components'][0]);
    }

    public function testDefaultsForMissingGroupsAndKeyIndependentOfInputOrder(): void
    {
        $plain = $this->configure([]);
        self::assertSame(100_000, $plain->quote->price->amount, 'leaf за замовчуванням — pine (0)');
        self::assertSame(['leaf' => 'pine'], $plain->snapshot['selection']);

        $a = $this->configure(['hinges' => ['discount', 'hidden'], 'leaf' => 'oak']);
        $b = $this->configure(['leaf' => 'oak', 'hinges' => ['discount', 'hidden']]);
        self::assertSame($a->key, $b->key);
        self::assertNotSame($a->key, $this->configure(['leaf' => 'oak'])->key);
        self::assertSame(45_000, $this->configure(['trim' => 'set'])->snapshot['pricing']['componentsMinor'], 'selectionPrice — за весь вибір');
    }

    public function testPercentIsFromTierBase(): void
    {
        $this->fx->variant->addPriceTier('5', Money::of(80_000, 'UAH'));
        $config = $this->calculator->configure($this->fx->variant, '5', ['leaf' => 'oak'], 'uk');
        self::assertSame(92_000, $config?->quote->price->amount, '800 + 15 % від 800');
    }

    public function testValidationErrors(): void
    {
        $this->fx->leaf->markDefault($this->fx->leaf->findValue('pine') ?? throw new LogicException(), false);
        $errors = $this->errors([]);
        self::assertArrayHasKey('leaf', $errors, 'обов\'язкова без default і без вибору');

        self::assertArrayHasKey('leaf', $this->errors(['leaf' => ['pine', 'oak']]), 'одиночний вибір');
        self::assertArrayHasKey('leaf', $this->errors(['leaf' => 'teak']), 'нема такого значення');
        self::assertArrayHasKey('height', $this->errors(['leaf' => 'pine', 'height' => '2500']), 'понад max');
        self::assertArrayHasKey('height', $this->errors(['leaf' => 'pine', 'height' => '2105']), 'не кратне кроку');
        self::assertArrayHasKey('height', $this->errors(['leaf' => 'pine', 'height' => 'abc']));
        self::assertSame(100_000, $this->configure(['leaf' => 'pine', 'height' => '1900'])->quote->price->amount, 'нижче порогу — без доплати');

        // компонент став «за запитом» після створення опції: без ціни вибору — відмова, а не 0; з ціною вибору — ок
        $this->fx->trimProduct->setPriceMode(PriceMode::OnRequest);
        self::assertStringContainsString('ціна за запитом', $this->errors(['leaf' => 'pine', 'trim' => 'kit'])['trim'] ?? '');
        self::assertSame(145_000, $this->configure(['leaf' => 'pine', 'trim' => 'set'])->quote->price->amount, '1000 + ціна вибору 450');
        $this->fx->trimProduct->setPriceMode(PriceMode::Fixed);

        $this->fx->trim->setActive(false);
        self::assertArrayHasKey('trim', $this->errors(['leaf' => 'pine', 'trim' => 'kit']), 'компонент недоступний');

        $this->fx->hinges->addAbsoluteModifier('refund', Money::of(-200_000, 'UAH'));
        self::assertArrayHasKey('', $this->errors(['leaf' => 'pine', 'hinges' => ['refund']]), 'ціна < 0');
    }

    public function testExplicitNoneIsKeptAndNotReplacedByDefault(): void
    {
        $this->fx->hinges->markDefault($this->fx->hinges->findValue('hidden') ?? throw new LogicException());
        $this->fx->trimGroup->markDefault($this->fx->trimGroup->findValue('set') ?? throw new LogicException());
        self::assertSame(100_000 + 12_300 + 45_000, $this->configure([])->quote->price->amount, 'без ключів — за замовчуванням');

        // порожній radio і зняті чекбокси (форма шле маркер ['']) — «без опції»
        $none = $this->configure(['hinges' => [''], 'trim' => '']);
        self::assertSame(100_000, $none->quote->price->amount);
        self::assertSame(['hinges' => [], 'leaf' => 'pine', 'trim' => ''], $none->snapshot['selection']);
        self::assertSame([], $none->components);
        self::assertSame(100_000, $this->calculator->reconfigure($this->fx->variant, '1', $none->snapshot)?->quote->price->amount, 'кошик і PlaceOrder — те саме, а не +123 +450');
        self::assertNotSame($this->configure([])->key, $none->key, 'інший рядок кошика, ніж вибір за замовчуванням');

        self::assertArrayHasKey('leaf', $this->errors(['leaf' => '']), 'обов\'язкову групу «без» не обрати');
    }

    public function testReconfigureFromSnapshotAndInvalidAfterCatalogChange(): void
    {
        $config = $this->configure(['leaf' => 'oak', 'height' => '2050']);
        self::assertSame($config->quote->price->amount, $this->calculator->reconfigure($this->fx->variant, '1', $config->snapshot)?->quote->price->amount);
        self::assertSame($config->quote->price->amount, TestPricing::create()->line($this->fx->variant, '1', $config->snapshot, null)->unitPrice()->amount);
        self::assertSame(100_000, TestPricing::create()->line($this->fx->variant, '1', null, null)->unitPrice()->amount, 'без снепшота — значення за замовчуванням (pine 0), як у PlaceOrder');

        $this->fx->variant->setPrice(Money::of(200_000, 'UAH'));
        self::assertSame(230_000 + 2_500, TestPricing::create()->line($this->fx->variant, '1', $config->snapshot, null)->unitPrice()->amount, 'у кошику — за поточним каталогом');

        $this->fx->leaf->removeValue($this->fx->leaf->findValue('oak') ?? throw new LogicException());
        $this->expectException(InvalidOptionsException::class);
        $this->calculator->reconfigure($this->fx->variant, '1', $config->snapshot);
    }

    public function testProductWithoutGroupsHasNoConfiguration(): void
    {
        self::assertNull($this->calculator->configure($this->fx->trim, '1', ['leaf' => 'oak'], 'uk'));
        self::assertSame('7.500', OptionConfiguration::componentQuantity('2.500', '3'));
        self::assertSame('0.834', OptionConfiguration::componentQuantity('0.333', '2.505'), 'half-up до 3 знаків');
    }

    public function testNumberGroupInvariants(): void
    {
        self::assertSame(OptionInputType::Number, $this->fx->height->getInputType());
        self::assertSame('2000.0000', $this->fx->height->getNumberThreshold());
        $this->expectExceptionMessage('лише одиночний');
        $this->fx->height->setSelection(OptionSelection::Multiple);
    }

    public function testNumberGroupRejectsValuesAndBadBounds(): void
    {
        foreach ([
            fn () => $this->fx->height->addAbsoluteModifier('x', Money::of(1, 'UAH')),
            fn () => $this->fx->leaf->configureNumber('1', '2', '1', NumberOptionRule::PerUnit, Money::of(1, 'UAH')),
            fn () => $this->fx->door->addOptionGroup('w')->configureNumber('5', '1', '1', NumberOptionRule::PerUnit, Money::of(1, 'UAH')),
            fn () => $this->fx->door->addOptionGroup('s')->configureNumber('1', '5', '0', NumberOptionRule::PerUnit, Money::of(1, 'UAH')),
            fn () => $this->fx->door->addOptionGroup('r')->configureNumber('1', '5', '1', NumberOptionRule::PerUnit, Money::of(-1, 'UAH')),
            fn () => $this->fx->door->addOptionGroup('t')->configureNumber('1', '5', '1', NumberOptionRule::AboveThreshold, Money::of(1, 'UAH')),
            fn () => $this->fx->door->addOptionGroup('c')->configureNumber('1', '5', '1', NumberOptionRule::PerUnit, Money::of(1, 'USD')),
        ] as $i => $invalid) {
            try {
                $invalid();
                self::fail('Очікувалась CatalogInvariantViolation, випадок '.$i);
            } catch (CatalogInvariantViolation) {
                self::addToAssertionCount(1);
            }
        }
    }

    /** @param array<string, mixed> $input */
    private function configure(array $input): OptionConfiguration
    {
        return $this->calculator->configure($this->fx->variant, '1', $input, 'uk') ?? throw new LogicException('нема конфігурації');
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, string>
     */
    private function errors(array $input): array
    {
        try {
            $this->calculator->configure($this->fx->variant, '1', $input, 'uk');
        } catch (InvalidOptionsException $e) {
            return $e->errors;
        }

        return [];
    }
}
