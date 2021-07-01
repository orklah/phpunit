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

use const DIRECTORY_SEPARATOR;
use function assert;
use function count;
use function defined;
use function dirname;
use function is_dir;
use function is_file;
use function is_int;
use function is_readable;
use function realpath;
use function substr;
use PHPUnit\Event\Facade;
use PHPUnit\Framework\TestSuite;
use PHPUnit\Runner\TestSuiteLoader;
use PHPUnit\TextUI\CliArguments\Configuration as CliConfiguration;
use PHPUnit\TextUI\XmlConfiguration\CodeCoverage\FilterMapper;
use PHPUnit\TextUI\XmlConfiguration\Configuration as XmlConfiguration;
use PHPUnit\TextUI\XmlConfiguration\LoadedFromFileConfiguration;
use PHPUnit\Util\Filesystem;
use SebastianBergmann\CodeCoverage\Filter as CodeCoverageFilter;
use SebastianBergmann\FileIterator\Facade as FileIteratorFacade;
use Throwable;

/**
 * CLI options and XML configuration are static within a single PHPUnit process.
 * It is therefore okay to use a Singleton registry here.
 *
 * @internal This class is not covered by the backward compatibility promise for PHPUnit
 */
final class Configuration
{
    private static ?Configuration $instance = null;

    private ?TestSuite $testSuite;

    private ?string $configurationFile;

    private ?string $bootstrap;

    private bool $cacheResult;

    private ?string $cacheDirectory;

    private ?string $coverageCacheDirectory;

    private bool $pathCoverage;

    private ?string $coverageClover;

    private ?string $coverageCobertura;

    private ?string $coverageCrap4j;

    private int $coverageCrap4jThreshold;

    private ?string $coverageHtml;

    private int $coverageHtmlLowUpperBound;

    private int $coverageHtmlHighLowerBound;

    private ?string $coveragePhp;

    private ?string $coverageText;

    private bool $coverageTextShowUncoveredFiles;

    private bool $coverageTextShowOnlySummary;

    private ?string $coverageXml;

    private string $testResultCacheFile;

    private CodeCoverageFilter $codeCoverageFilter;

    private bool $ignoreDeprecatedCodeUnitsFromCodeCoverage;

    private bool $disableCodeCoverageIgnore;

    private bool $failOnEmptyTestSuite;

    private bool $failOnIncomplete;

    private bool $failOnRisky;

    private bool $failOnSkipped;

    private bool $failOnWarning;

    private bool $outputToStandardErrorStream;

    private int|string $columns;

    private bool $tooFewColumnsRequested;

    private bool $loadPharExtensions;

    private ?string $pharExtensionDirectory;

    private bool $debug;

    /**
     * @psalm-var list<string>
     */
    private array $warnings;

    public static function get(): self
    {
        assert(self::$instance instanceof self);

        return self::$instance;
    }

