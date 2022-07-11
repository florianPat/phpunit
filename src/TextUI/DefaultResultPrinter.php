<?php declare(strict_types=1);
/*
 * This file is part of PHPUnit.
 *
 * (c) Sebastian Bergmann <sebastian@phpunit.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace PHPUnit\TextUI;

use SebastianBergmann\Timer\Duration;
use const PHP_EOL;
use function array_map;
use function array_reverse;
use function count;
use function floor;
use function implode;
use function in_array;
use function is_int;
use function max;
use function preg_split;
use function sprintf;
use function str_pad;
use function str_repeat;
use function strlen;
use function vsprintf;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Exception;
use PHPUnit\Framework\InvalidArgumentException;
use PHPUnit\Framework\Test;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\TestFailure;
use PHPUnit\Framework\TestResult;
use PHPUnit\Framework\TestSuite;
use PHPUnit\Framework\Warning;
use PHPUnit\Runner\PhptTestCase;
use PHPUnit\Util\Color;
use PHPUnit\Util\Printer;
use SebastianBergmann\Environment\Console;
use SebastianBergmann\Timer\ResourceUsageFormatter;
use SebastianBergmann\Timer\Timer;
use Throwable;

/**
 * @internal This class is not covered by the backward compatibility promise for PHPUnit
 */
class DefaultResultPrinter extends Printer implements ResultPrinter
{
    public const EVENT_TEST_START = 0;

    public const EVENT_TEST_END = 1;

    public const EVENT_TESTSUITE_START = 2;

    public const EVENT_TESTSUITE_END = 3;

    public const COLOR_NEVER = 'never';

    public const COLOR_AUTO = 'auto';

    public const COLOR_ALWAYS = 'always';

    public const COLOR_DEFAULT = self::COLOR_NEVER;

    private const AVAILABLE_COLORS = [self::COLOR_NEVER, self::COLOR_AUTO, self::COLOR_ALWAYS];

    /**
     * @var int
     */
    protected $column = 0;

    /**
     * @var int
     */
    protected $maxColumn;

    /**
     * @var bool
     */
    protected $lastTestFailed = false;

    /**
     * @var bool
     */
    protected $lastTestDeadlock = false;

    /**
     * @var int
     */
    protected $currentTestIndex = 0;

    /**
     * @var int
     */
    protected $numAssertions = 0;

    /**
     * @var int
     */
    protected $numTests = -1;

    /**
     * @var int
     */
    protected $numTestsRun = 0;

    /**
     * @var int
     */
    protected $numTestsWidth;

    /**
     * @var bool
     */
    protected $colors = false;

    /**
     * @var bool
     */
    protected $debug = false;

    /**
     * @var bool
     */
    protected $verbose = false;

    /**
     * @var int
     */
    private $numberOfColumns;

    /**
     * @var bool
     */
    private $reverse;

    /**
     * @var bool
     */
    private $defectListPrinted = false;

    /**
     * @var Timer
     */
    private $timer;

    protected array $state = [];

    /**
     * Constructor.
     *
     * @param null|resource|string $out
     * @param int|string           $numberOfColumns
     *
     * @throws Exception
     */
    public function __construct(
        $out = null,
        bool $verbose = false,
        string $colors = self::COLOR_DEFAULT,
        bool $debug = false,
        $numberOfColumns = 80,
        bool $reverse = false,
        ?\parallel\Channel $outputChannel = null,
        protected int $threadId = -1,
        protected ?\parallel\Channel $prevChannel = null,
        protected ?\parallel\Channel $nextChannel = null,
    ) {
        parent::__construct($out, $outputChannel);

        if (!in_array($colors, self::AVAILABLE_COLORS, true)) {
            throw InvalidArgumentException::create(
                3,
                vsprintf('value from "%s", "%s" or "%s"', self::AVAILABLE_COLORS)
            );
        }

        if (!is_int($numberOfColumns) && $numberOfColumns !== 'max') {
            throw InvalidArgumentException::create(5, 'integer or "max"');
        }

        $console            = new Console;
        $maxNumberOfColumns = $console->getNumberOfColumns();

        if ($numberOfColumns === 'max' || ($numberOfColumns !== 80 && $numberOfColumns > $maxNumberOfColumns)) {
            $numberOfColumns = $maxNumberOfColumns;
        }

        $this->numberOfColumns = $numberOfColumns;
        $this->verbose         = $verbose;
        $this->debug           = $debug;
        $this->reverse         = $reverse;

        if ($colors === self::COLOR_AUTO && $console->hasColorSupport()) {
            $this->colors = true;
        } else {
            $this->colors = (self::COLOR_ALWAYS === $colors);
        }

        $this->timer = new Timer;

        $this->timer->start();
    }

