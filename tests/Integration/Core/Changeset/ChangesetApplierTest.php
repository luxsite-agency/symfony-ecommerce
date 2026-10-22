<?php

declare(strict_types=1);

namespace App\Tests\Integration\Core\Changeset;

use App\Core\Audit\AdminAuditContext;
use App\Core\Audit\AuditActor;
use App\Core\Audit\AuditSource;
use App\Core\Catalog\FilterIndex\Message\ReindexProducts;
use App\Core\Changeset\ChangesetApplier;
use App\Core\Changeset\ChangesetAuthor;
use App\Core\Changeset\ChangesetPlanner;
use App\Core\Changeset\ChangesetStatus;
use App\Core\Changeset\Entity\Changeset;
use App\Core\Changeset\Exception\ChangesetConflict;
use App\Core\Changeset\Exception\ChangesetNotApplicable;
use App\Core\Changeset\Exception\RiskConfirmationRequired;
use App\Core\Changeset\Validate\AuditLookup;
use App\Core\Shared\Money\Money;
use DateTimeImmutable;
use Monolog\Handler\TestHandler;
use Monolog\Logger;

/** Застосування пакета (E15 PR-3): транзакція, повторний expect, журнал з changeset_id, наслідки слухачами, підтвердження ризику, гонки, строк. */
final class ChangesetApplierTest extends ChangesetTestCase
{
    private ChangesetPlanner $planner;
    private ChangesetApplier $applier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->planner = self::getContainer()->get(ChangesetPlanner::class);
        $this->applier = self::getContainer()->get(ChangesetApplier::class);
    }

    public function testApplyWritesAuditRowsWithChangesetIdAndTriggersListeners(): void
    {
        $changeset = $this->propose([
            self::priceOp('ML-14', '3990.00', '4262.00'),
            ['op' => 'set_translation', 'entity' => 'product', 'id' => $this->productId, 'locale' => 'uk', 'fields' => ['slug' => 'dveri-ml-14-bili', 'name' => 'Двері ML 14 білі'], 'expect' => ['slug' => 'dveri-ml-14', 'name' => 'Двері ML 14']],
            ['op' => 'set_product_categories', 'product' => $this->productId, 'categories' => [$this->doorsId, $this->handlesId], 'main' => $this->handlesId, 'expect' => ['categories' => [$this->doorsId], 'main' => $this->doorsId]],
            ['op' => 'set_price_tier', 'variant' => $this->variantId, 'min_quantity' => '10', 'customer_group' => 'wholesale', 'price' => '3500.00', 'expect' => ['price' => null]],
            ['op' => 'set_product_flags', 'product' => $this->productId, 'featured' => true, 'expect' => ['featured' => false]],
        ]);
        $id = (int) $changeset->getId();

        $applied = $this->applier->apply($changeset, $this->user(), false, '10.0.0.0');
        self::assertSame(ChangesetStatus::Applied, $applied->getStatus());
        self::assertSame('Олена', $applied->getAppliedByName());
        self::assertNull($applied->getRiskConfirmedAt(), 'Без ризиків підтвердження не фіксується');

        $product = $this->product();
        $variant = $product->getDefaultVariant();
        self::assertSame([399_000, 426_200], [$variant->getPrice()->amount, $variant->getOldPrice()?->amount]);
        self::assertSame('dveri-ml-14-bili', $product->translate('uk')->getSlug());
        self::assertSame($this->handlesId, $product->getMainCategory()?->getId());
        self::assertCount(1, $variant->getPriceTiers());
        self::assertTrue($product->isFeatured());

        $rows = $this->auditRows();
        $kinds = array_map(static fn (array $r): string => $r['action'].':'.$r['entity_type'].':'.($r['field'] ?? '-'), $rows);
        self::assertContains('update:product_variant:priceMinor', $kinds);
        self::assertContains('update:product_variant:oldPriceMinor', $kinds);
        self::assertContains('update:product:slug', $kinds);
        self::assertContains('update:product:name', $kinds);
        self::assertContains('update:product:categories', $kinds);
        self::assertContains('update:product:mainCategory', $kinds);
        self::assertContains('update:product:featured', $kinds);
        self::assertContains('create:price_tier:-', $kinds);
        self::assertContains('apply:changeset:-', $kinds);
        foreach ($rows as $row) {
            self::assertSame([$id, AuditSource::AiApi->value, $this->userId, 'Олена', 7, '10.0.0.0'], [(int) $row['changeset_id'], $row['source'], (int) $row['user_id'], $row['actor'], (int) $row['token_id'], $row['ip']]);
        }
        $apply = $rows[array_search('apply:changeset:-', $kinds, true)];
        $after = json_decode($apply['new_value'], true);
        ksort($after);
        self::assertSame(['operations' => 5, 'revert_of' => null, 'risk_confirmed' => false, 'risk_flags' => []], $after);
        self::assertSame($id, (int) $apply['entity_id']);

        // наслідки наявними слухачами: авто-301 на старий слаг, перерахунок індексу через чергу
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM seo_redirect WHERE source = ?', ['/dveri-ml-14']));
        $messages = array_map(static fn (object $envelope): object => $envelope->getMessage(), $this->asyncTransport()->get());
        self::assertNotEmpty(array_filter($messages, static fn (object $m): bool => $m instanceof ReindexProducts));
    }

    public function testRiskRequiresExplicitConfirmation(): void
    {
        $changeset = $this->propose([self::priceOp($this->variantId, '2000.00')]);
        self::assertSame(['price_jump'], $changeset->getRiskFlags());
        try {
            $this->applier->apply($changeset, $this->user());
            self::fail('Ризиковий пакет без підтвердження не застосовується');
        } catch (RiskConfirmationRequired $e) {
            self::assertSame([422, ['price_jump']], [$e->statusCode, $e->riskFlags]);
        }
        self::assertSame(426_200, $this->product()->getDefaultVariant()->getPrice()->amount);

        $applied = $this->applier->apply($this->reload($changeset), $this->user(), true);
        self::assertSame(ChangesetStatus::Applied, $applied->getStatus());
        self::assertSame('Олена', $applied->getRiskConfirmedByName());
        self::assertNotNull($applied->getRiskConfirmedAt());
        self::assertSame(200_000, $this->product()->getDefaultVariant()->getPrice()->amount);
        $apply = array_values(array_filter($this->auditRows(), static fn (array $r): bool => 'apply' === $r['action']))[0];
        self::assertSame(['price_jump'], json_decode($apply['new_value'], true)['risk_flags']);
        self::assertTrue(json_decode($apply['new_value'], true)['risk_confirmed']);
    }

    public function testConcurrentChangeBetweenDryRunAndApplyIsConflictWithBlame(): void
    {
        $changeset = $this->propose([self::priceOp($this->variantId, '4100.00')]);
        // хтось в адмінці змінив ціну після створення пакета
        $context = self::getContainer()->get(AdminAuditContext::class);
        $context->runAs(new AuditActor(AuditSource::AdminUi, 99, 'Петро'), function (): void {
            $this->product()->getDefaultVariant()->setPrice(Money::of(430_000, 'UAH'));
            $this->em->flush();
        });
        try {
            $this->applier->apply($this->reload($changeset), $this->user());
            self::fail('Змінена ціна має давати конфлікт');
        } catch (ChangesetConflict $e) {
            self::assertSame(409, $e->statusCode);
            self::assertSame('conflict', $e->issues[0]->code);
            self::assertSame(['4262.00', '4300.00', 'Петро'], [$e->issues[0]->details['expected'], $e->issues[0]->details['current'], $e->issues[0]->details['changed_by']]);
            self::assertStringContainsString('змінив Петро', $e->issues[0]->message);
        }
        self::assertSame(430_000, $this->product()->getDefaultVariant()->getPrice()->amount, 'Транзакцію відкочено, чужа ціна лишилась');
        self::assertSame(ChangesetStatus::Proposed, $this->reload($changeset)->getStatus());
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM admin_audit_entry WHERE changeset_id = ?', [$changeset->getId()]));
    }

    public function testNewRiskSinceProposalIsConflict(): void
    {
        $changeset = $this->propose([self::priceOp($this->variantId, '4000.00')]);
        self::assertSame([], $changeset->getRiskFlags());
        // ціну підняли через адмінку вдвічі, а expect і далі збігається? ні — expect 4262 не зійдеться; тому імітуємо новий ризик через журнал (cumulative)
        $this->db->insert('admin_audit_entry', ['occurred_at' => (new DateTimeImmutable('-1 day'))->format('Y-m-d H:i:s'), 'source' => AuditSource::AiApi->value, 'action' => 'update', 'entity_type' => 'product_variant', 'entity_id' => $this->variantId, 'field' => 'priceMinor', 'old_value' => '700000', 'new_value' => '426200', 'changeset_id' => 1]);
        try {
            $this->applier->apply($changeset, $this->user());
            self::fail('Новий ризик після створення пакета — конфлікт');
        } catch (ChangesetConflict $e) {
            self::assertSame('risk_changed', $e->issues[0]->code);
            self::assertSame(['cumulative'], $e->issues[0]->details['risk_flags']);
        }
    }

    public function testSecondApplyAndRejectAreRefused(): void
    {
        $changeset = $this->propose([self::priceOp($this->variantId, '4100.00')]);
        $this->applier->apply($changeset, $this->user());
        try {
            $this->applier->apply($this->reload($changeset), $this->user());
            self::fail('Повторне застосування');
        } catch (ChangesetNotApplicable $e) {
            self::assertSame([409, ChangesetStatus::Applied], [$e->statusCode, $e->status]);
        }
        $this->expectException(ChangesetNotApplicable::class);
        $this->applier->reject($this->reload($changeset), $this->user());
    }

    public function testExpiredChangesetIsGone(): void
    {
        $changeset = $this->propose([self::priceOp($this->variantId, '4100.00')]);
        $this->db->executeStatement('UPDATE admin_changeset SET expires_at = ? WHERE id = ?', [(new DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s'), $changeset->getId()]);
        try {
            $this->applier->apply($this->reload($changeset), $this->user());
            self::fail('Прострочений пакет');
        } catch (ChangesetNotApplicable $e) {
            self::assertSame([410, ChangesetStatus::Expired], [$e->statusCode, $e->status]);
        }
        self::assertSame('expired', $this->db->fetchOne('SELECT status FROM admin_changeset WHERE id = ?', [$changeset->getId()]));
    }

    /** Рев'ю C #72 M1: «Відхилити» зі застарілим станом у пам'яті не перекриває applied — стан перечитується під FOR UPDATE. */
    public function testRejectAndExpiryNeverOverwriteApplied(): void
    {
        $stale = $this->propose([self::priceOp($this->variantId, '4100.00')]);
        // чужий «Застосувати» встиг раніше (інший процес): у БД уже applied, у цього екземпляра — ще proposed
        $this->db->executeStatement('UPDATE admin_changeset SET status = ?, applied_by_name = ? WHERE id = ?', ['applied', 'Петро', $stale->getId()]);
        try {
            $this->applier->reject($stale, $this->user());
            self::fail('reject поверх applied');
        } catch (ChangesetNotApplicable $e) {
            self::assertSame(ChangesetStatus::Applied, $e->status);
        }
        self::assertSame('applied', $this->db->fetchOne('SELECT status FROM admin_changeset WHERE id = ?', [$stale->getId()]));
        self::assertSame(0, (int) $this->db->fetchOne("SELECT COUNT(*) FROM admin_audit_entry WHERE action = 'reject'"));

        // пакет перетнув expires_at під час чужого apply: ліниве expired не має затерти applied
        $stale = $this->propose([self::priceOp($this->variantId, '4100.00')]);
        $this->db->executeStatement('UPDATE admin_changeset SET status = ?, expires_at = ? WHERE id = ?', ['applied', (new DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s'), $stale->getId()]);
        $this->db->executeStatement('UPDATE admin_changeset SET expires_at = ? WHERE id = ?', [(new DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s'), $stale->getId()]);
        $this->em->getUnitOfWork()->setOriginalEntityData($stale, []);
        $stale->markExpired(); // те, що раніше робив assertApplicable поза транзакцією
        try {
            $this->applier->apply($stale, $this->user());
            self::fail('apply простроченого');
        } catch (ChangesetNotApplicable) {
        }
        self::assertSame('applied', $this->db->fetchOne('SELECT status FROM admin_changeset WHERE id = ?', [$stale->getId()]));
    }

    /** Рев'ю C #72 M2: token_id у рядках журналу — з пакета, навіть коли застосовує людина з сесії без токена. */
    public function testAuditTokenIdComesFromChangesetNotCaller(): void
    {
        $changeset = $this->propose([self::priceOp($this->variantId, '4100.00')]);
        self::assertSame(7, $changeset->getTokenId());
        $this->applier->apply($changeset, $this->user());
        $tokenIds = array_unique(array_map(static fn (array $r): int => (int) $r['token_id'], $this->auditRows()));
        self::assertSame([7], array_values($tokenIds));
        self::assertSame(1, self::getContainer()->get(AuditLookup::class)->priceOperationsToday(7), 'добовий ліміт на токен бачить цю операцію');
    }

    /** Рев'ю C #72 M3: при застосуванні FOR UPDATE беруться й переклади, пороги та зв'язки категорій цілей, не лише корені. */
    public function testApplyLocksDependentRowsForUpdate(): void
    {
        $changeset = $this->propose([
            ['op' => 'set_translation', 'entity' => 'product', 'id' => $this->productId, 'locale' => 'uk', 'fields' => ['name' => 'Двері ML 14 білі'], 'expect' => ['name' => 'Двері ML 14']],
            ['op' => 'set_price_tier', 'variant' => $this->variantId, 'min_quantity' => '10', 'customer_group' => null, 'price' => '3500.00', 'expect' => ['price' => null]],
            ['op' => 'set_product_categories', 'product' => $this->productId, 'categories' => [$this->doorsId, $this->handlesId], 'main' => $this->doorsId, 'expect' => ['categories' => [$this->doorsId], 'main' => $this->doorsId]],
            ['op' => 'set_translation', 'entity' => 'page', 'id' => $this->pageId, 'locale' => 'uk', 'fields' => ['title' => 'Доставка і оплата'], 'expect' => ['title' => 'Доставка']],
        ]);
        /** @var Logger $doctrine */
        $doctrine = self::getContainer()->get('monolog.logger.doctrine');
        $doctrine->pushHandler($sql = new TestHandler());
        $this->applier->apply($changeset, $this->user());
        $all = array_map(static fn ($r): string => \is_string($r->context['sql'] ?? null) ? $r->context['sql'] : $r->message, $sql->getRecords());
        $locks = array_values(array_filter($all, static fn (string $m): bool => str_contains($m, 'FOR UPDATE')));
        self::assertNotEmpty($all, 'doctrine-канал нічого не записав');
        foreach (['catalog_product_translation', 'content_page_translation', 'catalog_price_tier', 'catalog_product_category', 'admin_changeset', 'catalog_product_variant'] as $table) {
            self::assertNotEmpty(array_filter($locks, static fn (string $m): bool => str_contains($m, $table)), $table.' без FOR UPDATE');
        }
    }

    public function testRejectWritesAuditRow(): void
    {
        $changeset = $this->propose([self::priceOp($this->variantId, '4100.00')]);
        $rejected = $this->applier->reject($changeset, $this->user(), '10.0.0.0');
        self::assertSame(ChangesetStatus::Rejected, $rejected->getStatus());
        self::assertSame('Олена', $rejected->getRejectedByName());
        $rows = $this->auditRows();
        self::assertCount(1, $rows);
        self::assertSame(['reject', 'changeset', (int) $changeset->getId()], [$rows[0]['action'], $rows[0]['entity_type'], (int) $rows[0]['entity_id']]);
    }

    /** @param list<array<string, mixed>> $operations */
    private function propose(array $operations): Changeset
    {
        $changeset = $this->planner->propose(['title' => 'Тест', 'operations' => $operations], new ChangesetAuthor($this->userId, 'Олена', AuditSource::AiApi, 7, '10.0.0.0'));
        $this->asyncTransport()->reset();

        return $this->reload($changeset);
    }

    private function reload(Changeset $changeset): Changeset
    {
        $this->em->clear();
        $reloaded = $this->em->find(Changeset::class, $changeset->getId());
        self::assertNotNull($reloaded);

        return $reloaded;
    }
}
