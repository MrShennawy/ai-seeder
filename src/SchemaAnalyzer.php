<?php

declare(strict_types=1);

namespace Shennawy\AiSeeder;

use Illuminate\Support\Facades\Schema;
use Shennawy\AiSeeder\Contracts\SchemaAnalyzerInterface;

class SchemaAnalyzer implements SchemaAnalyzerInterface
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private array $cache = [];

    /**
     * Column types that map to JSON storage in MySQL / PostgreSQL / SQLite.
     *
     * @var array<int, string>
     */
    private const array JSON_TYPES = ['json', 'jsonb', 'longtext'];

    /**
     * Column names that should be treated as password fields.
     *
     * @var array<int, string>
     */
    private const array PASSWORD_COLUMN_NAMES = [
        'password',
        'password_hash',
        'passwd',
        'hashed_password',
        'user_password',
    ];

    /**
     * {@inheritDoc}
     */
    public function analyze(string $table): array
    {
        if (isset($this->cache[$table])) {
            return $this->cache[$table];
        }

        $columns = $this->extractColumns($table);
        $foreignKeys = $this->extractForeignKeys($table);

        $result = [
            'table' => $table,
            'columns' => $columns,
            'foreign_keys' => $foreignKeys,
        ];

        $this->cache[$table] = $result;

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function getTables(): array
    {
        return Schema::getTableListing();
    }

    /**
     * {@inheritDoc}
     */
    public function tableExists(string $table): bool
    {
        return Schema::hasTable($table);
    }

    /**
     * Extract column metadata from a table.
     *
     * @return array<int, array{name: string, type: string, nullable: bool, unique: bool, auto_increment: bool, primary_key: bool, is_json: bool, key_type: string, max_length: int|null, enum_values: array<int, string>, is_password: bool, is_datetime: bool}>
     */
    private function extractColumns(string $table): array
    {
        $schemaColumns = Schema::getColumns($table);
        $indexes = Schema::getIndexes($table);

        $uniqueColumns = $this->resolveUniqueColumns($indexes);
        $primaryKeyColumns = $this->resolvePrimaryKeyColumns($indexes);

        return array_map(function (array $column) use ($uniqueColumns, $primaryKeyColumns): array {
            $isPrimaryKey = in_array($column['name'], $primaryKeyColumns, true);
            $isAutoIncrement = $column['auto_increment'] ?? false;
            $typeName = strtolower($column['type_name']);
            $fullType = $column['type'] ?? $column['type_name'];
            $isDateTime = $this->isDateTimeColumn($column['name'], $typeName);

            return [
                'name' => $column['name'],
                'type' => $column['type_name'],
                'nullable' => $column['nullable'],
                'unique' => in_array($column['name'], $uniqueColumns, true),
                'auto_increment' => $isAutoIncrement,
                'primary_key' => $isPrimaryKey,
                'is_json' => $this->isJsonColumn($typeName, $column),
                'key_type' => $this->resolveKeyType($column, $isPrimaryKey, $isAutoIncrement),
                'max_length' => $this->extractMaxLength($fullType, $typeName),
                'enum_values' => $this->extractEnumValues($fullType, $typeName),
                'is_password' => $this->isPasswordColumn($column['name']),
                'is_datetime' => $isDateTime,
            ];
        }, $schemaColumns);
    }

    /**
     * Determine whether a column stores JSON data.
     */
    private function isJsonColumn(string $typeName, array $column): bool
    {
        if (in_array($typeName, self::JSON_TYPES, true)) {
            return true;
        }

        $fullType = strtolower($column['type'] ?? '');

        return str_contains($fullType, 'json');
    }

    /**
     * Determine the key generation type for a column.
     *
     * Returns: 'ulid', 'uuid', 'auto_increment', or 'none'.
     */
    private function resolveKeyType(array $column, bool $isPrimaryKey, bool $isAutoIncrement): string
    {
        if ($isAutoIncrement) {
            return 'auto_increment';
        }

        if (! $isPrimaryKey) {
            return 'none';
        }

        $typeName = strtolower($column['type_name']);
        $length = $column['length'] ?? null;

        if (in_array($typeName, ['char', 'varchar', 'string'], true)) {
            if ($length === 26) {
                return 'ulid';
            }

            if ($length === 36) {
                return 'uuid';
            }

            return 'ulid';
        }

        return 'none';
    }

    /**
     * Extract the maximum character length from a full column type string.
     *
     * Parses patterns like "varchar(255)", "char(2)", "string(36)".
     * Returns null for types without a length (text, longtext, etc.)
     * or for non-string types.
     */
    private function extractMaxLength(string $fullType, string $typeName): ?int
    {
        $stringTypes = ['varchar', 'char', 'string', 'character varying'];

        if (! in_array($typeName, $stringTypes, true)) {
            return null;
        }

        if (preg_match('/\((\d+)\)/', $fullType, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * Extract allowed values from an ENUM column type definition.
     *
     * Parses patterns like "enum('active','inactive','pending')".
     *
     * @return array<int, string>
     */
    private function extractEnumValues(string $fullType, string $typeName): array
    {
        if (strtolower($typeName) !== 'enum') {
            if (! str_starts_with(strtolower($fullType), 'enum(')) {
                return [];
            }
        }

        if (preg_match_all("/'([^']+)'/", $fullType, $matches)) {
            return $matches[1];
        }

        return [];
    }

    /**
     * Determine whether a column is a password/hash field by name.
     */
    private function isPasswordColumn(string $columnName): bool
    {
        return in_array(strtolower($columnName), self::PASSWORD_COLUMN_NAMES, true);
    }

    /**
     * Determine whether a column is a date/datetime/timestamp column.
     * Excludes native Laravel timestamps (created_at, updated_at, deleted_at).
     */
    private function isDateTimeColumn(string $columnName, string $typeName): bool
    {
        // Exclude native Laravel timestamps
        $nativeTimestamps = ['created_at', 'updated_at', 'deleted_at'];
        if (in_array(strtolower($columnName), $nativeTimestamps, true)) {
            return false;
        }

        // Check if the column type is date, datetime, or timestamp
        return in_array($typeName, ['date', 'datetime', 'timestamp', 'datetimetz'], true);
    }

    /**
     * Extract foreign key metadata from a table.
     *
     * @return array<int, array{column: string, foreign_table: string, foreign_column: string}>
     */
    private function extractForeignKeys(string $table): array
    {
        $foreignKeys = Schema::getForeignKeys($table);

        return array_map(fn (array $fk): array => [
            'column' => $fk['columns'][0],
            'foreign_table' => $fk['foreign_table'],
            'foreign_column' => $fk['foreign_columns'][0],
        ], $foreignKeys);
    }

    /**
     * Resolve which columns have unique constraints.
     *
     * @param  array<int, array<string, mixed>>  $indexes
     * @return array<int, string>
     */
    private function resolveUniqueColumns(array $indexes): array
    {
        $uniqueColumns = [];

        foreach ($indexes as $index) {
            if ($index['unique'] ?? false) {
                foreach ($index['columns'] as $column) {
                    $uniqueColumns[] = $column;
                }
            }
        }

        return array_unique($uniqueColumns);
    }

    /**
     * Resolve which columns are part of the primary key.
     *
     * @param  array<int, array<string, mixed>>  $indexes
     * @return array<int, string>
     */
    private function resolvePrimaryKeyColumns(array $indexes): array
    {
        foreach ($indexes as $index) {
            $isPrimary = ($index['primary'] ?? false)
                || (isset($index['name']) && strtolower($index['name']) === 'primary');

            if ($isPrimary) {
                return $index['columns'] ?? [];
            }
        }

        return [];
    }
}
