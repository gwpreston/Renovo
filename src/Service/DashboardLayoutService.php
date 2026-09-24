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
        return array_values(array_map(
            static fn (array $entry): DashboardCard => $entry['card'],
            array_filter($this->forUser($userId, $view), static fn (array $entry): bool => $entry['visible']),
        ));
    }

    /**
     * Every card of one view with its position and whether it is shown — what
     * the settings form renders.
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
     * Every view's layout, for the settings form.
     *
     * @return list<array{view: DashboardView, cards: list<array{card: DashboardCard, position: int, visible: bool}>}>
     */
    public function allFor(int $userId): array
    {
        return array_map(
            fn (DashboardView $view): array => ['view' => $view, 'cards' => $this->forUser($userId, $view)],
            DashboardView::cases(),
        );
    }

    /**
     * Save one view's layout, as submitted by the settings form.
     *
     * Positions arrive as whatever numbers the user typed; they are sorted and
     * renumbered here, so "3, 3, 1" is a legitimate way of saying "that one
     * first and I do not care about the other two".
     *
     * @param array<array-key, mixed> $positions Card key => position.
     * @param array<array-key, mixed> $visible   Card key => anything truthy.
     */
    public function update(int $userId, DashboardView $view, array $positions, array $visible): void
    {
        $ordered = [];
        foreach (DashboardCard::defaultOrder($view) as $index => $card) {
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

        $this->repository->replaceFor($userId, $view->value, $rows);
    }

    /**
     * Save whichever views the form actually submitted.
     *
     * A view is saved only when its positions came with the request. A
     * checkbox left unticked is simply absent from a form, so "no visibility
     * fields" cannot be told apart from "every card hidden" — only the
     * positions, which are always sent, say that a view's section was on the
     * page. Without this, a form that drew one view would silently hide every
     * card on the other.
     *
     * @param array<array-key, mixed> $positions View => card key => position.
     * @param array<array-key, mixed> $visible   View => card key => anything truthy.
     */
    public function updateSubmitted(int $userId, array $positions, array $visible): void
    {
        foreach (DashboardView::cases() as $view) {
            $viewPositions = $positions[$view->value] ?? null;
            if (!is_array($viewPositions) || $viewPositions === []) {
                continue;
            }

            $viewVisible = $visible[$view->value] ?? [];

            $this->update($userId, $view, $viewPositions, is_array($viewVisible) ? $viewVisible : []);
        }
    }
}
