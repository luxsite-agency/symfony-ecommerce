<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Shipping\NovaPoshta;

use App\Core\Order\ShippingPoint;
use Doctrine\DBAL\Connection;

/**
 * Читання локальних довідників НП для checkout: пошук міста за префіксом, відділення міста, снепшот обраного
 * відділення (ShippingPoint). isEnabled — ключ задано і довідник не порожній; інакше checkout показує текстові поля.
 */
final class NovaPoshtaDirectory
{
    private ?bool $hasData = null;

    public function __construct(private readonly Connection $db, private readonly NovaPoshtaClient $client)
    {
    }

    public function isEnabled(): bool
    {
        return $this->client->isEnabled() && $this->hasData();
    }

    public function hasData(): bool
    {
        return $this->hasData ??= false !== $this->db->fetchOne('SELECT 1 FROM shipping_np_city LIMIT 1');
    }

    /** @return list<NovaPoshtaCityView> міста за префіксом назви (uk або ru за мовою), спочатку точний збіг, далі великі типи */
    public function searchCities(string $query, string $locale, int $limit = 10): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 2) {
            return [];
        }
        $column = 'ru' === $locale ? 'name_ru' : 'name';
        $rows = $this->db->fetchAllAssociative(
            \sprintf('SELECT ref, name, name_ru, area, settlement_type FROM shipping_np_city WHERE %1$s LIKE ? ORDER BY (%1$s = ?) DESC, CHAR_LENGTH(%1$s), %1$s LIMIT %2$d', $column, max(1, min($limit, 50))),
            [self::likePrefix($query), $query],
        );

        return array_map(self::cityView(...), $rows);
    }

    public function city(string $ref): ?NovaPoshtaCityView
    {
        $row = $this->db->fetchAssociative('SELECT ref, name, name_ru, area, settlement_type FROM shipping_np_city WHERE ref = ?', [$ref]);

        return false === $row ? null : self::cityView($row);
    }

    /** Точний збіг назви (без регістру), як її ввів покупець без JS; кілька однойменних — null (нехай уточнить). */
    public function findCityByName(string $name, string $locale): ?NovaPoshtaCityView
    {
        $name = trim(preg_replace('/,.*$/u', '', $name) ?? $name);
        if ('' === $name) {
            return null;
        }
        $column = 'ru' === $locale ? 'name_ru' : 'name';
        $rows = $this->db->fetchAllAssociative(\sprintf('SELECT ref, name, name_ru, area, settlement_type FROM shipping_np_city WHERE %s = ? LIMIT 2', $column), [$name]);

        return 1 === \count($rows) ? self::cityView($rows[0]) : null;
    }

    /** @return list<NovaPoshtaWarehouseView> активні відділення й поштомати міста, за номером; $query — фільтр по номеру/адресі */
    public function warehouses(string $cityRef, string $locale, ?string $query = null, int $limit = 1000): array
    {
        $sql = 'SELECT ref, city_ref, number, name, name_ru, short_address, type, active FROM shipping_np_warehouse WHERE city_ref = ? AND active = 1';
        $params = [$cityRef];
        if (null !== $query && '' !== trim($query)) {
            $sql .= \sprintf(' AND (%s LIKE ? OR number LIKE ?)', 'ru' === $locale ? 'name_ru' : 'name');
            $params[] = '%'.addcslashes(trim($query), '%_\\').'%';
            $params[] = self::likePrefix(trim($query));
        }
        $sql .= \sprintf(' ORDER BY type, CAST(number AS UNSIGNED), number LIMIT %d', max(1, min($limit, 5000)));

        return array_map(self::warehouseView(...), $this->db->fetchAllAssociative($sql, $params));
    }

    public function warehouse(string $ref): ?NovaPoshtaWarehouseView
    {
        $row = $this->db->fetchAssociative('SELECT ref, city_ref, number, name, name_ru, short_address, type, active FROM shipping_np_warehouse WHERE ref = ?', [$ref]);

        return false === $row ? null : self::warehouseView($row);
    }

    /** Снепшот обраного відділення для замовлення (місто — з довідника за відділенням). null — відділення невідоме. */
    public function pointFor(string $warehouseRef, string $locale): ?ShippingPoint
    {
        $warehouse = '' === trim($warehouseRef) ? null : $this->warehouse(trim($warehouseRef));
        if (null === $warehouse) {
            return null;
        }
        $city = $this->city($warehouse->cityRef);

        return new ShippingPoint(
            provider: ShippingPoint::PROVIDER_NOVA_POSHTA,
            cityRef: $warehouse->cityRef,
            cityName: null !== $city ? $city->name($locale) : '',
            warehouseRef: $warehouse->ref,
            warehouseNumber: $warehouse->number,
            warehouseName: $warehouse->label($locale),
            warehouseType: $warehouse->type,
        );
    }

    /** @param array<string, mixed> $row */
    private static function cityView(array $row): NovaPoshtaCityView
    {
        return new NovaPoshtaCityView((string) $row['ref'], (string) $row['name'], (string) $row['name_ru'], (string) $row['area'], (string) $row['settlement_type']);
    }

    /** @param array<string, mixed> $row */
    private static function warehouseView(array $row): NovaPoshtaWarehouseView
    {
        return new NovaPoshtaWarehouseView((string) $row['ref'], (string) $row['city_ref'], (string) $row['number'], (string) $row['name'], (string) $row['name_ru'], (string) $row['short_address'], (string) $row['type'], (bool) $row['active']);
    }

    private static function likePrefix(string $value): string
    {
        return addcslashes($value, '%_\\').'%';
    }
}