    /**
     * @throws TestFileNotFoundException
     */
    public static function init(CliConfiguration $cliConfiguration, XmlConfiguration $xmlConfiguration): self
    {
        $warnings = [];

        $bootstrap = null;

        $configurationFile = null;

        if ($xmlConfiguration->wasLoadedFromFile()) {
            assert($xmlConfiguration instanceof LoadedFromFileConfiguration);

            $configurationFile = $xmlConfiguration->filename();
        }

        if ($cliConfiguration->hasBootstrap()) {
            $bootstrap = $cliConfiguration->bootstrap();
        } elseif ($xmlConfiguration->phpunit()->hasBootstrap()) {
            $bootstrap = $xmlConfiguration->phpunit()->bootstrap();
        }

        if ($bootstrap !== null) {
            self::handleBootstrap($bootstrap);
        }

        if ($cliConfiguration->hasArgument()) {
            $argument = realpath($cliConfiguration->argument());

            if (!$argument) {
                throw new TestFileNotFoundException($cliConfiguration->argument());
            }

            $testSuite = self::testSuiteFromPath(
                $argument,
                self::testSuffixes($cliConfiguration)
            );
        } else {
            $includeTestSuite = '';

            if ($cliConfiguration->hasTestSuite()) {
                $includeTestSuite = $cliConfiguration->testSuite();
            } elseif ($xmlConfiguration->phpunit()->hasDefaultTestSuite()) {
                $includeTestSuite = $xmlConfiguration->phpunit()->defaultTestSuite();
            }

            $testSuite = (new TestSuiteMapper)->map(
                $xmlConfiguration->testSuite(),
                $includeTestSuite,
                $cliConfiguration->hasExcludedTestSuite() ? $cliConfiguration->excludedTestSuite() : ''
            );
        }

        if ($cliConfiguration->hasCacheResult()) {
            $cacheResult = $cliConfiguration->cacheResult();
        } else {
            $cacheResult = $xmlConfiguration->phpunit()->cacheResult();
        }

        $cacheDirectory         = null;
        $coverageCacheDirectory = null;

        if ($cliConfiguration->hasCacheDirectory() && Filesystem::createDirectory($cliConfiguration->cacheDirectory())) {
            $cacheDirectory = realpath($cliConfiguration->cacheDirectory());
        } elseif ($xmlConfiguration->phpunit()->hasCacheDirectory() && Filesystem::createDirectory($xmlConfiguration->phpunit()->cacheDirectory())) {
            $cacheDirectory = realpath($xmlConfiguration->phpunit()->cacheDirectory());
        }

        if ($cacheDirectory !== null) {
            $coverageCacheDirectory = $cacheDirectory . DIRECTORY_SEPARATOR . 'code-coverage';
            $testResultCacheFile    = $cacheDirectory . DIRECTORY_SEPARATOR . 'test-results';
        }

        if ($coverageCacheDirectory === null) {
            if ($cliConfiguration->hasCoverageCacheDirectory() && Filesystem::createDirectory($cliConfiguration->coverageCacheDirectory())) {
                $coverageCacheDirectory = realpath($cliConfiguration->coverageCacheDirectory());
            } elseif ($xmlConfiguration->codeCoverage()->hasCacheDirectory()) {
                $coverageCacheDirectory = $xmlConfiguration->codeCoverage()->cacheDirectory()->path();
            }
        }

        if (!isset($testResultCacheFile)) {
            if ($cliConfiguration->hasCacheResultFile()) {
                $testResultCacheFile = $cliConfiguration->cacheResultFile();
            } elseif ($xmlConfiguration->phpunit()->hasCacheResultFile()) {
                $testResultCacheFile = $xmlConfiguration->phpunit()->cacheResultFile();
            } elseif ($xmlConfiguration->wasLoadedFromFile()) {
                $testResultCacheFile = dirname(realpath($xmlConfiguration->filename())) . DIRECTORY_SEPARATOR . '.phpunit.result.cache';
            } else {
                $candidate = realpath($_SERVER['PHP_SELF']);

                if ($candidate) {
                    $testResultCacheFile = dirname($candidate) . DIRECTORY_SEPARATOR . '.phpunit.result.cache';
                } else {
                    $testResultCacheFile = '.phpunit.result.cache';
                }
            }
        }

        $codeCoverageFilter = new CodeCoverageFilter;

        if ($cliConfiguration->hasCoverageFilter()) {
            foreach ($cliConfiguration->coverageFilter() as $directory) {
                $codeCoverageFilter->includeDirectory($directory);
            }
        }

        if ($xmlConfiguration->codeCoverage()->hasNonEmptyListOfFilesToBeIncludedInCodeCoverageReport()) {
            (new FilterMapper)->map(
                $codeCoverageFilter,
                $xmlConfiguration->codeCoverage()
            );
        }

        if ($cliConfiguration->hasDisableCodeCoverageIgnore()) {
            $disableCodeCoverageIgnore = $cliConfiguration->disableCodeCoverageIgnore();
        } else {
            $disableCodeCoverageIgnore = $xmlConfiguration->codeCoverage()->disableCodeCoverageIgnore();
        }

        if ($cliConfiguration->hasFailOnEmptyTestSuite()) {
            $failOnEmptyTestSuite = $cliConfiguration->failOnEmptyTestSuite();
        } else {
            $failOnEmptyTestSuite = $xmlConfiguration->phpunit()->failOnEmptyTestSuite();
        }

        if ($cliConfiguration->hasFailOnIncomplete()) {
            $failOnIncomplete = $cliConfiguration->failOnIncomplete();
        } else {
            $failOnIncomplete = $xmlConfiguration->phpunit()->failOnIncomplete();
        }

        if ($cliConfiguration->hasFailOnRisky()) {
            $failOnRisky = $cliConfiguration->failOnRisky();
        } else {
            $failOnRisky = $xmlConfiguration->phpunit()->failOnRisky();
        }

        if ($cliConfiguration->hasFailOnSkipped()) {
            $failOnSkipped = $cliConfiguration->failOnSkipped();
        } else {
            $failOnSkipped = $xmlConfiguration->phpunit()->failOnSkipped();
        }

        if ($cliConfiguration->hasFailOnWarning()) {
            $failOnWarning = $cliConfiguration->failOnWarning();
        } else {
            $failOnWarning = $xmlConfiguration->phpunit()->failOnWarning();
        }

        if ($cliConfiguration->hasStderr() && $cliConfiguration->stderr()) {
            $outputToStandardErrorStream = true;
        } else {
            $outputToStandardErrorStream = $xmlConfiguration->phpunit()->stderr();
        }

        $tooFewColumnsRequested = false;

        if ($cliConfiguration->hasColumns()) {
            $columns = $cliConfiguration->columns();
        } else {
            $columns = $xmlConfiguration->phpunit()->columns();
        }

        if (is_int($columns) && $columns < 16) {
            $columns                = 16;
            $tooFewColumnsRequested = true;
        }

        $loadPharExtensions = true;

        if ($cliConfiguration->hasNoExtensions() && $cliConfiguration->noExtensions()) {
            $loadPharExtensions = false;
        }

        $pharExtensionDirectory = null;

        if ($xmlConfiguration->phpunit()->hasExtensionsDirectory()) {
            $pharExtensionDirectory = $xmlConfiguration->phpunit()->extensionsDirectory();
        }

        if ($cliConfiguration->hasPathCoverage() && $cliConfiguration->pathCoverage()) {
            $pathCoverage = $cliConfiguration->pathCoverage();
        } else {
            $pathCoverage = $xmlConfiguration->codeCoverage()->pathCoverage();
        }

        $debug = false;

        if ($cliConfiguration->hasDebug() && $cliConfiguration->debug()) {
            $debug = true;

            if (!defined('PHPUNIT_TESTSUITE')) {
                $warnings[] = 'The --debug option is deprecated';
            }
        }

        $coverageClover                 = null;
        $coverageCobertura              = null;
        $coverageCrap4j                 = null;
        $coverageCrap4jThreshold        = 30;
        $coverageHtml                   = null;
        $coverageHtmlLowUpperBound      = 50;
        $coverageHtmlHighLowerBound     = 90;
        $coveragePhp                    = null;
        $coverageText                   = null;
        $coverageTextShowUncoveredFiles = false;
        $coverageTextShowOnlySummary    = false;
        $coverageXml                    = null;

        if (!($cliConfiguration->hasNoCoverage() && $cliConfiguration->noCoverage())) {
            if ($cliConfiguration->hasCoverageClover()) {
                $coverageClover = $cliConfiguration->coverageClover();
            } elseif ($xmlConfiguration->codeCoverage()->hasClover()) {
                $coverageClover = $xmlConfiguration->codeCoverage()->clover()->target()->path();
            }

            if ($cliConfiguration->hasCoverageCobertura()) {
                $coverageCobertura = $cliConfiguration->coverageCobertura();
            } elseif ($xmlConfiguration->codeCoverage()->hasCobertura()) {
                $coverageCobertura = $xmlConfiguration->codeCoverage()->cobertura()->target()->path();
            }

            if ($xmlConfiguration->codeCoverage()->hasCrap4j()) {
                $coverageCrap4jThreshold = $xmlConfiguration->codeCoverage()->crap4j()->threshold();
            }

            if ($cliConfiguration->hasCoverageCrap4J()) {
                $coverageCrap4j = $cliConfiguration->coverageCrap4J();
            } elseif ($xmlConfiguration->codeCoverage()->hasCrap4j()) {
                $coverageCrap4j = $xmlConfiguration->codeCoverage()->crap4j()->target()->path();
            }

            if ($xmlConfiguration->codeCoverage()->hasHtml()) {
                $coverageHtmlHighLowerBound = $xmlConfiguration->codeCoverage()->html()->highLowerBound();
                $coverageHtmlLowUpperBound  = $xmlConfiguration->codeCoverage()->html()->lowUpperBound();
            }

            if ($cliConfiguration->hasCoverageHtml()) {
                $coverageHtml = $cliConfiguration->coverageHtml();
            } elseif ($xmlConfiguration->codeCoverage()->hasHtml()) {
                $coverageHtml = $xmlConfiguration->codeCoverage()->html()->target()->path();
            }

            if ($cliConfiguration->hasCoveragePhp()) {
                $coveragePhp = $cliConfiguration->coveragePhp();
            } elseif ($xmlConfiguration->codeCoverage()->hasPhp()) {
                $coveragePhp = $xmlConfiguration->codeCoverage()->php()->target()->path();
            }

            if ($xmlConfiguration->codeCoverage()->hasText()) {
                $coverageTextShowUncoveredFiles = $xmlConfiguration->codeCoverage()->text()->showUncoveredFiles();
                $coverageTextShowOnlySummary    = $xmlConfiguration->codeCoverage()->text()->showOnlySummary();
            }

            if ($cliConfiguration->hasCoverageText()) {
                $coverageText = $cliConfiguration->coverageText();
            } elseif ($xmlConfiguration->codeCoverage()->hasText()) {
                $coverageText = $xmlConfiguration->codeCoverage()->text()->target()->path();
            }

            if ($cliConfiguration->hasCoverageXml()) {
                $coverageXml = $cliConfiguration->coverageXml();
            } elseif ($xmlConfiguration->codeCoverage()->hasXml()) {
                $coverageXml = $xmlConfiguration->codeCoverage()->xml()->target()->path();
            }
        }

        self::$instance = new self(
            $testSuite,
            $configurationFile,
            $bootstrap,
            $cacheResult,
            $cacheDirectory,
            $coverageCacheDirectory,
            $testResultCacheFile,
            $codeCoverageFilter,
            $coverageClover,
            $coverageCobertura,
            $coverageCrap4j,
            $coverageCrap4jThreshold,
            $coverageHtml,
            $coverageHtmlLowUpperBound,
            $coverageHtmlHighLowerBound,
            $coveragePhp,
            $coverageText,
            $coverageTextShowUncoveredFiles,
            $coverageTextShowOnlySummary,
            $coverageXml,
            $pathCoverage,
            $xmlConfiguration->codeCoverage()->ignoreDeprecatedCodeUnits(),
            $disableCodeCoverageIgnore,
            $failOnEmptyTestSuite,
            $failOnIncomplete,
            $failOnRisky,
            $failOnSkipped,
            $failOnWarning,
            $outputToStandardErrorStream,
            $columns,
            $tooFewColumnsRequested,
            $loadPharExtensions,
            $pharExtensionDirectory,
            $debug,
            $warnings
        );

        return self::$instance;
    }

