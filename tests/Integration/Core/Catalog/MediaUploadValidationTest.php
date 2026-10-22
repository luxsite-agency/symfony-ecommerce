<?php

declare(strict_types=1);

namespace App\Tests\Integration\Core\Catalog;

use App\Core\Catalog\Entity\Media;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/** Консиліум безпеки 10.10, п. 7: у медіатеку лише растрові зображення за вмістом І розширенням; svg, pdf і html-з-svg відкидаються. */
final class MediaUploadValidationTest extends KernelTestCase
{
    public function testOnlyRasterImagesWithMatchingExtensionPass(): void
    {
        self::bootKernel();
        $validator = self::getContainer()->get(ValidatorInterface::class);
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', true);
        self::assertIsString($png);
        $jpegImage = imagecreatetruecolor(1, 1);
        self::assertNotFalse($jpegImage);
        ob_start();
        imagejpeg($jpegImage);
        $jpeg = (string) ob_get_clean();
        $svgScript = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(document.cookie)</script></svg>';
        $cases = [
            ['logo.png', $png, 0, 'png з правильним розширенням'],
            ['photo.JPG', $jpeg, 0, 'jpeg з розширенням у верхньому регістрі'],
            ['logo.html', $png, 1, 'png під розширенням html'],
            ['x.html', $svgScript, 1, 'html з svg і скриптом (finfo: image/svg+xml, nginx: text/html)'],
            ['x.svg', $svgScript, 1, 'svg зі скриптом'],
            ['price.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF", 1, 'pdf'],
            ['logo.jpg', $png, 1, 'розширення не збігається з вмістом'],
        ];
        foreach ($cases as [$name, $content, $expected, $label]) {
            $tmp = tempnam(sys_get_temp_dir(), 'media');
            self::assertNotFalse($tmp);
            file_put_contents($tmp, $content);
            $media = new Media('pending', 'image/png', \strlen($content));
            $media->setFile(new UploadedFile($tmp, $name, null, null, true));
            $violations = $validator->validate($media);
            $messages = [];
            foreach ($violations as $violation) {
                if ('file' === $violation->getPropertyPath()) {
                    $messages[] = (string) $violation->getMessage();
                }
            }
            if (0 === $expected) {
                self::assertSame([], $messages, $label);
            } else {
                self::assertNotSame([], $messages, $label.': мало бути відхилено');
            }
            @unlink($tmp);
        }
    }
}
