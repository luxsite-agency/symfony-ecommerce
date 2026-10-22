<?php

declare(strict_types=1);

namespace App\Tests\Unit\Admin;

use App\Admin\Order\OrderHistoryLine;
use App\Core\Order\Entity\Order;
use App\Core\Order\Entity\OrderStatusHistory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;

/** Історія замовлення словами: коди переходу, стану й автора — лише для БД. */
final class OrderHistoryLineTest extends TestCase
{
    /** @return iterable<string, array{?string, string, string, string, ?string, string, string, ?string}> */
    public static function lines(): iterable
    {
        yield 'checkout' => [null, 'new', 'place', 'customer', 'checkout', 'Створено покупцем на сайті:', 'покупець', null];
        yield 'запит ціни' => [null, 'new', 'place', 'customer', 'price_request', 'Запит ціни від покупця:', 'покупець', null];
        yield 'менеджер' => ['new', 'confirmed', 'confirm', 'ivan@example.test', 'дзвонив', 'Нове →', 'менеджер ivan@example.test', 'дзвонив'];
        yield 'демо' => ['confirmed', 'cancelled', 'cancel', 'demo-seed', 'Демо-дані', 'Підтверджене →', 'демо-дані', 'Демо-дані'];
        yield 'система' => ['shipped', 'completed', 'complete', 'system', null, 'Відправлене →', 'система', null];
    }

    #[DataProvider('lines')]
    public function testLine(?string $from, string $to, string $transition, string $actor, ?string $comment, string $event, string $who, ?string $note): void
    {
        $order = (new ReflectionClass(Order::class))->newInstanceWithoutConstructor();
        $line = OrderHistoryLine::of(new OrderStatusHistory($order, $from, $to, $transition, $actor, $comment), self::translator());
        self::assertSame($event, $line->event);
        self::assertSame($who, $line->actor);
        self::assertSame($note, $line->comment, 'код джерела створення — не коментар');
    }

    /** Перекладач без ядра: лише uk-каталог домену admin (ICU), як в адмінці з мовою uk. */
    private static function translator(): Translator
    {
        $translator = new Translator('uk');
        $translator->addLoader('yaml', new YamlFileLoader());
        $translator->addResource('yaml', \dirname(__DIR__, 3).'/translations/admin+intl-icu.uk.yaml', 'uk', 'admin+intl-icu');

        return $translator;
    }
}