    private function __construct(?TestSuite $testSuite, ?string $configurationFile, ?string $bootstrap, bool $cacheResult, ?string $cacheDirectory, ?string $coverageCacheDirectory, string $testResultCacheFile, CodeCoverageFilter $codeCoverageFilter, ?string $coverageClover, ?string $coverageCobertura, ?string $coverageCrap4j, int $coverageCrap4jThreshold, ?string $coverageHtml, int $coverageHtmlLowUpperBound, int $coverageHtmlHighLowerBound, ?string $coveragePhp, ?string $coverageText, bool $coverageTextShowUncoveredFiles, bool $coverageTextShowOnlySummary, ?string $coverageXml, bool $pathCoverage, bool $ignoreDeprecatedCodeUnitsFromCodeCoverage, bool $disableCodeCoverageIgnore, bool $failOnEmptyTestSuite, bool $failOnIncomplete, bool $failOnRisky, bool $failOnSkipped, bool $failOnWarning, bool $outputToStandardErrorStream, int|string $columns, bool $tooFewColumnsRequested, bool $loadPharExtensions, ?string $pharExtensionDirectory, bool $debug, array $warnings)
    {
        $this->testSuite                                 = $testSuite;
        $this->configurationFile                         = $configurationFile;
        $this->bootstrap                                 = $bootstrap;
        $this->cacheResult                               = $cacheResult;
        $this->cacheDirectory                            = $cacheDirectory;
        $this->coverageCacheDirectory                    = $coverageCacheDirectory;
        $this->testResultCacheFile                       = $testResultCacheFile;
        $this->codeCoverageFilter                        = $codeCoverageFilter;
        $this->coverageClover                            = $coverageClover;
        $this->coverageCobertura                         = $coverageCobertura;
        $this->coverageCrap4j                            = $coverageCrap4j;
        $this->coverageCrap4jThreshold                   = $coverageCrap4jThreshold;
        $this->coverageHtml                              = $coverageHtml;
        $this->coverageHtmlLowUpperBound                 = $coverageHtmlLowUpperBound;
        $this->coverageHtmlHighLowerBound                = $coverageHtmlHighLowerBound;
        $this->coveragePhp                               = $coveragePhp;
        $this->coverageText                              = $coverageText;
        $this->coverageTextShowUncoveredFiles            = $coverageTextShowUncoveredFiles;
        $this->coverageTextShowOnlySummary               = $coverageTextShowOnlySummary;
        $this->coverageXml                               = $coverageXml;
        $this->pathCoverage                              = $pathCoverage;
        $this->ignoreDeprecatedCodeUnitsFromCodeCoverage = $ignoreDeprecatedCodeUnitsFromCodeCoverage;
        $this->disableCodeCoverageIgnore                 = $disableCodeCoverageIgnore;
        $this->failOnEmptyTestSuite                      = $failOnEmptyTestSuite;
        $this->failOnIncomplete                          = $failOnIncomplete;
        $this->failOnRisky                               = $failOnRisky;
        $this->failOnSkipped                             = $failOnSkipped;
        $this->failOnWarning                             = $failOnWarning;
        $this->outputToStandardErrorStream               = $outputToStandardErrorStream;
        $this->columns                                   = $columns;
        $this->tooFewColumnsRequested                    = $tooFewColumnsRequested;
        $this->loadPharExtensions                        = $loadPharExtensions;
        $this->pharExtensionDirectory                    = $pharExtensionDirectory;
        $this->debug                                     = $debug;
        $this->warnings                                  = $warnings;
    }

