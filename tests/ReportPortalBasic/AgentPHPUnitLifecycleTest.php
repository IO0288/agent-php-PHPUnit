<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\TestSuite;
use PHPUnit\Runner\BaseTestRunner;
use ReportPortalBasic\Enum\ItemStatusesEnum;
use ReportPortalBasic\Enum\ItemTypesEnum;

final class AgentPHPUnitLifecycleTest extends TestCase
{
    public function testNestedSuitesAreReportedAndFinishedAsAStack(): void
    {
        $service = new RecordingReportPortalService();
        $listener = $this->createListener($service);

        $listener->startTestSuite(new TestSuite('root suite'));
        $listener->startTestSuite(new TestSuite('module suite'));
        $listener->startTestSuite(new TestSuite('class suite'));

        $test = new AgentPHPUnitLifecycleSampleTest('testExample');
        $listener->startTest($test);
        $listener->addFailure($test, new AssertionFailedError('Intentional failure.'), 0.01);
        $this->setTestStatus($test, BaseTestRunner::STATUS_FAILURE);
        $listener->endTest($test, 0.01);

        $listener->endTestSuite(new TestSuite('class suite'));
        $listener->endTestSuite(new TestSuite('module suite'));
        $listener->endTestSuite(new TestSuite('root suite'));

        $this->assertSame(
            [
                ['createRootItem', 'id-1', '', 'root suite'],
                ['startChildItem', 'id-2', 'id-1', 'module suite', ItemTypesEnum::SUITE],
                ['startChildItem', 'id-3', 'id-2', 'class suite', ItemTypesEnum::SUITE],
                ['startChildItem', 'id-4', 'id-3', 'testExample', ItemTypesEnum::STEP],
                ['finishItem', 'id-4', ItemStatusesEnum::FAILED],
                ['finishItem', 'id-3', ItemStatusesEnum::FAILED],
                ['finishItem', 'id-2', ItemStatusesEnum::FAILED],
                ['finishItem', 'id-1', ItemStatusesEnum::FAILED],
            ],
            $service->importantCalls()
        );
    }

    public function testDataProviderMetadataIsReportedOnTestItem(): void
    {
        $service = new RecordingReportPortalService();
        $listener = $this->createListener($service);

        $listener->startTestSuite(new TestSuite('root suite'));

        $test = new AgentPHPUnitLifecycleSampleTest(
            'testWithData',
            [1, 2, 3],
            'one plus two'
        );
        $listener->startTest($test);

        $testCall = $service->findCall('startChildItem', ItemTypesEnum::STEP);

        $this->assertSame('testWithData with data set "one plus two"', $testCall['name']);
        $this->assertSame('AgentPHPUnitLifecycleSampleTest::testWithData', $testCall['metadata']['codeRef']);
        $this->assertSame(
            'AgentPHPUnitLifecycleSampleTest::testWithData with data set "one plus two"',
            $testCall['metadata']['uniqueId']
        );
        $this->assertSame(
            [
                ['key' => '_dataName', 'value' => 'one plus two'],
                ['key' => 'left', 'value' => '1'],
                ['key' => 'right', 'value' => '2'],
                ['key' => 'expected', 'value' => '3'],
            ],
            $testCall['metadata']['parameters']
        );
    }

    public function testErrorStatusIsReportedAsInterrupted(): void
    {
        $service = new RecordingReportPortalService();
        $listener = $this->createListener($service);

        $listener->startTestSuite(new TestSuite('root suite'));

        $test = new AgentPHPUnitLifecycleSampleTest('testExample');
        $listener->startTest($test);
        $listener->addError($test, new RuntimeException('Intentional error.'), 0.01);
        $this->setTestStatus($test, BaseTestRunner::STATUS_ERROR);
        $listener->endTest($test, 0.01);

        $this->assertSame(ItemStatusesEnum::INTERRUPTED, $service->findFinishedStatus('id-2'));
    }

