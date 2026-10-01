<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\Import\ImportPreset;
use App\Service\Import\RowTranslator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Reading a billing cycle out of somebody else's file.
 *
 * "6 months" used to import as every six days: the digit pattern matched the
 * number and ignored the unit. It reads the unit now.
 */
final class ImportCycleTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string, string}>
     */
    public static function cycles(): array
    {
        return [
            '6 months' => ['6 months', 'monthly', '', '6'],
            'Every 6 Months' => ['Every 6 Months', 'monthly', '', '6'],
            '2 years' => ['2 years', 'yearly', '', '2'],
            'Every 2 Years' => ['Every 2 Years', 'yearly', '', '2'],
            '2 weeks' => ['2 weeks', 'weekly', '', '2'],
            'fortnightly' => ['fortnightly', 'weekly', '', '2'],
            'Biweekly' => ['Biweekly', 'weekly', '', '2'],
            '45 days' => ['45 days', 'custom_days', '45', '1'],
            'every 45 days' => ['every 45 days', 'custom_days', '45', '1'],
            'bare 45' => ['45', 'custom_days', '45', '1'],
            'monthly' => ['Monthly', 'monthly', '', '1'],
            '3 months is quarterly' => ['3 months', 'quarterly', '', '1'],
            'every 3 months is quarterly' => ['Every 3 Months', 'quarterly', '', '1'],
            '24 months is two-yearly' => ['24 months', 'yearly', '', '2'],
            'quarterly' => ['quarterly', 'quarterly', '', '1'],
            'daily' => ['Daily', 'custom_days', '1', '1'],
            'every 2 days' => ['Every 2 Days', 'custom_days', '2', '1'],
            'unrecognised' => ['whenever', 'monthly', '', '1'],
            'empty' => ['', 'monthly', '', '1'],
        ];
    }

    #[DataProvider('cycles')]
    public function testTheCycleIsReadForItsUnit(string $raw, string $cycle, string $days, string $interval): void
    {
        $input = $this->translate(['billing_cycle' => 'Cycle'], ['Cycle' => $raw]);

        self::assertSame($cycle, $input['billing_cycle']);
        self::assertSame($days, $input['cycle_days']);
        self::assertSame($interval, $input['cycle_interval']);
    }

    public function testAnIntervalColumnMultipliesAPlainUnit(): void
    {
        $mapping = ['billing_cycle' => 'cycle', 'cycle_interval' => 'frequency'];

        $input = $this->translate($mapping, ['cycle' => 'Monthly', 'frequency' => '6']);
        self::assertSame(['monthly', '6'], [$input['billing_cycle'], $input['cycle_interval']]);

        $input = $this->translate($mapping, ['cycle' => 'Weekly', 'frequency' => '2']);
        self::assertSame(['weekly', '2'], [$input['billing_cycle'], $input['cycle_interval']]);

        $input = $this->translate($mapping, ['cycle' => 'Daily', 'frequency' => '10']);
        self::assertSame(['custom_days', '10'], [$input['billing_cycle'], $input['cycle_days']]);
    }

    public function testACountBeyondTheBoundsIsPassedOnForThePreviewToReport(): void
    {
        $input = $this->translate(['billing_cycle' => 'Cycle'], ['Cycle' => '30 months']);

        // Not clamped to 24, and not quietly turned into something else.
        self::assertSame(['monthly', '30'], [$input['billing_cycle'], $input['cycle_interval']]);
    }

    public function testTheWallosPresetMapsItsCycleAndFrequency(): void
    {
        $export = ImportPreset::guess('wallos', ['Name', 'Payment Cycle', 'Price', 'Next Payment']);
        self::assertSame('Payment Cycle', $export['billing_cycle'] ?? null);

        $database = ImportPreset::guess('wallos', ['name', 'price', 'cycle', 'frequency', 'next_payment']);
        self::assertSame('cycle', $database['billing_cycle'] ?? null);
        self::assertSame('frequency', $database['cycle_interval'] ?? null);
    }

    /**
     * @param array<string, string> $mapping
     * @param array<string, string> $row
     * @return array<string, mixed>
     */
    private function translate(array $mapping, array $row): array
    {
        return (new RowTranslator(['name' => 'Name', 'price' => 'Price'] + $mapping, 'GBP'))
            ->translate(['Name' => 'Example', 'Price' => '10.00'] + $row);
    }
}