    /**
     * @psalm-assert-if-true !null $this->testSuite
     */
    public function hasTestSuite(): bool
    {
        return $this->testSuite !== null && !$this->testSuite()->isEmpty();
    }

    /**
     * @throws NoTestSuiteException
     */
    public function testSuite(): TestSuite
    {
        if ($this->testSuite === null) {
            throw new NoTestSuiteException;
        }

        return $this->testSuite;
    }

    /**
     * @psalm-assert-if-true !null $this->configurationFile
     */
    public function hasConfigurationFile(): bool
    {
        return $this->configurationFile !== null;
    }

    /**
     * @throws NoConfigurationFileException
     */
    public function configurationFile(): string
    {
        if (!$this->hasConfigurationFile()) {
            throw new NoConfigurationFileException;
        }

        return $this->configurationFile;
    }

    /**
     * @psalm-assert-if-true !null $this->bootstrap
     */
    public function hasBootstrap(): bool
    {
        return $this->bootstrap !== null;
    }

    /**
     * @throws NoBootstrapException
     */
    public function bootstrap(): string
    {
        if (!$this->hasBootstrap()) {
            throw new NoBootstrapException;
        }

        return $this->bootstrap;
    }

    public function cacheResult(): bool
    {
        return $this->cacheResult;
    }

