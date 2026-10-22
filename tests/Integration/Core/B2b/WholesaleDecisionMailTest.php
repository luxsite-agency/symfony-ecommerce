<?php

declare(strict_types=1);

namespace App\Tests\Integration\Core\B2b;

use App\Core\B2b\Message\SendWholesaleDecisionMail;
use App\Core\B2b\MessageHandler\SendWholesaleDecisionMailHandler;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Sender\SendersLocatorInterface;
use Symfony\Component\Mime\Email;

/** E13 PR-5: листи «схвалено» і «відхилено» рендеряться всіма мовами, причина потрапляє лише у відхилення, транспорт auth_mail. */
final class WholesaleDecisionMailTest extends KernelTestCase
{
    public function testDecisionMailsRenderInEveryLocaleAndUseAuthMailTransport(): void
    {
        self::bootKernel();
        /** @var SendersLocatorInterface $locator */
        $locator = self::getContainer()->get('messenger.senders_locator');
        self::assertSame(['auth_mail'], array_keys(iterator_to_array($locator->getSenders(new Envelope(new SendWholesaleDecisionMail(SendWholesaleDecisionMail::APPROVED, 'a@example.test', 'A', 'uk', 'https://shop.example/'))))));

        $handler = self::getContainer()->get(SendWholesaleDecisionMailHandler::class);
        $kinds = [SendWholesaleDecisionMail::APPROVED, SendWholesaleDecisionMail::REJECTED];
        foreach (['uk', 'ru', 'en'] as $locale) {
            foreach ($kinds as $kind) {
                $handler(new SendWholesaleDecisionMail($kind, 'buyer@example.test', 'Олена', $locale, 'https://shop.example/account/wholesale', SendWholesaleDecisionMail::REJECTED === $kind ? 'Не знайшли <компанію>' : ''));
            }
        }
        $messages = self::getMailerMessages();
        self::assertCount(6, $messages);
        foreach ($messages as $i => $message) {
            self::assertInstanceOf(Email::class, $message);
            $text = (string) $message->getTextBody();
            $html = (string) $message->getHtmlBody();
            self::assertStringContainsString('https://shop.example/account/wholesale', $text);
            self::assertStringContainsString('Олена', $text);
            self::assertStringNotContainsString('email.account', $text.$html.$message->getSubject(), 'усі ключі перекладено');
            if (SendWholesaleDecisionMail::REJECTED === $kinds[$i % 2]) {
                self::assertStringContainsString('Не знайшли <компанію>', $text);
                self::assertStringContainsString('Не знайшли &lt;компанію&gt;', $html, 'причина екранована в HTML');
            } else {
                self::assertStringNotContainsString('Не знайшли', $text.$html);
            }
        }
    }
}
