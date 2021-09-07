<?php declare(strict_types=1);
/*
 * This file is part of PHPUnit.
 *
 * (c) Sebastian Bergmann <sebastian@phpunit.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace PHPUnit\Logging\TeamCity;

use function class_exists;
use function explode;
use function getmypid;
use function ini_get;
use function method_exists;
use function stripos;
use PHPUnit\Event\Code\Test;
use PHPUnit\Event\Event;
use PHPUnit\Event\EventFacadeIsSealedException;
use PHPUnit\Event\Facade;
use PHPUnit\Event\InvalidArgumentException;
use PHPUnit\Event\Telemetry\HRTime;
use PHPUnit\Event\Test\Aborted;
use PHPUnit\Event\Test\ConsideredRisky;
use PHPUnit\Event\Test\Errored;
use PHPUnit\Event\Test\Failed;
use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\PassedWithWarning;
use PHPUnit\Event\Test\Prepared;
use PHPUnit\Event\Test\Skipped;
use PHPUnit\Event\TestSuite\Finished as TestSuiteFinished;
use PHPUnit\Event\TestSuite\Started as TestSuiteStarted;
use PHPUnit\Event\UnknownSubscriberTypeException;
use PHPUnit\Util\Exception;
use PHPUnit\Util\Printer;
use ReflectionClass;
use ReflectionException;

/**
 * @internal This class is not covered by the backward compatibility promise for PHPUnit
 */
final class TeamCityLogger extends Printer
{
    private bool $isSummaryTestCountPrinted = false;

    private ?Test $test = null;

    private ?HRTime $time = null;

    private false|int $flowId;

    /**
     * @throws EventFacadeIsSealedException
     * @throws Exception
     * @throws UnknownSubscriberTypeException
     */
    public function __construct(string $out)
    {
        parent::__construct($out);

        $this->registerSubscribers();

        if (stripos(ini_get('disable_functions'), 'getmypid') === false) {
            $this->flowId = getmypid();
        } else {
            $this->flowId = false;
        }
    }

    public function testSuiteStarted(TestSuiteStarted $event): void
    {
        if (!$this->isSummaryTestCountPrinted) {
            $this->isSummaryTestCountPrinted = true;

            $this->printEvent(
                'testCount',
                ['count' => $event->testSuite()->count()]
            );
        }

        $suiteName = $event->testSuite()->name();

        if (empty($suiteName)) {
            return;
        }

        $parameters = ['name' => $suiteName];

        if (class_exists($suiteName, false)) {
            $fileName                   = self::getFileName($suiteName);
            $parameters['locationHint'] = "php_qn://{$fileName}::\\{$suiteName}";
        } else {
            $split = explode('::', $suiteName);

            if (count($split) === 2 && class_exists($split[0]) && method_exists($split[0], $split[1])) {
                $fileName                   = self::getFileName($split[0]);
                $parameters['locationHint'] = "php_qn://{$fileName}::\\{$suiteName}";
                $parameters['name']         = $split[1];
            }
        }

        $this->printEvent('testSuiteStarted', $parameters);
    }

    public function testSuiteFinished(TestSuiteFinished $event): void
    {
        $suiteName = $event->testSuite()->name();

        if (empty($suiteName)) {
            return;
        }

        $parameters = ['name' => $suiteName];

        if (!class_exists($suiteName, false)) {
            $split = explode('::', $suiteName);

            if (\count($split) === 2 && class_exists($split[0]) && method_exists($split[0], $split[1])) {
                $parameters['name'] = $split[1];
            }
        }

        $this->printEvent('testSuiteFinished', $parameters);
    }

    public function testPrepared(Prepared $event): void
    {
        $this->test = $event->test();
        $this->time = $event->telemetryInfo()->time();
    }

    public function testAborted(Aborted $event): void
    {
        if ($this->test === null) {
            $this->test = $event->test();
            $this->time = $event->telemetryInfo()->time();
        }
    }

    public function testSkipped(Skipped $event): void
    {
        if ($this->test === null) {
            $this->test = $event->test();
            $this->time = $event->telemetryInfo()->time();
        }
    }

    public function testErrored(Errored $event): void
    {
        if ($this->test === null) {
            $this->test = $event->test();
            $this->time = $event->telemetryInfo()->time();
        }
    }

    public function testFailed(Failed $event): void
    {
        if ($this->test === null) {
            $this->test = $event->test();
            $this->time = $event->telemetryInfo()->time();
        }
    }

    public function testPassedWithWarning(PassedWithWarning $event): void
    {
        if ($this->test === null) {
            $this->test = $event->test();
            $this->time = $event->telemetryInfo()->time();
        }
    }

    public function testConsideredRisky(ConsideredRisky $event): void
    {
        if ($this->test === null) {
            $this->test = $event->test();
            $this->time = $event->telemetryInfo()->time();
        }
    }

    public function testFinished(Finished $event): void
    {
        $this->printEvent(
            'testFinished',
            [
                'name'     => $event->test()->name(),
                'duration' => $this->duration($event),
            ]
        );

        $this->test = null;
        $this->time = null;
    }

    private function printEvent(string $eventName, array $params = []): void
    {
        $this->write("\n##teamcity[{$eventName}");

        if ($this->flowId) {
            $params['flowId'] = $this->flowId;
        }

        foreach ($params as $key => $value) {
            $escapedValue = self::escape((string) $value);

            $this->write(" {$key}='{$escapedValue}'");
        }

        $this->write("]\n");
    }

    /**
     * @throws EventFacadeIsSealedException
     * @throws UnknownSubscriberTypeException
     */
    private function registerSubscribers(): void
    {
        Facade::registerSubscriber(new TestSuiteStartedSubscriber($this));
        Facade::registerSubscriber(new TestSuiteFinishedSubscriber($this));
        Facade::registerSubscriber(new TestPreparedSubscriber($this));
        Facade::registerSubscriber(new TestFinishedSubscriber($this));
        Facade::registerSubscriber(new TestPassedWithWarningSubscriber($this));
        Facade::registerSubscriber(new TestErroredSubscriber($this));
        Facade::registerSubscriber(new TestFailedSubscriber($this));
        Facade::registerSubscriber(new TestAbortedSubscriber($this));
        Facade::registerSubscriber(new TestSkippedSubscriber($this));
        Facade::registerSubscriber(new TestConsideredRiskySubscriber($this));
    }

    /**
     * @throws InvalidArgumentException
     *
     * @todo we need to return milliseconds
     */
    private function duration(Event $event): float
    {
        if ($this->time === null) {
            return 0.0;
        }

        return round($event->telemetryInfo()->time()->duration($this->time)->asFloat(), 3);
    }

    private static function escape(string $string): string
    {
        return str_replace(
            ['|', "'", "\n", "\r", ']', '['],
            ['||', "|'", '|n', '|r', '|]', '|['],
            $string
        );
    }

    /**
     * @psalm-param class-string $className
     */
    private static function getFileName(string $className): string
    {
        try {
            return (new ReflectionClass($className))->getFileName();
            // @codeCoverageIgnoreStart
        } catch (ReflectionException $e) {
            throw new Exception(
                $e->getMessage(),
                (int) $e->getCode(),
                $e
            );
        }
        // @codeCoverageIgnoreEnd
    }
}
