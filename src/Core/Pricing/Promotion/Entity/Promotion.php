<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Pricing\Promotion\Entity;

use App\Core\Catalog\Entity\Brand;
use App\Core\Catalog\Entity\Category;
use App\Core\Catalog\Entity\Product;
use App\Core\Catalog\Entity\ProductVariant;
use App\Core\Customer\Entity\CustomerGroup;
use App\Core\Pricing\Promotion\Enum\PromotionType;
use App\Core\Shared\Format\DisplayFormat;
use App\Core\Shared\Money\Money;
use App\Core\Shared\Translation\TranslatableInvalidArgumentException;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Акція (Ф3): % або сума за одиницю на категорії (з підкатегоріями), бренди чи окремі товари, з датами й пріоритетом.
 * На весь каталог — лише з явним прапорцем wholeCatalog: акція, у якої видалили всі цілі (каскад FK при видаленні
 * бренду, категорії чи товару), не діє ні на що, а не стає загальною. Тип і значення знижки змінюються лише разом
 * (setPercentDiscount/setAmountDiscount), щоб порядок мапінгу форми не міг перечитати значення в іншій одиниці.
 * За замовчуванням акції не сумуються: діє одна найкраща (пріоритет, далі більша знижка);
 * позначені stackable складаються поверх переможця, якщо він теж stackable (DiscountResolver).
 * Групи покупців (рішення власника 07.10.2026): акція завжди діє на роздрібну ціну (гість, покупець без групи, покупець
 * групи без порога для цього варіанта); на групову ціну (спрацював поріг групи) — лише якщо групу явно додано в
 * customerGroups. Порожній список = лише роздріб, оптові ціни акція не чіпає. Групи порівнюються за id (CustomerGroup::is),
 * бо після $em->clear() це різні екземпляри; провайдер джойнить customerGroups, щоб відв'язана акція не падала.
 */
