<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use ReportPortalBasic\Enum\ItemStatusesEnum;
use ReportPortalBasic\Enum\ItemTypesEnum;
use ReportPortalBasic\Service\ReportPortalHTTPService;

final class ReportPortalHTTPServicePayloadTest extends TestCase
{
    public function testLaunchPayloadUsesCurrentReportPortalFields(): void
    {
        $history = [];
        $this->installMockClient([
            new Response(201, [], '{"id":"launch-uuid","number":1}'),
        ], $history);

        ReportPortalHTTPService::launchTestRun(
            'test launch name',
            'test launch description',
            ReportPortalHTTPService::DEFAULT_LAUNCH_MODE,
            ['suite' => 'smoke', 'phpunit']
        );

        $payload = $this->requestPayload($history, 0);

        $this->assertSame('/api/v1/agent-php-PHPUnit/launch', $this->requestPath($history, 0));
        $this->assertSame('test launch name', $payload['name']);
        $this->assertSame('test launch description', $payload['description']);
        $this->assertSame(ReportPortalHTTPService::DEFAULT_LAUNCH_MODE, $payload['mode']);
        $this->assertArrayHasKey('startTime', $payload);
        $this->assertSame([
            ['key' => 'suite', 'value' => 'smoke'],
            ['value' => 'phpunit'],
        ], $payload['attributes']);
        $this->assertArrayNotHasKey('start_time', $payload);
        $this->assertArrayNotHasKey('tags', $payload);
    }

    public function testItemLogAndFinishPayloadsUseCurrentReportPortalFields(): void
    {
        $history = [];
        $this->installMockClient([
            new Response(201, [], '{"id":"launch-uuid","number":1}'),
            new Response(201, [], '{"id":"root-item-uuid"}'),
            new Response(201, [], '{"id":"test-item-uuid"}'),
            new Response(201, [], '{"id":"log-uuid"}'),
            new Response(200, [], '{"message":"finished"}'),
        ], $history);

        ReportPortalHTTPService::launchTestRun('launch', 'description', ReportPortalHTTPService::DEFAULT_LAUNCH_MODE, []);
        ReportPortalHTTPService::createRootItem('root suite', '', ['layer' => 'root']);
        ReportPortalHTTPService::startChildItem(
            'root-item-uuid',
            '',
            'test method',
            ItemTypesEnum::TEST,
            [],
            [
                'codeRef' => 'ReportPortalConnectivityTest::testPassingAssertionIsReported',
                'uniqueId' => 'ReportPortalConnectivityTest::testPassingAssertionIsReported',
            ]
        );
        ReportPortalHTTPService::addLogMessage('test-item-uuid', 'failure details', 'error');
        ReportPortalHTTPService::finishItem('test-item-uuid', ItemStatusesEnum::FAILED, '0.004 seconds');

        $rootPayload = $this->requestPayload($history, 1);
        $testPayload = $this->requestPayload($history, 2);
        $logPayload = $this->requestPayload($history, 3);
        $finishPayload = $this->requestPayload($history, 4);

        $this->assertSame('/api/v1/agent-php-PHPUnit/item', $this->requestPath($history, 1));
        $this->assertSame('launch-uuid', $rootPayload['launchUuid']);
        $this->assertSame('suite', $rootPayload['type']);
        $this->assertArrayHasKey('startTime', $rootPayload);
        $this->assertSame([['key' => 'layer', 'value' => 'root']], $rootPayload['attributes']);
        $this->assertArrayNotHasKey('launch_id', $rootPayload);
        $this->assertArrayNotHasKey('start_time', $rootPayload);
        $this->assertArrayNotHasKey('tags', $rootPayload);

        $this->assertSame('/api/v1/agent-php-PHPUnit/item/root-item-uuid', $this->requestPath($history, 2));
        $this->assertSame('launch-uuid', $testPayload['launchUuid']);
        $this->assertSame('test', $testPayload['type']);
        $this->assertSame('ReportPortalConnectivityTest::testPassingAssertionIsReported', $testPayload['codeRef']);
        $this->assertSame('ReportPortalConnectivityTest::testPassingAssertionIsReported', $testPayload['uniqueId']);

        $this->assertSame('/api/v1/agent-php-PHPUnit/log', $this->requestPath($history, 3));
        $this->assertSame('launch-uuid', $logPayload['launchUuid']);
        $this->assertSame('test-item-uuid', $logPayload['itemUuid']);
        $this->assertSame('failure details', $logPayload['message']);
        $this->assertArrayNotHasKey('item_id', $logPayload);

        $this->assertSame('/api/v1/agent-php-PHPUnit/item/test-item-uuid', $this->requestPath($history, 4));
        $this->assertSame('launch-uuid', $finishPayload['launchUuid']);
        $this->assertSame('failed', $finishPayload['status']);
        $this->assertArrayHasKey('endTime', $finishPayload);
        $this->assertArrayNotHasKey('end_time', $finishPayload);
    }

    public function testRequestFailuresAreNotSilentlyIgnored(): void
    {
        $history = [];
        $this->installMockClient([
            new Response(400, [], '{"message":"launch payload is invalid"}'),
        ], $history);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('POST v1/agent-php-PHPUnit/launch returned HTTP 400');

        ReportPortalHTTPService::launchTestRun('launch', 'description', ReportPortalHTTPService::DEFAULT_LAUNCH_MODE, []);
    }

    public function testIdBearingResponsesMustContainId(): void
    {
        $history = [];
        $this->installMockClient([
            new Response(201, [], '{"message":"created without id"}'),
        ], $history);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ReportPortal response does not contain "id"');

        ReportPortalHTTPService::launchTestRun('launch', 'description', ReportPortalHTTPService::DEFAULT_LAUNCH_MODE, []);
    }

    private function installMockClient(array $responses, array &$history): void
    {
        ReportPortalHTTPService::configureClient(
            'token',
            'http://reportportal.example/api/',
            'http://reportportal.example',
            '.000+00:00',
            'agent-php-PHPUnit',
            false
        );

        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));

        $client = new Client([
            'base_uri' => 'http://reportportal.example/api/',
            'handler' => $stack,
            'http_errors' => false,
            'headers' => [
                'Authorization' => 'Bearer token',
            ],
        ]);

        $reflection = new ReflectionProperty(ReportPortalHTTPService::class, 'client');
        $reflection->setAccessible(true);
        $reflection->setValue(null, $client);
    }

    private function requestPayload(array $history, int $index): array
    {
        return json_decode((string) $history[$index]['request']->getBody(), true);
    }

    private function requestPath(array $history, int $index): string
    {
        return $history[$index]['request']->getUri()->getPath();
    }
}