    /**
     * @psalm-assert-if-true !null $this->cacheDirectory
     */
    public function hasCacheDirectory(): bool
    {
        return $this->cacheDirectory !== null;
    }

    /**
     * @throws NoCacheDirectoryException
     */
    public function cacheDirectory(): string
    {
        if (!$this->hasCacheDirectory()) {
            throw new NoCacheDirectoryException;
        }

        return $this->cacheDirectory;
    }

    /**
     * @psalm-assert-if-true !null $this->coverageCacheDirectory
     */
    public function hasCoverageCacheDirectory(): bool
    {
        return $this->coverageCacheDirectory !== null;
    }

    /**
     * @throws NoCoverageCacheDirectoryException
     */
    public function coverageCacheDirectory(): string
    {
        if (!$this->hasCoverageCacheDirectory()) {
            throw new NoCoverageCacheDirectoryException;
        }

        return $this->coverageCacheDirectory;
    }

    public function testResultCacheFile(): string
    {
        return $this->testResultCacheFile;
    }

    public function codeCoverageFilter(): CodeCoverageFilter
    {
        return $this->codeCoverageFilter;
    }

    public function ignoreDeprecatedCodeUnitsFromCodeCoverage(): bool
    {
        return $this->ignoreDeprecatedCodeUnitsFromCodeCoverage;
    }

