<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ReportPortalStatusMatrixTest extends TestCase
{
    public function testFailureIsReportedWithAssertionDetails(): void
    {
        $this->assertSame('expected value', 'actual value');
    }

    public function testErrorIsReportedWithExceptionDetails(): void
    {
        throw new RuntimeException('Intentional ReportPortal smoke error.');
    }

    public function testSkippedIsReportedWithReason(): void
    {
        $this->markTestSkipped('Intentional ReportPortal smoke skip.');
    }

    public function testIncompleteIsReportedWithReason(): void
    {
        $this->markTestIncomplete('Intentional ReportPortal smoke incomplete.');
    }

    public function testRiskyIsReportedWhenNoAssertionIsPerformed(): void
    {
        $value = 'no assertion on purpose';
        $value .= '';
    }
}
