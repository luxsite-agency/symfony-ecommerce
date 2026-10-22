<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Order\Attachment;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Mime\MimeTypes;

/**
 * Що можна прикласти до заявки: PDF, DWG, JPG, PNG до MAX_BYTES. Тип — за вмістом: finfo (symfony/mime) плюс сигнатура
 * на початку файлу; розширення і Content-Type браузера не беруться до уваги. DWG libmagic знає не у всіх версіях
 * (віддає application/octet-stream), тому для нього вирішує сигнатура «AC10xx» AutoCAD.
 */
final class AttachmentPolicy
{
    public const int MAX_BYTES = 10 * 1024 * 1024;

    /** @var array<string, string> MIME → розширення для назви при віддачі менеджеру */
    public const array ALLOWED = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/vnd.dwg' => 'dwg',
    ];

    /** @var array<string, string> сигнатура (regex по перших байтах) → MIME */
    private const array MAGIC = [
        '/^%PDF-/' => 'application/pdf',
        '/^\xFF\xD8\xFF/' => 'image/jpeg',
        '/^\x89PNG\r\n\x1A\n/' => 'image/png',
        '/^AC10\d\d/' => 'image/vnd.dwg',
    ];

    /**
     * @return string MIME з ALLOWED
     *
     * @throws AttachmentException
     */
    public function check(UploadedFile $file): string
    {
        if (!$file->isValid()) {
            throw new AttachmentException(\in_array($file->getError(), [\UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE], true) ? 'too_large' : 'upload_failed');
        }
        $size = $file->getSize();
        if (0 === $size) {
            throw new AttachmentException('upload_failed');
        }
        if ($size > self::MAX_BYTES) {
            throw new AttachmentException('too_large');
        }

        return self::detect($file->getPathname());
    }

    /**
     * MIME за вмістом файлу на диску (для тестів і команд). Сигнатура — головна; finfo має з нею погодитись
     * (або не знати формат — DWG).
     *
     * @throws AttachmentException bad_type
     */
    public static function detect(string $path): string
    {
        $head = (string) @file_get_contents($path, false, null, 0, 16);
        $bySignature = null;
        foreach (self::MAGIC as $pattern => $mime) {
            if (1 === preg_match($pattern, $head)) {
                $bySignature = $mime;
                break;
            }
        }
        if (null === $bySignature) {
            throw new AttachmentException('bad_type');
        }
        $byContent = MimeTypes::getDefault()->guessMimeType($path);
        $agrees = match ($bySignature) {
            'image/vnd.dwg' => \in_array($byContent, ['image/vnd.dwg', 'application/acad', 'application/x-acad', 'application/dwg', 'application/x-dwg', 'application/octet-stream', null], true),
            default => $byContent === $bySignature,
        };
        if (!$agrees) {
            throw new AttachmentException('bad_type');
        }

        return $bySignature;
    }
}
