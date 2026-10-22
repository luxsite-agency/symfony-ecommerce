<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core;

use App\Core\Audit\AuditAction;
use App\Core\Audit\Entity\AdminAuditEntry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

/** Колонка «Зміна» журналу (аудит UX A12): поле людською назвою з мовою, значення без JSON, створення й видалення — словом. */
final class AuditChangeSummaryTest extends TestCase
{
    /** @return iterable<string, array{AuditAction, ?string, ?string, mixed, mixed, string}> */
    public static function rows(): iterable
    {
        yield 'зміна назви' => [AuditAction::Update, 'name', 'uk', 'Двері', 'Двері Лінея', 'Назва (uk): Двері → Двері Лінея'];
        yield 'поле без мови' => [AuditAction::Update, 'active', null, false, true, 'Активний: ні → так'];
        yield 'порожнє було' => [AuditAction::Update, 'metaTitle', 'ru', null, 'SEO', 'SEO-заголовок (ru): — → SEO'];
        yield 'створення' => [AuditAction::Create, null, null, null, ['code' => 'ai.model'], 'створено'];
        yield 'видалення' => [AuditAction::Delete, null, null, ['name' => 'X'], null, 'видалено'];
        yield 'масив без JSON' => [AuditAction::Apply, null, null, null, ['items' => 3, 'risk' => 'low'], 'items: 3; risk: low'];
        yield 'невідоме поле' => [AuditAction::Update, 'weirdField', null, 1, 2, 'weirdField: 1 → 2'];
    }

    #[DataProvider('rows')]
    public function testSummary(AuditAction $action, ?string $field, ?string $locale, mixed $before, mixed $after, string $expected): void
    {
        $entry = (new ReflectionClass(AdminAuditEntry::class))->newInstanceWithoutConstructor();
        foreach (['action' => $action, 'field' => $field, 'locale' => $locale, 'before' => $before, 'after' => $after] as $prop => $value) {
            (new ReflectionProperty(AdminAuditEntry::class, $prop))->setValue($entry, $value);
        }
        self::assertSame($expected, $entry->getChangeSummary());
    }

    public function testLongValuesAreCut(): void
    {
        $entry = (new ReflectionClass(AdminAuditEntry::class))->newInstanceWithoutConstructor();
        foreach (['action' => AuditAction::Update, 'field' => 'description', 'locale' => 'uk', 'before' => str_repeat('а', 200), 'after' => 'б'] as $prop => $value) {
            (new ReflectionProperty(AdminAuditEntry::class, $prop))->setValue($entry, $value);
        }
        self::assertSame('Опис (uk): '.str_repeat('а', 80).'… → б', $entry->getChangeSummary());
    }
}
