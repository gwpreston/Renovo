<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Whether a membership is live or still waiting for the person to arrive.
 *
 * A provisioned member exists before they have ever signed in: the Owner who
 * added them has typed their name, chosen their role and sent an invite, and
 * none of that should wait on the invitee opening their mail. The membership
 * is therefore real from the moment it is created and carries `pending` until
 * the invite is accepted, which is what lets the member list show "invited,
 * hasn't been here yet" rather than an account indistinguishable from an
 * active one.
 *
 * `revoked` is deliberately absent from this column. A revoked login is a
 * property of the *account* — `users.disabled_at` — not of one membership, and
 * duplicating it here would create two places that can disagree about whether
 * somebody may sign in. The member list derives the word "revoked" from the
 * account; this column answers only "have they accepted?".
 *
 * Defaulting to `active` is what leaves every existing row untouched: a
 * household set up before this migration has members who plainly arrived.
 */
final class AddStatusToHouseholdMemberships extends AbstractMigration
{
    public function up(): void
    {
        $this->table('household_memberships')
            // active | pending
            ->addColumn('status', 'string', ['limit' => 20, 'null' => false, 'default' => 'active'])
            ->update();
    }

    public function down(): void
    {
        $this->table('household_memberships')
            ->removeColumn('status')
            ->update();
    }
}
