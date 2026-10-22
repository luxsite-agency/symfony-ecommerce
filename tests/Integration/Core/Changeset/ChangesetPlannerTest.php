<?php

declare(strict_types=1);

namespace App\Tests\Integration\Core\Changeset;

use App\Core\Audit\AuditSource;
use App\Core\Changeset\ChangesetAuthor;
use App\Core\Changeset\ChangesetPlanner;
use App\Core\Changeset\ChangesetStatus;
use App\Core\Changeset\Exception\ChangesetValidationFailed;
use App\Core\Changeset\Exception\TooManyOperations;
use App\Core\Changeset\Plan\Issue;
use App\Core\Shared\Money\Money;
use DateTimeImmutable;

/** Dry-run (E15 PR-3): схема, expect, домен без побічних ефектів, контент, ризикові прапорці, ліміт операцій, збереження пакета. */
final class ChangesetPlannerTest extends ChangesetTestCase
{
    private ChangesetPlanner $planner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->planner = self::getContainer()->get(ChangesetPlanner::class);
    }

    public function testDryRunBuildsDiffWithoutSideEffects(): void
    {
        $plan = $this->planner->dryRun(['title' => 'Тест', 'operations' => [
            self::priceOp('ML-14', '3990.00', '4262.00'),
            ['op' => 'set_translation', 'entity' => 'product', 'id' => $this->productId, 'locale' => 'uk', 'fields' => ['slug' => 'dveri-ml-14-bili', 'short_description' => 'Білі двері'], 'expect' => ['slug' => 'dveri-ml-14', 'short_description' => null]],
            ['op' => 'set_product_categories', 'product' => $this->productId, 'categories' => [$this->doorsId, $this->handlesId], 'main' => $this->handlesId, 'expect' => ['categories' => [$this->doorsId], 'main' => $this->doorsId]],
            ['op' => 'set_price_tier', 'variant' => $this->variantId, 'min_quantity' => '10', 'customer_group' => 'wholesale', 'price' => '3500.00', 'expect' => ['price' => null]],
        ]]);

        self::assertFalse($plan->hasErrors(), json_encode($plan->toArray(), \JSON_UNESCAPED_UNICODE) ?: '');
        self::assertSame([], $plan->riskFlags());
        $diff = $plan->diffArray();
        self::assertCount(4, $diff);
        self::assertSame(['product_variant', $this->variantId, 'ML-14'], array_values($diff[0]['target']));
        self::assertSame([['field' => 'price', 'locale' => null, 'before' => '4262.00', 'after' => '3990.00'], ['field' => 'old_price', 'locale' => null, 'before' => null, 'after' => '4262.00']], $diff[0]['changes']);
        self::assertSame('uk', $diff[1]['changes'][0]['locale']);
        self::assertSame(['dveri-ml-14', 'dveri-ml-14-bili'], [$diff[1]['changes'][0]['before'], $diff[1]['changes'][0]['after']]);
        self::assertSame([[$this->doorsId], [$this->doorsId, $this->handlesId]], [$diff[2]['changes'][0]['before'], $diff[2]['changes'][0]['after']]);
        self::assertSame([null, '3500.00'], [$diff[3]['changes'][0]['before'], $diff[3]['changes'][0]['after']]);
        self::assertSame($this->variantId, $plan->operations()[0]['target']['id'], 'SKU розв\'язано в id');

        // нічого не записано: сутності, журнал, 301, черга
        $product = $this->product();
        self::assertSame(426_200, $product->getDefaultVariant()->getPrice()->amount);
        self::assertSame('dveri-ml-14', $product->translate('uk')->getSlug());
        self::assertCount(0, $product->getDefaultVariant()->getPriceTiers());
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM admin_audit_entry'));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM seo_redirect'));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM admin_changeset'));
        self::assertCount(0, $this->asyncTransport()->get());
    }

    public function testExpectMismatchAndMissingAreErrors(): void
    {
        $plan = $this->planner->dryRun(['title' => 'Тест', 'operations' => [
            self::priceOp($this->variantId, '3990.00', null, '4000.00'),
            ['op' => 'set_product_flags', 'product' => $this->productId, 'active' => false, 'expect' => []],
        ]]);
        $codes = array_map(static fn (Issue $i): array => [$i->index, $i->code], $plan->errors());
        self::assertSame([[0, 'conflict'], [1, 'expect_missing']], $codes);
        self::assertSame(['field' => 'price', 'expected' => '4000.00', 'current' => '4262.00'], $plan->errors()[0]->details);

        $this->expectException(ChangesetValidationFailed::class);
        $this->planner->propose(['title' => 'Тест', 'operations' => [self::priceOp($this->variantId, '3990.00', null, '4000.00')]], $this->author());
    }

    public function testSchemaErrorsCarryOperationIndex(): void
    {
        try {
            $this->planner->dryRun(['title' => 'Тест', 'operations' => [
                self::priceOp($this->variantId, '3990.00'),
                ['op' => 'set_stock', 'variant' => $this->variantId, 'stock' => '5', 'expect' => []],
            ]]);
            self::fail('Невідома операція має відхилятись');
        } catch (ChangesetValidationFailed $e) {
            self::assertSame([1], array_map(static fn (Issue $i): ?int => $i->index, $e->issues));
            self::assertSame('schema', $e->issues[0]->code);
        }
        try {
            $this->planner->dryRun(['title' => 'Тест', 'operations' => [
                ['op' => 'set_variant_price', 'variant' => $this->variantId, 'price' => '39,90', 'expect' => []],
                ['op' => 'set_translation', 'entity' => 'product', 'id' => $this->productId, 'locale' => 'uk', 'fields' => ['name' => 'X'], 'expect' => [], 'extra' => 1],
            ]]);
            self::fail('Формат ціни й зайвий ключ мають відхилятись');
        } catch (ChangesetValidationFailed $e) {
            $messages = implode(' | ', array_map(static fn (Issue $i): string => $i->index.':'.$i->message, $e->issues));
            self::assertStringContainsString('0:old_price', $messages, 'old_price — обов\'язковий ключ');
            self::assertStringContainsString('1:', $messages);
            self::assertStringContainsString('extra', $messages);
        }
    }

    public function testTranslationRulesFieldWhitelistLocaleSlugHtml(): void
    {
        $plan = $this->planner->dryRun(['title' => 'Тест', 'operations' => [
            ['op' => 'set_translation', 'entity' => 'product', 'id' => $this->productId, 'locale' => 'uk', 'fields' => ['stock' => '5'], 'expect' => ['stock' => null]],
            ['op' => 'set_translation', 'entity' => 'product', 'id' => $this->productId, 'locale' => 'de', 'fields' => ['name' => 'Tür'], 'expect' => ['name' => null]],
            ['op' => 'set_translation', 'entity' => 'product', 'id' => $this->productId, 'locale' => 'uk', 'fields' => ['slug' => 'dveri'], 'expect' => ['slug' => 'dveri-ml-14']],
            ['op' => 'set_translation', 'entity' => 'page', 'id' => $this->pageId, 'locale' => 'uk', 'fields' => ['body' => '<p>Ціна — від 100 грн</p><script>alert(1)</script><p>Два  пробіли і "лапки"</p>'], 'expect' => ['body' => '<p>Текст</p>']],
            ['op' => 'set_translation', 'entity' => 'product', 'id' => $this->productId, 'locale' => 'ru', 'fields' => ['description' => 'x'], 'expect' => ['description' => null]],
            ['op' => 'set_translation', 'entity' => 'page', 'id' => $this->pageId, 'locale' => 'ru', 'fields' => ['title' => 'Доставка', 'slug' => 'dostavka-ru'], 'expect' => ['title' => null, 'slug' => null]],
        ]]);
        $errors = array_map(static fn (Issue $i): string => $i->index.':'.$i->code, $plan->errors());
        self::assertSame(['0:field_not_allowed', '1:locale', '2:slug_taken', '4:translation_missing'], $errors);
        $warnings = array_map(static fn (Issue $i): string => $i->index.':'.$i->code, $plan->warnings());
        self::assertSame(['3:html_sanitized', '3:typography_dash', '3:double_space', '3:straight_quotes'], $warnings);
        $byIndex = array_column($plan->diffArray(), null, 'index');
        $body = $byIndex[3]['changes'][0]['after'];
        self::assertStringNotContainsString('<script', $body);
        self::assertStringContainsString('Ціна — від 100 грн', $body);
        self::assertSame(['title', 'slug'], array_column($byIndex[5]['changes'], 'field'), 'Новий переклад з title і slug');
    }

    /** Рев'ю C #72 M4: новий переклад без slug отримує адресу з назви (як форма адмінки); зняти наявний slug порожнім не можна. */
    public function testSlugIsGeneratedForNewTranslationAndCannotBeCleared(): void
    {
        $plan = $this->planner->dryRun(['title' => 'Тест', 'operations' => [
            ['op' => 'set_translation', 'entity' => 'product', 'id' => $this->productId, 'locale' => 'en', 'fields' => ['name' => 'Door ML 14 White', 'description' => '<p>Steel</p>'], 'expect' => ['name' => null, 'description' => null]],
            ['op' => 'set_translation', 'entity' => 'page', 'id' => $this->pageId, 'locale' => 'en', 'fields' => ['title' => 'Delivery & payment'], 'expect' => ['title' => null]],
            ['op' => 'set_translation', 'entity' => 'product', 'id' => $this->productId, 'locale' => 'uk', 'fields' => ['slug' => ''], 'expect' => ['slug' => 'dveri-ml-14']],
        ]]);
        $diffs = $plan->diffArray();
        $fields = static fn (array $diff): array => array_column($diff['changes'], 'after', 'field');
        self::assertSame('door-ml-14-white', $fields($diffs[0])['slug']);
        self::assertSame('delivery-and-payment', $fields($diffs[1])['slug']);
        $codes = array_map(static fn ($i): string => $i->index.':'.$i->code, $plan->issues());
        self::assertContains('0:slug_generated', $codes);
        self::assertContains('1:slug_generated', $codes);
        self::assertContains('2:required', $codes);
        self::assertStringContainsString('не можна зняти', $plan->errors()[0]->message);

        // новий переклад зі своїм slug — генерації нема; зайнятий згенерований — slug_taken, як і явний
        $plan = $this->planner->dryRun(['title' => 'Тест', 'operations' => [
            ['op' => 'set_translation', 'entity' => 'product', 'id' => $this->productId, 'locale' => 'en', 'fields' => ['name' => 'Door', 'slug' => 'door-en'], 'expect' => ['name' => null, 'slug' => null]],
        ]]);
        self::assertSame([], array_filter($plan->issues(), static fn ($i): bool => 'slug_generated' === $i->code));
        self::assertSame('door-en', $fields($plan->diffArray()[0])['slug']);
    }

    /** Рев'ю C #72 L5: TEXT-поля міряються в байтах, щоб dry-run ловив те, що інакше впало б на apply помилкою БД. */
    public function testTextColumnsAreMeasuredInBytes(): void
    {
        $plan = $this->planner->dryRun(['title' => 'Тест', 'operations' => [
            ['op' => 'set_translation', 'entity' => 'product', 'id' => $this->productId, 'locale' => 'uk', 'fields' => ['short_description' => str_repeat('я', 40000)], 'expect' => ['short_description' => null]],
        ]]);
        self::assertSame(['too_long'], array_map(static fn ($i): string => $i->code, $plan->errors()));
        self::assertStringContainsString('байтів', $plan->errors()[0]->message);
    }

    public function testDomainInvariantsAndDuplicateTargets(): void
    {
        $plan = $this->planner->dryRun(['title' => 'Тест', 'operations' => [
            self::priceOp($this->variantId, '4300.00', '4262.00'),
            self::priceOp($this->variantId, '4100.00'),
            ['op' => 'set_product_categories', 'product' => $this->productId, 'categories' => [$this->handlesId], 'main' => $this->doorsId, 'expect' => ['categories' => [$this->doorsId], 'main' => $this->doorsId]],
            ['op' => 'remove_price_tier', 'variant' => 'NO-SUCH', 'min_quantity' => '10', 'customer_group' => null, 'expect' => ['price' => '1.00']],
            ['op' => 'set_price_tier', 'variant' => $this->variantId, 'min_quantity' => '10', 'customer_group' => 'retail', 'price' => '5000.00', 'expect' => ['price' => null]],
        ]]);
        $errors = array_map(static fn (Issue $i): string => $i->index.':'.$i->code, $plan->errors());
        self::assertSame(['0:domain', '1:duplicate_target', '2:domain', '3:not_found', '4:not_found'], $errors);
        self::assertStringContainsString('Стара ціна', $plan->errors()[0]->message);
    }

    public function testPriceRisksAreWarningsNotErrors(): void
    {
        $plan = $this->planner->dryRun(['title' => 'Тест', 'operations' => [
            self::priceOp($this->variantId, '0.00'),
            ['op' => 'set_price_tier', 'variant' => $this->variantId, 'min_quantity' => '5', 'customer_group' => null, 'price' => '9999.00', 'expect' => ['price' => null]],
        ]]);
        self::assertFalse($plan->hasErrors());
        self::assertSame(['price_jump', 'zero_price'], $plan->riskFlags());
        self::assertSame(['tier_above_base'], array_map(static fn (Issue $i): string => $i->code, $plan->warnings()));
    }

    public function testCumulativeRiskReadsAuditWindow(): void
    {
        // пакет (CLI-сценарій, не лише ai_api — рев'ю C #72 L2) уже знизив ціну з 5000 до 4262 чотири дні тому: ще −15 % дає сукупно > 30 % від 5000
        $this->db->insert('admin_audit_entry', ['occurred_at' => (new DateTimeImmutable('-4 days'))->format('Y-m-d H:i:s'), 'source' => AuditSource::Cli->value, 'action' => 'update', 'entity_type' => 'product_variant', 'entity_id' => $this->variantId, 'field' => 'priceMinor', 'old_value' => '500000', 'new_value' => '426200', 'changeset_id' => 1]);
        $plan = $this->planner->dryRun(['title' => 'Тест', 'operations' => [self::priceOp($this->variantId, '3400.00')]]);
        self::assertSame(['cumulative'], $plan->riskFlags());
        self::assertSame('5000.00', $plan->risks()[0]->details['baseline']);

        // та сама зміна руками з адмінки (без пакета) або поза вікном у базу не входить
        $this->db->executeStatement('UPDATE admin_audit_entry SET source = ?, changeset_id = NULL', [AuditSource::AdminUi->value]);
        self::assertSame([], $this->planner->dryRun(['title' => 'Тест', 'operations' => [self::priceOp($this->variantId, '3400.00')]])->riskFlags());
    }

    public function testTooManyOperationsIs413BeforeParsing(): void
    {
        $operations = array_fill(0, 201, ['op' => 'nonsense']);
        try {
            $this->planner->dryRun(['title' => 'Тест', 'operations' => $operations]);
            self::fail('201 операція має давати 413');
        } catch (TooManyOperations $e) {
            self::assertSame([413, 201, 200], [$e->statusCode, $e->count, $e->max]);
        }
    }

    public function testProposePersistsChangesetWithDiffAndRisks(): void
    {
        $changeset = $this->planner->propose(['title' => 'Знижка', 'operations' => [self::priceOp('ML-14', '2000.00')]], $this->author());
        self::assertNotNull($changeset->getId());
        self::assertSame(ChangesetStatus::Proposed, $changeset->getStatus());
        self::assertSame(['price_jump'], $changeset->getRiskFlags());
        self::assertSame('Олена', $changeset->getAuthor());
        self::assertSame(AuditSource::AiApi, $changeset->getSource());
        self::assertSame(7, $changeset->getTokenId());
        self::assertSame('2000.00', $changeset->getDiff()[0]['changes'][0]['after']);
        self::assertEqualsWithDelta(86400, $changeset->getExpiresAt()->getTimestamp() - $changeset->getCreatedAt()->getTimestamp(), 2);
        self::assertSame(426_200, $this->product()->getDefaultVariant()->getPrice()->amount, 'Пропозиція нічого не змінює');
        self::assertSame(Money::of(426_200, 'UAH')->toDecimalString(), '4262.00');
    }

    private function author(): ChangesetAuthor
    {
        return new ChangesetAuthor($this->userId, 'Олена', AuditSource::AiApi, 7, '10.0.0.0');
    }
}
