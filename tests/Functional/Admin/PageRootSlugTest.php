<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Core\Security\Enum\UserRole;

/** Сторінки в корені сайту (типово): форма сторінки в адмінці не приймає службовий слаг — помилка біля поля, мовою адмінки. */
final class PageRootSlugTest extends AdminWebTestCase
{
    public function testReservedSlugIsRejectedInForm(): void
    {
        $this->loginAs(UserRole::Manager);
        $crawler = $this->client->request('GET', $this->adminUrl('/page/new'));
        $form = $crawler->filter('form[name="Page"]')->form();
        $form->setValues(['Page[translationsForm][uk][title]' => 'Кошик', 'Page[translationsForm][uk][slug]' => 'cart']);
        $payload = $form->getPhpValues();
        $payload['ea']['newForm']['btn'] = 'saveAndReturn';
        $crawler = $this->client->request($form->getMethod(), $form->getUri(), $payload);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('«cart»', $crawler->filter('.invalid-feedback, .form-error-message')->text());
    }
}
