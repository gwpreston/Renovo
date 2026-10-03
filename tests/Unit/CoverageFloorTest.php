<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Support\CoverageFloor;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The coverage floor's own test: that it reads the project total, that the
 * boundary is inclusive, and that a report which measured nothing fails
 * rather than passing as if everything were covered.
 */
final class CoverageFloorTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/renovo-clover-' . bin2hex(random_bytes(6)) . '.xml';
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    public function testReadsTheProjectTotalNotAFileTotal(): void
    {
        $this->write(<<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <coverage generated="1">
              <project timestamp="1">
                <file name="/app/src/A.php">
                  <metrics loc="10" statements="10" coveredstatements="1"/>
                </file>
                <metrics files="1" statements="200" coveredstatements="180"/>
              </project>
            </coverage>
            XML);

        $floor = CoverageFloor::fromCloverFile($this->path);

        self::assertSame(200, $floor->statements);
        self::assertSame(180, $floor->covered);
        self::assertEqualsWithDelta(90.0, $floor->percent(), 0.001);
    }

    public function testExactlyTheFloorPasses(): void
    {
        $this->writeTotals(200, 170);

        self::assertTrue(CoverageFloor::fromCloverFile($this->path)->meets(85));
    }

    public function testOneStatementBelowTheFloorFails(): void
    {
        $this->writeTotals(200, 169);

        self::assertFalse(CoverageFloor::fromCloverFile($this->path)->meets(85));
    }

    public function testJustUnderTheFloorIsNotRoundedUp(): void
    {
        // 84.9993%: would print as 85.00 and must still fail.
        $this->writeTotals(14_286, 12_143);

        self::assertFalse(CoverageFloor::fromCloverFile($this->path)->meets(85));
    }

    public function testAReportThatMeasuredNothingIsRefused(): void
    {
        $this->writeTotals(0, 0);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('measured no statements');

        CoverageFloor::fromCloverFile($this->path);
    }

    public function testAMissingReportIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No coverage report');

        CoverageFloor::fromCloverFile($this->path);
    }

    public function testSomethingThatIsNotCloverIsRefused(): void
    {
        $this->write('<?xml version="1.0"?><testsuites/>');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no project metrics');

        CoverageFloor::fromCloverFile($this->path);
    }

    public function testMalformedXmlIsRefused(): void
    {
        $this->write('not xml at all');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not a readable Clover report');

        CoverageFloor::fromCloverFile($this->path);
    }

    private function writeTotals(int $statements, int $covered): void
    {
        $this->write(sprintf(
            '<?xml version="1.0"?><coverage><project>'
            . '<metrics statements="%d" coveredstatements="%d"/>'
            . '</project></coverage>',
            $statements,
            $covered,
        ));
    }

    private function write(string $contents): void
    {
        file_put_contents($this->path, $contents);
    }
}
