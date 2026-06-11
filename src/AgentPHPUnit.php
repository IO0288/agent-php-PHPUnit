<?php

declare(strict_types=1);

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Test;
use PHPUnit\Framework\TestListener;
use PHPUnit\Framework\TestSuite;
use PHPUnit\Framework\Warning;
use PHPUnit\Runner\BaseTestRunner;
use ReportPortalBasic\Enum\ItemStatusesEnum;
use ReportPortalBasic\Enum\ItemTypesEnum;
use ReportPortalBasic\Enum\LogLevelsEnum;
use ReportPortalBasic\Service\ReportPortalHTTPService;
use Psr\Http\Message\ResponseInterface;

class AgentPHPUnit implements TestListener
{
    protected $tests = [];

    private $UUID;
    private $projectName;
    private $host;
    private $timeZone;
    private $launchName;
    private $launchDescription;
    private $testName;
    private $testDescription;

    private $testItemID;

    private $suiteStack = [];
    private $isLaunchFailed = false;
    private $isFinished = false;

    /**
     * @var ReportPortalHTTPService
     */
    protected static $httpService;

    /**
     * agentPHPUnit constructor.
     * @param $UUID
     * @param $host
     * @param $projectName
     * @param $timeZone
     * @param $launchName
     * @param $launchDescription
     */
    public function __construct($UUID, $host, $projectName, $timeZone, $launchName, $launchDescription)
    {
        $this->UUID = $UUID;
        $this->host = $host;
        $this->projectName = $projectName;
        $this->timeZone = $timeZone;
        $this->launchName = $launchName;
        $this->launchDescription = $launchDescription;

        $this->configureClient();
        self::$httpService->launchTestRun($this->launchName, $this->launchDescription, ReportPortalHTTPService::DEFAULT_LAUNCH_MODE, []);
    }

    /**
     * agentPHPUnit destructor.
     */
    public function __destruct()
    {
        if ($this->isFinished || !self::$httpService instanceof ReportPortalHTTPService) {
            return;
        }

        $this->isFinished = true;
        $this->finishOpenSuites();
        $status = self::getStatusByBool($this->isLaunchFailed);
        $HTTPResult = self::$httpService->finishTestRun($status);
        self::$httpService->finishAll($HTTPResult);
    }

    /**
     * @param $test
     * @return string
     */
    private function getTestStatus($test)
    {
        $status = $test->getStatus();
        if ($status === BaseTestRunner::STATUS_PASSED) {
            return ItemStatusesEnum::PASSED;
        }
        if ($status === BaseTestRunner::STATUS_FAILURE) {
            return ItemStatusesEnum::FAILED;
        }
        if ($status === BaseTestRunner::STATUS_SKIPPED) {
            return ItemStatusesEnum::SKIPPED;
        }
        if ($status === BaseTestRunner::STATUS_INCOMPLETE) {
            return ItemStatusesEnum::STOPPED;
        }
        if ($status === BaseTestRunner::STATUS_ERROR) {
            return ItemStatusesEnum::FAILED;
        }
        if (defined(BaseTestRunner::class . '::STATUS_RISKY')
            && $status === constant(BaseTestRunner::class . '::STATUS_RISKY')
        ) {
            return ItemStatusesEnum::FAILED;
        }
        if (defined(BaseTestRunner::class . '::STATUS_WARNING')
            && $status === constant(BaseTestRunner::class . '::STATUS_WARNING')
        ) {
            return ItemStatusesEnum::FAILED;
        }

        return ItemStatusesEnum::SKIPPED;
    }

    /**
     * Configure http client.
     */
    private function configureClient()
    {
        $isHTTPErrorsAllowed = false;
        $baseURI = sprintf(ReportPortalHTTPService::BASE_URI_TEMPLATE, $this->host);
        ReportPortalHTTPService::configureClient($this->UUID, $baseURI, $this->host, $this->timeZone, $this->projectName, $isHTTPErrorsAllowed);
        self::$httpService = new ReportPortalHTTPService();
    }

