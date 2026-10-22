<?php

declare(strict_types=1);

namespace App\Tests\Integration\Core\Shipping;

use App\Core\Shipping\NovaPoshta\Message\SyncNovaPoshtaDirectory;
use App\Core\Shipping\NovaPoshta\MessageHandler\SyncNovaPoshtaDirectoryHandler;
use App\Core\Shipping\NovaPoshta\NovaPoshtaClient;
use App\Core\Shipping\NovaPoshta\NovaPoshtaDirectory;
use App\Core\Shipping\NovaPoshta\NovaPoshtaDirectorySync;
use App\Schedule;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\Trigger\CronExpressionTrigger;

/**
 * Синхронізація довідників НП з мокованого API: посторінково, upsert, видалення зниклих; пошук у довіднику;
 * хендлер/команда/розклад.
 */
final class NovaPoshtaSyncTest extends KernelTestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->db = self::getContainer()->get(Connection::class);
    }

    public function testSyncUpsertsAndRemovesStaleThenDirectorySearches(): void
    {
        $sync = $this->sync([
            'cities' => [[self::city('c-kyiv', 'Київ', 'Киев', 'Київська', 'місто'), self::city('c-bor', 'Бориспіль', 'Борисполь', 'Київська', 'місто')], [self::city('c-kyi2', 'Київець', 'Киевец', 'Львівська', 'село')]],
            'warehouses' => [[self::wh('w-1', 'c-kyiv', '1', 'Відділення №1: вул. Пирогівський шлях, 135', 'Branch'), self::wh('w-2', 'c-kyiv', '2', 'Відділення №2: вул. Богатирська, 11', 'Branch'), self::wh('w-p', 'c-kyiv', '5001', 'Поштомат №5001: вул. Хрещатик, 1', 'Postomat'), self::wh('w-old', 'c-bor', '3', 'Відділення №3 (тимчасово не працює)', 'Branch', 'NotWorking')]],
        ], pageSize: 2);
        $report = $sync->run();
        self::assertSame(3, $report->cities);
        self::assertSame(4, $report->warehouses);
        self::assertSame(0, $report->removedCities);

        $directory = new NovaPoshtaDirectory($this->db, new NovaPoshtaClient(new MockHttpClient(), 'k'));
        self::assertTrue($directory->isEnabled());
        self::assertSame(['Київ', 'Київець'], array_map(static fn ($c): string => $c->name, $directory->searchCities('Ки', 'uk')), 'Префікс, спочатку коротша/точна назва');
        self::assertSame('Київець, Львівська обл.', $directory->searchCities('Київе', 'uk')[0]->label('uk'));
        self::assertSame(['Киев', 'Киевец'], array_map(static fn ($c): string => $c->name('ru'), $directory->searchCities('Кие', 'ru')));
        self::assertSame([], $directory->searchCities('К', 'uk'), 'Менше 2 символів — порожньо');
        self::assertSame('c-kyiv', $directory->findCityByName('київ', 'uk')?->ref, 'Без регістру (collation)');
        self::assertSame('c-kyiv', $directory->findCityByName('Київ, Київська обл.', 'uk')?->ref, 'Label з області теж розпізнається');

        $warehouses = $directory->warehouses('c-kyiv', 'uk');
        self::assertSame(['1', '2', '5001'], array_map(static fn ($w): string => $w->number, $warehouses), 'Відділення перед поштоматами, за номером');
        self::assertSame('postomat', $warehouses[2]->type);
        self::assertSame([], $directory->warehouses('c-bor', 'uk'), 'Неробочі відділення не показуються');
        self::assertSame(['2'], array_map(static fn ($w): string => $w->number, $directory->warehouses('c-kyiv', 'uk', 'Богатир')));
        $point = $directory->pointFor('w-2', 'uk');
        self::assertNotNull($point);
        self::assertSame(['nova_poshta', 'c-kyiv', 'Київ', 'w-2', '2', 'Відділення №2: вул. Богатирська, 11', 'branch'], array_values($point->toArray()));
        self::assertNull($directory->pointFor('nope', 'uk'));

        // друга синхронізація: відділення №1 зникло з API, №2 перейменовано, місто Бориспіль зникло
        $report = $this->sync([
            'cities' => [[self::city('c-kyiv', 'Київ', 'Киев', 'Київська', 'місто'), self::city('c-kyi2', 'Київець', 'Киевец', 'Львівська', 'село')]],
            'warehouses' => [[self::wh('w-2', 'c-kyiv', '2', 'Відділення №2: вул. Богатирська, 11а', 'Branch'), self::wh('w-p', 'c-kyiv', '5001', 'Поштомат №5001: вул. Хрещатик, 1', 'Postomat')]],
        ], pageSize: 10)->run();
        self::assertSame(1, $report->removedCities);
        self::assertSame(2, $report->removedWarehouses, 'w-1 і w-old прибрано');
        self::assertSame('Відділення №2: вул. Богатирська, 11а', $directory->warehouse('w-2')?->name);
        self::assertSame(2, (int) $this->db->fetchOne('SELECT COUNT(*) FROM shipping_np_city'));
    }

    public function testEmptyApiAnswerKeepsExistingData(): void
    {
        $this->sync(['cities' => [[self::city('c-1', 'Одеса', 'Одесса', 'Одеська', 'місто')]], 'warehouses' => [[self::wh('w-1', 'c-1', '1', 'Відділення №1', 'Branch')]]])->run();
        try {
            $this->sync(['cities' => [[]], 'warehouses' => [[]]])->run();
            self::fail('Порожній довідник має бути помилкою');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('порожній', $e->getMessage());
        }
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM shipping_np_city'), 'Старі дані на місці');
    }

    public function testHandlerCommandAndSchedule(): void
    {
        $handler = new SyncNovaPoshtaDirectoryHandler(new NovaPoshtaClient(new MockHttpClient(), ''), self::getContainer()->get(NovaPoshtaDirectorySync::class), new NullLogger());
        $handler(new SyncNovaPoshtaDirectory()); // без ключа — тихо виходить, API не викликається

        $application = new Application(self::$kernel ?? self::bootKernel());
        $application->setAutoExit(false);
        $tester = new CommandTester($application->find('shipping:np:sync'));
        $tester->execute(['--async' => true]);
        self::assertStringContainsString('в чергу', $tester->getDisplay());
        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertContains(SyncNovaPoshtaDirectory::class, array_map(static fn ($e): string => $e->getMessage()::class, $transport->getSent()), 'Команда --async кладе повідомлення в async');

        // у розкладі є й інші задачі (Merchant feed, журнал 404) — беремо задачу НП за типом повідомлення
        $messages = array_values(array_filter(
            self::getContainer()->get(Schedule::class)->getSchedule()->getRecurringMessages(),
            static fn ($m): bool => $m->getTrigger() instanceof CronExpressionTrigger
                && [...$m->getMessages(new MessageContext('default', $m->getId(), $m->getTrigger(), new DateTimeImmutable()))][0] instanceof SyncNovaPoshtaDirectory,
        ));
        self::assertCount(1, $messages);
        $trigger = $messages[0]->getTrigger();
        self::assertInstanceOf(CronExpressionTrigger::class, $trigger);
        self::assertSame('30 3 * * *', (string) $trigger);
        $context = new MessageContext('default', $messages[0]->getId(), $trigger, new DateTimeImmutable());
        self::assertInstanceOf(SyncNovaPoshtaDirectory::class, [...$messages[0]->getMessages($context)][0] ?? null);
    }

    /** @param array{cities: list<list<array<string, mixed>>>, warehouses: list<list<array<string, mixed>>>} $pages */
    private function sync(array $pages, int $pageSize = 1000): NovaPoshtaDirectorySync
    {
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use ($pages): MockResponse {
            /** @var array{calledMethod: string, methodProperties: array{Page: int}} $body */
            $body = json_decode((string) $options['body'], true, 512, \JSON_THROW_ON_ERROR);
            $set = 'getCities' === $body['calledMethod'] ? $pages['cities'] : $pages['warehouses'];
            $data = $set[$body['methodProperties']['Page'] - 1] ?? [];

            return new MockResponse(json_encode(['success' => true, 'data' => $data, 'errors' => []], \JSON_THROW_ON_ERROR), ['response_headers' => ['content-type' => 'application/json']]);
        });

        return new NovaPoshtaDirectorySync(new NovaPoshtaClient($client, 'test-np-key'), $this->db, self::getContainer()->get(LockFactory::class), $pageSize);
    }

    /** @return array<string, string> */
    private static function city(string $ref, string $name, string $nameRu, string $area, string $type): array
    {
        return ['Ref' => $ref, 'Description' => $name, 'DescriptionRu' => $nameRu, 'AreaDescription' => $area, 'SettlementTypeDescription' => $type];
    }

    /** @return array<string, string> */
    private static function wh(string $ref, string $cityRef, string $number, string $name, string $category, string $status = 'Working'): array
    {
        return ['Ref' => $ref, 'CityRef' => $cityRef, 'Number' => $number, 'Description' => $name, 'DescriptionRu' => $name, 'ShortAddress' => 'Київ, '.$name, 'CategoryOfWarehouse' => $category, 'WarehouseStatus' => $status];
    }
}
