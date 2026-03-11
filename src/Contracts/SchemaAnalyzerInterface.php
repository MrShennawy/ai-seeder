<?php

declare(strict_types=1);

namespace Shennawy\AiSeeder\Contracts;

interface SchemaAnalyzerInterface
{
    /**
     * Get the full schema details for a given table.
     *
     * @return array{
     *     table: string,
     *     columns: array<int, array{name: string, type: string, nullable: bool, unique: bool, auto_increment: bool, primary_key: bool, is_json: bool, key_type: string, max_length: int|null, enum_values: array<int, string>, is_password: bool}>,
     *     foreign_keys: array<int, array{column: string, foreign_table: string, foreign_column: string}>
     * }
     */
    public function analyze(string $table): array;

    /**
     * Get a list of all tables in the database.
     *
     * @return array<int, string>
     */
    public function getTables(): array;

    /**
     * Determine if a table exists in the database.
     */
    public function tableExists(string $table): bool;
}