    /**
     * @param Test $test
     * @param Throwable $e
     * @param $logLevelsEnum
     * @param $testItemID
     */
    private function addSetOfLogMessages(Test $test, Throwable $e, $logLevelsEnum, $testItemID)
    {
        if (empty($testItemID)) {
            return;
        }

        $errorMessage = method_exists($e, 'toString') ? $e->toString() : (string) $e;
        self::$httpService->addLogMessage($testItemID, $errorMessage, $logLevelsEnum);

        if ($e instanceof AssertionFailedError) {
            $this->addAssertionLogMessages($test, $e, $logLevelsEnum, $testItemID);
        }

        $trace = $e->getTraceAsString();
        self::$httpService->addLogMessage($testItemID, $trace, $logLevelsEnum);
    }

    /**
     * @param Test $test
     * @param AssertionFailedError $e
     * @param $logLevelsEnum
     * @param $testItemID
     */
    private function addAssertionLogMessages(Test $test, AssertionFailedError $e, $logLevelsEnum, $testItemID)
    {
        $className = get_class($test);
        $traceArray = $e->getTrace();
        $arraySize = count($traceArray);
        $foundedFirstMatch = false;
        $counter = 0;
        while (!$foundedFirstMatch && $counter < $arraySize) {
            if (isset($traceArray[$counter]["file"]) && strpos($traceArray[$counter]["file"], $className) !== false) {
                $fileName = $traceArray[$counter]["file"];
                $fileLine = $traceArray[$counter]["line"] ?? '';
                $function = $traceArray[$counter]["function"] ?? '';
                $assertClass = $traceArray[$counter]["class"] ?? '';
                $type = $traceArray[$counter]["type"] ?? '';
                $args = implode(',', array_map([$this, 'formatTraceArgument'], $traceArray[$counter]["args"] ?? []));
                self::$httpService->addLogMessage($testItemID, $assertClass . $type . $function . '(' . $args . ')', $logLevelsEnum);
                self::$httpService->addLogMessage($testItemID, $fileName . ':' . $fileLine, $logLevelsEnum);
                $foundedFirstMatch = true;
            }
            $counter++;
        }
    }

    /**
     * @param mixed $argument
     * @return string
     */
    private function formatTraceArgument($argument)
    {
        if (is_scalar($argument) || $argument === null) {
            return (string) $argument;
        }

        if (is_object($argument)) {
            return get_class($argument);
        }

        return gettype($argument);
    }

    /**
     * @param bool $isFailedItem
     * @return string
     */
    private static function getStatusByBool(bool $isFailedItem)
    {
        if ($isFailedItem) {
            $stringItemStatus = ItemStatusesEnum::FAILED;
        } else {
            $stringItemStatus = ItemStatusesEnum::PASSED;
        }
        return $stringItemStatus;
    }

    /**
     * Get ID from response
     *
     * @param ResponseInterface $HTTPResponse
     * @return string
     */
    private static function getID(ResponseInterface $HTTPResponse)
    {
        $payload = json_decode((string) $HTTPResponse->getBody(), true);

        return isset($payload['id']) ? (string) $payload['id'] : '';
    }

    /**
     * Is a suite with name
     *
     * @param TestSuite $suite
     * @return bool
     */
    private static function hasSuiteName(TestSuite $suite): bool
    {
        return $suite->getName() !== "";
    }

    /**
     * @param string $status
     * @return bool
     */
    private static function isFailedStatus($status)
    {
        return in_array($status, [ItemStatusesEnum::FAILED, ItemStatusesEnum::CANCELLED], true);
    }

    private function markFailed()
    {
        $this->isLaunchFailed = true;
        $this->markActiveSuitesFailed();
    }

    private function markActiveSuitesFailed()
    {
        foreach ($this->suiteStack as $index => $suite) {
            $this->suiteStack[$index]['isFailed'] = true;
        }
    }

