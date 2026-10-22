<?php

declare(strict_types=1);

namespace App\Tests\Core\Order;

use App\Core\Order\OrderSubmitGuard;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/** Ліміти оформлення: окремо з IP і на телефон (рев'ю PR #6 п. 7); у застосунку цифри — параметри app.order_rate_limit_*. */
final class OrderSubmitGuardTest extends TestCase
{
    public function testPhoneLimitTriggersBeforeIpLimit(): void
    {
        $ip = new RateLimiterFactory(['id' => 'ip', 'policy' => 'sliding_window', 'limit' => 10, 'interval' => '10 minutes'], new InMemoryStorage());
        $phone = new RateLimiterFactory(['id' => 'phone', 'policy' => 'sliding_window', 'limit' => 2, 'interval' => '10 minutes'], new InMemoryStorage());
        $guard = new OrderSubmitGuard($ip, $phone);
        $request = Request::create('/checkout/', 'POST', server: ['REMOTE_ADDR' => '10.0.0.7']);

        $guard->check($request, '+380 67 123 45 67');
        $guard->check($request, '067-123-45-67'); // той самий номер у іншому записі — той самий ключ
        $guard->check($request, '+380501112233'); // інший номер — свій ліміт

        $this->expectException(TooManyRequestsHttpException::class);
        $guard->check($request, '0671234567');
    }

    public function testIpLimit(): void
    {
        $ip = new RateLimiterFactory(['id' => 'ip', 'policy' => 'sliding_window', 'limit' => 1, 'interval' => '10 minutes'], new InMemoryStorage());
        $phone = new RateLimiterFactory(['id' => 'phone', 'policy' => 'sliding_window', 'limit' => 100, 'interval' => '10 minutes'], new InMemoryStorage());
        $guard = new OrderSubmitGuard($ip, $phone);
        $request = Request::create('/quick-order', 'POST', server: ['REMOTE_ADDR' => '10.0.0.8']);
        $guard->check($request, '0501112233');
        $this->expectException(TooManyRequestsHttpException::class);
        $guard->check($request, '0509998877');
    }
}
