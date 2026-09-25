<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Which half of the scoping layer confines a capability to a member's own
 * rows. See `Scope::restrictsReadsToOwner()` and `restrictsWritesToOwner()`,
 * which are the two questions this names.
 */
enum CapabilityFence
{
    case None;
    case Reads;
    case Writes;
}
