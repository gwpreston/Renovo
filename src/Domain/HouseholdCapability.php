<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * What a member can do, said the way a person would say it.
 *
 * Deliberately not `Permission`. That enum is the vocabulary the routes assert
 * in, and it is the right size for that job and the wrong size for a card: a
 * reader comparing two people does not want seventeen rows of
 * `subscription.bulk_edit` and `attachment.manage`, they want to know who can
 * change things and who can only look. So this is a short, plain-language
 * ladder, and `HouseholdOverviewService` is where each rung is answered by
 * putting the real question to `PermissionService`. The grouping is a
 * presentation decision; what is in each group is not.
 *
 * The order is the ladder, least to most: seeing, then changing your own, then
 * changing anybody's, then setting what the household spends, and last the one
 * rung only an Owner stands on. Every card lists every rung — the ones a member
 * does not have are greyed rather than dropped, because a list that varies in
 * length between cards cannot be read across.
 *
 * Two rungs depend on the instance's isolation mode as well as the role, which
 * is why the answer needs a Scope rather than a Role: in ISOLATED nobody sees
 * every line and nobody edits anybody else's, however senior they are.
 */
enum HouseholdCapability: string
{
    case SeeEverything = 'see_everything';
    case EditOwn = 'edit_own';
    case EditAnything = 'edit_anything';
    case SetBudgets = 'set_budgets';
    case ManageMembers = 'manage_members';

    public function labelKey(): string
    {
        return 'capability.' . $this->value;
    }

    /**
     * The rungs, in the order a card lists them.
     *
     * @return list<self>
     */
    public static function ladder(): array
    {
        return self::cases();
    }
}
