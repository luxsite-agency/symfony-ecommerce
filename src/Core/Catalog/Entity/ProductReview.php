<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Entity;

use App\Core\Catalog\Enum\ReviewStatus;
use App\Core\Catalog\Repository\ProductReviewRepository;
use App\Core\Customer\Entity\Customer;
use App\Core\Security\Entity\User;
use App\Core\Shared\Translation\TranslatableInvalidArgumentException;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Відгук покупця на товар (рішення власника 13.10.2026): один відгук на товар від покупця (unique), оцінка 1–5, текст без
 * HTML і посилань (перевіряє ReviewText на вході; тут лише обрізка пробілів — тексти живих людей не правимо), мова
 * сторінки, стан модерації, позначка «купив цей товар» (виконане замовлення з цим товаром на момент збереження),
 * відповідь магазину (текст, хто, коли). Зникає разом з товаром або покупцем (CASCADE); видалення акаунта стирає явно
 * (AccountDeletion). `demo` — демо-відгук публічного демо (позначка на сторінці, не йде у schema.org).
 */
#[ORM\Entity(repositoryClass: ProductReviewRepository::class)]
#[ORM\Table(name: 'catalog_product_review')]
#[ORM\UniqueConstraint(name: 'uniq_product_review_customer', columns: ['product_id', 'customer_id'])]
#[ORM\Index(name: 'idx_product_review_product_status', columns: ['product_id', 'status', 'created_at'])]
#[ORM\Index(name: 'idx_product_review_status', columns: ['status', 'created_at'])]
#[ORM\Index(name: 'idx_product_review_customer', columns: ['customer_id', 'created_at'])]
class ProductReview
{
    public const int RATING_MIN = 1;
    public const int RATING_MAX = 5;
    public const int TEXT_MAX = 2000;
    public const int REPLY_MAX = 2000;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Product $product;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Customer $customer;

    #[ORM\Column(type: 'smallint')]
    private int $rating;

    #[ORM\Column(type: 'text')]
    private string $text;

    #[ORM\Column(length: 12)]
    private string $locale;

    #[ORM\Column(length: 16, enumType: ReviewStatus::class)]
    private ReviewStatus $status = ReviewStatus::Pending;

    #[ORM\Column]
    private bool $verifiedPurchase = false;

    #[ORM\Column]
    private bool $demo = false;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $replyText = null;

    /** Знімок «хто відповів» (ім'я для показу), бо користувача адмінки можуть видалити. */
    #[ORM\Column(length: 180, nullable: true)]
    private ?string $replyBy = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $replyUser = null;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $repliedAt = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $moderatedBy = null;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $moderatedAt = null;

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    #[ORM\Column]
    private DateTimeImmutable $updatedAt;

    public function __construct(Product $product, Customer $customer, int $rating, string $text, string $locale)
    {
        $this->product = $product;
        $this->customer = $customer;
        $this->locale = $locale;
        $this->createdAt = $this->updatedAt = new DateTimeImmutable();
        $this->setRating($rating);
        $this->setText($text);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProduct(): Product
    {
        return $this->product;
    }

    public function getCustomer(): Customer
    {
        return $this->customer;
    }

    public function getRating(): int
    {
        return $this->rating;
    }

    public function setRating(int $rating): void
    {
        if ($rating < self::RATING_MIN || $rating > self::RATING_MAX) {
            throw new TranslatableInvalidArgumentException(\sprintf('Оцінка — від %d до %d', self::RATING_MIN, self::RATING_MAX), 'error.review.rating_range', ['min' => (string) self::RATING_MIN, 'max' => (string) self::RATING_MAX]);
        }
        $this->rating = $rating;
        $this->touch();
    }

    public function getText(): string
    {
        return $this->text;
    }

    /** Лише обрізка пробілів по краях: жодної автокорекції тексту живої людини. */
    public function setText(string $text): void
    {
        $text = trim($text);
        if ('' === $text) {
            throw new TranslatableInvalidArgumentException('Текст відгуку порожній', 'error.review.text_blank');
        }
        if (mb_strlen($text) > self::TEXT_MAX) {
            throw new TranslatableInvalidArgumentException(\sprintf('Текст відгуку довший за %d символів', self::TEXT_MAX), 'error.review.text_too_long', ['max' => (string) self::TEXT_MAX]);
        }
        $this->text = $text;
        $this->touch();
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): void
    {
        $this->locale = $locale;
    }

    public function getStatus(): ReviewStatus
    {
        return $this->status;
    }

    public function isApproved(): bool
    {
        return ReviewStatus::Approved === $this->status;
    }

    public function isPending(): bool
    {
        return ReviewStatus::Pending === $this->status;
    }

    /** Рішення модератора (адмінка) або автосхвалення без премодерації ($by = null). */
    public function moderate(ReviewStatus $status, ?string $by): void
    {
        $this->status = $status;
        $this->moderatedBy = $by;
        $this->moderatedAt = new DateTimeImmutable();
        $this->touch();
    }

    /** Редагування покупцем: назад на модерацію, якщо увімкнено премодерацію. */
    public function resubmit(bool $premoderation): void
    {
        $this->status = $premoderation ? ReviewStatus::Pending : ReviewStatus::Approved;
        $this->moderatedBy = null;
        $this->moderatedAt = $premoderation ? null : new DateTimeImmutable();
        $this->touch();
    }

    public function isVerifiedPurchase(): bool
    {
        return $this->verifiedPurchase;
    }

    public function setVerifiedPurchase(bool $verified): void
    {
        $this->verifiedPurchase = $verified;
    }

    public function isDemo(): bool
    {
        return $this->demo;
    }

    public function markDemo(): void
    {
        $this->demo = true;
    }

    public function getReplyText(): ?string
    {
        return $this->replyText;
    }

    public function getReplyBy(): ?string
    {
        return $this->replyBy;
    }

    public function getReplyUser(): ?User
    {
        return $this->replyUser;
    }

    public function getRepliedAt(): ?DateTimeImmutable
    {
        return $this->repliedAt;
    }

    /** Відповідь магазину; порожній текст прибирає відповідь. */
    public function reply(?string $text, ?User $user): void
    {
        $text = null === $text ? null : trim($text);
        if (null === $text || '' === $text) {
            $this->replyText = $this->replyBy = $this->replyUser = $this->repliedAt = null;
            $this->touch();

            return;
        }
        if (mb_strlen($text) > self::REPLY_MAX) {
            throw new TranslatableInvalidArgumentException(\sprintf('Відповідь довша за %d символів', self::REPLY_MAX), 'error.review.reply_too_long', ['max' => (string) self::REPLY_MAX]);
        }
        $this->replyText = $text;
        $this->replyUser = $user;
        $this->replyBy = $user?->getDisplayName();
        $this->repliedAt = new DateTimeImmutable();
        $this->touch();
    }

    public function hasReply(): bool
    {
        return null !== $this->replyText;
    }

    public function getModeratedBy(): ?string
    {
        return $this->moderatedBy;
    }

    public function getModeratedAt(): ?DateTimeImmutable
    {
        return $this->moderatedAt;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function touch(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }
}
