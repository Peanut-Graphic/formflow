<?php
/**
 * Regression guards for two diagnostic-accuracy fixes:
 *  - health_check latency tiers: the >10s "slow" tier was dead code behind the
 *    >5s "degraded" tier, so very-high latency was mislabelled 'degraded'.
 *  - memory_limit -1 (unlimited) was reported as "may be too low".
 */

namespace ISF\Tests\Regression;

use PHPUnit\Framework\TestCase;
use ISF\Api\ApiClient;

final class DiagnosticAccuracyTest extends TestCase
{
    /**
     * @dataProvider latencies
     */
    public function test_latency_classification(int $ms, string $expected): void
    {
        $this->assertSame($expected, ApiClient::classify_latency($ms)['status']);
    }

    public static function latencies(): array
    {
        return [
            'fast'                => [200, 'healthy'],
            'at 5s boundary'      => [5000, 'healthy'],
            'degraded'            => [6000, 'degraded'],
            'at 10s boundary'     => [10000, 'degraded'],
            'slow tier reachable' => [12000, 'slow'],
        ];
    }

    public function test_unlimited_memory_limit_is_sufficient(): void
    {
        // memory_limit '-1' means unlimited; convert_to_bytes returns -1, and
        // the sufficiency check must accept it rather than warn.
        $diag = (new \ReflectionClass(\ISF\Diagnostics::class))->newInstanceWithoutConstructor();

        $sufficient = new \ReflectionMethod($diag, 'memory_limit_is_sufficient');
        $sufficient->setAccessible(true);

        $this->assertTrue($sufficient->invoke($diag, -1), 'unlimited (-1) must be sufficient');
        $this->assertTrue($sufficient->invoke($diag, 128 * 1024 * 1024), '128M is sufficient');
        $this->assertFalse($sufficient->invoke($diag, 32 * 1024 * 1024), '32M is too low');
    }
}
