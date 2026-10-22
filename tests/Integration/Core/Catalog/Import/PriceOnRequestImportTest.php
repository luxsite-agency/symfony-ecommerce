<?php

declare(strict_types=1);

namespace App\Tests\Integration\Core\Catalog\Import;

use App\Core\Catalog\Entity\CategoryTranslation;
use App\Core\Catalog\Entity\ProductVariant;
use App\Core\Catalog\Enum\ImportRunStatus;
use App\Core\Catalog\Enum\PriceMode;
use App\Core\Catalog\FilterIndex\FilterIndexer;
use App\Core\Catalog\Import\Feed\CsvFeedReader;
use App\Core\Catalog\Import\Feed\FeedOptions;
use App\Core\Catalog\Import\Message\ImportProductMedia;
use App\Core\Catalog\Import\ProductImporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/** CSV магазину Сокіл 77: без ціни → «за запитом» і не purchasable, категорії англійською, фото — у чергу завантаження. */
final class PriceOnRequestImportTest extends KernelTestCase
{
    public function testImportOfWorkshopCsv(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $rows = (new CsvFeedReader())->read(__DIR__.'/../../../../Fixtures/feeds/workshop.csv', new FeedOptions());
        $run = self::getContainer()->get(ProductImporter::class)->import('workshop', $rows, sync: true);
        self::assertSame(ImportRunStatus::Completed, $run->getStatus());
        self::assertSame([], $run->getErrors());

        $variants = $em->getRepository(ProductVariant::class);
        $custom = $variants->findOneBy(['sku' => '95-066']);
        self::assertNotNull($custom);
        self::assertSame(PriceMode::OnRequest, $custom->getProduct()->getPriceMode());
        self::assertFalse($custom->isOrderable(), 'ціна за запитом — купити не можна');
        self::assertSame('Custom manufacturing', $custom->getProduct()->getTranslation('en')?->getName());
        $category = $custom->getProduct()->getMainCategory();
        $en = $category?->getTranslation('en');
        self::assertInstanceOf(CategoryTranslation::class, $en);
        self::assertSame('Custom manufacturing', $en->getName());
        self::assertSame('custom-manufacturing-2', $en->getSlug(), 'товар «Custom manufacturing» уже зайняв /custom-manufacturing: адреси товарів і кореневих категорій спільні');

        $caseB = $variants->findOneBy(['sku' => '95-067-B']);
        self::assertNotNull($caseB);
        self::assertFalse($caseB->isActive(), 'варіант без ціни в картці з ціною — вимкнено');
        self::assertSame(PriceMode::Fixed, $caseB->getProduct()->getPriceMode());

        // read-index: «за запитом» — listed, але не purchasable
        self::getContainer()->get(FilterIndexer::class)->reindexProducts([(int) $custom->getProduct()->getId()]);
        self::assertSame(['1', '0'], array_values(array_map(strval(...), (array) $em->getConnection()->fetchAssociative('SELECT listed, purchasable FROM catalog_filter_variant WHERE variant_id = ?', [$custom->getId()]))));

        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $media = array_values(array_filter(array_map(static fn ($e) => $e->getMessage(), $transport->getSent()), static fn (object $m): bool => $m instanceof ImportProductMedia));
        self::assertCount(1, $media, 'фото — лише в картки, де вони є');
        self::assertSame(['https://example.test/p21.jpg', 'https://example.test/p21b.jpg'], $media[0]->urls);
        self::assertSame($variants->findOneBy(['sku' => '95-021'])?->getProduct()->getId(), $media[0]->productId);
    }
}
