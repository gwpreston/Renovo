<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Domain\SubscriptionFilter;
use App\Domain\SubscriptionStatus;
use App\Domain\SubscriptionType;
use PHPUnit\Framework\TestCase;

/**
 * The list view's filter state.
 *
 * The screen dropped three of its controls — currency, type and "include
 * paused" — but the value object kept all three parameters, because the API
 * parses the same query string. These assert that separation: what the form
 * stopped offering is not what the filter stopped understanding.
 */
final class SubscriptionFilterTest extends TestCase
{
    public function testTheParametersTheFormNoLongerOffersAreStillUnderstood(): void
    {
        // The API sends these, and a saved view made before the form changed
        // still carries them. Dropping the controls must not drop the parsing.
        $filter = SubscriptionFilter::fromQueryParams([
            'currency' => 'eur',
            'type' => 'lifetime',
            'inactive' => '1',
        ]);

        self::assertSame('EUR', $filter->currency);
        self::assertSame(SubscriptionType::Lifetime, $filter->type);
        self::assertTrue($filter->includeInactive);
    }

    public function testPausedSubscriptionsAreStillLeftOutUnlessAskedFor(): void
    {
        // The web controller asks; the API's default is untouched.
        self::assertFalse(SubscriptionFilter::fromQueryParams([])->includeInactive);
    }

    public function testIncludingPausedRowsIsNotAFilterTheUserApplied(): void
    {
        // The web list sets this on every request now. If it counted as an
        // active filter, a household with nothing in it would be told its
        // filters matched nothing instead of being invited to add the first
        // subscription.
        $filter = SubscriptionFilter::fromQueryParams([])->withIncludeInactive();

        self::assertTrue($filter->includeInactive);
        self::assertFalse($filter->hasActiveFilters());
    }

    public function testNarrowingTheListStillCountsAsAFilter(): void
    {
        $filter = SubscriptionFilter::fromQueryParams(['q' => 'netflix'])->withIncludeInactive();

        self::assertTrue($filter->hasActiveFilters());
    }

    public function testIncludingPausedRowsChangesNothingElseAboutTheFilter(): void
    {
        $filter = SubscriptionFilter::fromQueryParams([
            'q' => 'plan',
            'category' => '4',
            'owner' => '7',
            'currency' => 'USD',
            'type' => 'recurring',
            'tag' => ['2', '5'],
            'sort' => 'price',
            'dir' => 'desc',
            'page' => '3',
        ]);

        $widened = $filter->withIncludeInactive();

        self::assertSame('plan', $widened->search);
        self::assertSame(4, $widened->categoryId);
        self::assertSame(7, $widened->ownerUserId);
        self::assertSame('USD', $widened->currency);
        self::assertSame(SubscriptionType::Recurring, $widened->type);
        self::assertSame([2, 5], $widened->tagIds);
        self::assertSame('price', $widened->sort);
        self::assertSame('desc', $widened->direction);
        self::assertSame(3, $widened->page);
    }

    public function testAStatusIsReadFromTheQueryAndWrittenBack(): void
    {
        $filter = SubscriptionFilter::fromQueryParams(['status' => 'cancelled']);

        self::assertSame(SubscriptionStatus::Cancelled, $filter->status);
        self::assertTrue($filter->hasActiveFilters());
        self::assertStringContainsString('status=cancelled', $filter->toQueryString());
        self::assertNull(SubscriptionFilter::fromQueryParams(['status' => 'deleted'])->status);
    }

    public function testTheWebListLeavesCancelledRowsToTheirOwnFilter(): void
    {
        $api = SubscriptionFilter::fromQueryParams(['inactive' => '1']);
        $web = SubscriptionFilter::fromQueryParams(['status' => 'trial'])->withIncludeInactive();

        self::assertTrue($api->includeCancelled, 'the API\'s inactive=1 still means everything switched off');
        self::assertFalse($web->includeCancelled);
        self::assertSame(SubscriptionStatus::Trial, $web->status, 'the wither keeps the chosen status');
    }

    public function testMineIsReadFromTheScopeParameterAndSurvivesTheWithers(): void
    {
        $filter = SubscriptionFilter::fromQueryParams(['scope' => 'mine', 'q' => 'x']);

        self::assertTrue($filter->mine);
        self::assertTrue($filter->hasActiveFilters());
        self::assertStringContainsString('scope=mine', $filter->toQueryString());
        self::assertTrue($filter->withIncludeInactive()->mine);
        self::assertTrue($filter->unpaged()->mine);

        // Anything else is the household.
        self::assertFalse(SubscriptionFilter::fromQueryParams(['scope' => 'everyone'])->mine);
        self::assertStringNotContainsString('scope=', SubscriptionFilter::fromQueryParams([])->toQueryString());
    }

    public function testUnpagedKeepsTheFilterAndDropsThePaging(): void
    {
        $filter = SubscriptionFilter::fromQueryParams(['q' => 'gym', 'page' => '4', 'status' => 'paused'])
            ->withIncludeInactive();
        $unpaged = $filter->unpaged();

        self::assertTrue($filter->isPaged());
        self::assertFalse($unpaged->isPaged());
        self::assertSame('gym', $unpaged->search);
        self::assertSame(SubscriptionStatus::Paused, $unpaged->status);
        self::assertFalse($unpaged->includeCancelled);
        self::assertSame(1, $unpaged->page);
    }

    public function testATagChipTogglesItsOwnTagAndKeepsTheOthers(): void
    {
        $filter = SubscriptionFilter::fromQueryParams(['tag' => ['2', '5'], 'page' => '3']);

        $adding = $filter->queryTogglingTag(9);
        self::assertStringContainsString('tag[]=2', $adding);
        self::assertStringContainsString('tag[]=5', $adding);
        self::assertStringContainsString('tag[]=9', $adding);

        $removing = $filter->queryTogglingTag(5);
        self::assertStringContainsString('tag[]=2', $removing);
        self::assertStringNotContainsString('tag[]=5', $removing);

        // Back to the first page: the set of rows has changed under the pager.
        self::assertStringNotContainsString('page=', $adding);
    }
}
