<?php

declare(strict_types=1);

use PHPUnit\Framework as Framework;
use PHPUnit\Runner\BaseTestRunner;
use ReportPortalBasic\Enum\ItemStatusesEnum;
use ReportPortalBasic\Enum\ItemTypesEnum;
use ReportPortalBasic\Enum\LogLevelsEnum;
use ReportPortalBasic\Service\ReportPortalHTTPService;
use GuzzleHttp\Psr7\Response;

class AgentPHPUnit implements Framework\TestListener
{
    protected $tests = [];

    private $UUID;
    private $projectName;
    private $host;
    private $timeZone;
    private $launchName;
    private $launchDescription;
    private $className;
    private $classDescription;
    private $testName;
    private $testDescription;

    private $rootItemID;
    private $classItemID;
    private $testItemID;

    private static $suiteCounter = 0;
    private $isLaunchFailed = false;
    private $isCurrentClassFailed = false;
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
            return ItemStatusesEnum::CANCELLED;
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
     * @param Framework\Test $test
     * @param Throwable $e
     * @param $logLevelsEnum
     * @param $testItemID
     */
    private function addSetOfLogMessages(Framework\Test $test, Throwable $e, $logLevelsEnum, $testItemID)
    {
        if (empty($testItemID)) {
            return;
        }

        $errorMessage = method_exists($e, 'toString') ? $e->toString() : (string) $e;
        self::$httpService->addLogMessage($testItemID, $errorMessage, $logLevelsEnum);

        if ($e instanceof Framework\AssertionFailedError) {
            $this->addAssertionLogMessages($test, $e, $logLevelsEnum, $testItemID);
        }

        $trace = $e->getTraceAsString();
        self::$httpService->addLogMessage($testItemID, $trace, $logLevelsEnum);
    }

    /**
     * @param Framework\Test $test
     * @param Framework\AssertionFailedError $e
     * @param $logLevelsEnum
     * @param $testItemID
     */
    private function addAssertionLogMessages(Framework\Test $test, Framework\AssertionFailedError $e, $logLevelsEnum, $testItemID)
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
     * @param Response $HTTPResponse
     * @return string
     */
    private static function getID(Response $HTTPResponse)
    {
        $payload = json_decode((string) $HTTPResponse->getBody(), true);

        return isset($payload['id']) ? (string) $payload['id'] : '';
    }

    /**
     * Is a suite with name
     *
     * @param Framework\TestSuite $suite
     * @return bool
     */
    private static function hasSuiteName(Framework\TestSuite $suite): bool
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
        $this->isCurrentClassFailed = true;
        $this->isLaunchFailed = true;
    }

    /**
     * A warning occurred.
     * @param Framework\Test $test
     * @param Framework\Warning $e
     * @param float $time
     */
    public function addWarning(\PHPUnit\Framework\Test $test, \PHPUnit\Framework\Warning $e, float $time): void
    {
        $this->addSetOfLogMessages($test, $e, LogLevelsEnum::WARN, $this->testItemID);
    }

    /**
     * Risky test.
     * @param Framework\Test $test
     * @param Throwable $t
     * @param float $time
     */
    public function addRiskyTest(\PHPUnit\Framework\Test $test, \Throwable $t, float $time): void
    {
        $this->markFailed();
        $this->addSetOfLogMessages($test, $t, LogLevelsEnum::WARN, $this->testItemID);
    }

    /**
     * An error occurred.
     * @param Framework\Test $test
     * @param Throwable $t
     * @param float $time
     */
    public function addError(\PHPUnit\Framework\Test $test, \Throwable $t, float $time): void
    {
        $this->markFailed();
        $this->addSetOfLogMessages($test, $t, LogLevelsEnum::FATAL, $this->testItemID);
    }

    /**
     * A test ended.
     * @param Framework\Test $test
     * @param float $time
     */
    public function endTest(\PHPUnit\Framework\Test $test, float $time): void
    {
        $testStatus = $this->getTestStatus($test);
        if (self::isFailedStatus($testStatus)) {
            $this->markFailed();
        }

        self::$httpService->finishItem($this->testItemID, $testStatus, $time . ' seconds');
    }

    /**
     * A test started.
     * @param Framework\Test $test
     */
    public function startTest(\PHPUnit\Framework\Test $test): void
    {
        $this->testName = $test->getName();
        $this->testDescription = '';
        $response = self::$httpService->startChildItem($this->classItemID, $this->testDescription, $this->testName, ItemTypesEnum::TEST, []);
        $this->testItemID = self::getID($response);
    }

    /**
     * A test suite started.
     * @param Framework\TestSuite $suite
     */
    public function startTestSuite(\PHPUnit\Framework\TestSuite $suite): void
    {
        if (self::hasSuiteName($suite)) {
            self::$suiteCounter++;

            if (self::$suiteCounter == 1) {
                $suiteName = $suite->getName();
                $response = self::$httpService->createRootItem($suiteName, '', []);
                $this->rootItemID = self::getID($response);
            } elseif (self::$suiteCounter > 1) {
                $className = $suite->getName();
                $this->className = $className;
                $this->classDescription = '';
                if (self::$suiteCounter == 2) {
                    $response = self::$httpService->startChildItem($this->rootItemID, $this->classDescription, $this->className, ItemTypesEnum::SUITE, []);
                    $this->classItemID = self::getID($response);
                    $this->isCurrentClassFailed = false;
                }
            }
        }
    }

    /**
     * A test suite ended.
     * @param Framework\TestSuite $suite
     */
    public function endTestSuite(\PHPUnit\Framework\TestSuite $suite): void
    {
        if (self::hasSuiteName($suite)) {
            self::$suiteCounter--;
            if (self::$suiteCounter == 0) {
                self::$httpService->finishRootItem();
            } elseif (self::$suiteCounter == 1 && !empty($this->classItemID)) {
                $status = self::getStatusByBool($this->isCurrentClassFailed);
                self::$httpService->finishItem($this->classItemID, $status, $this->classDescription);
            }
        }
    }

    /**
     * A failure occurred.
     * @param Framework\Test $test
     * @param Framework\AssertionFailedError $e
     * @param float $time
     */
    public function addFailure(\PHPUnit\Framework\Test $test, \PHPUnit\Framework\AssertionFailedError $e, float $time): void
    {
        $this->markFailed();
        $this->addSetOfLogMessages($test, $e, LogLevelsEnum::ERROR, $this->testItemID);
    }

    /**
     * Skipped test.
     * @param Framework\Test $test
     * @param Throwable $t
     * @param float $time
     */
    public function addSkippedTest(\PHPUnit\Framework\Test $test, \Throwable $t, float $time): void
    {
        $this->addSetOfLogMessages($test, $t, LogLevelsEnum::WARN, $this->testItemID);
    }

    /**
     * Incomplete test.
     * @param Framework\Test $test
     * @param Throwable $t
     * @param float $time
     */
    public function addIncompleteTest(\PHPUnit\Framework\Test $test, \Throwable $t, float $time): void
    {
        $this->addSetOfLogMessages($test, $t, LogLevelsEnum::WARN, $this->testItemID);
    }
}
