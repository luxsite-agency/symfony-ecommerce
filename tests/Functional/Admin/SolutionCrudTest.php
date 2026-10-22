<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Core\Catalog\Entity\Product;
use App\Core\Content\Entity\Solution;
use App\Core\Content\Entity\SolutionGroup;
use App\Core\Security\Enum\UserRole;
use App\Tests\Integration\Core\Catalog\FilterIndex\CatalogIndexFixture;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Intl\Languages;

/**
 * Рішення в адмінці: менеджер створює групу й рішення з варіантом-товаром через форму, слаг формується з назви, «Переглянути
 * на сайті» — після публікації; пошук товару для варіанта (формат EasyAdmin); англійська адмінка — без кирилиці в підписах.
 */
final class SolutionCrudTest extends AdminWebTestCase
{
    public function testManagerCreatesSolutionWithOption(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $fx = new CatalogIndexFixture($this->em(), $suffix);
        $product = array_values($fx->p)[0];
        $product->translate('uk')->setName('Стелаж '.$suffix);
        $this->em()->flush();

        $this->loginAs(UserRole::Manager);
        foreach (['/solution', '/solution/new', '/solution-group', '/solution-group/new'] as $path) {
            $this->client->request('GET', $this->adminUrl($path));
            self::assertResponseIsSuccessful($path);
        }

        $crawler = $this->client->request('GET', $this->adminUrl('/solution-group/new'));
        self::assertFormRedirected($this->submit($crawler, 'SolutionGroup', ['SolutionGroup[translationsForm][uk][title]' => 'Зберігання '.$suffix]));
        $group = $this->em()->getRepository(SolutionGroup::class)->findOneBy([], ['id' => 'DESC']);
        self::assertNotNull($group);

        $crawler = $this->client->request('GET', $this->adminUrl('/solution/new'));
        $crawler = $this->submit($crawler, 'Solution', [
            'Solution[position]' => '3',
            'Solution[group]' => (string) $group->getId(),
            'Solution[translationsForm][uk][title]' => 'Палетне зберігання '.$suffix,
            'Solution[translationsForm][uk][loadsText]' => "EUR-палети\nFIN-палети",
            'Solution[translationsForm][uk][faqText]' => "Скільки?\nПісля розрахунку",
        ], extra: ['options' => [['product' => ['autocomplete' => (string) $product->getId()], 'position' => '0', 'translationsForm' => ['uk' => ['prosText' => "Доступ до палети\nДо 6000 кг", 'note' => 'Абзац']]]]]);
        self::assertFormRedirected($crawler);

        $this->em()->clear();
        $solution = $this->em()->getRepository(Solution::class)->findOneBy(['position' => 3], ['id' => 'DESC']);
        self::assertNotNull($solution);
        self::assertFalse($solution->isPublished());
        self::assertSame($group->getId(), $solution->getGroup()?->getId());
        $translation = $solution->translate('uk');
        self::assertMatchesRegularExpression('/^paletne-zberihannia/', (string) $translation->getSlug(), 'слаг з назви (SlugListener)');
        self::assertSame(['EUR-палети', 'FIN-палети'], $translation->getLoads());
        self::assertSame([['q' => 'Скільки?', 'a' => 'Після розрахунку']], $translation->getFaq());
        $options = $solution->getSortedOptions();
        self::assertCount(1, $options);
        self::assertSame($product->getId(), $options[0]->getProduct()?->getId());
        self::assertSame(['Доступ до палети', 'До 6000 кг'], $options[0]->translate('uk')->getPros());
        self::assertSame(['uk'], $solution->getTranslatedLocales(), 'порожні переклади не збережено');

        $crawler = $this->client->request('GET', $this->adminUrl(\sprintf('/solution/%d/edit', $solution->getId())));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Стелаж '.$suffix, $crawler->filter('select[name="Solution[options][0][product][autocomplete]"] option[selected]')->text(), 'у select лише обраний товар');
        self::assertFormRedirected($this->submit($crawler, 'Solution', ['Solution[published]' => '1'], edit: true));
        $crawler = $this->client->request('GET', $this->adminUrl(\sprintf('/solution/%d/edit', $solution->getId())));
        self::assertStringEndsWith('/solutions/'.$translation->getSlug().'/', (string) $crawler->filter('a[target="_blank"][href*="/solutions/"]')->attr('href'));

        $this->client->request('GET', $this->adminUrl('/solution-products?page=1&query='.urlencode('Стелаж '.$suffix)));
        self::assertResponseIsSuccessful();
        $json = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame([['entityId' => $product->getId(), 'entityAsString' => 'Стелаж '.$suffix.' · '.$product->getVariants()->first()->getSku()]], $json['results']);
        self::assertNull($json['next_page']);

        // неактивний товар — з позначкою (рев'ю #102, L4)
        $fresh = $this->em()->find(Product::class, $product->getId()); // клієнт перезавантажив ядро — інший EntityManager
        self::assertNotNull($fresh);
        $fresh->setActive(false);
        $this->em()->flush();
        $this->client->request('GET', $this->adminUrl('/solution-products?page=1&query='.urlencode('Стелаж '.$suffix)));
        $json = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertStringEndsWith(' · неактивний', $json['results'][0]['entityAsString']);
    }

    public function testEnglishAdminHasNoCyrillicInSolutionScreens(): void
    {
        $this->loginAs(UserRole::Manager);
        // назви мов у формі перекладів — самоназви (TranslationsType: «Українська»), як в усіх CRUD
        $endonyms = array_map(static fn (string $l): string => ucfirst(Languages::getName($l, $l)), (array) static::getContainer()->getParameter('app.locales'));
        $this->client->request('GET', $this->adminUrl('/?_locale=en'));
        foreach (['/solution', '/solution/new', '/solution-group/new'] as $path) {
            $crawler = $this->client->request('GET', $this->adminUrl($path));
            self::assertResponseIsSuccessful($path);
            $labels = $crawler->filter('label, legend, .form-help, h1, .content-header-title')->each(static fn (Crawler $n): string => $n->text());
            foreach (array_diff($labels, $endonyms) as $label) {
                self::assertDoesNotMatchRegularExpression('/\p{Cyrillic}/u', $label, $path.': '.$label);
            }
        }
        $this->client->request('GET', $this->adminUrl('/?_locale=uk'));
    }

    /**
     * @param array<string, string> $values
     * @param array<string, mixed>  $extra  поля поза HTML-формою (нові елементи колекції)
     */
    private function submit(Crawler $crawler, string $formName, array $values, bool $edit = false, array $extra = []): Crawler
    {
        $form = $crawler->filter(\sprintf('form[name="%s"]', $formName))->form();
        $form->setValues($values);
        $payload = $form->getPhpValues();
        $payload[$formName] = array_replace_recursive($payload[$formName] ?? [], $extra);
        $payload['ea'][$edit ? 'editForm' : 'newForm']['btn'] = 'saveAndReturn';

        return $this->client->request($form->getMethod(), $form->getUri(), $payload, $form->getPhpFiles());
    }
}
