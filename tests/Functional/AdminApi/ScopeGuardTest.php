<?php

declare(strict_types=1);

namespace App\Tests\Functional\AdminApi;

use App\Core\AdminApi\Mcp\McpTools;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Страж (рев'ю C #73 L5): access_control пускає на /api/admin будь-який чинний токен менеджера, скоупи захищають лише те,
 * що позначено #[IsGranted('scope:…')]. Кожен маршрут /api/admin/v1/* мусить нести такий атрибут на дії або класі,
 * крім явного білого списку. Новий ресурс без скоупу валить цей тест, а не відкривається всім токенам.
 */
final class ScopeGuardTest extends KernelTestCase
{
    /** Маршрути без скоупу — свідомо: /me лише каже, хто ти */
    private const array WHITELIST = ['admin_api_me'];

    public function testEveryAdminApiRouteRequiresScope(): void
    {
        self::bootKernel();
        $router = self::getContainer()->get(RouterInterface::class);
        $checked = 0;
        foreach ($router->getRouteCollection() as $name => $route) {
            if (!str_starts_with($route->getPath(), '/api/admin/v1') || \in_array($name, self::WHITELIST, true)) {
                continue;
            }
            self::assertNotSame('', $route->getHost(), $name.': маршрут /api/admin мусить бути прив’язаний до admin_host (рев\'ю C #77 L2)');
            $controller = (string) $route->getDefault('_controller');
            [$class, $method] = str_contains($controller, '::') ? explode('::', $controller, 2) : [$controller, '__invoke'];
            self::assertTrue(class_exists($class), $name.': контролер '.$class);
            $attributes = [...(new ReflectionMethod($class, $method))->getAttributes(IsGranted::class), ...(new ReflectionClass($class))->getAttributes(IsGranted::class)];
            $scoped = false;
            foreach ($attributes as $attribute) {
                /** @var IsGranted $granted */
                $granted = $attribute->newInstance();
                $value = $granted->attribute instanceof Expression ? (string) $granted->attribute : (\is_string($granted->attribute) ? $granted->attribute : '');
                $scoped = $scoped || str_contains($value, 'scope:');
            }
            self::assertTrue($scoped, \sprintf('%s (%s %s) без #[IsGranted(\'scope:…\')] — ресурс відкрився б будь-якому токену', $name, implode('|', $route->getMethods()), $route->getPath()));
            ++$checked;
        }
        self::assertGreaterThanOrEqual(11, $checked, 'маршрути PR-4 на місці');
    }

    /** Те саме для MCP (PR-5): кожен інструмент у tools/list має запис у McpTools::SCOPES, крім білого списку NO_SCOPE (me). */
    public function testEveryMcpToolDeclaresScope(): void
    {
        self::bootKernel();
        $tools = self::getContainer()->get(McpTools::class);
        $names = [];
        foreach ($tools->definitions() as $tool) {
            $name = $tool['name'];
            self::assertIsString($name);
            $names[] = $name;
            if (\in_array($name, McpTools::NO_SCOPE, true)) {
                continue;
            }
            self::assertArrayHasKey($name, McpTools::SCOPES, \sprintf('інструмент %s без скоупу відкрився б будь-якому токену', $name));
        }
        self::assertSame([], array_diff(array_keys(McpTools::SCOPES), $names), 'скоуп без інструмента — мертвий запис');
        self::assertNotContains('apply_changeset', $names);
    }
}