    /**
     * @return string
     */
    private function getCurrentSuiteID()
    {
        if (empty($this->suiteStack)) {
            return '';
        }

        $suite = $this->suiteStack[count($this->suiteStack) - 1];

        return $suite['id'];
    }

    /**
     * A warning occurred.
     * @param Test $test
     * @param Warning $e
     * @param float $time
     */
    public function addWarning(Test $test, Warning $e, float $time): void
    {
        $this->markFailed();
        $this->addSetOfLogMessages($test, $e, LogLevelsEnum::WARN, $this->testItemID);
    }

    /**
     * Risky test.
     * @param Test $test
     * @param Throwable $t
     * @param float $time
     */
    public function addRiskyTest(Test $test, \Throwable $t, float $time): void
    {
        $this->markFailed();
        $this->addSetOfLogMessages($test, $t, LogLevelsEnum::WARN, $this->testItemID);
    }

    /**
     * An error occurred.
     * @param Test $test
     * @param Throwable $t
     * @param float $time
     */
    public function addError(Test $test, \Throwable $t, float $time): void
    {
        $this->markFailed();
        $this->addSetOfLogMessages($test, $t, LogLevelsEnum::FATAL, $this->testItemID);
    }

    /**
     * A test ended.
     * @param Test $test
     * @param float $time
     */
    public function endTest(Test $test, float $time): void
    {
        $testStatus = $this->getTestStatus($test);
        if (self::isFailedStatus($testStatus)) {
            $this->markFailed();
        }

        if (!empty($this->testItemID)) {
            self::$httpService->finishItem($this->testItemID, $testStatus, $time . ' seconds');
            $this->testItemID = '';
        }
    }

    /**
     * A test started.
     * @param Test $test
     */
    public function startTest(Test $test): void
    {
        $this->testName = $this->getTestName($test, true);
        $this->testDescription = '';
        $parentItemID = $this->getCurrentSuiteID();
        if (empty($parentItemID)) {
            $this->startSuite(get_class($test));
            $parentItemID = $this->getCurrentSuiteID();
        }

        $response = self::$httpService->startChildItem(
            $parentItemID,
            $this->testDescription,
            $this->testName,
            ItemTypesEnum::STEP,
            [],
            $this->buildTestItemMetadata($test)
        );
        $this->testItemID = self::getID($response);
    }

    /**
     * @param Test $test
     * @return array
     */
    private function buildTestItemMetadata(Test $test)
    {
        $className = get_class($test);
        $testName = $this->getTestName($test, true);
        $methodName = $this->getTestName($test, false);
        $metadata = [
            'codeRef' => $className . '::' . $methodName,
            'uniqueId' => $className . '::' . $testName,
        ];

        $parameters = $this->buildTestParameters($test, $methodName);
        if (!empty($parameters)) {
            $metadata['parameters'] = $parameters;
        }

        return $metadata;
    }

    /**
     * @param Test $test
     * @param string $methodName
     * @return array
     */
    private function buildTestParameters(Test $test, string $methodName)
    {
        if (!method_exists($test, 'getProvidedData')) {
            return [];
        }

        $providedData = $test->getProvidedData();
        if (empty($providedData)) {
            return [];
        }

        $parameters = [];
        if (method_exists($test, 'dataName')) {
            $dataName = $test->dataName();
            if ($dataName !== '') {
                $parameters[] = [
                    'key' => '_dataName',
                    'value' => (string) $dataName,
                ];
            }
        }

        $parameterNames = $this->getMethodParameterNames($test, $methodName);
        $index = 0;
        foreach ($providedData as $key => $value) {
            $parameterKey = isset($parameterNames[$index]) ? $parameterNames[$index] : (string) $key;
            $parameters[] = [
                'key' => $parameterKey,
                'value' => $this->formatParameterValue($value),
            ];
            $index++;
        }

        return $parameters;
    }

