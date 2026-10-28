<?php

declare(strict_types=1);

namespace Nora\Database;

use InvalidArgumentException;

final class QueryBuilder
{
    private const SELECT = 'SELECT';
    private const INSERT = 'INSERT';
    private const UPDATE = 'UPDATE';
    private const DELETE = 'DELETE';

    /** @var list<string> */
    private array $columns = ['*'];

    /** @var list<array{column: string, operator: string, value: mixed}> */
    private array $conditions = [];

    /** @var array<string, mixed> */
    private array $values = [];

    private ?int $limitValue = null;
    private ?int $offsetValue = null;

    private function __construct(
        private readonly string $table,
        private readonly string $operation = self::SELECT,
    )
    {
        self::assertIdentifier($table);
    }

    /**
     * @param list<string> $columns
     */
    public static function select(string $table, array $columns = ['*']): self
    {
        $builder = new self($table, self::SELECT);
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

    /**
     * @param array<string, mixed> $values
     */
    public static function insert(string $table, array $values): self
    {
        if ($values === []) {
            throw new InvalidArgumentException('An INSERT query must include at least one value.');
        }

        $builder = new self($table, self::INSERT);
        $builder->values = self::validatedValues($values);

        return $builder;
    }

    /**
     * @param array<string, mixed> $values
     */
    public static function update(string $table, array $values): self
    {
        if ($values === []) {
            throw new InvalidArgumentException('An UPDATE query must include at least one value.');
        }

        $builder = new self($table, self::UPDATE);
        $builder->values = self::validatedValues($values);

        return $builder;
    }

    public static function delete(string $table): self
    {
        return new self($table, self::DELETE);
    }

    public function where(string $column, string $operator, mixed $value): self
    {
        if ($this->operation === self::INSERT) {
            throw new InvalidArgumentException('INSERT queries do not accept WHERE conditions.');
        }
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
        if ($this->operation !== self::SELECT) {
            throw new InvalidArgumentException('LIMIT is supported only for SELECT queries.');
        }
        if ($limit < 0) {
            throw new InvalidArgumentException('Query limit cannot be negative.');
        }
        $this->limitValue = $limit;

        return $this;
    }

    public function offset(int $offset): self
    {
        if ($this->operation !== self::SELECT) {
            throw new InvalidArgumentException('OFFSET is supported only for SELECT queries.');
        }
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
        $table = self::quoteIdentifier($this->table);
        $parameters = array_values($this->values);
        if ($this->operation === self::INSERT) {
            $columns = array_keys($this->values);
            $sql = 'INSERT INTO ' . $table
                . ' (' . implode(', ', array_map(self::quoteIdentifier(...), $columns)) . ')'
                . ' VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')';

            return [$sql, $parameters];
        }

        if ($this->operation === self::UPDATE) {
            $this->assertDestructivePredicate();
            $assignments = [];
            foreach (array_keys($this->values) as $column) {
                $assignments[] = self::quoteIdentifier($column) . ' = ?';
            }
            $sql = 'UPDATE ' . $table . ' SET ' . implode(', ', $assignments);
        } elseif ($this->operation === self::DELETE) {
            $this->assertDestructivePredicate();
            $sql = 'DELETE FROM ' . $table;
        } else {
            $columns = $this->columns === ['*']
                ? '*'
                : implode(', ', array_map(self::quoteIdentifier(...), $this->columns));
            $sql = 'SELECT ' . $columns . ' FROM ' . $table;
            $parameters = [];
        }

        if ($this->conditions !== []) {
            $parts = [];
            foreach ($this->conditions as $condition) {
                $parts[] = self::quoteIdentifier($condition['column'])
                    . ' ' . $condition['operator'] . ' ?';
                $parameters[] = $condition['value'];
            }
            $sql .= ' WHERE ' . implode(' AND ', $parts);
        }

        if ($this->operation === self::SELECT && $this->limitValue !== null) {
            $sql .= ' LIMIT ' . $this->limitValue;
        }
        if ($this->operation === self::SELECT && $this->offsetValue !== null) {
            if ($this->limitValue === null) {
                $sql .= ' LIMIT 18446744073709551615';
            }
            $sql .= ' OFFSET ' . $this->offsetValue;
        }

        return [$sql, $parameters];
    }

    public function operation(): string
    {
        return $this->operation;
    }

    private function assertDestructivePredicate(): void
    {
        if ($this->conditions === []) {
            throw new InvalidArgumentException('UPDATE and DELETE queries require at least one WHERE condition.');
        }
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private static function validatedValues(array $values): array
    {
        foreach ($values as $column => $_value) {
            if (!is_string($column)) {
                throw new InvalidArgumentException('Query values must be keyed by column names.');
            }
            self::assertIdentifier($column);
        }

        return $values;
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
