<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Domain\Allocation;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Splitting money between people.
 *
 * One property matters more than all the others and is asserted on every case:
 * the shares add up to the amount. Money that disappears between the split and
 * the total is a bug, however small, and it is the kind of bug that only shows
 * up as a household arguing about a penny.
 */
final class AllocationTest extends TestCase
{
    public function testAnEvenSplitThatDividesExactly(): void
    {
        self::assertSame([500, 500], Allocation::evenly(1000, 2));
    }

    public function testAnEvenSplitThatDoesNotDivideGivesTheRemainderOut(): void
    {
        // £10.00 three ways is not £3.33 each — that loses a penny. Somebody
        // pays the extra one, and it is the same somebody every time.
        $shares = Allocation::evenly(1000, 3);

        self::assertSame([334, 333, 333], $shares);
        self::assertSame(1000, array_sum($shares));
    }

    public function testASmallAmountWhereTheRoundingErrorWouldBeLarge(): void
    {
        // 10p three ways. Independent rounding would give 3p each and lose 1p —
        // a tenth of the whole bill.
        $shares = Allocation::evenly(10, 3);

        self::assertSame([4, 3, 3], $shares);
        self::assertSame(10, array_sum($shares));
    }

    public function testWeightedSharesFollowTheWeights(): void
    {
        // Alice pays twice what Bob does out of £30.
        self::assertSame([2000, 1000], Allocation::byWeight(3000, [2, 1]));
    }

    public function testWeightedSharesWithAnAwkwardRemainder(): void
    {
        $shares = Allocation::byWeight(1000, [1, 1, 1, 1, 1, 1, 1]);

        self::assertSame(1000, array_sum($shares));
        // The leftover 6p goes one each to the six largest remainders.
        self::assertSame([143, 143, 143, 143, 143, 143, 142], $shares);
    }

    public function testAZeroWeightGetsNothing(): void
    {
        self::assertSame([0, 1000], Allocation::byWeight(1000, [0, 1]));
    }

    public function testAZeroAmountSplitsIntoZeroes(): void
    {
        self::assertSame([0, 0, 0], Allocation::evenly(0, 3));
    }

    public function testARefundSplitsExactlyToo(): void
    {
        // A credit divides by the same rule with the sign carried through, so
        // the parts of a refund also sum to the refund.
        $shares = Allocation::evenly(-1000, 3);

        self::assertSame([-334, -333, -333], $shares);
        self::assertSame(-1000, array_sum($shares));
    }

    public function testTheAllocationIsDeterministic(): void
    {
        // The extra penny must not wander between members from one page load to
        // the next, so ties are broken by position rather than by sort order.
        $first = Allocation::evenly(100, 3);

        for ($i = 0; $i < 20; $i++) {
            self::assertSame($first, Allocation::evenly(100, 3));
        }
    }

    /**
     * @param list<int> $weights
     */
    #[DataProvider('exhaustiveCases')]
    public function testSharesAlwaysSumToTheAmount(int $amount, array $weights): void
    {
        $shares = Allocation::byWeight($amount, $weights);

        self::assertCount(count($weights), $shares);
        self::assertSame($amount, array_sum($shares));
    }

    /**
     * @return list<array{0: int, 1: list<int>}>
     */
    public static function exhaustiveCases(): array
    {
        $cases = [];

        // Every amount from 0 to 60 against a handful of realistic weightings.
        foreach ([[1, 1], [1, 1, 1], [2, 1], [3, 2, 1], [1, 1, 1, 1, 1, 1, 1]] as $weights) {
            for ($amount = 0; $amount <= 60; $amount++) {
                $cases[] = [$amount, $weights];
            }
        }

        return $cases;
    }

    public function testSplittingBetweenNobodyIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Allocation::byWeight(1000, []);
    }

    public function testWeightsThatAreAllZeroAreRefused(): void
    {
        // Otherwise the amount would have nowhere to go and would silently
        // vanish.
        $this->expectException(InvalidArgumentException::class);

        Allocation::byWeight(1000, [0, 0]);
    }

    public function testANegativeWeightIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Allocation::byWeight(1000, [2, -1]);
    }
}
