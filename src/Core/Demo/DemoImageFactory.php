<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Demo;

use App\Core\Catalog\Entity\Media;
use finfo;
use GdImage;
use RuntimeException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

use function Symfony\Component\String\u;

/**
 * Плейсхолдер-зображення 1200×900 (GD): кольоровий фон, назва товару й підпис. Зберігається так само, як із
 * медіатеки адмінки: Media + файл через Vich (prePersist переносить у public/media і пише path/mime/розмір),
 * тож мініатюри Liip працюють як для завантажених руками. Ім'я файлу починається з «demo-» — позначка для --purge.
 *
 * Шрифт — вбудований растровий GD, збільшений: TTF у образі нема, тому текст — латинська транслітерація.
 *
 * fromFile() — те саме для справжнього фото з паку (DemoPhotos): файл копіюється (Vich переносить, а пак лишається
 * недоторканим), contentHash = sha256 — ознака «це фото, а не плейсхолдер» для ідемпотентного повторного сіду.
 */
final class DemoImageFactory
{
    public const int WIDTH = 1200;
    public const int HEIGHT = 900;

    public function create(string $title, string $caption, string $background, string $fileBase, string $format = 'webp'): Media
    {
        if (!\function_exists('imagecreatetruecolor')) {
            throw new RuntimeException('Для демо-зображень потрібне розширення GD');
        }
        $image = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        [$r, $g, $b] = self::rgb($background);
        $bg = (int) imagecolorallocate($image, $r, $g, $b);
        $light = ($r * 299 + $g * 587 + $b * 114) / 1000 > 150;
        $ink = (int) ($light ? imagecolorallocate($image, 34, 34, 34) : imagecolorallocate($image, 250, 250, 250));
        imagefill($image, 0, 0, $bg);
        imagesetthickness($image, 6);
        imagerectangle($image, 40, 40, self::WIDTH - 41, self::HEIGHT - 41, $ink);

        $this->text($image, 'DEMO', 70, 70, 4, $ink, $bg);
        $lines = explode("\n", wordwrap(self::ascii($title), 26, "\n", true));
        $scale = 5;
        $lineHeight = imagefontheight(5) * $scale + 18;
        $y = (int) ((self::HEIGHT - \count($lines) * $lineHeight) / 2) - 40;
        foreach (\array_slice($lines, 0, 4) as $line) {
            $this->text($image, $line, null, $y, $scale, $ink, $bg);
            $y += $lineHeight;
        }
        $this->text($image, self::ascii($caption), null, self::HEIGHT - 170, 3, $ink, $bg);

        $path = tempnam(sys_get_temp_dir(), 'demo-img');
        if (false === $path) {
            throw new RuntimeException('Не вдалося створити тимчасовий файл');
        }
        $ok = 'jpg' === $format ? imagejpeg($image, $path, 82) : imagewebp($image, $path, 80);
        if (!$ok) {
            throw new RuntimeException(\sprintf('GD не зберіг зображення у форматі %s', $format));
        }
        $mime = 'jpg' === $format ? 'image/jpeg' : 'image/webp';

        // як MediaCrudController::createEntity: Vich на prePersist перепише path/mimeType/sizeBytes реальними значеннями
        $media = new Media('pending-'.bin2hex(random_bytes(6)), $mime, 0);
        $media->setFile(new UploadedFile($path, $fileBase.'.'.$format, $mime, null, true));
        $media->setAlt(mb_substr($title, 0, 255));

        return $media;
    }

    private const array MIME_EXTENSIONS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    /** Media зі справжнього файлу (JPEG/PNG/WebP). $fileBase — ім'я без розширення, починається з «demo-» (позначка для --purge). */
    public function fromFile(string $path, string $alt, string $fileBase): Media
    {
        $mime = (string) (new finfo(\FILEINFO_MIME_TYPE))->file($path);
        if (!isset(self::MIME_EXTENSIONS[$mime])) {
            throw new RuntimeException(\sprintf('%s: не JPEG/PNG/WebP (%s)', $path, $mime ?: '?'));
        }
        $tmp = tempnam(sys_get_temp_dir(), 'demo-photo');
        if (false === $tmp || !copy($path, $tmp)) {
            throw new RuntimeException(\sprintf('Не вдалося скопіювати %s', $path));
        }
        $media = new Media('pending-'.bin2hex(random_bytes(6)), $mime, 0);
        $media->setFile(new UploadedFile($tmp, $fileBase.'.'.self::MIME_EXTENSIONS[$mime], $mime, null, true));
        $media->setContentHash(DemoPhotos::hash($path));
        $media->setAlt(mb_substr($alt, 0, 255));

        return $media;
    }

    /** Рядок растровим шрифтом GD, збільшений у $scale разів (без згладжування — піксельний, але читабельний). */
    private function text(GdImage $image, string $text, ?int $x, int $y, int $scale, int $ink, int $bg): void
    {
        if ('' === $text) {
            return;
        }
        $font = 5;
        $w = imagefontwidth($font) * \strlen($text);
        $h = imagefontheight($font);
        $small = imagecreatetruecolor($w, $h);
        imagefill($small, 0, 0, $bg);
        imagestring($small, $font, 0, 0, $text, $ink);
        $x ??= (int) ((self::WIDTH - $w * $scale) / 2);
        imagecopyresized($image, $small, $x, $y, 0, 0, $w * $scale, $h * $scale, $w, $h);
    }

    private static function ascii(string $text): string
    {
        return u($text)->ascii()->replaceMatches('/[^\x20-\x7E]/', '')->collapseWhitespace()->toString();
    }

    /** @return array{int<0, 255>, int<0, 255>, int<0, 255>} */
    private static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        if (!preg_match('/^[0-9a-f]{6}$/i', $hex)) {
            return [120, 120, 120];
        }

        return [max(0, min(255, (int) hexdec(substr($hex, 0, 2)))), max(0, min(255, (int) hexdec(substr($hex, 2, 2)))), max(0, min(255, (int) hexdec(substr($hex, 4, 2))))];
    }
}
