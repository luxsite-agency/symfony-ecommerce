<?php

declare(strict_types=1);

namespace App\Tests\Integration\Core\Changeset;

use App\Core\Audit\AdminAuditContext;
use App\Core\Audit\AuditActor;
use App\Core\Audit\AuditSource;
use App\Core\Changeset\ChangesetApplier;
use App\Core\Changeset\ChangesetAuthor;
use App\Core\Changeset\ChangesetPlanner;
use App\Core\Changeset\ChangesetReverter;
use App\Core\Changeset\ChangesetStatus;
use App\Core\Changeset\Entity\Changeset;
use App\Core\Changeset\Exception\ChangesetNotApplicable;
use App\Core\Changeset\Exception\ChangesetValidationFailed;
use App\Core\Shared\Money\Money;

/** Відкат (E15 PR-3): інверсні операції з diff → новий пакет → застосування; чужа зміна після — конфлікт з «хто/коли». */
final class ChangesetReverterTest extends ChangesetTestCase
{
    private ChangesetPlanner $planner;
    private ChangesetApplier $applier;
    private ChangesetReverter $reverter;
    private ChangesetAuthor $author;

    protected function setUp(): void
    {
        parent::setUp();
        $this->planner = self::getContainer()->get(ChangesetPlanner::class);
        $this->applier = self::getContainer()->get(ChangesetApplier::class);
        $this->reverter = self::getContainer()->get(ChangesetReverter::class);
        $this->author = new ChangesetAuthor($this->userId, 'Олена', AuditSource::AiApi);
    }

    public function testRevertRestoresValuesThroughNewChangeset(): void
    {
        $applied = $this->applied([
            self::priceOp('ML-14', '3990.00', '4262.00'),
            ['op' => 'set_translation', 'entity' => 'product', 'id' => $this->productId, 'locale' => 'uk', 'fields' => ['slug' => 'dveri-ml-14-bili', 'description' => '<p>Новий</p>'], 'expect' => ['slug' => 'dveri-ml-14', 'description' => '<p>Опис</p>']],
            ['op' => 'set_product_categories', 'product' => $this->productId, 'categories' => [$this->doorsId, $this->handlesId], 'main' => $this->handlesId, 'expect' => ['categories' => [$this->doorsId], 'main' => $this->doorsId]],
            ['op' => 'set_price_tier', 'variant' => $this->variantId, 'min_quantity' => '10', 'customer_group' => null, 'price' => '3500.00', 'expect' => ['price' => null]],
            ['op' => 'set_product_flags', 'product' => $this->productId, 'active' => false, 'expect' => ['active' => true]],
            ['op' => 'set_translation', 'entity' => 'product', 'id' => $this->productId, 'locale' => 'uk', 'fields' => ['name' => 'Двері ML 14'], 'expect' => ['name' => 'Двері ML 14']],
        ]);

        $inverse = $this->reverter->inverse($applied)['operations'];
        self::assertSame(['set_variant_price', 'set_translation', 'set_product_categories', 'remove_price_tier', 'set_product_flags'], array_column($inverse, 'op'), 'noop-операція у відкат не потрапляє');
        self::assertSame(['price' => '4262.00', 'old_price' => null, 'expect' => ['price' => '3990.00', 'old_price' => '4262.00']], array_intersect_key($inverse[0], array_flip(['price', 'old_price', 'expect'])));
        self::assertSame(['slug' => 'dveri-ml-14', 'description' => '<p>Опис</p>'], $inverse[1]['fields']);
        self::assertSame(['price' => '3500.00'], $inverse[3]['expect']);
        self::assertSame(['active' => true, 'expect' => ['active' => false]], array_intersect_key($inverse[4], array_flip(['active', 'expect'])));

        $revert = $this->reverter->propose($applied, $this->author);
        self::assertSame($applied->getId(), $revert->getRevertOf()?->getId());
        self::assertSame(ChangesetStatus::Proposed, $revert->getStatus());
        self::assertStringStartsWith('Відкат #', $revert->getTitle());
        $this->applier->apply($this->reload($revert), $this->user());

        $product = $this->product();
        self::assertSame([426_200, null], [$product->getDefaultVariant()->getPrice()->amount, $product->getDefaultVariant()->getOldPrice()]);
        self::assertSame('dveri-ml-14', $product->translate('uk')->getSlug());
        self::assertSame('<p>Опис</p>', $product->translate('uk')->getDescription());
        self::assertSame($this->doorsId, $product->getMainCategory()?->getId());
        self::assertCount(1, $product->getCategories());
        self::assertCount(0, $product->getDefaultVariant()->getPriceTiers());
        self::assertTrue($product->isActive());
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM seo_redirect WHERE source = ?', ['/dveri-ml-14-bili']), 'Авто-301 зі скасованого слага');
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM seo_redirect WHERE source = ?', ['/dveri-ml-14']), 'Стара адреса знову жива — правило знято');
        $apply = $this->db->fetchOne('SELECT new_value FROM admin_audit_entry WHERE action = ? AND entity_id = ?', ['apply', $revert->getId()]);
        self::assertSame($applied->getId(), json_decode((string) $apply, true)['revert_of'], 'Рядок apply відкату вказує на оригінал');
    }