    public function printResult(TestResult $result): void
    {
        if ($this->threadId !== -1) {
            $this->outputChannel->send([
                'type' => 'end',
            ]);

            $this->state = $this->prevChannel->recv();
        }

        $this->printHeader($result);
        $this->printErrors($result);
        $this->printWarnings($result);
        $this->printFailures($result);
        $this->printRisky($result);

        if ($this->verbose) {
            $this->printIncompletes($result);
            $this->printSkipped($result);
        }

        $this->printFooter($result);
    }

    /**
     * A deadlock occurred.
     */
    public function addDeadlock(Test $test, Throwable $t, float $time): void
    {
        $this->lastTestDeadlock = true;
        $this->writeProgressWithColor('fg-yellow, bold', 'D');
        $this->lastTestFailed = true;
    }

    /**
     * An error occurred.
     */
    public function addError(Test $test, Throwable $t, float $time): void
    {
        $this->writeProgressWithColor('fg-red, bold', 'E');
        $this->lastTestFailed = true;
    }

    /**
     * A failure occurred.
     */
    public function addFailure(Test $test, AssertionFailedError $e, float $time): void
    {
        $this->writeProgressWithColor('bg-red, fg-white', 'F');
        $this->lastTestFailed = true;
    }

    /**
     * A warning occurred.
     */
    public function addWarning(Test $test, Warning $e, float $time): void
    {
        $this->writeProgressWithColor('fg-yellow, bold', 'W');
        $this->lastTestFailed = true;
    }

    /**
     * Incomplete test.
     */
    public function addIncompleteTest(Test $test, Throwable $t, float $time): void
    {
        $this->writeProgressWithColor('fg-yellow, bold', 'I');
        $this->lastTestFailed = true;
    }

    /**
     * Risky test.
     */
    public function addRiskyTest(Test $test, Throwable $t, float $time): void
    {
        $this->writeProgressWithColor('fg-yellow, bold', 'R');
        $this->lastTestFailed = true;
    }

    /**
     * Skipped test.
     */
    public function addSkippedTest(Test $test, Throwable $t, float $time): void
    {
        $this->writeProgressWithColor('fg-cyan, bold', 'S');
        $this->lastTestFailed = true;
    }

    /**
     * A testsuite started.
     */
    public function startTestSuite(TestSuite $suite): void
    {
        if ($this->numTests == -1) {
            $this->numTests      = count($suite);
            $this->numTestsWidth = strlen((string) $this->numTests);
            $this->maxColumn     = $this->numberOfColumns - strlen('  /  (XXX%)') - (2 * $this->numTestsWidth);
        }
    }

    /**
     * A testsuite ended.
     */
    public function endTestSuite(TestSuite $suite): void
    {
    }

    /**
     * A test started.
     */
    public function startTest(Test $test): void
    {
        if ($this->debug) {
            $this->write(
                sprintf(
                    "Test '%s' started\n",
                    \PHPUnit\Util\Test::describeAsString($test)
                )
            );
        }
    }

    /**
     * A test ended.
     */
    public function endTest(Test $test, float $time): void
    {
        if ($this->debug) {
            $this->write(
                sprintf(
                    "Test '%s' ended\n",
                    \PHPUnit\Util\Test::describeAsString($test)
                )
            );
        }

        if (!$this->lastTestFailed) {
            $this->writeProgress('.');
        }

        if (!$this->lastTestDeadlock) {
            if ($test instanceof TestCase) {
                $this->numAssertions += $test->getNumAssertions();
            } elseif ($test instanceof PhptTestCase) {
                $this->numAssertions++;
            }
        }

        $this->lastTestFailed = false;
        $this->lastTestDeadlock = false;

        if ($test instanceof TestCase && !$test->hasExpectationOnOutput()) {
            $this->write($test->getActualOutput());
        }
    }

