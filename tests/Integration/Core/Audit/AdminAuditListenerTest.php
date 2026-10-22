<?php

declare(strict_types=1);

namespace App\Tests\Integration\Core\Audit;

use App\Core\Audit\AdminAuditContext;
use App\Core\Audit\AuditActor;
use App\Core\Audit\AuditSource;
use App\Core\Catalog\Entity\Category;
use App\Core\Catalog\Entity\Product;
use App\Core\Catalog\Import\ProductImporter;
use App\Core\Catalog\Import\ProductImportRow;
use App\Core\Catalog\Import\VariantImportRow;
use App\Core\Content\Entity\Page;
use App\Core\Settings\Entity\Setting;
use App\Core\Settings\SettingKeys;
use App\Core\Shared\Money\Money;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Журнал змін з UnitOfWork (E15 PR-1): рядок на поле з before/after, знімки на create/delete, переклади на батька, без актора — тиша. */
final class AdminAuditListenerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $db;
    private AdminAuditContext $context;
    private Category $doors;
    private Category $handles;
    private Product $product;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->db = $this->em->getConnection();
        $this->context = self::getContainer()->get(AdminAuditContext::class);

        $this->doors = new Category();
        $this->doors->translate('uk')->setName('Двері')->setSlug('dveri');
        $this->handles = new Category();
        $this->handles->translate('uk')->setName('Ручки')->setSlug('ruchky');
        $this->product = new Product('ML-14', 'UAH');
        $this->product->translate('uk')->setName('Двері ML 14')->setSlug('dveri-ml-14');
        $this->product->addCategory($this->doors);
        $this->product->getDefaultVariant()->setPrice(Money::of(426_200, 'UAH'));
        foreach ([$this->doors, $this->handles, $this->product] as $entity) {
            $this->em->persist($entity);
        }
        // без актора (воркер, CLI, сайт) — жодного рядка, навіть на створення
        $this->em->flush();
        self::assertSame(0, $this->rowCount());
    }

    public function testUpdateWritesRowPerFieldWithActor(): void
    {
        $variant = $this->product->getDefaultVariant();
        $this->context->runAs(new AuditActor(AuditSource::AiApi, 7, 'Олена', tokenId: 3, changesetId: 11, ip: '10.0.0.0'), function () use ($variant): void {
            $variant->setPrice(Money::of(399_000, 'UAH'), Money::of(426_200, 'UAH'));
            $this->product->translate('uk')->setName('Двері ML 14 білі');
            $this->product->addCategory($this->handles);
            $this->product->setFeatured(true);
            $this->em->flush();
        });

        $rows = $this->rows();
        self::assertSame([
            ['product', 'categories'],
            ['product', 'featured'],
            ['product', 'name'],
            ['product_variant', 'oldPriceMinor'],
            ['product_variant', 'priceMinor'],
        ], array_map(static fn (array $r): array => [$r['entity_type'], $r['field']], $rows));

        $price = $this->row($rows, 'product_variant', 'priceMinor');
        self::assertSame(426200, json_decode($price['old_value']));
        self::assertSame(399000, json_decode($price['new_value']));
        self::assertSame($variant->getId(), (int) $price['entity_id']);
        self::assertSame('ML-14', $price['entity_label']);
        self::assertSame(['update', 'ai_api', 7, 'Олена', 3, 11, '10.0.0.0'], [$price['action'], $price['source'], (int) $price['user_id'], $price['actor'], (int) $price['token_id'], (int) $price['changeset_id'], $price['ip']]);

        $name = $this->row($rows, 'product', 'name');
        self::assertSame('uk', $name['locale'], 'Переклад пишеться на товар з локаллю');
        self::assertSame($this->product->getId(), (int) $name['entity_id']);
        self::assertSame('Двері ML 14', json_decode($name['old_value']));
        self::assertSame('Двері ML 14 білі', json_decode($name['new_value']));

        $categories = $this->row($rows, 'product', 'categories');
        self::assertSame([$this->doors->getId()], json_decode($categories['old_value']));
        self::assertSame([$this->doors->getId(), $this->handles->getId()], json_decode($categories['new_value']));

        $featured = $this->row($rows, 'product', 'featured');
        self::assertFalse(json_decode($featured['old_value']));
        self::assertTrue(json_decode($featured['new_value']));
        self::assertNull($featured['locale']);
        self::assertNotContains('updatedAt', array_column($rows, 'field'), 'Технічні поля не журналюються');
    }

    public function testCreateAndDeleteWriteSnapshots(): void
    {
        $variant = $this->product->getDefaultVariant();
        $actor = AuditActor::system(AuditSource::Cli, 'тест');
        $tier = $this->context->runAs($actor, function () use ($variant) {
            $tier = $variant->addPriceTier('5', Money::of(400_000, 'UAH'));
            $this->em->flush();

            return $tier;
        });

        $rows = $this->rows();
        self::assertCount(1, $rows);
        self::assertSame(['create', 'price_tier', $tier->getId(), 'ML-14 від 5', null], [$rows[0]['action'], $rows[0]['entity_type'], (int) $rows[0]['entity_id'], $rows[0]['entity_label'], $rows[0]['old_value']]);
        $after = json_decode($rows[0]['new_value'], true);
        ksort($after);
        self::assertSame(['currency' => 'UAH', 'minQuantity' => '5.000', 'priceMinor' => 400000, 'variant' => $variant->getId()], $after);

        $this->db->executeStatement('DELETE FROM admin_audit_entry');
        $tierId = $tier->getId();
        $this->context->runAs($actor, function () use ($variant, $tier): void {
            $variant->removePriceTier($tier);
            $this->em->flush();
        });
        $rows = $this->rows();
        self::assertCount(1, $rows);
        self::assertSame(['delete', 'price_tier', $tierId, 'ML-14 від 5', null], [$rows[0]['action'], $rows[0]['entity_type'], (int) $rows[0]['entity_id'], $rows[0]['entity_label'], $rows[0]['new_value']]);
        self::assertSame(400000, json_decode($rows[0]['old_value'], true)['priceMinor']);
    }

    public function testDeletingProductRecordsProductAndTranslations(): void
    {
        $productId = $this->product->getId();
        $this->context->runAs(AuditActor::system(AuditSource::Cli, 'тест'), function (): void {
            $this->em->remove($this->product);
            $this->em->flush();
        });
        $rows = $this->rows();
        $kinds = array_map(static fn (array $r): string => $r['entity_type'].':'.$r['action'].':'.($r['locale'] ?? '-'), $rows);
        self::assertContains('product:delete:-', $kinds);
        self::assertContains('product:delete:uk', $kinds, 'Переклад видаленого товару — рядок з локаллю на той самий id');
        self::assertContains('product_variant:delete:-', $kinds);
        foreach ($rows as $row) {
            if ('product' === $row['entity_type']) {
                self::assertSame($productId, (int) $row['entity_id']);
            }
        }
    }

    /** Рев'ю C #68 L3: новий товар з категоріями — один рядок create зі списком id, без «update categories [] → [ids]». */
    public function testCreateWithManyToManyGoesIntoSnapshot(): void
    {
        $this->context->runAs(AuditActor::system(AuditSource::Cli, 'тест'), function (): void {
            $product = new Product('ML-15', 'UAH');
            $product->translate('uk')->setName('Двері ML 15')->setSlug('dveri-ml-15');
            $product->addCategory($this->doors);
            $product->addCategory($this->handles);
            $this->em->persist($product);
            $this->em->flush();
        });
        $rows = $this->rows();
        $products = array_values(array_filter($rows, static fn (array $r): bool => 'product' === $r['entity_type'] && null === $r['locale']));
        self::assertCount(1, $products, 'лише create, без update по колекції');
        self::assertSame('create', $products[0]['action']);
        self::assertSame([$this->doors->getId(), $this->handles->getId()], json_decode($products[0]['new_value'], true)['categories']);
        self::assertSame([], array_filter($rows, static fn (array $r): bool => 'update' === $r['action']));
    }

    /** Рев'ю C #68 L2: невдалий flush (postFlush не викликається) не лишає рядків відкочених змін для наступного flush. */
    public function testFailedFlushDoesNotLeakIntoNextFlush(): void
    {
        $actor = AuditActor::system(AuditSource::Cli, 'тест');
        try {
            $this->context->runAs($actor, function (): void {
                $this->product->setFeatured(true);
                $dupe = new Product('ML-14', 'UAH'); // SKU варіанта унікальний → INSERT падає між onFlush і postFlush
                $dupe->translate('uk')->setName('Дубль')->setSlug('dubl');
                $this->em->persist($dupe);
                $this->em->flush();
            });
            self::fail('очікували порушення унікальності');
        } catch (UniqueConstraintViolationException) {
        }
        self::assertSame(0, $this->rowCount(), 'відкочений flush нічого не пише');

        self::getContainer()->get(ManagerRegistry::class)->resetManager();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $product = $em->find(Product::class, $this->product->getId());
        self::assertNotNull($product);
        $this->context->runAs($actor, static function () use ($em, $product): void {
            $product->setActive(false);
            $em->flush();
        });
        $rows = $this->rows();
        self::assertSame(['product:active'], array_map(static fn (array $r): string => $r['entity_type'].':'.$r['field'], $rows), 'лише зміна другого flush, без featured з відкоченого');
    }

    /** Рев'ю C #68 L1: слаг, перерахований SlugListener-ом у preUpdate (порожній → з заголовка), у журналі з фактичним «стало». */
    public function testSlugRecomputedInPreUpdateIsJournaledWithActualValue(): void
    {
        $page = new Page();
        $page->translate('uk')->setTitle('Доставка')->setSlug('stara-adresa');
        $this->em->persist($page);
        $this->em->flush();

        $this->context->runAs(AuditActor::system(AuditSource::Cli, 'тест'), function () use ($page): void {
            $page->translate('uk')->setSlug(null);
            $this->em->flush();
        });
        $rows = $this->rows();
        $slug = $this->row($rows, 'page', 'slug');
        self::assertSame('uk', $slug['locale']);
        self::assertSame('stara-adresa', json_decode($slug['old_value']));
        self::assertSame('dostavka', json_decode($slug['new_value']), 'не null, а слаг, який реально ліг у БД');
        self::assertSame('dostavka', $page->translate('uk')->getSlug());
    }

    /** Рев'ю C #68 L4: значення опцій (їх ціна входить у ціну рядка) журналюються; переклад опції — на значення з локаллю. */
    public function testOptionValueIsJournaled(): void
    {
        $group = $this->product->addOptionGroup('color');
        $value = $group->addAbsoluteModifier('red', Money::of(10_000, 'UAH'));
        $value->translate('uk')->setLabel('Червоний');
        $this->em->flush();
        $this->db->executeStatement('DELETE FROM admin_audit_entry');

        $this->context->runAs(AuditActor::system(AuditSource::Cli, 'тест'), function () use ($group, $value): void {
            $value->setPosition(5);
            $value->translate('uk')->setLabel('Червоний глянець');
            $group->addAbsoluteModifier('blue', Money::of(15_000, 'UAH'));
            $this->em->flush();
        });
        $rows = $this->rows();
        $kinds = array_map(static fn (array $r): string => $r['entity_type'].':'.$r['action'].':'.($r['locale'] ?? '-').':'.($r['field'] ?? '-'), $rows);
        self::assertContains('option_value:update:-:position', $kinds);
        self::assertContains('option_value:update:uk:label', $kinds);
        self::assertContains('option_value:create:-:-', $kinds);
        $position = $this->row($rows, 'option_value', 'position');
        self::assertSame($value->getId(), (int) $position['entity_id']);
        self::assertSame('ML-14 / color: Червоний глянець', $position['entity_label']);
        $created = array_values(array_filter($rows, static fn (array $r): bool => 'create' === $r['action'] && 'option_value' === $r['entity_type']))[0];
        self::assertSame(10_000 + 5_000, json_decode($created['new_value'], true)['amountMinor']);
    }

    /** Налаштування журналюються; зашифроване значення (конверт enc:v1:) — лише маскою з останніми 4 символами. */
    public function testSettingSecretValueIsMasked(): void
    {
        $this->context->runAs(AuditActor::system(AuditSource::Cli, 'тест'), function (): void {
            $setting = new Setting(SettingKeys::GTM_CONTAINER_ID, 'GTM-1');
            $this->em->persist($setting);
            $this->em->flush();
            $setting->setValue('enc:v1:wxyz:'.base64_encode(random_bytes(40)));
            $this->em->flush();
        });
        $rows = $this->rows();
        self::assertSame(['setting:create', 'setting:update'], array_map(static fn (array $r): string => $r['entity_type'].':'.$r['action'], $rows));
        self::assertSame('GTM-1', json_decode($rows[0]['new_value'], true)['value']);
        self::assertSame('GTM-1', json_decode($rows[1]['old_value']));
        self::assertSame('…wxyz', json_decode($rows[1]['new_value']));
        self::assertStringNotContainsString('enc:v1', (string) json_encode($rows));
    }

    public function testImportDoesNotWriteAudit(): void
    {
        $row = new ProductImportRow('F-1', ['uk' => 'Ручка Форт'], [new VariantImportRow('F-1-V', 'FORT-H-1', 35_000, 'UAH', '10')], categoryPaths: [['Фурнітура']]);
        $run = self::getContainer()->get(ProductImporter::class)->import('supplier', [$row], sync: true);
        self::assertSame([], $run->getErrors());
        self::assertSame(1, $run->getProductsCreated());
        self::assertSame(0, $this->rowCount(), 'Імпорт (без актора) журнал не пише — у нього власний звіт');
    }

    public function testFlushWithoutAuditedEntitiesIsCheap(): void
    {
        // актор є, але змінились лише незначущі для журналу сутності — рядків нема
        $this->context->runAs(AuditActor::system(AuditSource::Cli, 'тест'), function (): void {
            $this->em->flush();
        });
        self::assertSame(0, $this->rowCount());
    }

    private function rowCount(): int
    {
        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM admin_audit_entry');
    }

    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        return $this->db->fetchAllAssociative('SELECT * FROM admin_audit_entry ORDER BY entity_type, field, id');
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, mixed>
     */
    private function row(array $rows, string $type, string $field): array
    {
        foreach ($rows as $row) {
            if ($row['entity_type'] === $type && $row['field'] === $field) {
                return $row;
            }
        }
        self::fail(\sprintf('Нема рядка %s.%s', $type, $field));
    }
}
