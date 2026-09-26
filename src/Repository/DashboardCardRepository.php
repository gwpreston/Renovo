<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * A user's dashboard layouts: which cards they show on each view, and in what
 * order.
 *
 * Per user and per view, like the other preference tables, and written a view
 * at a time as a whole: the settings form submits that view's entire layout,
 * so a save replaces its rows rather than trying to reconcile a diff. That keeps the position values contiguous
 * and means a card added in a later version appears for everybody instead of
 * being invisible to whoever had saved a layout before it existed.
 */
final class DashboardCardRepository extends AbstractRepository
{
    protected function table(): string
    {
        return 'dashboard_cards';
    }

    protected function filterableColumns(): array
    {
        return ['user_id', 'view', 'card_key'];
    }

    /**
     * @return array<string, array{position: int, visible: bool}>
     */
    public function layoutFor(int $userId, string $view): array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM ' . $this->quote('dashboard_cards')
            . ' WHERE ' . $this->quote('user_id') . ' = :user'
            . ' AND ' . $this->quote('view') . ' = :view'
            . ' ORDER BY ' . $this->quote('position') . ' ASC',
            ['user' => $userId, 'view' => $view],
        );

        $layout = [];
        foreach ($rows as $row) {
            $layout[(string) $row['card_key']] = [
                'position' => (int) $row['position'],
                'visible' => $this->db->platform()->toBoolean($row['is_visible']),
            ];
        }

        return $layout;
    }

    /**
     * @param list<array{card_key: string, position: int, visible: bool}> $cards
     */
    public function replaceFor(int $userId, string $view, array $cards): void
    {
        $this->db->transactional(function () use ($userId, $view, $cards): void {
            $this->db->execute(
                'DELETE FROM ' . $this->quote('dashboard_cards')
                . ' WHERE ' . $this->quote('user_id') . ' = :user'
                . ' AND ' . $this->quote('view') . ' = :view',
                ['user' => $userId, 'view' => $view],
            );

            // Written with execute() rather than insert(): this table's key is
            // the user, the view and the card together, so there is no
            // generated id to ask for and nothing to hand back.
            foreach ($cards as $card) {
                $this->db->execute(
                    'INSERT INTO ' . $this->quote('dashboard_cards') . ' ('
                    . $this->quote('user_id') . ', ' . $this->quote('view') . ', '
                    . $this->quote('card_key') . ', '
                    . $this->quote('position') . ', ' . $this->quote('is_visible')
                    . ') VALUES (:user, :view, :card, :position, :visible)',
                    [
                        'user' => $userId,
                        'view' => $view,
                        'card' => $card['card_key'],
                        'position' => $card['position'],
                        'visible' => $card['visible'],
                    ],
                );
            }
        });
    }
}
