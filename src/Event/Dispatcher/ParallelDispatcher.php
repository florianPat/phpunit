<?php
declare(strict_types=1);
/*
 * This file is part of PHPUnit.
 *
 * (c) Sebastian Bergmann <sebastian@phpunit.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace PHPUnit\Event;

use function in_array;
use function serialize;

class ParallelDispatcher implements Dispatcher
{
    private const NOT_CALLED_EVENTS = [
        Application\Started::class,
        Application\Finished::class,

        TestSuite\Loaded::class,

        TestRunner\BootstrapFinished::class,
        TestRunner\Configured::class,
        TestRunner\EventFacadeSealed::class,
        TestRunner\ExtensionLoadedFromPhar::class,
        TestRunner\ExtensionBootstrapped::class,
    ];

    private const JUST_ONE_DISPATCHED = [
        TestSuite\Filtered::class,
        TestSuite\Sorted::class,
        TestSuite\Started::class,
        TestSuite\Skipped::class,
        TestSuite\Finished::class,

        TestRunner\ExecutionFinished::class,
        TestRunner\ExecutionStarted::class,
        TestRunner\Finished::class,
        TestRunner\Started::class,
    ];

    private const FLUSH_EVENTS = [
        Test\Finished::class,
        TestSuite\Finished::class,
        TestRunner\Finished::class,
        TestRunner\ExecutionStarted::class,
    ];
    private readonly CollectingDispatcher $collectingDispatcher;

    public function __construct(
        private readonly \parallel\Channel $eventDispatcherChannel,
    ) {
        $this->collectingDispatcher = new CollectingDispatcher;
    }

    public function dispatch(Event $event): void
    {
        if (in_array($event::class, self::NOT_CALLED_EVENTS, true)) {
            return;
        }

        if (in_array($event::class, self::JUST_ONE_DISPATCHED, true) && 0 !== $GLOBALS['THREAD_ID']) {
            return;
        }

        $this->collectingDispatcher->dispatch($event);

        if (!in_array($event::class, self::FLUSH_EVENTS, true)) {
            return;
        }

        $this->eventDispatcherChannel->send(serialize($this->collectingDispatcher->flush()));
    }
}
