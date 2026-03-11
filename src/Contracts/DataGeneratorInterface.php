<?php

declare(strict_types=1);

namespace Shennawy\AiSeeder\Contracts;

use Shennawy\AiSeeder\GenerationResult;

interface DataGeneratorInterface
{
    /**
     * Generate rows of dummy data for a given table schema.
     *
     * Returns a GenerationResult containing the generated rows and token usage.
     *
     * @param  array{
     *     table: string,
     *     columns: array<int, array{name: string, type: string, nullable: bool, unique: bool, auto_increment: bool, primary_key: bool, is_json: bool, key_type: string, max_length: int|null, enum_values: array<int, string>, is_password: bool}>,
     *     foreign_keys: array<int, array{column: string, foreign_table: string, foreign_column: string}>
     * }  $schema
     * @param  array<string, array<int, int|string>>  $foreignKeyConstraints
     * @param  string  $language  Language code: 'en', 'ar', or 'mixed'
     * @param  string|null  $contextCode  Raw PHP source code for business logic context (FormRequest, Model, etc.)
     */
    public function generate(array $schema, int $count, array $foreignKeyConstraints = [], string $language = 'en', ?string $contextCode = null): GenerationResult;
}
