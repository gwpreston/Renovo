<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * A user's dashboard layout: which cards they show, and in what order.
 *
 * Per user, like the other preference tables, and written as a whole: the
 * settings form submits the entire layout, so a save replaces every row rather
 * than trying to reconcile a diff. That keeps the position values contiguous
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
        return ['user_id', 'card_key'];
    }

    /**
     * @return array<string, array{position: int, visible: bool}>
     */
    public function layoutFor(int $userId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM ' . $this->quote('dashboard_cards')
            . ' WHERE ' . $this->quote('user_id') . ' = :user'
            . ' ORDER BY ' . $this->quote('position') . ' ASC',
            ['user' => $userId],
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
    public function replaceFor(int $userId, array $cards): void
    {
        $this->db->transactional(function () use ($userId, $cards): void {
            $this->db->execute(
                'DELETE FROM ' . $this->quote('dashboard_cards')
                . ' WHERE ' . $this->quote('user_id') . ' = :user',
                ['user' => $userId],
            );

            // Written with execute() rather than insert(): this table's key is
            // the user and the card together, so there is no generated id to
            // ask for and nothing to hand back.
            foreach ($cards as $card) {
                $this->db->execute(
                    'INSERT INTO ' . $this->quote('dashboard_cards') . ' ('
                    . $this->quote('user_id') . ', ' . $this->quote('card_key') . ', '
                    . $this->quote('position') . ', ' . $this->quote('is_visible')
                    . ') VALUES (:user, :card, :position, :visible)',
                    [
                        'user' => $userId,
                        'card' => $card['card_key'],
                        'position' => $card['position'],
                        'visible' => $card['visible'],
                    ],
                );
            }
        });
    }
}
