<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Shared\Crypto;

use App\Core\Shared\Crypto\SecretBox;
use App\Core\Shared\Crypto\SecretRedactProcessor;
use App\Core\Shared\Crypto\SecretUnreadableException;
use DateTimeImmutable;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** E15 BYOK: конверт секрету, маска без ключа, інший ключ не відкриває, процесор ховає секрет у логах. */
final class SecretBoxTest extends TestCase
{
    private const string SECRET = 'sk-or-v1-0123456789abcdef0123456789abcdef';

    public function testEncryptsToEnvelopeWithMaskAndDecryptsBack(): void
    {
        $box = new SecretBox(SecretBox::generateKey());
        $envelope = $box->encrypt(self::SECRET);
        self::assertStringStartsWith('enc:v1:cdef:', $envelope);
        self::assertTrue(SecretBox::isEnvelope($envelope));
        self::assertStringNotContainsString(self::SECRET, $envelope);
        self::assertSame('…cdef', SecretBox::mask($envelope));
        self::assertSame(self::SECRET, $box->decrypt($envelope));
        self::assertNotSame($envelope, $box->encrypt(self::SECRET), 'nonce випадковий: той самий секрет — інший конверт');
    }

    public function testPlainValuesAreNotEnvelopes(): void
    {
        self::assertFalse(SecretBox::isEnvelope(self::SECRET));
        self::assertFalse(SecretBox::isEnvelope(null));
        self::assertFalse(SecretBox::isEnvelope('enc:v1:abcd:не base64'));
        self::assertNull(SecretBox::mask(self::SECRET));
        self::assertSame('…', SecretBox::mask('enc:v1::'.base64_encode('x')), 'короткий секрет — маска без символів');
    }

    public function testOtherKeyDoesNotOpen(): void
    {
        $envelope = (new SecretBox(SecretBox::generateKey()))->encrypt(self::SECRET);
        $other = new SecretBox(SecretBox::generateKey());
        $this->expectException(SecretUnreadableException::class);
        $other->decrypt($envelope);
    }

    public function testCorruptEnvelopeAndMissingKeyAreUnreadableToo(): void
    {
        $box = new SecretBox(SecretBox::generateKey());
        $envelope = $box->encrypt(self::SECRET);
        foreach (['не конверт', 'enc:v1:cdef:'.base64_encode('short'), substr($envelope, 0, -8).'AAAAAAAA'] as $broken) {
            try {
                $box->decrypt($broken);
                self::fail('очікувався SecretUnreadableException для '.$broken);
            } catch (SecretUnreadableException) {
            }
        }
        $this->expectException(SecretUnreadableException::class);
        (new SecretBox(''))->decrypt($envelope);
    }

    /** L4: маска — лише [A-Za-z0-9], інакше «:» у хвості зламав би конверт; L5: порожній і некоректний ключ — різні проблеми. */
    public function testMaskSkipsUnsafeTailAndKeyProblemDistinguishesEmptyFromInvalid(): void
    {
        $box = new SecretBox(SecretBox::generateKey());
        $withColon = $box->encrypt('secret-value-ab:c');
        self::assertTrue(SecretBox::isEnvelope($withColon));
        self::assertSame('…', SecretBox::mask($withColon));
        self::assertSame('secret-value-ab:c', $box->decrypt($withColon));
        self::assertSame('…', SecretBox::mask($box->encrypt('short')));
        self::assertSame('…z9Z0', SecretBox::mask($box->encrypt('abcdefgz9Z0')));

        self::assertSame('empty', (new SecretBox(''))->keyProblem());
        self::assertSame('empty', (new SecretBox("  \n"))->keyProblem());
        self::assertSame('invalid', (new SecretBox(base64_encode('short')))->keyProblem());
        self::assertSame('invalid', (new SecretBox('not base64!'))->keyProblem());
        self::assertNull($box->keyProblem());
    }

    public function testUnavailableWithoutProperKey(): void
    {
        self::assertFalse((new SecretBox(''))->isAvailable());
        self::assertFalse((new SecretBox(base64_encode('short')))->isAvailable());
        self::assertFalse((new SecretBox('not base64!'))->isAvailable());
        self::assertTrue((new SecretBox(SecretBox::generateKey()))->isAvailable());
        $this->expectException(RuntimeException::class);
        (new SecretBox(''))->encrypt(self::SECRET);
    }

    public function testRedactProcessorMasksRevealedSecrets(): void
    {
        $box = new SecretBox(SecretBox::generateKey());
        $processor = new SecretRedactProcessor($box);
        $record = static fn (string $message, array $context = []): LogRecord => new LogRecord(new DateTimeImmutable(), 'app', Level::Error, $message, $context);

        self::assertSame('until decrypt: '.self::SECRET, $processor($record('until decrypt: '.self::SECRET))->message, 'поки секрет не відкривали — нічого маскувати');
        $box->decrypt($box->encrypt(self::SECRET));
        $out = $processor($record('Authorization: Bearer '.self::SECRET, ['headers' => ['authorization' => 'Bearer '.self::SECRET], 'nested' => [['k' => self::SECRET]], 'n' => 5]));
        self::assertSame('Authorization: Bearer …cdef', $out->message);
        self::assertSame('Bearer …cdef', $out->context['headers']['authorization']);
        self::assertSame('…cdef', $out->context['nested'][0]['k']);
        self::assertSame(5, $out->context['n']);
    }
}
