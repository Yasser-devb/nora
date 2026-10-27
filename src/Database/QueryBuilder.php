<?php

declare(strict_types=1);

namespace Nora\Database;

use InvalidArgumentException;

final class QueryBuilder
{
    /** @var list<string> */
    private array $columns = ['*'];

    /** @var list<array{column: string, operator: string, value: mixed}> */
    private array $conditions = [];

    private ?int $limitValue = null;
    private ?int $offsetValue = null;

    private function __construct(private readonly string $table)
    {
        self::assertIdentifier($table);
    }

    /**
     * @param list<string> $columns
     */
    public static function select(string $table, array $columns = ['*']): self
    {
        $builder = new self($table);
        if ($columns !== ['*']) {
            if ($columns === []) {
                throw new InvalidArgumentException('A SELECT query must include at least one column.');
            }
            foreach ($columns as $column) {
                self::assertIdentifier($column);
            }
            $builder->columns = $columns;
        }

        return $builder;
    }

    public function where(string $column, string $operator, mixed $value): self
    {
        self::assertIdentifier($column);
        $operator = strtoupper($operator);
        if (!in_array($operator, ['=', '!=', '<>', '<', '<=', '>', '>=', 'LIKE'], true)) {
            throw new InvalidArgumentException('Unsupported query comparison operator.');
        }

        $this->conditions[] = ['column' => $column, 'operator' => $operator, 'value' => $value];

        return $this;
    }

    public function limit(int $limit): self
    {
        if ($limit < 0) {
            throw new InvalidArgumentException('Query limit cannot be negative.');
        }
        $this->limitValue = $limit;

        return $this;
    }

    public function offset(int $offset): self
    {
        if ($offset < 0) {
            throw new InvalidArgumentException('Query offset cannot be negative.');
        }
        $this->offsetValue = $offset;

        return $this;
    }

    /**
     * @return array{string, list<mixed>}
     */
    public function toSql(): array
    {
        $columns = $this->columns === ['*']
            ? '*'
            : implode(', ', array_map(self::quoteIdentifier(...), $this->columns));
        $sql = 'SELECT ' . $columns . ' FROM ' . self::quoteIdentifier($this->table);
        $parameters = [];

        if ($this->conditions !== []) {
            $parts = [];
            foreach ($this->conditions as $index => $condition) {
                $parts[] = self::quoteIdentifier($condition['column'])
                    . ' ' . $condition['operator'] . ' ?';
                $parameters[] = $condition['value'];
            }
            $sql .= ' WHERE ' . implode(' AND ', $parts);
        }

        if ($this->limitValue !== null) {
            $sql .= ' LIMIT ' . $this->limitValue;
        }
        if ($this->offsetValue !== null) {
            if ($this->limitValue === null) {
                $sql .= ' LIMIT 18446744073709551615';
            }
            $sql .= ' OFFSET ' . $this->offsetValue;
        }

        return [$sql, $parameters];
    }

    private static function assertIdentifier(string $identifier): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $identifier) !== 1) {
            throw new InvalidArgumentException('Unsafe SQL identifier.');
        }
    }

    private static function quoteIdentifier(string $identifier): string
    {
        self::assertIdentifier($identifier);

        return '`' . $identifier . '`';
    }
}
