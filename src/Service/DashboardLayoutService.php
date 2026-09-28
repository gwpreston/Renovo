<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\DashboardCard;
use App\Domain\DashboardView;
use App\Repository\DashboardCardRepository;

/**
 * Which dashboard cards a user sees on each view, and in what order.
 *
 * Each view has a layout of its own, so hiding a card on Overview leaves
 * Household alone. A user who has never touched a view gets its default order,
 * with each card shown or not as the card itself says — the subscriptions
 * table is listed but off. Somebody who has rearranged a view gets their
 * arrangement, with any card added since they last saved appended in its
 * default place and default visibility rather than silently missing — which is
 * the reason the stored layout is merged with the enum instead of replacing it.
 */
final class DashboardLayoutService
{
    public function __construct(private readonly DashboardCardRepository $repository)
    {
    }

    /**
     * The cards to render on one view, in order, hidden ones removed.
     *
     * @return list<DashboardCard>
     */
    public function visibleFor(int $userId, DashboardView $view): array
    {
        return self::visibleOf($this->forUser($userId, $view));
    }

    /**
     * The shown cards of a layout `forUser()` returned, in order — for a
     * caller that needs the whole layout as well and should not read it twice.
     *
     * @param list<array{card: DashboardCard, position: int, visible: bool}> $layout
     * @return list<DashboardCard>
     */
    public static function visibleOf(array $layout): array
    {
        return array_values(array_map(
            static fn (array $entry): DashboardCard => $entry['card'],
            array_filter($layout, static fn (array $entry): bool => $entry['visible']),
        ));
    }

    /**
     * Every card of one view with its position and whether it is shown — what
     * the dashboard draws while it is being customised.
     *
     * @return list<array{card: DashboardCard, position: int, visible: bool}>
     */
    public function forUser(int $userId, DashboardView $view): array
    {
        $stored = $this->repository->layoutFor($userId, $view->value);

        $cards = [];
        foreach (DashboardCard::defaultOrder($view) as $index => $card) {
            $entry = $stored[$card->value] ?? null;

            $cards[] = [
                'card' => $card,
                // A card the stored layout has never heard of sorts after the
                // ones it has, by its default position.
                'position' => $entry === null ? count($stored) + $index : $entry['position'],
                'visible' => $entry === null ? $card->visibleByDefault() : $entry['visible'],
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
     * Save a view in the order it was dragged into.
     *
     * Only the order comes from the request; whether each card is shown is
     * kept as it was. Keys that name no card of this view are passed over,
     * and a card the list leaves out keeps its place after the ones it names,
     * so a stale page cannot lose a card by not knowing about it.
     *
     * @param list<string> $keys Card keys, first to last.
     */
    public function reorder(int $userId, DashboardView $view, array $keys): void
    {
        $current = $this->forUser($userId, $view);

        $rank = array_flip(array_values(array_unique($keys)));
        $count = count($rank);
        foreach ($current as $index => $entry) {
            $current[$index]['requested'] = $rank[$entry['card']->value] ?? $count + $index;
        }

        usort(
            $current,
            static fn (array $a, array $b): int => $a['requested'] <=> $b['requested'],
        );

        $this->save($userId, $view, $current);
    }

    /**
     * Move one card a place up (-1) or down (+1), swapping it with its
     * neighbour. At either end there is no neighbour, and nothing changes.
     */
    public function move(int $userId, DashboardView $view, DashboardCard $card, int $offset): void
    {
        $current = $this->forUser($userId, $view);

        foreach ($current as $index => $entry) {
            if ($entry['card'] !== $card) {
                continue;
            }

            $target = $index + $offset;
            if (!isset($current[$target])) {
                return;
            }

            [$current[$index], $current[$target]] = [$current[$target], $current[$index]];
            $this->save($userId, $view, $current);

            return;
        }
    }

    /**
     * Show or hide one card. Said outright rather than toggled, so a form
     * sent twice leaves the card as the member asked for, not back as it was.
     */
    public function setVisible(int $userId, DashboardView $view, DashboardCard $card, bool $visible): void
    {
        $current = $this->forUser($userId, $view);

        foreach ($current as $index => $entry) {
            if ($entry['card'] === $card) {
                $current[$index]['visible'] = $visible;
            }
        }

        $this->save($userId, $view, $current);
    }

    /**
     * Put one view back as it ships: its stored rows are removed, so it reads
     * as the default order and visibility again — and goes on following the
     * default, cards added by later versions included, until it is next
     * rearranged.
     */
    public function reset(int $userId, DashboardView $view): void
    {
        $this->repository->replaceFor($userId, $view->value, []);
    }

    /**
     * Write a view's cards back in the order given, positions renumbered from
     * nought so they stay contiguous.
     *
     * @param list<array{card: DashboardCard, visible: bool}> $entries
     */
    private function save(int $userId, DashboardView $view, array $entries): void
    {
        $rows = [];
        foreach (array_values($entries) as $position => $entry) {
            $rows[] = [
                'card_key' => $entry['card']->value,
                'position' => $position,
                'visible' => $entry['visible'],
            ];
        }

        $this->repository->replaceFor($userId, $view->value, $rows);
    }
}
