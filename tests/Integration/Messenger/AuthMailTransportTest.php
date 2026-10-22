<?php

declare(strict_types=1);

namespace App\Tests\Integration\Messenger;

use App\Core\Catalog\Import\Message\ProcessImportChunk;
use App\Core\Shared\Messenger\AuthMailMessage;
use ReflectionClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Sender\SendersLocatorInterface;

/** Листи входу/реєстрації (E12 PR-0) — окремий транспорт auth_mail, не спільна черга async з імпортом. */
final class AuthMailTransportTest extends KernelTestCase
{
    public function testAuthMailHasOwnTransport(): void
    {
        self::bootKernel();
        /** @var SendersLocatorInterface $locator */
        $locator = self::getContainer()->get('messenger.senders_locator');
        $senders = static fn (object $m): array => array_keys(iterator_to_array($locator->getSenders(new Envelope($m))));

        self::assertSame(['auth_mail'], $senders(new class implements AuthMailMessage {}));
        self::assertNotContains('auth_mail', $senders((new ReflectionClass(ProcessImportChunk::class))->newInstanceWithoutConstructor()));
    }
}
