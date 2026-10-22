<?php

declare(strict_types=1);

namespace App\Tests\Core\Shared\Text;

use App\Core\Shared\Text\UkrainianSlugger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\String\Slugger\AsciiSlugger;

/** Слаги без дефіса на мʼякому знаку й апострофі (seo-tech-notes п. 12): українська — КМУ № 55/2010, інші мови — без ь/ъ. */
final class UkrainianSluggerTest extends TestCase
{
    public function testOfficialUkrainianTransliteration(): void
    {
        $s = new UkrainianSlugger(new AsciiSlugger());
        foreach ([
            'Запальничка Zippo військової частини' => 'zapalnychka-zippo-viiskovoi-chastyny',
            'Патч/шеврон ДШВ ЗСУ мультикам' => 'patch-shevron-dshv-zsu-multykam',
            'Настільний герб «Сокіл 77»' => 'nastilnyi-herb-sokil-77',
            'Індивідуальне виробництво' => 'indyvidualne-vyrobnytstvo',
            'Мʼякий знак, пʼять, п\'ять' => 'miakyi-znak-piat-piat',
            // приклади з постанови КМУ № 55
            'Згорани Єнакієве Їжакевич Йосипівка Юрій Яготин Щербухи Гаєвич Короп\'є' => 'zghorany-yenakiieve-yizhakevych-yosypivka-yurii-yahotyn-shcherbukhy-haievych-koropie',
        ] as $text => $slug) {
            self::assertSame($slug, $s->slug($text, '-', 'uk')->lower()->toString(), $text);
        }
        self::assertSame('nastolnyi-podezd', $s->slug('Настольный подъезд', '-', 'ru')->lower()->toString(), 'ru — ICU, але без апострофа-дефіса');
        self::assertSame('sokil-teams-patch', $s->slug("Sokil Team's patch", '-', 'en')->lower()->toString(), 'апостроф без дефіса');
    }
}
