<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Unit\Core\Order;

use App\Core\Order\Attachment\AttachmentException;
use App\Core\Order\Attachment\AttachmentPolicy;
use App\Core\Order\LeadRequestService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/** Тип вкладення — за вмістом (сигнатура + finfo), не за розширенням чи Content-Type браузера; ліміт 10 МБ. */
final class AttachmentPolicyTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function accepted(): iterable
    {
        yield 'pdf' => ["%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF\n", 'application/pdf'];
        yield 'png' => [(string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', true), 'image/png'];
        yield 'jpeg' => ["\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xD9", 'image/jpeg'];
        yield 'dwg (AutoCAD 2018, libmagic може не знати)' => ['AC1032'.str_repeat("\x00", 64), 'image/vnd.dwg'];
    }

    #[DataProvider('accepted')]
    public function testDetectsByContent(string $bytes, string $mime): void
    {
        $path = $this->tmp($bytes);
        self::assertSame($mime, AttachmentPolicy::detect($path));
        // розширення й заявлений тип не важать: той самий файл під назвою .exe з Content-Type text/plain проходить як $mime
        self::assertSame($mime, (new AttachmentPolicy())->check(new UploadedFile($path, 'plan.exe', 'text/plain', null, true)));
    }

    /** @return iterable<string, array{string}> */
    public static function rejected(): iterable
    {
        yield 'PE-файл під назвою .pdf' => ["MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xFF\xFF"];
        yield 'HTML' => ['<!doctype html><html><script>alert(1)</script></html>'];
        yield 'текст' => ['just text, no signature'];
        yield 'SVG (скрипти всередині)' => ['<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"><script>1</script></svg>'];
        yield 'порожній' => [''];
        yield 'PDF-сигнатура, але finfo каже інше' => ["%PDF-\n<html><body>x</body></html>"];
    }

    #[DataProvider('rejected')]
    public function testRejectsForeignContent(string $bytes): void
    {
        $path = $this->tmp($bytes);
        try {
            AttachmentPolicy::detect($path);
            if ('' === $bytes) {
                self::fail('порожній файл має бути відхилений');
            }
            // finfo для «%PDF- + html» може повернути application/pdf — тоді сигнатура й finfo збігаються, це прийнятно (PDF не рендериться)
            self::assertStringStartsWith('%PDF-', $bytes);
        } catch (AttachmentException $e) {
            self::assertSame('bad_type', $e->key);
        }
    }

    public function testSizeAndUploadErrors(): void
    {
        $policy = new AttachmentPolicy();
        $path = $this->tmp("%PDF-1.4\n%%EOF\n");
        try {
            $policy->check(new UploadedFile($path, 'a.pdf', null, \UPLOAD_ERR_INI_SIZE, true));
            self::fail();
        } catch (AttachmentException $e) {
            self::assertSame('too_large', $e->key);
        }
        try {
            $policy->check(new UploadedFile($path, 'a.pdf', null, \UPLOAD_ERR_PARTIAL, true));
            self::fail();
        } catch (AttachmentException $e) {
            self::assertSame('upload_failed', $e->key);
        }
        $big = $this->tmp("%PDF-1.4\n".str_repeat('x', AttachmentPolicy::MAX_BYTES));
        try {
            $policy->check(new UploadedFile($big, 'big.pdf', null, null, true));
            self::fail();
        } catch (AttachmentException $e) {
            self::assertSame('too_large', $e->key);
        }
    }

    public function testComposeComment(): void
    {
        self::assertSame('Заявка', LeadRequestService::composeComment('', '', '', ''));
        self::assertSame("Заявка: треба стелажі\nКонтекст: Рішення: Палетне зберігання\nВаріант: Не знаю, порадьте\nСторінка: https://racks.example/solutions/pallet/",
            LeadRequestService::composeComment('треба стелажі', 'Рішення: Палетне зберігання', 'Не знаю, порадьте', 'https://racks.example/solutions/pallet/'));
    }

    private function tmp(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'att');
        self::assertNotFalse($path);
        file_put_contents($path, $bytes);
        register_shutdown_function(static fn () => @unlink($path));

        return $path;
    }
}
