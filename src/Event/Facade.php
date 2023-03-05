<?php declare(strict_types=1);
/*
 * This file is part of PHPUnit.
 *
 * (c) Sebastian Bergmann <sebastian@phpunit.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace PHPUnit\Event;

use PHPUnit\Event\Telemetry\HRTime;

/**
 * @internal This class is not covered by the backward compatibility promise for PHPUnit
 */
final class Facade
{
    private static ?self $instance = null;
    private Emitter $emitter;
    private ?TypeMap $typeMap                         = null;
    private ?Emitter $suspended                       = null;
    private ?DeferringDispatcher $deferringDispatcher = null;
    private bool $sealed                              = false;

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self;
        }

        return self::$instance;
    }

    public static function emitter(): Emitter
    {
        return self::instance()->emitter;
    }

    public function __construct()
    {
        $this->emitter = $this->createDispatchingEmitter();
    }

    /**
     * @throws EventFacadeIsSealedException
     * @throws UnknownSubscriberTypeException
     */
    public function registerSubscribers(Subscriber ...$subscribers): void
    {
        foreach ($subscribers as $subscriber) {
            $this->registerSubscriber($subscriber);
        }
    }

    /**
     * @throws EventFacadeIsSealedException
     * @throws UnknownSubscriberTypeException
     */
    public function registerSubscriber(Subscriber $subscriber): void
    {
        if ($this->sealed) {
            throw new EventFacadeIsSealedException;
        }

        $this->deferredDispatcher()->registerSubscriber($subscriber);
    }

    /**
     * @throws EventFacadeIsSealedException
     */
    public function registerTracer(Tracer\Tracer $tracer): void
    {
        if ($this->sealed) {
            throw new EventFacadeIsSealedException;
        }

        $this->deferredDispatcher()->registerTracer($tracer);
    }

    /** @noinspection PhpUnused */
    public function initForIsolation(HRTime $offset): CollectingDispatcher
    {
        $dispatcher = new CollectingDispatcher;

        $this->emitter = new DispatchingEmitter(
            $dispatcher,
            new Telemetry\System(
                new Telemetry\SystemStopWatchWithOffset($offset),
                new Telemetry\SystemMemoryMeter
            )
        );

        $this->sealed = true;

        return $dispatcher;
    }

    public function initForParallel(\parallel\Channel $eventDispatcherChannel): ParallelDispatcher
    {
        $dispatcher = new ParallelDispatcher($eventDispatcherChannel);

        $this->emitter = new DispatchingEmitter(
            $dispatcher,
            self::createTelemetrySystem(),
        );

        $this->sealed = true;

        return $dispatcher;
    }

    public function forward(EventCollection $events): void
    {
        if ($this->suspended !== null) {
            return;
        }

        $dispatcher = $this->deferredDispatcher();

        foreach ($events as $event) {
            $dispatcher->dispatch($event);
        }
    }

    public function seal(): void
    {
        $this->deferredDispatcher()->flush();

        $this->sealed = true;

        $this->emitter->testRunnerEventFacadeSealed();
    }

    private function createDispatchingEmitter(): DispatchingEmitter
    {
        return new DispatchingEmitter(
            $this->deferredDispatcher(),
            $this->createTelemetrySystem()
        );
    }

    private function createTelemetrySystem(): Telemetry\System
    {
        return new Telemetry\System(
            new Telemetry\SystemStopWatch,
            new Telemetry\SystemMemoryMeter
        );
    }

    private function deferredDispatcher(): DeferringDispatcher
    {
        if ($this->deferringDispatcher === null) {
            $this->deferringDispatcher = new DeferringDispatcher(
                new DirectDispatcher($this->typeMap())
            );
        }

        return $this->deferringDispatcher;
    }

    private function typeMap(): TypeMap
    {
        if ($this->typeMap === null) {
            $typeMap = new TypeMap;

            $this->registerDefaultTypes($typeMap);

            $this->typeMap = $typeMap;
        }

        return $this->typeMap;
    }

    private function registerDefaultTypes(TypeMap $typeMap): void
    {
        $defaultEvents = [
            Application\Started::class,
            Application\Finished::class,

            Test\MarkedIncomplete::class,
            Test\AfterLastTestMethodCalled::class,
            Test\AfterLastTestMethodFinished::class,
            Test\AfterTestMethodCalled::class,
            Test\AfterTestMethodFinished::class,
            Test\AssertionSucceeded::class,
            Test\AssertionFailed::class,
            Test\BeforeFirstTestMethodCalled::class,
            Test\BeforeFirstTestMethodErrored::class,
            Test\BeforeFirstTestMethodFinished::class,
            Test\BeforeTestMethodCalled::class,
            Test\BeforeTestMethodFinished::class,
            Test\ComparatorRegistered::class,
            Test\ConsideredRisky::class,
            Test\DeprecationTriggered::class,
            Test\Errored::class,
            Test\ErrorTriggered::class,
            Test\Failed::class,
            Test\Finished::class,
            Test\NoticeTriggered::class,
            Test\Passed::class,
            Test\PhpDeprecationTriggered::class,
            Test\PhpNoticeTriggered::class,
            Test\PhpunitDeprecationTriggered::class,
            Test\PhpunitErrorTriggered::class,
            Test\PhpunitWarningTriggered::class,
            Test\PhpWarningTriggered::class,
            Test\PostConditionCalled::class,
            Test\PostConditionFinished::class,
            Test\PreConditionCalled::class,
            Test\PreConditionFinished::class,
            Test\PreparationStarted::class,
            Test\Prepared::class,
            Test\PrintedUnexpectedOutput::class,
            Test\Skipped::class,
            Test\WarningTriggered::class,

            Test\MockObjectCreated::class,
            Test\MockObjectForAbstractClassCreated::class,
            Test\MockObjectForIntersectionOfInterfacesCreated::class,
            Test\MockObjectForTraitCreated::class,
            Test\MockObjectFromWsdlCreated::class,
            Test\PartialMockObjectCreated::class,
            Test\TestProxyCreated::class,
            Test\TestStubCreated::class,
            Test\TestStubForIntersectionOfInterfacesCreated::class,

            TestRunner\BootstrapFinished::class,
            TestRunner\Configured::class,
            TestRunner\EventFacadeSealed::class,
            TestRunner\ExecutionFinished::class,
            TestRunner\ExecutionStarted::class,
            TestRunner\ExtensionLoadedFromPhar::class,
            TestRunner\ExtensionBootstrapped::class,
            TestRunner\Finished::class,
            TestRunner\Started::class,
            TestRunner\DeprecationTriggered::class,
            TestRunner\WarningTriggered::class,

            TestSuite\Filtered::class,
            TestSuite\Finished::class,
            TestSuite\Loaded::class,
            TestSuite\Skipped::class,
            TestSuite\Sorted::class,
            TestSuite\Started::class,
        ];

        foreach ($defaultEvents as $eventClass) {
            $typeMap->addMapping(
                $eventClass . 'Subscriber',
                $eventClass
            );
        }
    }
}
