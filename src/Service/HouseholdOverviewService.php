<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Entity\HouseholdMember;
use App\Domain\Entity\Subscription;
use App\Domain\Entity\SubscriptionSplit;
use App\Domain\Permission;
use App\Domain\Rounding;
use App\Security\PermissionService;
use App\Security\Scope;

/**
 * The household as a list of people, each with what they carry.
 *
 * Two questions per member — how many subscriptions are theirs, and what they
 * cost by month and by year — and both are answered from something that
 * already exists. The rows come from the scoped subscription query, the shares
 * from `SplitService` and the money from `StatsService::combine()`. Nothing
 * here decides anything a second time. What each member's *role* lets them do
 * is not per member at all; it is the members screen's role table, from
 * `RoleMatrix`.
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
 * re-derived from the household's. This is what `SplitService::chargeShare()`
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
 * **A Viewer is shown their own figures and nobody else's.** The roster is
 * every member's to read, but what everybody else spends is the household's
 * business rather than an onlooker's — that is `Permission::ViewHousehold`,
 * which a Viewer does not hold. Their own share is not somebody else's
 * spending, so it is the one figure they get.
 *
 * @phpstan-import-type Combined from StatsService
 * @phpstan-type Figures array{totals: list<array{currency: string, amount_minor: int}>, combined: Combined}
 * @phpstan-type MemberOverview array{
 *     member: HouseholdMember,
 *     is_self: bool,
 *     figures_withheld: bool,
 *     subscription_count: int,
 *     one_off_count: int,
 *     monthly: Figures,
 *     yearly: Figures
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
     * Empty for a user with no household. Every member gets the roster; whose
     * figures are filled in is decided here, row by row, so the caller renders
     * what it is given and never asks.
     *
     * @return list<MemberOverview>
     */
    public function members(Scope $scope): array
    {
        if (!$this->permissions->allows($scope, Permission::ViewSubscriptions)) {
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
        // Two reasons for a blank, one outcome. In ISOLATED the figures that
        // could be computed are a fraction of the truth; for a Viewer they are
        // somebody else's spending. Either way the reader's own are complete
        // and theirs to see.
        $withheld = $scope->restrictsReadsToOwner()
            || !$this->permissions->allows($scope, Permission::ViewHousehold);

        $overview = [];
        foreach ($members as $member) {
            $isSelf = $member->userId === $scope->userId;

            $overview[] = $this->forMember(
                $member,
                $isSelf,
                $withheld && !$isSelf,
                $subscriptions,
                $splits,
            );
        }

        return $overview;
    }

    /**
     * The dashboard's Who pays: each member's monthly share, and the part of
     * the household's spend it is.
     *
     * In SHARED mode, every member whose figures are shown, with their share
     * as a percentage of the members' shares added together — which is the
     * household's spend, divided by who carries it. The percentages are
     * withheld, not estimated, when any member's monthly figure cannot be
     * totalled in one currency.
     *
     * **In ISOLATED mode only the viewer's own share**, and no percentage: the
     * viewer cannot see the household's total, so a share *of* it would be
     * invented, and listing the other members with blank figures would still
     * say who has how many subscriptions. The card retitles itself "Your
     * share" on `own_share_only`.
     *
     * Private rows are already absent from any viewer who is not their payer,
     * because the scoped query never returned them; they count for the payer,
     * in the payer's own view.
     *
     * A Viewer gets nothing: the card is a comparison of who carries what,
     * and a Viewer is not shown what anybody else carries.
     *
     * @return array{rows: list<MemberOverview>, percents: array<int, int|null>, own_share_only: bool}
     */
    public function whoPays(Scope $scope): array
    {
        $members = $this->permissions->allows($scope, Permission::ViewHousehold) ? $this->members($scope) : [];
        $ownOnly = $scope->restrictsReadsToOwner();

        $rows = array_values(array_filter(
            $members,
            static fn (array $row): bool => $ownOnly ? $row['is_self'] : !$row['figures_withheld'],
        ));

        $percents = [];
        if (!$ownOnly) {
            $total = 0;
            $complete = true;
            foreach ($rows as $row) {
                $amount = $row['monthly']['combined']['amount_minor'];
                if ($amount === null) {
                    $complete = false;
                    break;
                }
                $total += $amount;
            }

            foreach ($rows as $row) {
                $amount = $row['monthly']['combined']['amount_minor'];
                $percents[$row['member']->userId] = $complete && $total > 0 && $amount !== null
                    ? Rounding::multiplyDivide($amount, 100, $total)
                    : null;
            }
        }

        return ['rows' => $rows, 'percents' => $percents, 'own_share_only' => $ownOnly];
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
}