#[ORM\Entity]
#[ORM\Table(name: 'pricing_promotion')]
#[ORM\Index(name: 'idx_pricing_promotion_active_dates', columns: ['active', 'starts_at', 'ends_at'])]
#[Assert\Callback('validateTargets')]
class Promotion
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank(message: 'pricing.promotion.name.not_blank')]
    #[Assert\Length(max: 120)]
    private string $name;

    #[ORM\Column]
    private bool $active = true;

    #[ORM\Column(length: 16, enumType: PromotionType::class)]
    private PromotionType $type = PromotionType::Percent;

    /** Відсоток у базисних пунктах (1500 = 15 %) або сума в мінорних одиницях — залежно від type. */
    #[ORM\Column(type: Types::BIGINT)]
    private int $value = 0;

    /** Валюта суми (для type = amount); акція суми діє лише на варіанти в цій валюті. */
    #[ORM\Column(length: 3, options: ['fixed' => true])]
    private string $currency;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $startsAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[Assert\GreaterThan(propertyPath: 'startsAt', message: 'pricing.promotion.ends_at.after_start')]
    private ?DateTimeImmutable $endsAt = null;

    /** Більший — пріоритетніший при виборі однієї акції. */
    #[ORM\Column]
    private int $priority = 0;

    /** Може складатися з іншими stackable-акціями; типово — ні (без сумування). */
    #[ORM\Column]
    private bool $stackable = false;

    /** Явно «на весь каталог»; без нього акція без цілей не діє ні на що. */
    #[ORM\Column(options: ['default' => false])]
    private bool $wholeCatalog = false;

    /** @var Collection<int, Category> */
    #[ORM\ManyToMany(targetEntity: Category::class)]
    #[ORM\JoinTable(name: 'pricing_promotion_category')]
    private Collection $categories;

    /** @var Collection<int, Brand> */
    #[ORM\ManyToMany(targetEntity: Brand::class)]
    #[ORM\JoinTable(name: 'pricing_promotion_brand')]
    private Collection $brands;

    /** @var Collection<int, Product> */
    #[ORM\ManyToMany(targetEntity: Product::class)]
    #[ORM\JoinTable(name: 'pricing_promotion_product')]
    private Collection $products;

    /** @var Collection<int, CustomerGroup> Групи, для яких акція діє додатково до роздробу; порожньо = лише роздріб. */
    #[ORM\ManyToMany(targetEntity: CustomerGroup::class)]
    #[ORM\JoinTable(name: 'pricing_promotion_customer_group')]
    private Collection $customerGroups;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $updatedAt;

    public function __construct(string $name, string $currency)
    {
        $this->name = mb_substr(trim($name), 0, 120);
        $this->currency = strtoupper($currency);
        $this->categories = new ArrayCollection();
        $this->brands = new ArrayCollection();
        $this->products = new ArrayCollection();
        $this->customerGroups = new ArrayCollection();
        $this->createdAt = $this->updatedAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = mb_substr(trim($name), 0, 120);
        $this->touch();
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): void
    {
        $this->active = $active;
        $this->touch();
    }

    public function getType(): PromotionType
    {
        return $this->type;
    }

    public function getValue(): int
    {
        return $this->value;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    /** Відсоток знижки (для type = percent), 0–100 з двома знаками. */
    public function getPercent(): ?float
    {
        return PromotionType::Percent === $this->type ? $this->value / 100 : null;
    }

    /** Атомарно: тип «відсоток» і значення 0,01–100 (два знаки) разом. */
    public function setPercentDiscount(string $percent): void
    {
        $normalized = str_replace(',', '.', trim($percent));
        if (!preg_match('/^\d{1,3}(\.\d{1,2})?$/', $normalized)) {
            throw new TranslatableInvalidArgumentException('Відсоток знижки — число від 0,01 до 100, до двох знаків після коми', 'error.promotion.percent_format');
        }
        $basisPoints = (int) round((float) $normalized * 100);
        if ($basisPoints < 1 || $basisPoints > 10_000) {
            throw new TranslatableInvalidArgumentException('Відсоток знижки має бути від 0,01 до 100', 'error.promotion.percent_range');
        }
        $this->type = PromotionType::Percent;
        $this->value = $basisPoints;
        $this->touch();
    }

    /** Сума знижки за одиницю (для type = amount) як десятковий рядок «150.00». */
    public function getAmountDecimal(): ?string
    {
        return PromotionType::Amount === $this->type ? Money::of($this->value, $this->currency)->toDecimalString() : null;
    }

    /** Атомарно: тип «сума за одиницю» і значення разом; валюта — валюта акції. */
    public function setAmountDiscount(string $amount): void
    {
        $normalized = str_replace(',', '.', trim($amount));
        if (!preg_match('/^\d+(\.\d{1,2})?$/', $normalized)) {
            throw new TranslatableInvalidArgumentException('Сума знижки — число більше за нуль, напр. 150.00', 'error.promotion.amount_format');
        }
        $money = Money::fromDecimalString($normalized, $this->currency);
        if (!$money->isPositive()) {
            throw new TranslatableInvalidArgumentException('Сума знижки має бути більшою за нуль', 'error.promotion.amount_positive');
        }
        $this->type = PromotionType::Amount;
        $this->value = $money->amount;
        $this->touch();
    }

    public function getStartsAt(): ?DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function setStartsAt(?DateTimeImmutable $startsAt): void
    {
        $this->startsAt = $startsAt;
        $this->touch();
    }

    public function getEndsAt(): ?DateTimeImmutable
    {
        return $this->endsAt;
    }

    /**
     * Кінець «до цієї хвилини включно»: форма дає хвилини без секунд, а isRunning/провайдер порівнюють endsAt >= now,
     * тож 23:59 без секунд закінчувало акцію о 23:59:00. Зберігаємо 23:59:59 (колонка DATETIME — без мікросекунд).
     */
    public function setEndsAt(?DateTimeImmutable $endsAt): void
    {
        $this->endsAt = $endsAt?->setTime((int) $endsAt->format('H'), (int) $endsAt->format('i'), 59);
        $this->touch();
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function setPriority(int $priority): void
    {
        $this->priority = $priority;
        $this->touch();
    }

    public function isStackable(): bool
    {
        return $this->stackable;
    }

    public function setStackable(bool $stackable): void
    {
        $this->stackable = $stackable;
        $this->touch();
    }

    public function isWholeCatalog(): bool
    {
        return $this->wholeCatalog;
    }

    public function setWholeCatalog(bool $wholeCatalog): void
    {
        $this->wholeCatalog = $wholeCatalog;
        $this->touch();
    }

    public function hasTargets(): bool
    {
        return !$this->categories->isEmpty() || !$this->brands->isEmpty() || !$this->products->isEmpty();
    }

    /** @return Collection<int, Category> */
    public function getCategories(): Collection
    {
        return $this->categories;
    }

    public function addCategory(Category $category): void
    {
        if (!$this->categories->contains($category)) {
            $this->categories->add($category);
        }
    }

    public function removeCategory(Category $category): void
    {
        $this->categories->removeElement($category);
    }

    /** @return Collection<int, Brand> */
    public function getBrands(): Collection
    {
        return $this->brands;
    }

    public function addBrand(Brand $brand): void
    {
        if (!$this->brands->contains($brand)) {
            $this->brands->add($brand);
        }
    }

    public function removeBrand(Brand $brand): void
    {
        $this->brands->removeElement($brand);
    }

    /** @return Collection<int, Product> */
    public function getProducts(): Collection
    {
        return $this->products;
    }

    public function addProduct(Product $product): void
    {
        if (!$this->products->contains($product)) {
            $this->products->add($product);
        }
    }

    public function removeProduct(Product $product): void
    {
        $this->products->removeElement($product);
    }

    /** @return Collection<int, CustomerGroup> */
    public function getCustomerGroups(): Collection
    {
        return $this->customerGroups;
    }

    public function addCustomerGroup(CustomerGroup $group): void
    {
        if (!$this->allowsGroup($group)) {
            $this->customerGroups->add($group);
        }
    }

    public function removeCustomerGroup(CustomerGroup $group): void
    {
        $this->customerGroups->removeElement($group);
    }

    /** Чи дозволена акція для групи, чий поріг дав ціну: null (роздрібна ціна) — завжди; група — лише з явного списку. */
    public function allowsGroup(?CustomerGroup $group): bool
    {
        if (null === $group) {
            return true;
        }
        foreach ($this->customerGroups as $allowed) {
            if ($allowed->is($group)) {
                return true;
            }
        }

        return false;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function isRunning(DateTimeImmutable $at): bool
    {
        return $this->active
            && (null === $this->startsAt || $this->startsAt <= $at)
            && (null === $this->endsAt || $this->endsAt >= $at);
    }

    /**
     * Спершу група покупця (allowsGroup), потім цілі: wholeCatalog — на все; інакше товар, бренд або категорія товару
     * чи будь-який її предок; без цілей — ні на що.
     */
    public function appliesTo(ProductVariant $variant, ?CustomerGroup $group): bool
    {
        if (!$this->allowsGroup($group)) {
            return false;
        }
        if ($this->wholeCatalog) {
            return true;
        }
        $product = $variant->getProduct();
        if ($this->products->contains($product)) {
            return true;
        }
        if (null !== $product->getBrand() && $this->brands->contains($product->getBrand())) {
            return true;
        }
        foreach ($product->getCategories() as $category) {
            if ($this->categories->contains($category)) {
                return true;
            }
            foreach ($category->getAncestors() as $ancestor) {
                if ($this->categories->contains($ancestor)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Знижка за одиницю для ціни: не більша за саму ціну; сума в іншій валюті — 0. */
    public function discountFor(Money $price): Money
    {
        if (!$price->isPositive()) {
            return Money::zero($price->currency);
        }
        $discount = match ($this->type) {
            PromotionType::Percent => $price->percentage($this->value),
            PromotionType::Amount => $price->currency === $this->currency ? Money::of($this->value, $price->currency) : Money::zero($price->currency),
        };

        return $discount->min($price);
    }

    /** Для списку в адмінці: «−15 %» або «−150,00 ₴». */
    public function getSummary(): string
    {
        return PromotionType::Percent === $this->type
            ? \sprintf('−%s %%', rtrim(rtrim(number_format($this->value / 100, 2, '.', ''), '0'), '.'))
            : '−'.DisplayFormat::money(Money::of($this->value, $this->currency), 'uk');
    }

    public function validateTargets(ExecutionContextInterface $context): void
    {
        if ($this->wholeCatalog && $this->hasTargets()) {
            $context->buildViolation('Або «на весь каталог», або цілі (категорії, бренди, товари) — не разом')->atPath('wholeCatalog')->addViolation();
        } elseif (!$this->wholeCatalog && !$this->hasTargets()) {
            $context->buildViolation('Оберіть категорії, бренди чи товари або позначте «на весь каталог»')->atPath('wholeCatalog')->addViolation();
        }
    }

    public function __toString(): string
    {
        return $this->name;
    }

    private function touch(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }
}
