<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Functional\Site;

use App\Core\Order\Entity\Order;
use App\Core\Order\Enum\OrderSource;
use App\Core\Order\Message\PushOrderToCrm;
use App\Core\Order\Message\SendCustomerOrderEmail;
use App\Core\Order\Message\SendShopOrderEmail;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Twig\Environment;

/**
 * Заявка без товару /request: partial із контекстом сторінки «Рішення», захист (CSRF, honeypot), валідація, вкладення
 * лише дозволених типів у приватному сховищі, Order source=lead → CRM і лист магазину.
 */
final class LeadRequestTest extends WebTestCase
{
    public function testPartialContract(): void
    {
        $client = static::createClient();
        /** @var string $host */
        $host = static::getContainer()->getParameter('site_host');
        $request = Request::create('https://'.$host.'/solutions/pallet/');
        $request->setLocale('uk');
        static::getContainer()->get(RequestStack::class)->push($request);
        $html = static::getContainer()->get(Environment::class)->render('site/partials/_lead_form.html.twig', [
            'context' => 'Рішення: Палетне зберігання',
            'choices' => ['Фронтальні стелажі', 'Набивні стелажі'],
            'source_url' => 'https://'.$host.'/solutions/pallet/',
        ]);
        self::assertStringContainsString('action="/request"', $html);
        self::assertStringContainsString('enctype="multipart/form-data"', $html);
        self::assertStringContainsString('name="context" value="Рішення: Палетне зберігання"', $html);
        self::assertStringContainsString('name="source_url" value="https://'.$host.'/solutions/pallet/"', $html);
        self::assertStringContainsString('<option value="Набивні стелажі">', $html);
        self::assertStringContainsString('Не знаю, порадьте</option>', $html);
        self::assertStringContainsString('name="website"', $html, 'honeypot');
        self::assertStringContainsString('name="attachment"', $html);
        self::assertStringNotContainsString('site.lead.', $html, 'усі ключі перекладено');
        self::assertStringNotContainsString('flash-error', $html);

        // flash контролера partial показує сам (base.html.twig їх не виводить)
        $session = new \Symfony\Component\HttpFoundation\Session\Session(new \Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage());
        $session->getFlashBag()->add('error', 'Вкажіть ім\'я');
        $request->setSession($session);
        $html = static::getContainer()->get(Environment::class)->render('site/partials/_lead_form.html.twig');
        self::assertStringContainsString('<p class="flash flash-error" role="alert">Вкажіть ім&#039;я</p>', $html);

        $plain = static::getContainer()->get(Environment::class)->render('site/partials/_lead_form.html.twig');
        self::assertStringNotContainsString('name="choice"', $plain, 'без choices селекта нема');
        self::assertStringNotContainsString('name="context"', $plain);
        self::assertStringNotContainsString('name="source_url"', $plain);
    }

