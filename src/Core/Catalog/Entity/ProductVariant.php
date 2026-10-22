<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Entity;

use App\Core\Catalog\Enum\PriceMode;
use App\Core\Catalog\Enum\ProductKind;
use App\Core\Catalog\Enum\SalesUnit;
use App\Core\Catalog\Enum\StockPolicy;
use App\Core\Catalog\Exception\CatalogInvariantViolation;
use App\Core\Customer\Entity\CustomerGroup;
use App\Core\Shared\Money\Money;
use App\Core\Shared\Quantity\Quantity;
use App\Core\Shared\Translation\TranslatableInvalidArgumentException;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Одиниця купівлі (ADR Rev 2): власні sku, ціна, залишок. Cart/Order посилаються лише на варіант.
 * Залишок міняє атомарний UPDATE … WHERE stock >= :q (ADR Settled), не цей об'єкт; setStock() — для імпорту й адмінки.
 */
#[ORM\Entity]
#[ORM\Table(name: 'catalog_product_variant')]
#[ORM\UniqueConstraint(name: 'uniq_catalog_product_variant_sku', columns: ['sku'])]
#[ORM\UniqueConstraint(name: 'uniq_catalog_product_variant_external_id', columns: ['external_id'])]
#[ORM\Index(name: 'idx_catalog_product_variant_barcode', columns: ['barcode'])]
#[ORM\Index(name: 'idx_catalog_product_variant_stock', columns: ['stock_policy', 'active', 'stock'])]
#[ORM\Index(name: 'idx_catalog_product_variant_product_position', columns: ['product_id', 'position'])]
#[UniqueEntity(fields: ['sku'], message: 'catalog.product_variant.sku.unique')]
#[UniqueEntity(fields: ['externalId'], message: 'catalog.product_variant.external_id.unique', ignoreNull: true)]
#[ORM\HasLifecycleCallbacks]
class ProductVariant
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'variants')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Product $product;

    #[ORM\Column(length: 64)]
    #[Assert\NotBlank(message: 'catalog.product_variant.sku.not_blank')]
    private string $sku;

    /** Ключ зіставлення з джерелом імпорту (ProductImportRow, ADR D2). Ідентифікатори CRM — у ExternalRef (Rev 3). */
    #[ORM\Column(length: 128, nullable: true)]
    private ?string $externalId = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $barcode = null;

    #[ORM\Column]
    private int $priceMinor = 0;

    /** Перекреслена ціна; якщо задана — строго більша за поточну. */
    #[ORM\Column(nullable: true)]
    private ?int $oldPriceMinor = null;

    #[ORM\Column(length: 3, options: ['fixed' => true])]
    private string $currency;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 3)]
    private string $stock = '0.000';

    #[ORM\Column(length: 16, enumType: StockPolicy::class)]
    private StockPolicy $stockPolicy = StockPolicy::InStock;

    /** Термін виготовлення для made_to_order (ADR Rev 5); null — «уточнюйте». */
    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $leadTimeDays = null;

    #[ORM\Column(length: 8, enumType: SalesUnit::class)]
    private SalesUnit $unit = SalesUnit::Piece;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 3)]
    private string $minQuantity = '1.000';

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 3)]
    private string $quantityStep = '1.000';

    /** Кількість в упаковці (45 шт у пачці, ADR Rev 7) — підказка й опційне округлення. */
    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 3, nullable: true)]
    private ?string $packQuantity = null;

    #[ORM\Column]
    private int $position = 0;

    #[ORM\Column]
    private bool $active = true;

    /**
     * Значення осей варіанта (розмір, колір — ADR Rev 2): по одному на атрибут-вісь, UNIQUE(variant_id, attribute_id) у БД.
     *
     * @var Collection<int, VariantAxisValue>
     */
    #[ORM\OneToMany(targetEntity: VariantAxisValue::class, mappedBy: 'variant', cascade: ['persist'], orphanRemoval: true)]
    private Collection $axisValues;

    /** @var Collection<int, PriceTier> */
    #[Assert\Valid]
    #[ORM\OneToMany(targetEntity: PriceTier::class, mappedBy: 'variant', cascade: ['persist'], orphanRemoval: true)]
    private Collection $priceTiers;

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    #[ORM\Column]
    private DateTimeImmutable $updatedAt;

    /** @internal використовуйте new Product() / Product::addVariant() */
    public function __construct(Product $product, string $sku, string $currency)
    {
        $this->product = $product;
        $this->changeSku($sku);
        $this->currency = Money::zero($currency)->currency;
        $this->axisValues = new ArrayCollection();
        $this->priceTiers = new ArrayCollection();
        $this->createdAt = $this->updatedAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProduct(): Product
    {
        return $this->product;
    }

    public function __toString(): string
    {
        return $this->sku;
    }

    public function getSku(): string
    {
        return $this->sku;
    }

    public function changeSku(string $sku): void
    {
        $sku = trim($sku);
        if ('' === $sku || mb_strlen($sku) > 64) {
            throw new TranslatableInvalidArgumentException('SKU має бути непорожнім і не довшим за 64 символи', 'error.variant.sku_length');
        }
        foreach ($this->product->getVariants() as $sibling) {
            if ($sibling !== $this && $sibling->sku === $sku) {
                throw new CatalogInvariantViolation(\sprintf('SKU "%s" вже є в цьому товарі', $sku), 'error.variant.sku_exists', ['sku' => $sku]);
            }
        }
        $this->sku = $sku;
    }

    public function getExternalId(): ?string
    {
        return $this->externalId;
    }

    public function setExternalId(?string $externalId): void
    {
        $externalId = null === $externalId ? null : trim($externalId);
        if (null !== $externalId && ('' === $externalId || \strlen($externalId) > 128)) {
            throw new TranslatableInvalidArgumentException('externalId має бути непорожнім і не довшим за 128 байтів', 'error.variant.external_id_length');
        }
        $this->externalId = $externalId;
    }

    public function getBarcode(): ?string
    {
        return $this->barcode;
    }

    public function setBarcode(?string $barcode): void
    {
        $barcode = null === $barcode ? null : trim($barcode);
        $this->barcode = '' === $barcode ? null : $barcode;
    }

    // --- ціна ---

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getPrice(): Money
    {
        return Money::of($this->priceMinor, $this->currency);
    }

    public function getOldPrice(): ?Money
    {
        return null === $this->oldPriceMinor ? null : Money::of($this->oldPriceMinor, $this->currency);
    }

    /** Ціна й перекреслена ціна змінюються разом, щоб інваріант old > price перевірявся на парі. */
    public function setPrice(Money $price, ?Money $oldPrice = null): void
    {
        $this->assertCurrency($price);
        if ($price->isNegative()) {
            throw new CatalogInvariantViolation('Ціна не може бути від\'ємною', 'error.variant.price_negative');
        }
        if (null !== $oldPrice) {
            $this->assertCurrency($oldPrice);
            if (!$oldPrice->greaterThan($price)) {
                throw new CatalogInvariantViolation('Стара ціна має бути більшою за поточну', 'error.variant.old_price_not_greater');
            }
        }
        $this->priceMinor = $price->amount;
        $this->oldPriceMinor = $oldPrice?->amount;
    }

    /** @return Collection<int, PriceTier> */
    public function getPriceTiers(): Collection
    {
        return $this->priceTiers;
    }

    /** Поріг ціни за кількістю, опційно для групи клієнтів (ADR Rev 7). Вибір порога — справа PriceResolver. */
    public function addPriceTier(string $minQuantity, Money $price, ?CustomerGroup $group = null): PriceTier
    {
        $this->assertCurrency($price);
        $minQuantity = Quantity::positive($minQuantity);
        foreach ($this->priceTiers as $tier) {
            $tierGroup = $tier->getCustomerGroup();
            $sameGroup = null === $tierGroup ? null === $group : (null !== $group && $tierGroup->is($group));
            if ($sameGroup && 0 === Quantity::compare($tier->getMinQuantity(), $minQuantity)) {
                throw new CatalogInvariantViolation(\sprintf('Поріг від %s для цієї групи вже є', $minQuantity), 'error.variant.tier_exists', ['min_quantity' => $minQuantity]);
            }
        }
        $tier = new PriceTier($this, $minQuantity, $price, $group);
        $this->priceTiers->add($tier);

        return $tier;
    }

    public function removePriceTier(PriceTier $tier): void
    {
        $this->priceTiers->removeElement($tier);
    }

    // --- залишок ---

    public function getStock(): string
    {
        return $this->stock;
    }

    /** Від'ємний залишок з CRM (in_stock − in_reserve < 0) імпорт має обрізати до нуля до виклику. */
    public function setStock(string $stock): void
    {
        $this->stock = Quantity::nonNegative($stock);
    }

    public function getStockPolicy(): StockPolicy
    {
        return $this->stockPolicy;
    }

    public function setStockPolicy(StockPolicy $policy): void
    {
        if (ProductKind::Service === $this->product->getKind() && StockPolicy::NotTracked !== $policy) {
            throw new CatalogInvariantViolation('Послуга не має залишку: лише not_tracked', 'error.variant.service_stock_policy');
        }
        $this->stockPolicy = $policy;
    }

    public function getLeadTimeDays(): ?int
    {
        return $this->leadTimeDays;
    }

    public function setLeadTimeDays(?int $days): void
    {
        if (null !== $days && ($days < 0 || $days > 32767)) {
            throw new TranslatableInvalidArgumentException('Термін виготовлення — від 0 до 32767 днів', 'error.variant.lead_time_range');
        }
        $this->leadTimeDays = $days;
    }

    // --- кількість ---

    public function getUnit(): SalesUnit
    {
        return $this->unit;
    }

    public function setUnit(SalesUnit $unit): void
    {
        $this->unit = $unit;
    }

    public function getMinQuantity(): string
    {
        return $this->minQuantity;
    }

    public function getQuantityStep(): string
    {
        return $this->quantityStep;
    }

    public function setQuantityRules(string $minQuantity, string $quantityStep): void
    {
        $this->minQuantity = Quantity::positive($minQuantity);
        $this->quantityStep = Quantity::positive($quantityStep);
    }

    public function getPackQuantity(): ?string
    {
        return $this->packQuantity;
    }

    public function setPackQuantity(?string $packQuantity): void
    {
        $this->packQuantity = null === $packQuantity ? null : Quantity::positive($packQuantity);
    }

    // --- осі варіанта ---

    /** @return Collection<int, VariantAxisValue> */
    public function getAxisValues(): Collection
    {
        return $this->axisValues;
    }

    public function getAxisValue(Attribute $attribute): ?AttributeValue
    {
        return $this->findAxis($attribute)?->getValue();
    }

    /** Ставить значення осі (замінює попереднє тієї ж осі); два варіанти товару з однаковим набором осей неприпустимі. */
    public function setAxisValue(AttributeValue $value): void
    {
        $attribute = $value->getAttribute();
        if (!$attribute->isVariantAxis()) {
            throw new CatalogInvariantViolation(\sprintf('Атрибут "%s" не є віссю варіантів', $attribute->getCode()), 'error.variant.not_axis', ['attribute' => $attribute->getCode()]);
        }

        $next = array_map(
            static fn (VariantAxisValue $axis): AttributeValue => $axis->getValue(),
            array_filter($this->axisValues->toArray(), static fn (VariantAxisValue $axis): bool => $axis->getAttribute() !== $attribute),
        );
        $next[] = $value;
        $signature = self::axisSignature($next);
        foreach ($this->product->getVariants() as $sibling) {
            if ($sibling !== $this && $sibling->currentAxisSignature() === $signature) {
                throw new CatalogInvariantViolation(\sprintf('Варіант з таким набором осей уже є: %s', $sibling->getSku()), 'error.variant.axes_duplicate', ['sku' => $sibling->getSku()]);
            }
        }

        $existing = $this->findAxis($attribute);
        if (null !== $existing) {
            $existing->changeValue($value);

            return;
        }
        $this->axisValues->add(new VariantAxisValue($this, $value));
    }

    public function removeAxisValue(Attribute $attribute): void
    {
        $existing = $this->findAxis($attribute);
        if (null !== $existing) {
            $this->axisValues->removeElement($existing);
        }
    }

    // --- службове ---

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): void
    {
        $this->position = $position;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    /**
     * Можна покласти в кошик / оформити: варіант і товар активні й ціна не «за запитом» (для неї на картці — «Запитати
     * ціну»). Залишок і правила кількості перевіряються окремо.
     */
    public function isOrderable(): bool
    {
        return $this->active && $this->product->isActive() && PriceMode::OnRequest !== $this->product->getPriceMode();
    }

    public function setActive(bool $active): void
    {
        $this->active = $active;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }

    private function assertCurrency(Money $money): void
    {
        if ($money->currency !== $this->currency) {
            throw new CatalogInvariantViolation(\sprintf('Валюта варіанта %s, а не %s', $this->currency, $money->currency), 'error.variant.currency', ['expected' => $this->currency, 'actual' => $money->currency]);
        }
    }

    private function findAxis(Attribute $attribute): ?VariantAxisValue
    {
        return $this->axisValues->findFirst(static fn (int $_, VariantAxisValue $axis): bool => $axis->getAttribute() === $attribute);
    }

    private function currentAxisSignature(): string
    {
        return self::axisSignature(array_map(static fn (VariantAxisValue $axis): AttributeValue => $axis->getValue(), $this->axisValues->toArray()));
    }

    /** @param array<AttributeValue> $values */
    private static function axisSignature(array $values): string
    {
        $ids = array_map(spl_object_id(...), $values);
        sort($ids);

        return implode(',', $ids);
    }
}