    public function testInterruptedStatusMarksParentSuitesAsFailed(): void
    {
        $service = new RecordingReportPortalService();
        $listener = $this->createListener($service);

        $listener->startTestSuite(new TestSuite('root suite'));

        $test = new AgentPHPUnitLifecycleSampleTest('testExample');
        $listener->startTest($test);
        $this->setTestStatus($test, BaseTestRunner::STATUS_ERROR);
        $listener->endTest($test, 0.01);
        $listener->endTestSuite(new TestSuite('root suite'));

        $this->assertSame(ItemStatusesEnum::INTERRUPTED, $service->findFinishedStatus('id-2'));
        $this->assertSame(ItemStatusesEnum::FAILED, $service->findFinishedStatus('id-1'));
    }

    private function createListener(RecordingReportPortalService $service): AgentPHPUnit
    {
        $property = new ReflectionProperty(AgentPHPUnit::class, 'httpService');
        $property->setAccessible(true);
        $property->setValue(null, $service);

        $reflection = new ReflectionClass(AgentPHPUnit::class);

        return $reflection->newInstanceWithoutConstructor();
    }

    private function setTestStatus(TestCase $test, int $status): void
    {
        $property = new ReflectionProperty(TestCase::class, 'status');
        $property->setAccessible(true);
        $property->setValue($test, $status);
    }
}

final class AgentPHPUnitLifecycleSampleTest extends TestCase
{
    public function testExample(): void
    {
        $this->assertTrue(true);
    }

    public function testWithData(int $left, int $right, int $expected): void
    {
        $this->assertSame($expected, $left + $right);
    }
}

final class RecordingReportPortalService
{
    private $nextID = 1;

    private $calls = [];

    public function createRootItem(string $name, string $description, array $tags): Response
    {
        $id = $this->nextID();
        $this->calls[] = [
            'method' => 'createRootItem',
            'id' => $id,
            'name' => $name,
            'description' => $description,
            'tags' => $tags,
        ];

        return $this->responseWithID($id);
    }

    public function startChildItem(string $parentItemID, string $description, string $name, string $type, array $tags, array $metadata = []): Response
    {
        $id = $this->nextID();
        $this->calls[] = [
            'method' => 'startChildItem',
            'id' => $id,
            'parentItemID' => $parentItemID,
            'name' => $name,
            'description' => $description,
            'type' => $type,
            'tags' => $tags,
            'metadata' => $metadata,
        ];

        return $this->responseWithID($id);
    }

    public function addLogMessage(string $itemID, string $message, string $logLevel): Response
    {
        $id = $this->nextID();
        $this->calls[] = [
            'method' => 'addLogMessage',
            'id' => $id,
            'itemID' => $itemID,
            'message' => $message,
            'logLevel' => $logLevel,
        ];

        return $this->responseWithID($id);
    }

    public function finishItem(string $itemID, string $status, string $description): Response
    {
        $this->calls[] = [
            'method' => 'finishItem',
            'itemID' => $itemID,
            'status' => $status,
            'description' => $description,
        ];

        return new Response(200, [], '{"message":"finished"}');
    }

    public function importantCalls(): array
    {
        $important = [];
        foreach ($this->calls as $call) {
            if ($call['method'] === 'createRootItem') {
                $important[] = [$call['method'], $call['id'], $call['description'], $call['name']];
            }
            if ($call['method'] === 'startChildItem') {
                $important[] = [$call['method'], $call['id'], $call['parentItemID'], $call['name'], $call['type']];
            }
            if ($call['method'] === 'finishItem') {
                $important[] = [$call['method'], $call['itemID'], $call['status']];
            }
        }

        return $important;
    }

    public function findCall(string $method, string $type): array
    {
        foreach ($this->calls as $call) {
            if ($call['method'] === $method && ($call['type'] ?? null) === $type) {
                return $call;
            }
        }

        throw new RuntimeException(sprintf('Call %s with type %s was not recorded.', $method, $type));
    }

    public function findFinishedStatus(string $itemID): string
    {
        foreach ($this->calls as $call) {
            if ($call['method'] === 'finishItem' && $call['itemID'] === $itemID) {
                return $call['status'];
            }
        }

        throw new RuntimeException(sprintf('Finish call for item %s was not recorded.', $itemID));
    }

    private function nextID(): string
    {
        return 'id-' . $this->nextID++;
    }

    private function responseWithID(string $id): Response
    {
        return new Response(201, [], json_encode(['id' => $id]));
    }
}