    public function disableCodeCoverageIgnore(): bool
    {
        return $this->disableCodeCoverageIgnore;
    }

    public function pathCoverage(): bool
    {
        return $this->pathCoverage;
    }

    public function hasCoverageReport(): bool
    {
        return $this->hasCoverageClover() ||
               $this->hasCoverageCobertura() ||
               $this->hasCoverageCrap4j() ||
               $this->hasCoverageHtml() ||
               $this->hasCoverageText() ||
               $this->hasCoverageXml();
    }

    /**
     * @psalm-assert-if-true !null $this->coverageClover
     */
    public function hasCoverageClover(): bool
    {
        return $this->coverageClover !== null;
    }

    /**
     * @throws CodeCoverageReportNotConfiguredException
     */
    public function coverageClover(): string
    {
        if (!$this->hasCoverageClover()) {
            throw new CodeCoverageReportNotConfiguredException;
        }

        return $this->coverageClover;
    }

    /**
     * @psalm-assert-if-true !null $this->coverageCobertura
     */
    public function hasCoverageCobertura(): bool
    {
        return $this->coverageCobertura !== null;
    }

    /**
     * @throws CodeCoverageReportNotConfiguredException
     */
    public function coverageCobertura(): string
    {
        if (!$this->hasCoverageCobertura()) {
            throw new CodeCoverageReportNotConfiguredException;
        }

        return $this->coverageCobertura;
    }

    /**
     * @psalm-assert-if-true !null $this->coverageCrap4j
     */
    public function hasCoverageCrap4j(): bool
    {
        return $this->coverageCrap4j !== null;
    }

    /**
     * @throws CodeCoverageReportNotConfiguredException
     */
    public function coverageCrap4j(): string
    {
        if (!$this->hasCoverageCrap4j()) {
            throw new CodeCoverageReportNotConfiguredException;
        }

        return $this->coverageCrap4j;
    }

    public function coverageCrap4jThreshold(): int
    {
        return $this->coverageCrap4jThreshold;
    }

    /**
     * @psalm-assert-if-true !null $this->coverageHtml
     */
    public function hasCoverageHtml(): bool
    {
        return $this->coverageHtml !== null;
    }

    /**
     * @throws CodeCoverageReportNotConfiguredException
     */
    public function coverageHtml(): string
    {
        if (!$this->hasCoverageHtml()) {
            throw new CodeCoverageReportNotConfiguredException;
        }

        return $this->coverageHtml;
    }

    public function coverageHtmlLowUpperBound(): int
    {
        return $this->coverageHtmlLowUpperBound;
    }

    public function coverageHtmlHighLowerBound(): int
    {
        return $this->coverageHtmlHighLowerBound;
    }

    /**
     * @psalm-assert-if-true !null $this->coveragePhp
     */
    public function hasCoveragePhp(): bool
    {
        return $this->coveragePhp !== null;
    }

    /**
     * @throws CodeCoverageReportNotConfiguredException
     */
    public function coveragePhp(): string
    {
        if (!$this->hasCoveragePhp()) {
            throw new CodeCoverageReportNotConfiguredException;
        }

        return $this->coveragePhp;
    }

