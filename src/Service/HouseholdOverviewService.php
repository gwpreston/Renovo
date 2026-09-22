<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Entity\HouseholdMember;
use App\Domain\Entity\Subscription;
use App\Domain\Entity\SubscriptionSplit;
use App\Domain\HouseholdCapability;
use App\Domain\Permission;
use App\Domain\Rounding;
use App\Security\PermissionService;
use App\Security\Scope;

/**
 * The household as a list of people, each with what they carry.
 *
 * Three questions per member — how many subscriptions are theirs, what they
 * cost by month and by year, and what their role lets them do — and every one
 * of them is answered from something that already exists. The rows come from
 * the scoped subscription query, the shares from `SplitService`, the money
 * from `StatsService::combine()`, and the permissions from `PermissionService`
 * itself. Nothing here decides anything a second time.
 *
 * **A member's figures are their share, not their inventory.** A subscription
 * they own outright counts in full; one they have split counts at their portion
 * of it; one they have split entirely away counts as nothing, even though their
 * name is still on the row. That is the rule budgets already state — "everything
 * a budget measures is one member's share" — and the reason the count follows
 * the same rule as the money: a card saying six subscriptions above a third of
 * the cost of six subscriptions is two answers to one question.
 *
 * **Each member's figure is their own, and the parts are not re-allocated.**
 * `SplitService` guarantees that the shares of a *price* add up to the price
 * exactly — `Allocation` hands out the leftover minor units rather than
 * rounding each share on its own. What a card shows is not the price but the
 * monthly and annual equivalents of it, and each member's slice of those is
 * rounded independently here. On an awkward cycle the cards can therefore
 * total a minor unit or two away from the household's own figure: £10 a year
 * split three ways is 83p a month for the household and 28p each on three
 * cards. Every individual figure is right to the minor unit; their sum is not
 * re-derived from the household's. This is what `ForecastService::proportion()`
 * already does with a projected charge, and making it exact would mean
 * allocating against the normalised figure — a change to the split machinery
 * rather than to this screen.
 *
 * **In ISOLATED mode, other members' figures are withheld rather than
 * estimated.** The scoping layer restricts every read to rows the caller owns,
 * for every role including an Owner's, so the subscription set this works from
 * is *the caller's own* plus the splits they take part in. Aggregating it per
 * member would render a fraction of somebody's spending as though it were all
 * of it. The roster still shows — names and roles are household-visible
 * already — and the money says it is hidden. A withheld figure is a smaller
 * failure than a wrong one, which is the same judgement `StatsService` makes
 * about a currency it cannot convert.
 *
 * @phpstan-import-type Combined from StatsService
 * @phpstan-type Figures array{totals: list<array{currency: string, amount_minor: int}>, combined: Combined}
 * @phpstan-type Rung array{capability: HouseholdCapability, held: bool}
 * @phpstan-type MemberOverview array{
 *     member: HouseholdMember,
 *     is_self: bool,
 *     figures_withheld: bool,
 *     subscription_count: int,
 *     one_off_count: int,
 *     monthly: Figures,
 *     yearly: Figures,
 *     capabilities: list<Rung>
 * }
 */
final class HouseholdOverviewService
{
    public function __construct(
        private readonly HouseholdMemberService $members,
        private readonly SubscriptionService $subscriptions,
        private readonly SplitService $splits,
        private readonly StatsService $stats,
        private readonly PermissionService $permissions,
        private readonly CatchUpService $catchUp,
    ) {
    }

    /**
     * Every member of the household, with their share of it.
     *
     * Empty for anybody whose role does not reach the screen, and empty for a
     * user with no household. The caller renders what it is given, so a Viewer
     * asking the dashboard for this gets a card with nothing in it rather than
     * a card that has to remember to hide itself.
     *
     * @return list<MemberOverview>
     */
    public function members(Scope $scope): array
    {
        if (!$this->permissions->allows($scope, Permission::ViewHousehold)) {
            return [];
        }

        $members = $this->members->list($scope);
        if ($members === []) {
            return [];
        }

        // The same first step the dashboard and the statistics take: due price
        // changes applied, ended trials converted, overdue dates advanced. A
        // member's share computed from a price that is about to be corrected
        // would disagree with the totals on the screen beside it.
        $this->catchUp->run($scope);

        $subscriptions = $this->subscriptions->allForStats($scope);
        $splits = $this->splits->allInScope($scope);
        $withheld = $scope->restrictsReadsToOwner();

        $overview = [];
        foreach ($members as $member) {
            $isSelf = $member->userId === $scope->userId;

            $overview[] = $this->forMember(
                $member,
                $isSelf,
                // In ISOLATED the caller's own figures are the one complete
                // answer available, so they are the one that is shown.
                $withheld && !$isSelf,
                $subscriptions,
                $splits,
                $scope,
            );
        }

        return $overview;
    }

    /**
     * Whether the per-member figures are worth drawing at all.
     *
     * A household of one is a list with the reader on it, and in ISOLATED mode
     * every row but the reader's own is blank. Neither is a comparison, which
     * is the whole of what the dashboard card is for — so the card asks this
     * and renders nothing rather than occupying a row to say very little. The
     * page itself has more to show (the roster, the roles) and does not ask.
     *
     * @param list<MemberOverview> $overview
     */
    public function isWorthComparing(array $overview): bool
    {
        $comparable = 0;
        foreach ($overview as $row) {
            if (!$row['figures_withheld']) {
                $comparable++;
            }
        }

        return $comparable > 1;
    }