    protected function printDefects(array $defects, string $type): void
    {
        $count = count($defects);

        if ($this->threadId !== -1) {
            if ($this->state['type'] !== 'defect' || $this->state['defectType'] !== $type) {
                $this->state = [
                    'type' => 'defect',
                    'defectType' => $type,
                    'count' => 0,
                ];
            }

            $this->state['count'] += $count;
            $this->nextChannel->send($this->state);
            $this->state = $this->prevChannel->recv();

            $count = $this->state['count'];

            if ($count == 0) {
                $this->nextChannel->send($this->state);
                $this->state = $this->prevChannel->recv();

                return;
            }

            if (!isset($this->state['i'])) {
                if ($this->defectListPrinted) {
                    $this->write("\n--\n\n");
                }

                $this->write(
                    sprintf(
                        "There %s %d %s%s:\n",
                        ($count == 1) ? 'was' : 'were',
                        $count,
                        $type,
                        ($count == 1) ? '' : 's'
                    )
                );

                $this->state['i'] = 1;
            }

            $i = $this->state['i'];

            foreach ($defects as $defect) {
                $this->printDefect($defect, $i++);
            }

            $this->defectListPrinted = true;

            $this->state['i'] = $i;
            $this->nextChannel->send($this->state);
            $this->state = $this->prevChannel->recv();

            return;
        }

        if ($count == 0) {
            return;
        }

        if ($this->defectListPrinted) {
            $this->write("\n--\n\n");
        }

        $this->write(
            sprintf(
                "There %s %d %s%s:\n",
                ($count == 1) ? 'was' : 'were',
                $count,
                $type,
                ($count == 1) ? '' : 's'
            )
        );

        $i = 1;

        if ($this->reverse) {
            $defects = array_reverse($defects);
        }

        foreach ($defects as $defect) {
            $this->printDefect($defect, $i++);
        }

        $this->defectListPrinted = true;
    }

    protected function printDefect(TestFailure $defect, int $count): void
    {
        $this->printDefectHeader($defect, $count);
        $this->printDefectTrace($defect);
    }

    protected function printDefectHeader(TestFailure $defect, int $count): void
    {
        $this->write(
            sprintf(
                "\n%d) %s\n",
                $count,
                $defect->getTestName()
            )
        );
    }

    protected function printDefectTrace(TestFailure $defect): void
    {
        $e = $defect->thrownException();

        $this->write((string) $e);

        while ($e = $e->getPrevious()) {
            $this->write("\nCaused by\n" . $e);
        }
    }

    protected function printErrors(TestResult $result): void
    {
        $this->printDefects($result->errors(), 'error');
    }

    protected function printFailures(TestResult $result): void
    {
        $this->printDefects($result->failures(), 'failure');
    }

    protected function printWarnings(TestResult $result): void
    {
        $this->printDefects($result->warnings(), 'warning');
    }

    protected function printIncompletes(TestResult $result): void
    {
        $this->printDefects($result->notImplemented(), 'incomplete test');
    }

    protected function printRisky(TestResult $result): void
    {
        $this->printDefects($result->risky(), 'risky test');
    }

    protected function printSkipped(TestResult $result): void
    {
        $this->printDefects($result->skipped(), 'skipped test');
    }

    // ---------------------------
    // Copied from SebastianBergmann\Timer\ResourceUsageFormatter
    /**
     * @psalm-var array<string,int>
     */
    private const SIZES = [
        'GB' => 1073741824,
        'MB' => 1048576,
        'KB' => 1024,
    ];

    private function bytesToString(int $bytes): string
    {
        foreach (self::SIZES as $unit => $value) {
            if ($bytes >= $value) {
                return sprintf('%.2f %s', $bytes >= 1024 ? $bytes / $value : $bytes, $unit);
            }
        }

        return $bytes . ' byte' . ($bytes !== 1 ? 's' : '');
    }
    // ---------------------------

    protected function printHeader(TestResult $result): void
    {
        if ($this->threadId !== -1) {
            if ($this->state['type'] !== 'header') {
                $this->state = [
                    'type' => 'header',
                    'duration' => 0,
                    'memory' => 0,
                ];
            }
            $lastDuration = $this->state['duration'];
            $currentDuration = $this->timer->stop()->asNanoseconds();
            if ($currentDuration > $lastDuration) {
                $this->state['duration'] = $currentDuration;
            }
            $this->state['memory'] += memory_get_peak_usage(true);
            $this->nextChannel->send($this->state);

            $this->state = $this->prevChannel->recv();
            if ($this->state['type'] === 'header') {
                $this->write(PHP_EOL . PHP_EOL . sprintf(
                        'Time: %s, Memory: %s',
                        Duration::fromNanoseconds($this->state['duration'])->asString(),
                        $this->bytesToString($this->state['memory']),
                    ) . PHP_EOL . PHP_EOL);
            }

            return;
        }

        if (count($result) > 0) {
            $this->write(PHP_EOL . PHP_EOL . (new ResourceUsageFormatter)->resourceUsage($this->timer->stop()) . PHP_EOL . PHP_EOL);
        }
    }

