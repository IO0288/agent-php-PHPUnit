<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ReportPortalConnectivityTest extends TestCase
{
    public function testPassingAssertionIsReported(): void
    {
        $this->assertSame('agent-php-PHPUnit', 'agent-php-PHPUnit');
    }

    /**
     * @dataProvider additionProvider
     */
    public function testDataProviderDatasetIsReported(int $left, int $right, int $expected): void
    {
        $this->assertSame($expected, $left + $right);
    }

    public function additionProvider(): array
    {
        return [
            'zero plus zero' => [0, 0, 0],
            'one plus two' => [1, 2, 3],
        ];
    }
}
