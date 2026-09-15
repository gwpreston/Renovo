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

    // Money. Viewing budgets and forecasts is part of viewing subscriptions —
    // a figure derived from data you can already see discloses nothing new —
    // so only the writes need permissions of their own.
    case ManageBudgets = 'budget.manage';
    case ManagePrices = 'price.manage';
    case ManageSplits = 'split.manage';
    case RecordUsage = 'usage.record';
    case BulkEdit = 'subscription.bulk_edit';

    case ManageHousehold = 'household.manage';

    // Reading the audit log. Not a household-data permission: what it grants is
    // sight of who did what, which an instance administrator has instance-wide
    // and a household Owner has for their own household.
    case ViewAuditLog = 'audit.view';

    case ManageInstance = 'instance.manage';

    public function isMutating(): bool
    {
        return !in_array($this, [self::ViewSubscriptions, self::ViewAuditLog], true);
    }
}