    protected function printFooter(TestResult $result): void
    {
        if (-1 !== $this->threadId) {
            if ($this->state['type'] !== 'footer') {
                $this->state = [
                    'type' => 'footer',
                    'finished' => false,
                    'resultCount' => 0,
                    'numAssertions' => 0,
                    'wasSuccessfulAndNoTestIsRiskyOrSkippedOrIncomplete' => true,
                    'wasSuccessful' => true,
                    'allHarmless' => true,
                    'errorCount' => 0,
                    'failureCount' => 0,
                    'warningCount' => 0,
                    'skippedCount' => 0,
                    'notImplementedCount' => 0,
                    'riskyCount' => 0,
                ];
            }

            $this->state['resultCount'] += count($result);
            $this->state['numAssertions'] += $this->numAssertions;
            $this->state['wasSuccessfulAndNoTestIsRiskyOrSkippedOrIncomplete'] = $this->state['wasSuccessfulAndNoTestIsRiskyOrSkippedOrIncomplete'] && $result->wasSuccessfulAndNoTestIsRiskyOrSkippedOrIncomplete();
            $this->state['wasSuccessful'] = $this->state['wasSuccessful'] && $result->wasSuccessful();
            $this->state['allHarmless'] = $this->state['allHarmless'] && $result->allHarmless();
            $this->state['errorCount'] += $result->errorCount();
            $this->state['failureCount'] += $result->failureCount();
            $this->state['warningCount'] += $result->warningCount();
            $this->state['skippedCount'] += $result->skippedCount();
            $this->state['notImplementedCount'] += $result->notImplementedCount();
            $this->state['riskyCount'] += $result->riskyCount();
            $this->nextChannel->send($this->state);
            $this->state = $this->prevChannel->recv();

            if ($this->state['type'] !== 'footer') {
                return;
            }

            if ($this->state['finished']) {
                $this->nextChannel->send($this->state);
                return;
            }

            if ($this->state['resultCount'] === 0) {
                $this->writeWithColor(
                    'fg-black, bg-yellow',
                    'No tests executed!'
                );

                $this->state['finished'] = true;
                $this->nextChannel->send($this->state);
                return;
            }

            if ($this->state['wasSuccessfulAndNoTestIsRiskyOrSkippedOrIncomplete']) {
                $this->writeWithColor(
                    'fg-black, bg-green',
                    sprintf(
                        'OK (%d test%s, %d assertion%s)',
                        $this->state['resultCount'],
                        ($this->state['resultCount'] === 1) ? '' : 's',
                        $this->state['numAssertions'],
                        ($this->state['numAssertions'] === 1) ? '' : 's'
                    )
                );

                $this->state['finished'] = true;
                $this->nextChannel->send($this->state);
                return;
            }

            $color = 'fg-black, bg-yellow';

            if ($this->state['wasSuccessful']) {
                if ($this->verbose || !$this->state['allHarmless']) {
                    $this->write("\n");
                }

                $this->writeWithColor(
                    $color,
                    'OK, but incomplete, skipped, or risky tests!'
                );
            } else {
                $this->write("\n");

                if ($this->state['errorCount']) {
                    $color = 'fg-white, bg-red';

                    $this->writeWithColor(
                        $color,
                        'ERRORS!'
                    );
                } elseif ($this->state['failureCount']) {
                    $color = 'fg-white, bg-red';

                    $this->writeWithColor(
                        $color,
                        'FAILURES!'
                    );
                } elseif ($this->state['warningCount']) {
                    $color = 'fg-black, bg-yellow';

                    $this->writeWithColor(
                        $color,
                        'WARNINGS!'
                    );
                }
            }

            $this->writeCountString($this->state['resultCount'], 'Tests', $color, true);
            $this->writeCountString($this->state['numAssertions'], 'Assertions', $color, true);
            $this->writeCountString($this->state['errorCount'], 'Errors', $color);
            $this->writeCountString($this->state['failureCount'], 'Failures', $color);
            $this->writeCountString($this->state['warningCount'], 'Warnings', $color);
            $this->writeCountString($this->state['skippedCount'], 'Skipped', $color);
            $this->writeCountString($this->state['notImplementedCount'], 'Incomplete', $color);
            $this->writeCountString($this->state['riskyCount'], 'Risky', $color);
            $this->writeWithColor($color, '.');

            $this->state['finished'] = true;
            $this->nextChannel->send($this->state);
            return;
        }

        if (count($result) === 0) {
            $this->writeWithColor(
                'fg-black, bg-yellow',
                'No tests executed!'
            );

            return;
        }

        if ($result->wasSuccessfulAndNoTestIsRiskyOrSkippedOrIncomplete()) {
            $this->writeWithColor(
                'fg-black, bg-green',
                sprintf(
                    'OK (%d test%s, %d assertion%s)',
                    count($result),
                    (count($result) === 1) ? '' : 's',
                    $this->numAssertions,
                    ($this->numAssertions === 1) ? '' : 's'
                )
            );

            return;
        }

        $color = 'fg-black, bg-yellow';

        if ($result->wasSuccessful()) {
            if ($this->verbose || !$result->allHarmless()) {
                $this->write("\n");
            }

            $this->writeWithColor(
                $color,
                'OK, but incomplete, skipped, or risky tests!'
            );
        } else {
            $this->write("\n");

            if ($result->errorCount()) {
                $color = 'fg-white, bg-red';

                $this->writeWithColor(
                    $color,
                    'ERRORS!'
                );
            } elseif ($result->failureCount()) {
                $color = 'fg-white, bg-red';

                $this->writeWithColor(
                    $color,
                    'FAILURES!'
                );
            } elseif ($result->warningCount()) {
                $color = 'fg-black, bg-yellow';

                $this->writeWithColor(
                    $color,
                    'WARNINGS!'
                );
            }
        }

        $this->writeCountString(count($result), 'Tests', $color, true);
        $this->writeCountString($this->numAssertions, 'Assertions', $color, true);
        $this->writeCountString($result->errorCount(), 'Errors', $color);
        $this->writeCountString($result->failureCount(), 'Failures', $color);
        $this->writeCountString($result->warningCount(), 'Warnings', $color);
        $this->writeCountString($result->skippedCount(), 'Skipped', $color);
        $this->writeCountString($result->notImplementedCount(), 'Incomplete', $color);
        $this->writeCountString($result->riskyCount(), 'Risky', $color);
        $this->writeWithColor($color, '.');
    }