    /**
     * @param Test $test
     * @param string $methodName
     * @return array
     */
    private function getMethodParameterNames(Test $test, string $methodName)
    {
        try {
            $method = new ReflectionMethod($test, $methodName);
        } catch (ReflectionException $e) {
            return [];
        }

        $names = [];
        foreach ($method->getParameters() as $parameter) {
            $names[] = $parameter->getName();
        }

        return $names;
    }

    /**
     * @param mixed $value
     * @return string
     */
    private function formatParameterValue($value)
    {
        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded !== false) {
            return $encoded;
        }

        if (is_object($value)) {
            return get_class($value);
        }

        return gettype($value);
    }

    /**
     * @param Test $test
     * @param bool $withDataSet
     * @return string
     */
    private function getTestName(Test $test, bool $withDataSet)
    {
        if (!method_exists($test, 'getName')) {
            return get_class($test);
        }

        $method = new ReflectionMethod($test, 'getName');
        if ($method->getNumberOfParameters() > 0) {
            return $test->getName($withDataSet);
        }

        return $test->getName();
    }

    /**
     * A test suite started.
     * @param TestSuite $suite
     */
    public function startTestSuite(TestSuite $suite): void
    {
        if (self::hasSuiteName($suite)) {
            $this->startSuite($suite->getName());
        }
    }

    /**
     * A test suite ended.
     * @param TestSuite $suite
     */
    public function endTestSuite(TestSuite $suite): void
    {
        if (self::hasSuiteName($suite)) {
            $this->finishCurrentSuite();
        }
    }

    /**
     * @param string $suiteName
     */
    private function startSuite(string $suiteName)
    {
        $description = '';
        if (empty($this->suiteStack)) {
            $response = self::$httpService->createRootItem($suiteName, $description, []);
        } else {
            $response = self::$httpService->startChildItem(
                $this->getCurrentSuiteID(),
                $description,
                $suiteName,
                ItemTypesEnum::SUITE,
                []
            );
        }

        $this->suiteStack[] = [
            'id' => self::getID($response),
            'description' => $description,
            'isFailed' => false,
        ];
    }

    private function finishCurrentSuite()
    {
        if (empty($this->suiteStack)) {
            return;
        }

        $suite = array_pop($this->suiteStack);
        $this->finishSuite($suite);

        if ($suite['isFailed'] && !empty($this->suiteStack)) {
            $this->suiteStack[count($this->suiteStack) - 1]['isFailed'] = true;
        }
    }

    private function finishOpenSuites()
    {
        while (!empty($this->suiteStack)) {
            $this->finishCurrentSuite();
        }
    }

    /**
     * @param array $suite
     */
    private function finishSuite(array $suite)
    {
        if (empty($suite['id'])) {
            return;
        }

        $status = self::getStatusByBool($suite['isFailed']);
        self::$httpService->finishItem($suite['id'], $status, $suite['description']);
    }

    /**
     * A failure occurred.
     * @param Test $test
     * @param AssertionFailedError $e
     * @param float $time
     */
    public function addFailure(Test $test, AssertionFailedError $e, float $time): void
    {
        $this->markFailed();
        $this->addSetOfLogMessages($test, $e, LogLevelsEnum::ERROR, $this->testItemID);
    }

    /**
     * Skipped test.
     * @param Test $test
     * @param Throwable $t
     * @param float $time
     */
    public function addSkippedTest(Test $test, \Throwable $t, float $time): void
    {
        $this->addSetOfLogMessages($test, $t, LogLevelsEnum::WARN, $this->testItemID);
    }

    /**
     * Incomplete test.
     * @param Test $test
     * @param Throwable $t
     * @param float $time
     */
    public function addIncompleteTest(Test $test, \Throwable $t, float $time): void
    {
        $this->addSetOfLogMessages($test, $t, LogLevelsEnum::WARN, $this->testItemID);
    }
}
