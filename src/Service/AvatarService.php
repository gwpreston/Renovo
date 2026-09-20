<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Entity\User;
use App\Repository\MembershipRepository;
use App\Repository\UserRepository;

/**
 * Who may look at whose face.
 *
 * One question, in one place, because the answer is not the one the rest of
 * the application gives. Household *data* is filtered by role and by the
 * isolation mode; an avatar is not data about money, and applying that rule to
 * it would leave a Viewer on an ISOLATED instance looking at a member list of
 * broken images beside names they can plainly read.
 *
 * So the rule is membership, not visibility: you may see the picture of
 * somebody you share a household with, and your own. Anybody else gets the
 * same answer as a user id that does not exist.
 */
final class AvatarService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly MembershipRepository $memberships,
        private readonly AvatarStorage $storage,
    ) {
    }

    /**
     * The file to stream, or null when there is nothing to stream or nobody
     * to stream it to.
     */
    public function pathFor(User $viewer, int $subjectId): ?string
    {
        if (!$this->memberships->shareAHousehold($viewer->id, $subjectId)) {
            return null;
        }

        $subject = $this->users->findById($subjectId);
        if ($subject === null || $subject->avatarPath === null) {
            return null;
        }

        return $this->storage->absolutePath($subject->avatarPath);
    }
}