    public function testSubmitWithAttachmentCreatesLead(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        /** @var string $host */
        $host = static::getContainer()->getParameter('site_host');
        $url = 'https://'.$host.'/request';
        $suffix = bin2hex(random_bytes(3));
        $phone = '067 '.substr((string) hexdec($suffix), 0, 7);
        $fields = ['_token' => 'csrf-token', 'locale' => 'uk', 'name' => 'Олег', 'phone' => $phone, 'email' => 'Oleg@Example.Test',
            'company' => 'ТОВ «Склад»', 'comment' => 'потрібні стелажі на 200 палет', 'context' => 'Рішення: Палетне зберігання',
            'choice' => 'Не знаю, порадьте', 'source_url' => 'https://'.$host.'/solutions/pallet/'];

        // stateless CSRF: чужий Origin — 403, нічого не створено
        $client->request('POST', $url, $fields, server: ['HTTP_ORIGIN' => 'https://evil.example']);
        self::assertResponseStatusCodeSame(403);

        // honeypot: бот заповнив «website» — редирект з «успіхом», замовлення нема
        $client->request('POST', $url, $fields + ['website' => 'http://spam.example'], server: ['HTTP_REFERER' => 'https://'.$host.'/solutions/pallet/']);
        self::assertResponseRedirects('https://'.$host.'/solutions/pallet/');
        self::assertCount(1, $this->flashes($client, 'success'), 'бот бачить «успіх»');
        self::assertNull($em->getRepository(Order::class)->findOneBy(['company' => 'ТОВ «Склад»']));

        // валідація: без імені й без тексту — помилки перекладом, замовлення нема
        $client->request('POST', $url, ['_token' => 'csrf-token', 'locale' => 'uk', 'name' => '', 'phone' => '12', 'comment' => ''], server: ['HTTP_REFERER' => 'https://'.$host.'/about']);
        self::assertResponseRedirects('https://'.$host.'/about');
        // flash показує сам partial на сторінці повернення (base.html.twig flash-ів не виводить) — перевіряємо через сесію
        $errors = $this->flashes($client, 'error');
        self::assertContains("Вкажіть ім'я", $errors);
        self::assertContains('Вкажіть коректний номер телефону', $errors);
        self::assertContains('Опишіть задачу або прикладіть файл', $errors);

        // чужий вміст під назвою .pdf — відмова за вмістом
        $bad = tempnam(sys_get_temp_dir(), 'bad');
        file_put_contents((string) $bad, "MZ\x90\x00 not a pdf");
        $client->request('POST', $url, $fields, ['attachment' => new UploadedFile((string) $bad, 'plan.pdf', 'application/pdf', null, true)], ['HTTP_REFERER' => 'https://'.$host.'/about']);
        self::assertStringContainsString('Такий тип файлу не приймаємо', implode(' ', $this->flashes($client, 'error')));
        self::assertNull($em->getRepository(Order::class)->findOneBy(['company' => 'ТОВ «Склад»']));

        // успіх: PDF-вкладення, лід у CRM і лист магазину, файл поза public/
        $pdf = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents((string) $pdf, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF\n");
        $client->request('POST', $url, $fields, ['attachment' => new UploadedFile((string) $pdf, 'план складу.pdf', 'application/octet-stream', null, true)], ['HTTP_REFERER' => 'https://'.$host.'/solutions/pallet/']);
        self::assertResponseRedirects('https://'.$host.'/solutions/pallet/');
        $transport = static::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $sent = array_map(static fn ($e): string => $e->getMessage()::class, $transport->getSent());
        self::assertContains(PushOrderToCrm::class, $sent, 'лід — у CRM');
        self::assertContains(SendShopOrderEmail::class, $sent);
        self::assertNotContains(SendCustomerOrderEmail::class, $sent);
        self::assertContains('Заявку надіслано. Менеджер зв\'яжеться з вами найближчим часом.', $this->flashes($client, 'success'));

        $em->clear();
        $order = $em->getRepository(Order::class)->findOneBy(['company' => 'ТОВ «Склад»', 'source' => OrderSource::Lead]);
        self::assertNotNull($order);
        self::assertSame('oleg@example.test', $order->getEmail());
        self::assertSame(0, $order->getTotal()->amount);
        self::assertCount(0, $order->getItems());
        self::assertSame("Заявка: потрібні стелажі на 200 палет\nКонтекст: Рішення: Палетне зберігання\nВаріант: Не знаю, порадьте\nСторінка: https://".$host.'/solutions/pallet/', $order->getComment());
        $attachment = $order->getAttachments()->first();
        self::assertNotFalse($attachment);
        self::assertSame('план складу.pdf', $attachment->getOriginalName());
        self::assertSame('application/pdf', $attachment->getMime());
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $attachment->getStoredName());
        /** @var string $dir */
        $dir = static::getContainer()->getParameter('app.order_attachment_dir');
        // шлях — з того самого uploadedAt, що в метаданих (рев'ю C L2), не з «зараз»
        $stored = $dir.'/'.$attachment->getUploadedAt()->format('Y/m').'/'.$attachment->getStoredName();
        self::assertSame($stored, static::getContainer()->get(\App\Core\Order\Attachment\AttachmentStorage::class)->path($attachment));
        self::assertFileExists($stored);
        self::assertStringStartsWith('%PDF-', (string) file_get_contents($stored));
        self::assertStringNotContainsString('/public/', $stored);
        self::assertSame('0640', substr(\sprintf('%o', fileperms($stored)), -4));
        $history = $order->getHistory()->first();
        self::assertNotFalse($history);
        self::assertSame('lead', $history->getComment());

        // чужий source_url — не потрапляє в коментар
        $client->request('POST', $url, ['source_url' => 'https://evil.example/x', 'comment' => 'x', 'company' => 'Чужий URL'] + $fields, server: ['HTTP_REFERER' => 'https://'.$host.'/about']);
        $em->clear();
        $order = $em->getRepository(Order::class)->findOneBy(['company' => 'Чужий URL']);
        self::assertNotNull($order);
        self::assertStringNotContainsString('evil.example', (string) $order->getComment());
    }

    /** @return list<string> */
    private function flashes(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client, string $type): array
    {
        $session = $client->getRequest()->getSession();
        self::assertInstanceOf(\Symfony\Component\HttpFoundation\Session\Session::class, $session);

        // peek, не get: сесія вже збережена після відповіді, тож зняті тут flash-і однаково повернуться в наступному запиті
        return array_values(array_map(strval(...), $session->getFlashBag()->peek($type)));
    }
}
