<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Customer\Entity;

use App\Core\Customer\Repository\CustomerRepository;
use App\Core\Order\PhoneNormalizer;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;
use Symfony\Component\Security\Core\User\EquatableInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Покупець сайту (E12, консиліум 07.10, П2). Окремо від адмінського Security\User: свій provider і firewall site.
 * Email — ідентичність (нижній регістр, унікальний); вхід лише після підтвердження email. Реєстрація приймає лише
 * адресу: ім'я й пароль задає власник скриньки на сторінці підтвердження (verifyEmail), тож «попередня реєстрація»
 * чужої адреси нічого не передає (рев'ю безпеки #45, п. 2). Неперевірений рядок без імені й пароля лише «займає» адресу
 * на 7 днів (PurgeUnverifiedCustomers). authVersion — у підписі remember_me і login_link та в isEqualTo:
 * збільшення розлогінює всі пристрої. Групу ставить лише схвалення заявки (E13), у сесію вона не кладеться —
 * provider перечитує покупця щозапиту.
 */
#[ORM\Entity(repositoryClass: CustomerRepository::class)]
#[ORM\Table(name: 'customer')]
#[ORM\UniqueConstraint(name: 'uniq_customer_email', columns: ['email'])]
#[ORM\UniqueConstraint(name: 'uniq_customer_google_sub', columns: ['google_sub'])]
#[ORM\Index(name: 'idx_customer_unverified', columns: ['email_verified_at', 'created_at'])]
class Customer implements UserInterface, PasswordAuthenticatedUserInterface, EquatableInterface
{
    public const string ROLE = 'ROLE_CUSTOMER';
    public const int EMAIL_MAX = 180;
    public const int NAME_MAX = 120;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** null — лише після видалення акаунта (erase): ідентичність стерта, рядок лишається для замовлень і журналу. */
    #[ORM\Column(length: self::EMAIL_MAX, nullable: true)]
    private ?string $email;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $emailVerifiedAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $password = null;

    #[ORM\Column(length: self::NAME_MAX)]
    private string $name;

    /** Контакт профілю, не ідентичність: не унікальний, не підтверджений (OTP — v2). */
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(length: 12)]
    private string $locale;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?CustomerGroup $customerGroup = null;

    #[ORM\Column]
    private bool $active = true;

    #[ORM\Column]
    private int $authVersion = 1;

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    #[ORM\Column]
    private DateTimeImmutable $updatedAt;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $lastLoginAt = null;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $deletedAt = null;

    /**
     * Google як спосіб входу в той самий кабінет (рішення власника 13.10.2026: один Customer — пароль, login-link і Google).
     * `sub` з id_token — стабільний ідентифікатор акаунта Google (email там може змінитись), унікальний серед покупців.
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $googleSub = null;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $googleLinkedAt = null;

    /** Адреса акаунта Google на момент прив'язки — лише для показу в профілі й листах (рев'ю #74, M1); для входу — sub. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $googleEmail = null;

    /** Порожнє ім'я — лише в неперевіреного з форми реєстрації: його задає власник скриньки у verifyEmail(). */
    public function __construct(string $email, string $name, string $locale)
    {
        $this->email = self::normalizeEmail($email);
        if ('' === $this->email || mb_strlen($this->email) > self::EMAIL_MAX || false === filter_var($this->email, \FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Некоректний email');
        }
        if ('' === trim($name)) {
            $this->name = '';
        } else {
            $this->rename($name);
        }
        $this->locale = $locale;
        $this->createdAt = $this->updatedAt = new DateTimeImmutable();
    }

    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    /** Порожній рядок — лише у видаленого (email стерто). */
    public function getEmail(): string
    {
        return $this->email ?? '';
    }

    public function getUserIdentifier(): string
    {
        return $this->email ?? '';
    }

    /**
     * Нова адреса після підтвердження листом на неї (E12 PR-6). Сесії з попередньою адресою гаснуть (isEqualTo порівнює email).
     *
     * @throws InvalidArgumentException
     */
    public function changeEmail(string $email): void
    {
        $email = self::normalizeEmail($email);
        if ('' === $email || mb_strlen($email) > self::EMAIL_MAX || false === filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Некоректний email');
        }
        $this->email = $email;
        $this->emailVerifiedAt = new DateTimeImmutable();
        $this->touch();
    }

    /**
     * Видалення акаунта на запит покупця (рішення власника 11.10.2026: дані зберігаються постійно, тож «видалити» —
     * стерти ідентичність і вимкнути вхід): email, пароль, ім'я, телефон → NULL/порожньо (не фіктивні значення: email
     * унікальний), active = false, authVersion++ (сесії, remember_me, посилання гаснуть). Рядок лишається: на нього
     * посилаються журнал згод і журнал безпеки за id.
     */
    public function erase(): void
    {
        $this->email = null;
        $this->password = null;
        $this->name = '';
        $this->phone = null;
        $this->customerGroup = null;
        $this->googleSub = null; // акаунт Google може зареєструватись знову
        $this->googleLinkedAt = null;
        $this->googleEmail = null;
        $this->active = false;
        // emailVerifiedAt лишається: нічний purge «неперевірених» видалив би рядок разом із журналом згод (рев'ю #60, п. 1)
        $this->deletedAt = new DateTimeImmutable();
        ++$this->authVersion;
        $this->touch();
    }

    /**
     * Заблокувати вхід після повернення старої адреси: пароль стерто (новий — через «забули пароль» на стару адресу), сесії й
     * remember_me гаснуть. Google відв'язується теж: той, хто змінив адресу, міг прив'язати свій акаунт Google і інакше
     * зберіг би вхід.
     */
    public function lockOut(): void
    {
        $this->password = null;
        $this->googleSub = null;
        $this->googleLinkedAt = null;
        $this->googleEmail = null;
        ++$this->authVersion;
        $this->touch();
    }

    public function getGoogleSub(): ?string
    {
        return $this->googleSub;
    }

    public function isGoogleLinked(): bool
    {
        return null !== $this->googleSub;
    }

    public function getGoogleLinkedAt(): ?DateTimeImmutable
    {
        return $this->googleLinkedAt;
    }

    /** Адреса акаунта Google (з id_token на момент прив'язки): у профілі й листах покупець бачить, ЯКИЙ акаунт підключено. */
    public function getGoogleEmail(): ?string
    {
        return $this->googleEmail;
    }

    /** Прив'язати акаунт Google (sub і email з перевіреного id_token). Інші сесії не гаснуть: спосіб входу додано, не змінено. */
    public function linkGoogle(string $sub, string $email): void
    {
        if ('' === $sub || \strlen($sub) > 255) {
            throw new InvalidArgumentException('Некоректний ідентифікатор Google');
        }
        $this->googleSub = $sub;
        $this->googleEmail = mb_substr(self::normalizeEmail($email), 0, 255);
        $this->googleLinkedAt = new DateTimeImmutable();
        $this->touch();
    }

    /** Відв'язати Google: лише коли лишається інший спосіб входу (пароль) — перевіряє той, хто викликає. */
    public function unlinkGoogle(): void
    {
        $this->googleSub = null;
        $this->googleLinkedAt = null;
        $this->googleEmail = null;
        $this->touch();
    }

    /** Чи є хоч один спосіб увійти: пароль (і login-link) або Google. Підтверджений без обох — заблокований lockOut(). */
    public function hasLoginMethod(): bool
    {
        return null !== $this->password || null !== $this->googleSub;
    }

    /**
     * Підтвердження email стороннім провайдером (Google з email_verified=true): без пароля — кабінет працює, вхід через
     * Google або «забули пароль» на цю адресу. Для заготовки з реєстрації (без імені) ім'я береться з профілю Google;
     * authVersion++ гасить усе, що видавалось до підтвердження (посилання з листів гасить AuthTokens::revokeAll окремо).
     */
    public function verifyEmailByProvider(?string $name = null): void
    {
        if ('' === $this->name) {
            $this->rename(null === $name || '' === trim($name) ? $this->emailLocalPart() : $name);
        }
        $this->emailVerifiedAt ??= new DateTimeImmutable();
        ++$this->authVersion;
        $this->touch();
    }

    private function emailLocalPart(): string
    {
        $email = $this->email ?? '';
        $at = strpos($email, '@');

        return false === $at || 0 === $at ? 'Покупець' : substr($email, 0, $at);
    }

    public function isDeleted(): bool
    {
        return null !== $this->deletedAt;
    }

    public function getDeletedAt(): ?DateTimeImmutable
    {
        return $this->deletedAt;
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        return [self::ROLE];
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function rename(string $name): void
    {
        $name = trim($name);
        if ('' === $name || mb_strlen($name) > self::NAME_MAX) {
            throw new InvalidArgumentException('Ім\'я має бути непорожнім і не довшим за '.self::NAME_MAX.' символів');
        }
        $this->name = $name;
        $this->touch();
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    /** E.164 (+380XXXXXXXXX) через PhoneNormalizer; порожньо — null. @throws InvalidArgumentException */
    public function setPhone(?string $phone): void
    {
        $phone = null === $phone ? null : trim($phone);
        $this->phone = null === $phone || '' === $phone ? null : PhoneNormalizer::normalize($phone);
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

    public function getCustomerGroup(): ?CustomerGroup
    {
        return $this->customerGroup;
    }

    public function setCustomerGroup(?CustomerGroup $group): void
    {
        $this->customerGroup = $group;
        $this->touch();
    }

    public function isEmailVerified(): bool
    {
        return null !== $this->emailVerifiedAt;
    }

    public function getEmailVerifiedAt(): ?DateTimeImmutable
    {
        return $this->emailVerifiedAt;
    }

    /**
     * Підтвердження email власником скриньки: пароль (готовий хеш, UserPasswordHasherInterface) і ім'я він задає сам на
     * сторінці з листа. Без імені — лише для вже названого покупця (тести, майбутні сценарії); безіменного не підтвердити.
     */
    public function verifyEmail(string $passwordHash, ?string $name = null): void
    {
        if (null !== $name) {
            $this->rename($name);
        }
        if ('' === $this->name) {
            throw new InvalidArgumentException('Імʼя задається при підтвердженні email');
        }
        $this->emailVerifiedAt ??= new DateTimeImmutable();
        $this->changePasswordHash($passwordHash);
    }

    /** Лише готовий хеш, не сирий пароль. Інші сесії й remember_me втрачають силу (хеш і authVersion змінились). */
    public function changePasswordHash(string $hash): void
    {
        if ('' === $hash) {
            throw new InvalidArgumentException('Порожній хеш пароля');
        }
        $this->password = $hash;
        ++$this->authVersion;
        $this->touch();
    }

    /**
     * Перехешування при вході (PasswordUpgraderInterface; лише коли змінились алгоритм чи cost хешера). authVersion
     * не змінюється, бо пароль той самий. Але хеш інший: сесії ІНШИХ пристроїв (isEqualTo порівнює crc32c хешу) і
     * remember_me (підпис з password) один раз стануть недійсними, і там треба буде увійти знову. Поточна сесія
     * лишається: токен серіалізується вже з новим хешем. Це свідомо (рев'ю безпеки #45, п. 10): рідкісна подія, і
     * розлогінення на ній безпечніше, ніж прибрати хеш із перевірки сесії.
     */
    public function upgradePasswordHash(string $hash): void
    {
        $this->password = $hash;
    }

    /** «Вийти на всіх пристроях»: сесії, remember_me і невикористані login_link інших пристроїв недійсні. */
    public function logoutEverywhere(): void
    {
        ++$this->authVersion;
        $this->touch();
    }

    public function getAuthVersion(): int
    {
        return $this->authVersion;
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

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getLastLoginAt(): ?DateTimeImmutable
    {
        return $this->lastLoginAt;
    }

    public function recordLogin(): void
    {
        $this->lastLoginAt = new DateTimeImmutable();
    }

    /**
     * Сесія зберігає лише id, email, crc32c хешу пароля, authVersion і active (без групи й проксі Doctrine). Змінились
     * пароль, email, authVersion або покупця вимкнено — isEqualTo() дає false, і ContextListener розлогінює.
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'password' => null === $this->password ? null : hash('crc32c', $this->password),
            'authVersion' => $this->authVersion,
            'active' => $this->active,
        ];
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        $this->id = \is_int($data['id'] ?? null) ? $data['id'] : null;
        $this->email = isset($data['email']) && '' !== $data['email'] ? (string) $data['email'] : null;
        $this->password = isset($data['password']) ? (string) $data['password'] : null;
        $this->authVersion = (int) ($data['authVersion'] ?? 0);
        $this->active = (bool) ($data['active'] ?? false);
    }

    public function isEqualTo(UserInterface $user): bool
    {
        if (!$user instanceof self || $user->id !== $this->id || $user->email !== $this->email
            || $user->authVersion !== $this->authVersion || !$user->active) {
            return false;
        }
        // $this — з сесії (crc32c хешу), $user — свіжий з БД (повний хеш)
        $mine = $this->password;
        $fresh = $user->password;

        return $mine === $fresh || (null !== $mine && null !== $fresh && hash_equals($mine, hash('crc32c', $fresh)));
    }

    private function touch(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }
}
