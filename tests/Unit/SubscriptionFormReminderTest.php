<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\SubscriptionFormService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The reminder chips over the three-state `reminder_days`: what the form draws
 * for each stored value, and which chips it offers.
 */
final class SubscriptionFormReminderTest extends TestCase
{
    public function testEachStoredStateIsDrawnAsItsChoice(): void
    {
        $form = $this->service();

        self::assertSame(['reminder_mode' => 'default', 'reminder_day' => []], $form->reminderValues(null));
        self::assertSame(['reminder_mode' => 'never', 'reminder_day' => []], $form->reminderValues(''));
        self::assertSame(['reminder_mode' => 'days', 'reminder_day' => [14, 7]], $form->reminderValues('14,7'));
    }

    public function testADayWithNoChipOfItsOwnGetsOne(): void
    {
        $form = $this->service();

        self::assertSame([1, 3, 7, 14, 30], $form->reminderChoices([]));
        self::assertSame([1, 3, 7, 14, 30, 60], $form->reminderChoices([60, 14]));
        self::assertSame([1, 2, 3, 7, 14, 30], $form->reminderChoices([2]));
    }

    /**
     * Neither method touches a collaborator, so the service is built without
     * its constructor rather than with four mocks that would never be called.
     */
    private function service(): SubscriptionFormService
    {
        return (new ReflectionClass(SubscriptionFormService::class))->newInstanceWithoutConstructor();
    }
}
