<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Support\CssPercent;
use PHPUnit\Framework\TestCase;

/**
 * Widths on the dashboard, from two integers and never from a float.
 */
final class CssPercentTest extends TestCase
{
    public function testAPartIsWrittenToATenthOfAPercent(): void
    {
        self::assertSame('50.0%', CssPercent::of(500, 1000));
        self::assertSame('33.3%', CssPercent::of(1, 3));
        self::assertSame('66.7%', CssPercent::of(2, 3));
    }

    public function testNothingAndNoScaleAreZero(): void
    {
        self::assertSame('0%', CssPercent::of(0, 1000));
        self::assertSame('0%', CssPercent::of(-5, 1000));
        self::assertSame('0%', CssPercent::of(500, 0));
    }

    /** A figure past its scale fills the scale rather than leaving its card. */
    public function testItNeverExceedsTheWhole(): void
    {
        self::assertSame('100.0%', CssPercent::of(1500, 1000));
    }
}
