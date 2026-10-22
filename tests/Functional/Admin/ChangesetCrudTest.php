<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Core\Audit\AdminAuditContext;
use App\Core\Audit\AuditActor;
use App\Core\Audit\AuditSource;
use App\Core\Catalog\Entity\ProductVariant;
use App\Core\Changeset\ChangesetAuthor;
use App\Core\Changeset\ChangesetPlanner;
use App\Core\Changeset\Entity\Changeset;
use App\Core\Security\Entity\User;
use App\Core\Security\Enum\UserRole;
use App\Core\Shared\Money\Money;
use App\Tests\Functional\AdminApi\CatalogFixtures;
use DateTimeImmutable;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Сторінка «Пакети змін» (E15 PR-4, дизайн B pr4-ui.md §10): видимість, застосування з CSRF, чекбокс ризику (422 без нього),
 * конфлікт 409 з «хто/коли», повторний apply, прострочений, відхилення, відкат, лист про ціновий пакет, екранований diff.
 */
final class ChangesetCrudTest extends AdminWebTestCase
{
    use CatalogFixtures;

    /** @var array{doorsId: int, handlesId: int, productId: int, variantId: int, pageId: int, postId: int} */
    private array $ids;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client->disableReboot();
        $this->ids = self::seedCatalog($this->em());
    }

    /** @param list<array<string, mixed>> $operations */
    private function propose(User $author, array $operations, string $title = 'Тест'): Changeset
    {
        $changeset = static::getContainer()->get(ChangesetPlanner::class)->propose(['title' => $title, 'operations' => $operations], ChangesetAuthor::of($author, AuditSource::AiApi, 7));
        $this->transport()->reset();
        $this->em()->clear();
        $fresh = $this->em()->find(Changeset::class, $changeset->getId());
        self::assertNotNull($fresh);

        return $fresh;
    }

    /** @return array<string, mixed> */
    private function contentOp(): array
    {
        return ['op' => 'set_translation', 'entity' => 'product', 'id' => $this->ids['productId'], 'locale' => 'uk', 'fields' => ['short_description' => 'Вхідні <script>alert(1)</script> двері', 'slug' => 'dveri-ml-14-vhidni'], 'expect' => ['short_description' => 'Сталеві двері', 'slug' => 'dveri-ml-14']];
    }

    /** @return array<string, mixed> */
    private function priceOp(string $price): array
    {
        return ['op' => 'set_variant_price', 'variant' => 'ML-14', 'price' => $price, 'old_price' => null, 'expect' => ['price' => '4262.00', 'old_price' => null]];
    }

    private function detail(Changeset $changeset): Crawler
    {
        $crawler = $this->client->request('GET', $this->adminUrl('/changeset/'.$changeset->getId()));

        return $crawler;
    }

    /**
     * POST дії через форму на сторінці (CSRF з форми), як людина.
     *
     * @param array<string, string> $fields
     */
    private function submit(Crawler $crawler, string $action, array $fields = []): Crawler
    {
        $form = $crawler->filter(\sprintf('form[data-changeset-action="%s"]', $action))->last()->form();
        foreach ($fields as $name => $value) {
            $form[$name]->setValue($value);
        }

        return $this->client->submit($form);
    }

    private function transport(): InMemoryTransport
    {
        $transport = static::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    private function dbStatus(Changeset $changeset): string
    {
        return (string) $this->em()->getConnection()->fetchOne('SELECT status FROM admin_changeset WHERE id = ?', [$changeset->getId()]);
    }

    public function testManagerSeesOnlyOwnPackagesAndForeignDetailIs403(): void
    {
        $olena = $this->loginAs(UserRole::Manager, 'olena@example.test');
        $ihor = $this->createUser('ihor@example.test', UserRole::Manager);
        $mine = $this->propose($olena, [$this->contentOp()], 'Мій пакет');
        $foreign = $this->propose($ihor, [$this->priceOp('4100.00')], 'Чужий пакет');

        $crawler = $this->client->request('GET', $this->adminUrl('/changeset'));
        self::assertResponseIsSuccessful();
        $text = $crawler->filter('table.datagrid')->text();
        self::assertStringContainsString('Мій пакет', $text);
        self::assertStringNotContainsString('Чужий пакет', $text);
        self::assertStringContainsString('Запропоновано', $text);
        // усі nav сторінки: першим тепер іде перемикач мови «UA / EN»
        self::assertStringContainsString('Пакети змін', implode(' ', $crawler->filter('#main-menu, .main-menu, nav')->each(static fn ($n): string => $n->text())));

        $this->detail($foreign);
        self::assertResponseStatusCodeSame(403);
        $this->detail($mine);
        self::assertResponseIsSuccessful();

        // адміністратор бачить усе
        $this->client->loginUser($this->createUser('admin@example.test', UserRole::Admin), 'admin');
        $text = $this->client->request('GET', $this->adminUrl('/changeset'))->filter('table.datagrid')->text();
        self::assertStringContainsString('Чужий пакет', $text);
        $this->detail($foreign);
        self::assertResponseIsSuccessful();
    }

    public function testApplyWithoutRiskThroughFormAndRepeatIsRefused(): void
    {
        $olena = $this->loginAs(UserRole::Manager, 'olena@example.test');
        $changeset = $this->propose($olena, [$this->contentOp()]);
        $crawler = $this->detail($changeset);
        self::assertResponseIsSuccessful();
        // diff: екрановано, slug з підказкою про 301, посилання на форму товару
        $html = (string) $crawler->filter('#diff')->html();
        self::assertStringNotContainsString('<script>alert(1)</script>', $html, 'HTML від AI не виконується');
        self::assertStringContainsString('буде створено 301', $html);
        self::assertStringContainsString('/product/'.$this->ids['productId'].'/edit', $html);
        self::assertCount(0, $crawler->filter('#risk-confirm'), 'без ризиків — без чекбокса');

        $applyToken = (string) $crawler->filter('form[data-changeset-action="apply"] input[name="token"]')->attr('value');
        $rejectToken = (string) $crawler->filter('form[data-changeset-action="reject"] input[name="token"]')->attr('value');
        $this->submit($crawler, 'apply');
        self::assertResponseStatusCodeSame(303);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Застосовано 1 операцію', $crawler->filter('.alert-success')->text());
        self::assertStringContainsString('filters%5BchangesetId%5D', (string) $crawler->filter('.alert-success a')->attr('href'));
        self::assertSame('applied', $this->dbStatus($changeset));
        self::assertSame('dveri-ml-14-vhidni', $this->em()->getConnection()->fetchOne('SELECT slug FROM catalog_product_translation WHERE product_id = ?', [$this->ids['productId']]));
        self::assertSame(7, (int) $this->em()->getConnection()->fetchOne('SELECT token_id FROM admin_audit_entry WHERE changeset_id = ? LIMIT 1', [$changeset->getId()]), 'token_id — з пакета (M2)');
        self::assertCount(0, $crawler->filter('form[data-changeset-action="apply"]'), 'кнопок застосування вже нема');
        self::assertCount(1, $crawler->filter('form[data-changeset-action="revert"]'));
        self::assertStringNotContainsString('Лист надіслано', $crawler->filter('.alert-success')->text(), 'контентний пакет — без листа');

        // повторний POST (кнопка «назад», друга вкладка) з тим самим CSRF — 303 і flash «уже застосовано», не 403 (рев'ю C #77 M2)
        $this->client->request('POST', $this->adminUrl('/changeset/'.$changeset->getId().'/apply'), ['token' => $applyToken]);
        self::assertResponseStatusCodeSame(303);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Пакет уже застосовано: Olena', $crawler->filter('.alert-warning')->text());
        self::assertSame('applied', $this->dbStatus($changeset));
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne("SELECT COUNT(*) FROM admin_audit_entry WHERE action = 'apply' AND changeset_id = ?", [$changeset->getId()]), 'вдруге не застосовано');
        // те саме для «Відхилити» застосованого
        $this->client->request('POST', $this->adminUrl('/changeset/'.$changeset->getId().'/reject'), ['token' => $rejectToken]);
        self::assertResponseStatusCodeSame(303);
        self::assertStringContainsString('Пакет уже застосовано', $this->client->followRedirect()->filter('.alert-warning')->text());

        $this->client->request('POST', $this->adminUrl('/changeset/'.$changeset->getId().'/apply'), ['token' => (string) $crawler->filter('form[data-changeset-action="revert"] input[name="token"]')->attr('value')]);
        self::assertResponseStatusCodeSame(403, 'чужий CSRF-токен (від іншої дії) не проходить');
    }

    public function testRiskyPackageNeedsCheckboxAndSendsPriceMail(): void
    {
        $olena = $this->loginAs(UserRole::Manager, 'olena@example.test');
        $changeset = $this->propose($olena, [$this->priceOp('2000.00')]);
        $crawler = $this->detail($changeset);
        self::assertStringContainsString('Стрибок ціни понад 30 %', $crawler->filter('#risks')->text());
        self::assertCount(1, $crawler->filter('#risk-confirm[required]'));
        self::assertStringContainsString('−53 %', $crawler->filter('#diff')->text());

        $crawler = $this->submit($crawler, 'apply');
        self::assertResponseStatusCodeSame(422);
        self::assertCount(1, $crawler->filter('#risk-confirm.is-invalid'));
        self::assertSame('proposed', $this->dbStatus($changeset));

        $this->submit($crawler, 'apply', ['risk_confirmed' => '1']);
        self::assertResponseStatusCodeSame(303);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Лист надіслано відповідальним за ціни', $crawler->filter('.alert-success')->text());
        self::assertStringContainsString('підтвердив(ла) ризики: Olena', $crawler->filter('#changeset-'.$changeset->getId())->text());
        self::assertSame('applied', $this->dbStatus($changeset));
        $row = $this->em()->getConnection()->fetchAssociative('SELECT risk_confirmed_by_name FROM admin_changeset WHERE id = ?', [$changeset->getId()]);
        self::assertSame('Olena', $row['risk_confirmed_by_name'] ?? null);
    }

    public function testConflictRendersBlameWith409AndKeepsProposed(): void
    {
        $olena = $this->loginAs(UserRole::Manager, 'olena@example.test');
        $changeset = $this->propose($olena, [$this->priceOp('4100.00')]);
        // хтось змінив ціну в адмінці після створення пакета
        static::getContainer()->get(AdminAuditContext::class)->runAs(new AuditActor(AuditSource::AdminUi, 99, 'Петро'), function (): void {
            $variant = $this->em()->find(ProductVariant::class, $this->ids['variantId']);
            self::assertNotNull($variant);
            $variant->setPrice(Money::of(430_000, 'UAH'));
            $this->em()->flush();
        });
        $crawler = $this->submit($this->detail($changeset), 'apply');
        self::assertResponseStatusCodeSame(409);
        $conflicts = $crawler->filter('#conflicts');
        self::assertStringContainsString('Петро', $conflicts->text());
        self::assertStringContainsString('4 300,00', $conflicts->text());
        self::assertCount(0, $crawler->filter('form[data-changeset-action="apply"]'), 'застосувати вже не можна');
        self::assertSame('proposed', $this->dbStatus($changeset));
    }

    public function testExpiredRejectAndCsrf(): void
    {
        $olena = $this->loginAs(UserRole::Manager, 'olena@example.test');
        $expired = $this->propose($olena, [$this->priceOp('4100.00')], 'Старий');
        $applyToken = (string) $this->detail($expired)->filter('form[data-changeset-action="apply"] input[name="token"]')->attr('value');
        $this->em()->getConnection()->executeStatement('UPDATE admin_changeset SET expires_at = ? WHERE id = ?', [(new DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s'), $expired->getId()]);
        $this->em()->clear();
        $crawler = $this->detail($expired);
        self::assertStringContainsString('Прострочено', $crawler->filter('#changeset-'.$expired->getId())->text());
        self::assertCount(0, $crawler->filter('form[data-changeset-action]'));
        // POST на прострочений (стара вкладка з валідним CSRF) — flash, не 403 (рев'ю C #77 M2)
        $this->client->request('POST', $this->adminUrl('/changeset/'.$expired->getId().'/apply'), ['token' => $applyToken]);
        self::assertResponseStatusCodeSame(303);
        self::assertStringContainsString('Пакет прострочено', $this->client->followRedirect()->filter('.alert-warning')->text());
        self::assertSame('expired', $this->dbStatus($expired));
        self::assertStringContainsString('Прострочено', $this->client->request('GET', $this->adminUrl('/changeset'))->filter('table.datagrid')->text());

        $changeset = $this->propose($olena, [$this->priceOp('4100.00')]);
        $this->client->request('POST', $this->adminUrl('/changeset/'.$changeset->getId().'/reject'), ['token' => 'bad']);
        self::assertResponseStatusCodeSame(403);
        $this->submit($this->detail($changeset), 'reject');
        self::assertResponseStatusCodeSame(303);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Пакет відхилено', $crawler->filter('.alert-success')->text());
        self::assertSame('rejected', $this->dbStatus($changeset));
        self::assertCount(0, $crawler->filter('form[data-changeset-action]'));
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne("SELECT COUNT(*) FROM admin_audit_entry WHERE action = 'reject' AND changeset_id = ?", [$changeset->getId()]));
        self::assertSame(426_200, (int) $this->em()->getConnection()->fetchOne('SELECT price_minor FROM catalog_product_variant WHERE id = ?', [$this->ids['variantId']]));
    }

    public function testRevertCreatesProposalAndOriginalShowsRevertedBy(): void
    {
        $olena = $this->loginAs(UserRole::Manager, 'olena@example.test');
        $changeset = $this->propose($olena, [$this->priceOp('4100.00')]);
        $this->submit($this->detail($changeset), 'apply');
        $crawler = $this->client->followRedirect();
        $crawler = $this->submit($crawler, 'revert');
        self::assertResponseStatusCodeSame(303);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Створено пакет відкату', $crawler->filter('.alert-success')->text());
        self::assertStringContainsString('Відкат #'.$changeset->getId(), $crawler->filter('h1')->text());
        self::assertStringContainsString('Відкат пакета #'.$changeset->getId(), $crawler->filter('section')->text());
        self::assertStringContainsString('4 262,00', $crawler->filter('#diff')->text());

        $this->submit($crawler, 'apply');
        $this->client->followRedirect();
        self::assertSame(426_200, (int) $this->em()->getConnection()->fetchOne('SELECT price_minor FROM catalog_product_variant WHERE id = ?', [$this->ids['variantId']]));
        $crawler = $this->detail($changeset);
        self::assertStringContainsString('Відкочено пакетом #', $crawler->filter('section')->text());
        self::assertCount(0, $crawler->filter('form[data-changeset-action="revert"]'), 'двічі відкотити не можна');
    }
}