    /**
     * One member's row.
     *
     * @param list<Subscription> $subscriptions
     * @param array<int, list<SubscriptionSplit>> $splits
     * @return MemberOverview
     */
    private function forMember(
        HouseholdMember $member,
        bool $isSelf,
        bool $withheld,
        array $subscriptions,
        array $splits,
        Scope $scope,
    ): array {
        $monthly = [];
        $yearly = [];
        $count = 0;
        $oneOff = 0;

        if (!$withheld) {
            foreach ($subscriptions as $subscription) {
                if (!$subscription->isActive) {
                    continue;
                }

                $participants = $splits[$subscription->id] ?? [];

                // "Bears any of it" rather than "pays something today": a free
                // trial's share is zero for everybody while it runs, and it is
                // still the member's subscription to be counted.
                if (!$this->splits->bears($subscription, $participants, $member->userId)) {
                    continue;
                }

                $count++;

                $monthlyMinor = $subscription->monthlyMinor();
                if ($monthlyMinor === null) {
                    // One-off and lifetime entries have no monthly equivalent,
                    // so they are counted and kept out of the figures rather
                    // than amortised over a period nobody chose.
                    $oneOff++;
                    continue;
                }

                $share = $this->splits->shareFor($subscription, $participants, $member->userId);
                $currency = $subscription->price->currency;

                $monthly[$currency] = ($monthly[$currency] ?? 0)
                    + $this->proportion($monthlyMinor, $subscription->price->amountMinor, $share->amountMinor);
                $yearly[$currency] = ($yearly[$currency] ?? 0)
                    + $this->proportion(
                        $subscription->yearlyMinor() ?? 0,
                        $subscription->price->amountMinor,
                        $share->amountMinor,
                    );
            }
        }

        ksort($monthly);
        ksort($yearly);

        return [
            'member' => $member,
            'is_self' => $isSelf,
            'figures_withheld' => $withheld,
            'subscription_count' => $count,
            'one_off_count' => $oneOff,
            'monthly' => $this->figures($monthly),
            'yearly' => $this->figures($yearly),
            'capabilities' => $this->capabilitiesOf($member, $scope),
        ];
    }

    /**
     * A member's portion of a normalised figure.
     *
     * The share is a proportion of the *current price*, which is not the number
     * being divided: the monthly and annual equivalents are the price spread
     * over a cycle. So the ratio is taken from the price and applied to the
     * figure, in integers throughout — a float here would be a rounding error
     * per member per subscription, compounding across the card.
     *
     * A price of zero — a running trial — has no ratio to take, and the whole
     * figure is the honest answer: the member bears it, and what it costs today
     * is nothing either way.
     */
    private function proportion(int $figureMinor, int $priceMinor, int $shareMinor): int
    {
        if ($priceMinor === 0) {
            return $figureMinor;
        }

        return Rounding::multiplyDivide($figureMinor, $shareMinor, $priceMinor);
    }

    /**
     * Per-currency subtotals with the combined figure beside them, in the shape
     * `partials/spend.twig` renders.
     *
     * The combining is `StatsService`'s, not a second implementation of it, so
     * a member's card withholds a total for an unconvertible currency on
     * exactly the same terms as the tile above it.
     *
     * @param array<string, int> $byCurrency
     * @return Figures
     */
    private function figures(array $byCurrency): array
    {
        $totals = [];
        foreach ($byCurrency as $currency => $amountMinor) {
            $totals[] = ['currency' => (string) $currency, 'amount_minor' => $amountMinor];
        }

        return ['totals' => $totals, 'combined' => $this->stats->combine($byCurrency)];
    }

    /**
     * Each rung of the ladder, and whether this member is on it.
     *
     * Every question is put to `PermissionService` with a scope carrying their
     * role, so a card cannot claim something the routes would refuse. The
     * instance-admin flag is deliberately false: this says what a *household
     * role* confers, and the flag would quietly light rows on everybody's card
     * that have nothing to do with the household.
     *
     * The two rungs that mention other people's rows ask the isolation mode as
     * well. In ISOLATED the scoping layer restricts every read and write to the
     * rows a member owns, whatever their role — so "see every line" and "add
     * and edit anything" are false for an Owner there just as they are for
     * everybody else, and saying otherwise would describe a power the
     * repository does not grant.
     *
     * @return list<Rung>
     */
    private function capabilitiesOf(HouseholdMember $member, Scope $scope): array
    {
        $theirs = Scope::forMember(
            $member->userId,
            false,
            (int) $scope->householdId,
            $member->role,
            $scope->isolationMode,
        );

        $mayWrite = $this->permissions->allows($theirs, Permission::UpdateSubscription);

        $held = [
            HouseholdCapability::SeeEverything->value =>
                $this->permissions->allows($theirs, Permission::ViewSubscriptions)
                && !$theirs->restrictsReadsToOwner(),
            HouseholdCapability::EditOwn->value => $mayWrite,
            // Two different questions, and a Contributor is the reason they
            // cannot be one: they see every line and change only their own.
            HouseholdCapability::EditAnything->value => $mayWrite && !$theirs->restrictsWritesToOwner(),
            HouseholdCapability::SetBudgets->value =>
                $this->permissions->allows($theirs, Permission::ManageBudgets),
            HouseholdCapability::ManageMembers->value =>
                $this->permissions->allows($theirs, Permission::ManageHousehold),
        ];

        $rungs = [];
        foreach (HouseholdCapability::ladder() as $capability) {
            $rungs[] = ['capability' => $capability, 'held' => $held[$capability->value]];
        }

        return $rungs;
    }
}
