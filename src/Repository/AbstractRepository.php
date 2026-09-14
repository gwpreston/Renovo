<?php

declare(strict_types=1);

namespace App\Repository;

use App\Persistence\Criteria;
use App\Persistence\Database;
use InvalidArgumentException;

/**
 * Shared SQL assembly for repositories.
 *
 * Subclasses declare a table and the columns that may be filtered or sorted
 * on; they never hand a SQL string to the database. Anything a caller supplies
 * — a sort column, a filter, a search term — is validated against that
 * allow-list and bound as a parameter.
 *
 * This class handles unscoped tables only (users, instance settings). Anything
 * belonging to a household extends AbstractScopedRepository instead.
 */
abstract class AbstractRepository
{
    public function __construct(protected readonly Database $db)
    {
    }

    abstract protected function table(): string;

    /**
     * Columns that callers may filter, search or sort on.
     *
     * @return list<string>
     */
    abstract protected function filterableColumns(): array;

    /**
     * Compile a criteria into a WHERE clause plus bound parameters.
     *
     * @param array<string, mixed> $params Populated with the bound values.
     * @return list<string> SQL conditions to be AND-ed together.
     */
    protected function compileConditions(Criteria $criteria, array &$params, string $prefix = 'c'): array
    {
        $conditions = [];
        $index = 0;

        foreach ($criteria->filters() as $filter) {
            $column = $this->qualify($this->assertColumn($filter['column']));

            switch ($filter['operator']) {
                case 'IS NULL':
                case 'IS NOT NULL':
                    $conditions[] = $column . ' ' . $filter['operator'];
                    break;

                case 'IN':
                case 'NOT IN':
                    /** @var array<int, int|string> $values */
                    $values = is_array($filter['value']) ? array_values($filter['value']) : [];
                    if ($values === []) {
                        // An empty IN list matches nothing; NOT IN matches all.
                        $conditions[] = $filter['operator'] === 'IN' ? '1 = 0' : '1 = 1';
                        break;
                    }
                    $placeholders = [];
                    foreach ($values as $value) {
                        $name = sprintf('%s_%d', $prefix, $index++);
                        $placeholders[] = ':' . $name;
                        $params[$name] = $value;
                    }
                    $conditions[] = sprintf('%s %s (%s)', $column, $filter['operator'], implode(', ', $placeholders));
                    break;

                default:
                    $name = sprintf('%s_%d', $prefix, $index++);
                    $conditions[] = sprintf('%s %s :%s', $column, $filter['operator'], $name);
                    $params[$name] = $filter['value'];
            }
        }

        foreach ($criteria->searches() as $search) {
            $name = sprintf('%s_%d', $prefix, $index++);
            $params[$name] = '%' . $this->escapeLike(mb_strtolower($search['term'])) . '%';

            $parts = [];
            foreach ($search['columns'] as $column) {
                $parts[] = $this->db->platform()->caseInsensitiveLike(
                    $this->qualify($this->assertColumn($column)),
                    ':' . $name,
                );
            }

            if ($parts !== []) {
                $conditions[] = '(' . implode(' OR ', $parts) . ')';
            }
        }

        return $conditions;
    }

    protected function compileOrderBy(Criteria $criteria, string $default = ''): string
    {
        $parts = [];
        foreach ($criteria->ordering() as $order) {
            $parts[] = $this->qualify($this->assertColumn($order['column'])) . ' ' . $order['direction'];
        }

        if ($parts === []) {
            return $default === '' ? '' : ' ORDER BY ' . $default;
        }

        return ' ORDER BY ' . implode(', ', $parts);
    }

    /**
     * @param array<string, mixed> $params
     */
    protected function compileLimit(Criteria $criteria, array &$params): string
    {
        $limit = $criteria->limitValue();
        if ($limit === null) {
            return '';
        }

        $params['__limit'] = $limit;
        $params['__offset'] = $criteria->offsetValue();

        return ' LIMIT :__limit OFFSET :__offset';
    }

    protected function quote(string $identifier): string
    {
        return $this->db->platform()->quoteIdentifier($identifier);
    }

    /**
     * Qualify a column with its table name so joins stay unambiguous.
     */
    protected function qualify(string $column): string
    {
        return $this->quote($this->table()) . '.' . $this->quote($column);
    }

    protected function assertColumn(string $column): string
    {
        if (!in_array($column, $this->filterableColumns(), true)) {
            throw new InvalidArgumentException(sprintf(
                'Column "%s" is not filterable on %s.',
                $column,
                static::class,
            ));
        }

        return $column;
    }

    /**
     * Escape the wildcards a user may have typed so a search for "100%" does
     * not match everything.
     */
    protected function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
