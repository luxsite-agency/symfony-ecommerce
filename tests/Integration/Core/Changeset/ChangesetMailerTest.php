<?php

declare(strict_types=1);

namespace App\Tests\Integration\Core\Changeset;

use App\Core\Audit\AuditSource;
use App\Core\Changeset\ChangesetApplier;
use App\Core\Changeset\ChangesetAuthor;
use App\Core\Changeset\ChangesetPlanner;
use App\Core\Changeset\Entity\Changeset;
use App\Core\Changeset\Mail\ChangesetMailer;
use App\Core\Changeset\Message\SendChangesetAppliedMail;
use App\Core\Changeset\MessageHandler\SendChangesetAppliedMailHandler;
use App\Core\Security\Entity\User;
use App\Core\Security\Enum\UserRole;
use App\Core\Settings\SettingKeys;
use App\Core\Settings\Settings;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Event\MessageEvent;

/** Лист про ціновий пакет (E15 PR-4): адресати з prices.notify_email або активні менеджери без адмінів; зміст — ціни, прапорці, посилання. */
final class ChangesetMailerTest extends ChangesetTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // кеш Settings (cache.app) переживає відкат DAMA — скинути, щоб значення з попереднього запуску не впливало
        self::getContainer()->get(Settings::class)->invalidate();
    }

    protected function tearDown(): void
    {
        self::getContainer()->get(Settings::class)->invalidate();
        parent::tearDown();
    }

    public function testRecipientsFallbackToActiveManagersWithoutAdmins(): void
    {
        $admin = new User('admin@example.com', 'Адмін', UserRole::Admin);
        $inactive = new User('old@example.com', 'Старий', UserRole::Manager);
        $inactive->setActive(false);
        $this->em->persist($admin);
        $this->em->persist($inactive);
        $this->em->flush();
        $mailer = self::getContainer()->get(ChangesetMailer::class);
        self::assertSame(['manager@example.com'], $mailer->recipients());

        $settings = self::getContainer()->get(Settings::class);
        $settings->set(SettingKeys::PRICES_NOTIFY_EMAIL, 'prices@example.com, boss@example.com');
        self::assertSame(['prices@example.com', 'boss@example.com'], $mailer->recipients());
    }

    public function testAppliedPricePackageQueuesOneMailWithDiffAndLinks(): void
    {
        $planner = self::getContainer()->get(ChangesetPlanner::class);
        $applier = self::getContainer()->get(ChangesetApplier::class);
        $changeset = $planner->propose(['title' => 'Зимові ціни', 'operations' => [self::priceOp($this->variantId, '2000.00')]], new ChangesetAuthor($this->userId, 'Олена', AuditSource::AiApi, 7));
        $this->asyncTransport()->reset();
        $this->em->clear();
        $changeset = $this->em->find(Changeset::class, $changeset->getId());
        self::assertNotNull($changeset);
        $applier->apply($changeset, $this->user(), true);

        $queued = array_values(array_filter(array_map(static fn (object $e): object => $e->getMessage(), $this->asyncTransport()->get()), static fn (object $m): bool => $m instanceof SendChangesetAppliedMail));
        self::assertCount(1, $queued);

        $sent = [];
        // MessageEvent іде двічі (queued у Mailer::send і при відправці транспортом) — рахуємо лише відправку, вона вже з відрендереним тілом
        self::getContainer()->get('event_dispatcher')->addListener(MessageEvent::class, static function (MessageEvent $event) use (&$sent): void {
            if (!$event->isQueued()) {
                $sent[] = $event->getMessage();
            }
        });
        (new SendChangesetAppliedMailHandler($this->em, self::getContainer()->get(ChangesetMailer::class)))($queued[0]);
        self::assertCount(1, $sent);
        $email = $sent[0];
        self::assertInstanceOf(TemplatedEmail::class, $email);
        self::assertSame('manager@example.com', $email->getTo()[0]->getAddress());
        self::assertStringContainsString('Застосовано ціновий пакет #'.$changeset->getId().': Зимові ціни', (string) $email->getSubject());
        self::assertStringContainsString('ML-14 price: 4262.00 → 2000.00 (−53 %)', (string) $email->getTextBody());
        self::assertStringNotContainsString('<p>', (string) $email->getTextBody());
        $text = (string) $email->getTextBody();
        self::assertStringContainsString('Ризикові прапорці: price_jump — підтвердив(ла) Олена', $text);
        self::assertStringContainsString('/changeset/'.$changeset->getId(), $text);
        self::assertStringContainsString('changesetId', $text);
        self::assertStringContainsString('<a href=', (string) $email->getHtmlBody());
    }
}