    public function writeProgress(string $progress, bool $lastTestDeadlock = false): void
    {
        if ($this->threadId !== -1) {
            $this->outputChannel->send([
                'type' => 'progress',
                'progress' => $progress,
                'testIndex' => $this->lastTestDeadlock ? $this->getCurrentTestIndex() : -1,
            ]);
            return;
        }

        if ($this->debug) {
            return;
        }

        $this->write($progress);
        $this->column++;
        if (!$this->lastTestDeadlock && !$lastTestDeadlock) {
            $this->numTestsRun++;
        }

        if ($this->column == $this->maxColumn || $this->numTestsRun == $this->numTests) {
            if ($this->numTestsRun == $this->numTests) {
                $this->write(str_repeat(' ', $this->maxColumn - $this->column));
            }

            $this->write(
                sprintf(
                    ' %' . $this->numTestsWidth . 'd / %' .
                    $this->numTestsWidth . 'd (%3s%%)',
                    $this->numTestsRun,
                    $this->numTests,
                    floor(($this->numTestsRun / $this->numTests) * 100)
                )
            );

            if ($this->column == $this->maxColumn) {
                $this->writeNewLine();
            }
        }
    }

    protected function writeNewLine(): void
    {
        $this->column = 0;
        $this->write("\n");
    }

    /**
     * Formats a buffer with a specified ANSI color sequence if colors are
     * enabled.
     */
    protected function colorizeTextBox(string $color, string $buffer): string
    {
        if (!$this->colors) {
            return $buffer;
        }

        $lines   = preg_split('/\r\n|\r|\n/', $buffer);
        $padding = max(array_map('\strlen', $lines));

        $styledLines = [];

        foreach ($lines as $line) {
            $styledLines[] = Color::colorize($color, str_pad($line, $padding));
        }

        return implode(PHP_EOL, $styledLines);
    }

    /**
     * Writes a buffer out with a color sequence if colors are enabled.
     */
    protected function writeWithColor(string $color, string $buffer, bool $lf = true): void
    {
        $this->write($this->colorizeTextBox($color, $buffer));

        if ($lf) {
            $this->write(PHP_EOL);
        }
    }

    /**
     * Writes progress with a color sequence if colors are enabled.
     */
    protected function writeProgressWithColor(string $color, string $buffer): void
    {
        $buffer = $this->colorizeTextBox($color, $buffer);
        $this->writeProgress($buffer);
    }

    private function writeCountString(int $count, string $name, string $color, bool $always = false): void
    {
        static $first = true;

        if ($always || $count > 0) {
            $this->writeWithColor(
                $color,
                sprintf(
                    '%s%s: %d',
                    !$first ? ', ' : '',
                    $name,
                    $count
                ),
                false
            );

            $first = false;
        }
    }

    public function getCurrentTestIndex(): int
    {
        return $this->currentTestIndex;
    }

    public function setCurrentTestIndex(int $currentTestIndex): void
    {
        $this->currentTestIndex = $currentTestIndex;
    }
}