    /**
     * @psalm-assert-if-true !null $this->coverageText
     */
    public function hasCoverageText(): bool
    {
        return $this->coverageText !== null;
    }

    /**
     * @throws CodeCoverageReportNotConfiguredException
     */
    public function coverageText(): string
    {
        if (!$this->hasCoverageText()) {
            throw new CodeCoverageReportNotConfiguredException;
        }

        return $this->coverageText;
    }

    public function coverageTextShowUncoveredFiles(): bool
    {
        return $this->coverageTextShowUncoveredFiles;
    }

    public function coverageTextShowOnlySummary(): bool
    {
        return $this->coverageTextShowOnlySummary;
    }

    /**
     * @psalm-assert-if-true !null $this->coverageXml
     */
    public function hasCoverageXml(): bool
    {
        return $this->coverageXml !== null;
    }

    /**
     * @throws CodeCoverageReportNotConfiguredException
     */
    public function coverageXml(): string
    {
        if (!$this->hasCoverageXml()) {
            throw new CodeCoverageReportNotConfiguredException;
        }

        return $this->coverageXml;
    }

    public function failOnEmptyTestSuite(): bool
    {
        return $this->failOnEmptyTestSuite;
    }

    public function failOnIncomplete(): bool
    {
        return $this->failOnIncomplete;
    }

    public function failOnRisky(): bool
    {
        return $this->failOnRisky;
    }

    public function failOnSkipped(): bool
    {
        return $this->failOnSkipped;
    }

    public function failOnWarning(): bool
    {
        return $this->failOnWarning;
    }

    public function outputToStandardErrorStream(): bool
    {
        return $this->outputToStandardErrorStream;
    }

    public function columns(): int|string
    {
        return $this->columns;
    }

    public function tooFewColumnsRequested(): bool
    {
        return $this->tooFewColumnsRequested;
    }

    public function loadPharExtensions(): bool
    {
        return $this->loadPharExtensions;
    }

    /**
     * @psalm-assert-if-true !null $this->pharExtensionDirectory
     */
    public function hasPharExtensionDirectory(): bool
    {
        return $this->pharExtensionDirectory !== null;
    }

    /**
     * @throws NoPharExtensionDirectoryException
     */
    public function pharExtensionDirectory(): string
    {
        if (!$this->hasPharExtensionDirectory()) {
            throw new NoPharExtensionDirectoryException;
        }

        return $this->pharExtensionDirectory;
    }

    public function debug(): bool
    {
        return $this->debug;
    }

    public function hasWarnings(): bool
    {
        return count($this->warnings) > 0;
    }

    /**
     * @psalm-return list<string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * @psalm-param list<string> $suffixes
     */
    private static function testSuiteFromPath(string $path, array $suffixes): TestSuite
    {
        if (is_dir($path)) {
            $files = (new FileIteratorFacade)->getFilesAsArray($path, $suffixes);

            $suite = new TestSuite($path);
            $suite->addTestFiles($files);

            return $suite;
        }

        if (is_file($path) && substr($path, -5, 5) === '.phpt') {
            $suite = new TestSuite;
            $suite->addTestFile($path);

            return $suite;
        }

        try {
            $testClass = (new TestSuiteLoader)->load($path);
        } catch (\PHPUnit\Exception $e) {
            print $e->getMessage() . PHP_EOL;

            exit(1);
        }

        return new TestSuite($testClass);
    }

    private static function testSuffixes(CliConfiguration $cliConfiguration): array
    {
        $testSuffixes = ['Test.php', '.phpt'];

        if ($cliConfiguration->hasTestSuffixes()) {
            $testSuffixes = $cliConfiguration->testSuffixes();
        }

        return $testSuffixes;
    }

    /**
     * @throws InvalidBootstrapException
     */
    private static function handleBootstrap(string $filename): void
    {
        if (!is_readable($filename)) {
            throw new InvalidBootstrapException($filename);
        }

        try {
            include $filename;
        } catch (Throwable $t) {
            throw new BootstrapException($t);
        }

        Facade::emitter()->bootstrapFinished($filename);
    }
}