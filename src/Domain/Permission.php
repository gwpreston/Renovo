<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Every permission the application checks. Routes name one of these; the
 * PermissionService is the only place that maps them onto roles.
 */
enum Permission: string
{
    case ViewSubscriptions = 'subscription.view';
    case CreateSubscription = 'subscription.create';
    case UpdateSubscription = 'subscription.update';
    case DeleteSubscription = 'subscription.delete';

    case ManageCategories = 'category.manage';
    case ManageTags = 'tag.manage';

    case ManageHousehold = 'household.manage';

    case ManageInstance = 'instance.manage';

    public function isMutating(): bool
    {
        return $this !== self::ViewSubscriptions;
    }
}
