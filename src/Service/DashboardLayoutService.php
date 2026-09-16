<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\DashboardCard;
use App\Repository\DashboardCardRepository;

/**
 * Which dashboard cards a user sees, and in what order.
 *
 * A user who has never touched this gets the enum's own order and every card
 * shown. Somebody who has rearranged it gets their arrangement, with any card
 * added since they last saved appended in its default place rather than
 * silently missing — which is the reason the stored layout is merged with the
 * enum instead of replacing it.
 */
final class DashboardLayoutService
{
    public function __construct(private readonly DashboardCardRepository $repository)
    {
    }

    /**
     * The cards to render, in order, hidden ones removed.
     *
     * @return list<DashboardCard>
     */
    public function visibleFor(int $userId): array
    {
        return array_values(array_map(
            static fn (array $entry): DashboardCard => $entry['card'],
            array_filter($this->forUser($userId), static fn (array $entry): bool => $entry['visible']),
        ));
    }

    /**
     * Every card with its position and whether it is shown — what the settings
     * form renders.
     *
     * @return list<array{card: DashboardCard, position: int, visible: bool}>
     */
    public function forUser(int $userId): array
    {
        $stored = $this->repository->layoutFor($userId);

        $cards = [];
        foreach (DashboardCard::defaultOrder() as $index => $card) {
            $entry = $stored[$card->value] ?? null;

            $cards[] = [
                'card' => $card,
                // A card the stored layout has never heard of sorts after the
                // ones it has, by its default position.
                'position' => $entry === null ? count($stored) + $index : $entry['position'],
                'visible' => $entry === null || $entry['visible'],
            ];
        }

        usort(
            $cards,
            static fn (array $a, array $b): int => $a['position'] <=> $b['position']
                ?: strcmp($a['card']->value, $b['card']->value),
        );

        return $cards;
    }

    /**
     * Save a layout submitted by the settings form.
     *
     * Positions arrive as whatever numbers the user typed; they are sorted and
     * renumbered here, so "3, 3, 1" is a legitimate way of saying "that one
     * first and I do not care about the other two".
     *
     * @param array<array-key, mixed> $positions Card key => position.
     * @param array<array-key, mixed> $visible   Card key => anything truthy.
     */
    public function update(int $userId, array $positions, array $visible): void
    {
        $ordered = [];
        foreach (DashboardCard::cases() as $index => $card) {
            $raw = $positions[$card->value] ?? null;

            $ordered[] = [
                'card' => $card,
                'requested' => is_numeric($raw) ? (int) $raw : $index,
                'visible' => array_key_exists($card->value, $visible),
            ];
        }

        usort(
            $ordered,
            static fn (array $a, array $b): int => $a['requested'] <=> $b['requested']
                ?: strcmp($a['card']->value, $b['card']->value),
        );

        $rows = [];
        foreach ($ordered as $position => $entry) {
            $rows[] = [
                'card_key' => $entry['card']->value,
                'position' => $position,
                'visible' => $entry['visible'],
            ];
        }

        $this->repository->replaceFor($userId, $rows);
    }
}