    public function testRevertAfterForeignChangeIsConflictWithBlame(): void
    {
        $applied = $this->applied([self::priceOp($this->variantId, '3990.00')]);
        self::getContainer()->get(AdminAuditContext::class)->runAs(new AuditActor(AuditSource::AdminUi, 99, 'Петро'), function (): void {
            $this->product()->getDefaultVariant()->setPrice(Money::of(410_000, 'UAH'));
            $this->em->flush();
        });
        try {
            $this->reverter->propose($this->reload($applied), $this->author);
            self::fail('Після чужої зміни відкат не створюється');
        } catch (ChangesetValidationFailed $e) {
            self::assertSame('conflict', $e->issues[0]->code);
            self::assertSame('Петро', $e->issues[0]->details['changed_by']);
            self::assertSame('3990.00', $e->issues[0]->details['expected']);
            self::assertSame('4100.00', $e->issues[0]->details['current']);
        }
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM admin_changeset WHERE revert_of_id IS NOT NULL'));
    }

    /** Рев'ю C #72 L4: переклад, створений пакетом, відкат не знімає — зрозуміла помилка замість «required». */
    public function testRevertOfCreatedTranslationIsRefusedWithExplanation(): void
    {
        $proposed = $this->planner->propose(['title' => 'en', 'operations' => [
            ['op' => 'set_translation', 'entity' => 'product', 'id' => $this->productId, 'locale' => 'en', 'fields' => ['name' => 'Door ML 14', 'slug' => 'door-ml-14'], 'expect' => ['name' => null, 'slug' => null]],
        ]], $this->author);
        $applied = $this->applier->apply($this->reload($proposed), $this->user());
        try {
            $this->reverter->propose($this->reload($applied), $this->author);
            self::fail('відкат створеного перекладу');
        } catch (ChangesetValidationFailed $e) {
            self::assertSame('revert_unsupported', $e->issues[0]->code);
            self::assertStringContainsString('лише в адмінці', $e->issues[0]->message);
        }
    }

    public function testOnlyAppliedChangesetsCanBeReverted(): void
    {
        $proposed = $this->planner->propose(['title' => 'Тест', 'operations' => [self::priceOp($this->variantId, '3990.00')]], $this->author);
        $this->expectException(ChangesetNotApplicable::class);
        $this->reverter->inverse($this->reload($proposed));
    }

    /** @param list<array<string, mixed>> $operations */
    private function applied(array $operations): Changeset
    {
        $changeset = $this->planner->propose(['title' => 'Тест', 'operations' => $operations], $this->author);
        $this->applier->apply($this->reload($changeset), $this->user(), true);

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
