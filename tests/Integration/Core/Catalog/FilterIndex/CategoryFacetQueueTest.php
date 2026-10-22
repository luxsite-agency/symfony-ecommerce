<?php

declare(strict_types=1);

namespace App\Tests\Integration\Core\Catalog\FilterIndex;

use App\Core\Catalog\FilterIndex\CategoryFacetQueue;
use App\Core\Catalog\FilterIndex\FilterIndexer;
use App\Core\Catalog\FilterIndex\Message\RefreshCategoryFacets;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/** Черга перерахунку зведення (ADR Rev 11 п. 4): категорія з предками, одне повідомлення на рядок, затримка. */
final class CategoryFacetQueueTest extends KernelTestCase
{
    public function testMarksCategoryWithAncestorsOnceAndWaitsForDelay(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $db = self::getContainer()->get(Connection::class);
        $fx = new CatalogIndexFixture($em, bin2hex(random_bytes(3)));
        self::getContainer()->get(FilterIndexer::class)->reindexProducts($fx->productIds());
        $db->executeStatement('DELETE FROM '.CategoryFacetQueue::TABLE);

        $bus = new class implements MessageBusInterface {
            /** @var list<Envelope> */
            public array $sent = [];

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                return $this->sent[] = new Envelope($message, $stamps);
            }
        };
        $queue = new CategoryFacetQueue($db, $bus, 120);

        self::assertTrue($queue->markProducts([$fx->id('P1')]), 'новий рядок — треба повідомлення');
        $queued = array_map(intval(...), $db->fetchFirstColumn('SELECT category_id FROM '.CategoryFacetQueue::TABLE.' ORDER BY category_id'));
        $expected = [(int) $fx->shoes->getId(), (int) $fx->sneakers->getId(), (int) $fx->running->getId()];
        sort($expected);
        self::assertSame($expected, $queued, 'running і всі предки');
        self::assertFalse($queue->markProducts([$fx->id('P2')]), 'sneakers і shoes уже в черзі — повідомлення в дорозі');
        self::assertSame([], $queue->ready(), 'ще не відлежали 120 с');

        $queue->dispatch();
        self::assertInstanceOf(RefreshCategoryFacets::class, $bus->sent[0]->getMessage());
        self::assertSame(120_000, $bus->sent[0]->last(DelayStamp::class)?->getDelay());

        $db->executeStatement('UPDATE '.CategoryFacetQueue::TABLE.' SET queued_at = NOW(6) - INTERVAL 10 MINUTE');
        self::assertCount(3, $queue->ready());
        self::assertTrue($queue->markProducts([$fx->id('P2')]), 'завислий рядок (повідомлення загубилось) — шлемо знову');
        self::assertTrue($queue->take((int) $fx->shoes->getId()));
        self::assertFalse($queue->take((int) $fx->shoes->getId()), 'другий процес категорію вже не отримає');
    }
}
