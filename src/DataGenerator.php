<?php

declare(strict_types=1);

namespace Shennawy\AiSeeder;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Shennawy\AiSeeder\Agents\SeederAgent;
use Shennawy\AiSeeder\Contracts\DataGeneratorInterface;

class DataGenerator implements DataGeneratorInterface
{
    /**
     * Standard Laravel timestamp columns — excluded from AI generation
     * and injected natively by PHP.
     */
    private const TIMESTAMP_COLUMNS = ['created_at', 'updated_at', 'deleted_at'];

    /**
     * {@inheritDoc}
     */
    public function generate(array $schema, int $count, array $foreignKeyConstraints = [], string $language = 'en', ?string $contextCode = null): GenerationResult
    {
        $columnDefinitions = $this->buildColumnDefinitions($schema, $foreignKeyConstraints);
        $prompt = $this->buildPrompt($schema, $count, $language, $contextCode);

        $result = $this->invokeWithRetry($prompt, $count, $columnDefinitions, $language);

        $rows = $this->postProcess($result['rows'], $schema, $foreignKeyConstraints);

        return new GenerationResult(
            rows: $rows,
            promptTokens: $result['prompt_tokens'],
            completionTokens: $result['completion_tokens'],
        );
    }

    /**
     * Build column definitions for the structured schema.
     *
     * Excludes primary key columns (auto-increment, ULID, UUID) entirely —
     * they are injected programmatically after AI generation.
     *
     * @return array<string, array<string, mixed>>
     */
    private function buildColumnDefinitions(array $schema, array $foreignKeyConstraints): array
    {
        $definitions = [];
        $foreignKeyColumns = $this->getForeignKeyColumns($schema);

        foreach ($schema['columns'] as $column) {
            if ($this->shouldExcludeColumn($column, $foreignKeyColumns)) {
                continue;
            }

            $name = $column['name'];

            $definition = [
                'type' => $this->normalizeColumnType($column['type'], $column),
                'nullable' => $column['nullable'],
                'unique' => $column['unique'],
                'is_json' => $column['is_json'] ?? false,
                'max_length' => $column['max_length'] ?? null,
                'enum_values' => $column['enum_values'] ?? [],
                'description' => $this->buildColumnDescription($column),
            ];

            $definitions[$name] = $definition;
        }

        return $definitions;
    }

    /**
     * Determine whether a column should be excluded from AI generation.
     *
     * Primary keys (auto-increment, ULID, UUID), password columns,
     * and foreign key columns are always excluded — they are injected
     * programmatically after AI generation.
     */
    private function shouldExcludeColumn(array $column, array $foreignKeyColumns = []): bool
    {
        if ($column['auto_increment'] ?? false) {
            return true;
        }

        if ($column['primary_key'] ?? false) {
            $keyType = $column['key_type'] ?? 'none';

            if (in_array($keyType, ['ulid', 'uuid', 'auto_increment'], true)) {
                return true;
            }
        }

        if ($column['is_password'] ?? false) {
            return true;
        }

        // Foreign key columns are handled by PHP, not the AI
        if (in_array($column['name'], $foreignKeyColumns, true)) {
            return true;
        }

        // Standard Laravel timestamps are injected natively by PHP
        if (in_array($column['name'], self::TIMESTAMP_COLUMNS, true)) {
            return true;
        }

        return false;
    }

    /**
     * Extract the list of foreign key column names from the schema.
     *
     * @return array<int, string>
     */
    private function getForeignKeyColumns(array $schema): array
    {
        return array_map(
            fn (array $fk): string => $fk['column'],
            $schema['foreign_keys'] ?? [],
        );
    }

    /**
     * Normalize the database column type to a simpler category.
     */
    private function normalizeColumnType(string $type, array $column = []): string
    {
        if ($column['is_json'] ?? false) {
            return 'json';
        }

        $type = strtolower($type);

        return match (true) {
            in_array($type, ['integer', 'bigint', 'smallint', 'tinyint', 'mediumint', 'int', 'biginteger'], true) => 'integer',
            in_array($type, ['float', 'double', 'decimal', 'real', 'numeric'], true) => 'float',
            in_array($type, ['boolean', 'bool', 'tinyint(1)'], true) => 'boolean',
            default => 'string',
        };
    }

    /**
     * Build a human-readable description for a column to guide the AI.
     */
    private function buildColumnDescription(array $column): string
    {
        $parts = [];

        $parts[] = "Database type: {$column['type']}";

        // Add critical datetime formatting rule for date/datetime/timestamp columns
        $dateTimeTypes = ['date', 'datetime', 'timestamp', 'datetimetz'];
        if (in_array(strtolower($column['type']), $dateTimeTypes, true)) {
            $parts[] = "CRITICAL: You MUST format this date exactly as 'YYYY-MM-DD HH:MM:SS' (e.g., '2024-01-15 10:00:00'). DO NOT use ISO 8601, and DO NOT include 'T' or 'Z'.";
        }

        $maxLength = $column['max_length'] ?? null;

        if ($maxLength !== null) {
            $parts[] = "MAX LENGTH: {$maxLength} characters — your value MUST NOT exceed {$maxLength} chars";
        }

        $enumValues = $column['enum_values'] ?? [];

        if (! empty($enumValues)) {
            $quoted = implode(', ', array_map(fn (string $v) => "'{$v}'", $enumValues));
            $parts[] = "ENUM — you MUST select strictly from: [{$quoted}]";
        }

        if ($column['unique'] ?? false) {
            $parts[] = 'Must be UNIQUE across all rows';
        }

        if ($column['nullable'] ?? false) {
            $parts[] = 'Can be null';
        }

        if ($column['is_json'] ?? false) {
            $name = strtolower($column['name']);
            $listPatterns = ['objectives', 'prerequisites', 'keywords', 'tags', 'skills', 'features', 'items', 'categories', 'roles', 'permissions', 'images', 'attachments', 'links', 'recipients', 'emails', 'phones', 'languages', 'topics', 'benefits', 'requirements', 'highlights', 'amenities', 'options'];

            if (in_array($name, $listPatterns, true) || str_ends_with($name, 's')) {
                $parts[] = 'JSON COLUMN — this column represents a list of items. You MUST return a flat JSON Array of strings like ["item1","item2","item3"]. Do NOT return a JSON Object.';
            } else {
                $parts[] = 'JSON COLUMN — this column represents structured key-value data. You MUST return a JSON Object like {"key":"value"}. Do NOT return a flat array.';
            }
        }

        return implode('. ', $parts);
    }

    /**
     * Build the text prompt sent to the AI agent.
     */
    private function buildPrompt(array $schema, int $count, string $language = 'en', ?string $contextCode = null): string
    {
        $table = $schema['table'];
        $columnDescriptions = [];
        $jsonColumnNames = [];
        $foreignKeyColumns = $this->getForeignKeyColumns($schema);

        foreach ($schema['columns'] as $column) {
            if ($this->shouldExcludeColumn($column, $foreignKeyColumns)) {
                continue;
            }

            $desc = "- `{$column['name']}` ({$column['type']})";

            $maxLength = $column['max_length'] ?? null;

            if ($maxLength !== null) {
                $desc .= " [MAX {$maxLength} chars]";
            }

            $enumValues = $column['enum_values'] ?? [];

            if (! empty($enumValues)) {
                $quoted = implode(', ', array_map(fn (string $v) => "'{$v}'", $enumValues));
                $desc .= " [ENUM → ONLY use: {$quoted}]";
            }

            if ($column['nullable'] ?? false) {
                $desc .= ' [nullable]';
            }

            if ($column['unique'] ?? false) {
                $desc .= ' [unique]';
            }

            if ($column['is_json'] ?? false) {
                $colName = strtolower($column['name']);
                $listPatterns = ['objectives', 'prerequisites', 'keywords', 'tags', 'skills', 'features', 'items', 'categories', 'roles', 'permissions', 'images', 'attachments', 'links', 'recipients', 'emails', 'phones', 'languages', 'topics', 'benefits', 'requirements', 'highlights', 'amenities', 'options'];

                if (in_array($colName, $listPatterns, true) || str_ends_with($colName, 's')) {
                    $desc .= ' [JSON — MUST be a flat Array of strings like ["item1","item2"]]';
                } else {
                    $desc .= ' [JSON — MUST be an Object like {"key":"value"}]';
                }
                $jsonColumnNames[] = $column['name'];
            }

            $columnDescriptions[] = $desc;
        }

        $columnsText = implode("\n", $columnDescriptions);

        $jsonWarning = '';

        if (! empty($jsonColumnNames)) {
            $names = implode(', ', array_map(fn ($n) => "`{$n}`", $jsonColumnNames));
            $jsonWarning = <<<WARNING

            CRITICAL JSON RULE:
            The following columns are JSON columns: {$names}.
            For these columns you MUST return a valid JSON structure, NOT a plain string.
            - If the column name implies a list of items (e.g., objectives, prerequisites, keywords, tags, skills, features — typically plural nouns), you MUST return a flat JSON Array of strings: ["item1", "item2", "item3"]. Do NOT wrap list data in an Object.
            - If the column name implies key-value/structured data (e.g., content, conditions, settings, metadata, config), or PHP validation rules use dot-notation sub-keys (e.g., content.location), you MUST return a JSON Object: {"key": "value"}.
            - NEVER output a flat sequential array of alternating keys and values like ["location", "Riyadh", "date", "2024-01-01"]. That is ALWAYS wrong.
            WARNING;
        }

        $languageInstruction = $this->buildLanguageInstruction($language);

        $contextSection = $this->buildContextSection($contextCode);

        return <<<PROMPT
        Generate exactly {$count} realistic rows for the `{$table}` table.

        Table columns (primary keys, foreign keys, passwords, and timestamps are auto-generated — do NOT include them):
        {$columnsText}

        Requirements:
        - Generate EXACTLY {$count} rows.
        - Use realistic, contextually appropriate values (e.g., real-looking names, emails, addresses).
        - Ensure all unique columns have distinct values across every row.
        {$jsonWarning}
        {$languageInstruction}
        {$contextSection}
        PROMPT;
    }

    /**
     * Build a language instruction string for the AI prompt.
     *
     * Supports any single language code (e.g., 'fr', 'ar') or a
     * comma-separated list of languages (e.g., 'ar,en', 'es,pt,fr').
     * Returns an empty string for the default 'en'.
     */
    private function buildLanguageInstruction(string $language): string
    {
        $languages = array_values(array_filter(
            array_map('trim', explode(',', $language)),
            fn (string $l): bool => $l !== '',
        ));

        if (empty($languages)) {
            return '';
        }

        // Single default language — no special instruction needed
        if (count($languages) === 1 && strtolower($languages[0]) === 'en') {
            return '';
        }

        if (count($languages) === 1) {
            $lang = $languages[0];

            return <<<LANG

            CRITICAL LANGUAGE RULE:
            All generated text for human-readable string/text columns (names, titles, descriptions, bios, etc.) MUST be entirely in natural, realistic {$lang} language.
            Use authentic {$lang} names, titles, and content that a native speaker would recognise as natural.
            Do NOT use English for any human-readable text content. Only technical values (emails, URLs, timestamps, IDs) may remain in ASCII/Latin characters.
            LANG;
        }

        // Multiple languages
        $langList = implode(', ', $languages);

        return <<<LANG

        CRITICAL LANGUAGE RULE:
        The generated text for human-readable string/text columns (names, titles, descriptions, bios, etc.) MUST be a realistic mix of the following languages: {$langList}.
        Distribute the languages naturally across the generated rows to simulate a multi-lingual dataset.
        Each row's human-readable fields should predominantly use one of the listed languages, but vary across rows so that all specified languages are well represented.
        Only technical values (emails, URLs, timestamps, IDs) should always remain in ASCII/Latin characters.
        LANG;
    }

    /**
     * Build a context section for the AI prompt from raw PHP source code.
     *
     * When provided, this injects the full source of a related class
     * (FormRequest, Model, etc.) so the AI can analyze validation rules,
     * morph maps, casts, and other code-defined constraints.
     */
    private function buildContextSection(?string $contextCode): string
    {
        if ($contextCode === null || trim($contextCode) === '') {
            return '';
        }

        return <<<CONTEXT

        ### BUSINESS LOGIC & VALIDATION RULES ###
        Below is the raw PHP code defining the rules and relationships for this data.
        You MUST deeply analyze this code (e.g., conditional validation rules, morph maps, custom casts, JSON structure definitions).
        Ensure your generated JSON fully complies with these strict rules:

        ```php
        {$contextCode}
        ```
        CONTEXT;
    }

    /**
     * Post-process AI-generated rows:
     * - Sanitize string "null" → actual null for nullable columns
     * - Convert ISO 8601 datetime strings to MySQL YYYY-MM-DD HH:MM:SS format
     * - Inject ULID/UUID primary keys
     * - Inject hashed passwords for password columns
     * - Inject random valid foreign key IDs from resolved parent tables
     * - Inject native Laravel timestamps (created_at, updated_at)
     * - json_encode() any JSON column values that are arrays/objects
     * - Truncate strings exceeding max_length (safety net)
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, array<int, int|string>>  $foreignKeyConstraints  Resolved parent IDs per FK column
     * @return array<int, array<string, mixed>>
     */
    private function postProcess(array $rows, array $schema, array $foreignKeyConstraints = []): array
    {
        $primaryKeyColumn = null;
        $primaryKeyType = null;
        $jsonColumns = [];
        $passwordColumns = [];
        $maxLengths = [];
        $enumConstraints = [];
        $nullableColumns = [];
        $timestampColumns = [];
        $dateTimeColumns = [];

        foreach ($schema['columns'] as $column) {
            if (($column['primary_key'] ?? false) && in_array($column['key_type'] ?? '', ['ulid', 'uuid'], true)) {
                $primaryKeyColumn = $column['name'];
                $primaryKeyType = $column['key_type'];
            }

            if ($column['is_json'] ?? false) {
                $jsonColumns[] = $column['name'];
            }

            if ($column['is_password'] ?? false) {
                $passwordColumns[] = $column['name'];
            }

            if (($column['max_length'] ?? null) !== null) {
                $maxLengths[$column['name']] = $column['max_length'];
            }

            if (! empty($column['enum_values'] ?? [])) {
                $enumConstraints[$column['name']] = $column['enum_values'];
            }

            if ($column['nullable'] ?? false) {
                $nullableColumns[] = $column['name'];
            }

            // Track which timestamp columns exist on this table
            if (in_array($column['name'], self::TIMESTAMP_COLUMNS, true)) {
                $timestampColumns[] = $column['name'];
            }

            // Track date/datetime/timestamp columns that need formatting sanitation
            if ($column['is_datetime'] ?? false) {
                $dateTimeColumns[] = $column['name'];
            }
        }

        $now = now()->format('Y-m-d H:i:s');

        return array_map(function (array $row) use (
            $primaryKeyColumn,
            $primaryKeyType,
            $jsonColumns,
            $passwordColumns,
            $maxLengths,
            $enumConstraints,
            $foreignKeyConstraints,
            $nullableColumns,
            $timestampColumns,
            $dateTimeColumns,
            $now,
        ): array {
            // --- Data Sanitation: string "null" → actual null ---
            // LLMs often output the literal string "null" instead of JSON null.
            // For nullable columns, cast "null", "NULL", and empty strings to PHP null.
            foreach ($nullableColumns as $nullableColumn) {
                if (! array_key_exists($nullableColumn, $row)) {
                    continue;
                }

                $value = $row[$nullableColumn];

                if (is_string($value) && (strtolower(trim($value)) === 'null' || trim($value) === '')) {
                    $row[$nullableColumn] = null;
                }
            }

            // --- Data Sanitation: ISO 8601 → MySQL datetime format ---
            // LLMs often generate ISO 8601 datetime strings (e.g., '2024-01-15T10:00:00Z')
            // which MySQL rejects. Convert them to MySQL format: 'YYYY-MM-DD HH:MM:SS'
            foreach ($dateTimeColumns as $dateTimeColumn) {
                if (! array_key_exists($dateTimeColumn, $row)) {
                    continue;
                }

                $value = $row[$dateTimeColumn];

                if (is_null($value)) {
                    continue;
                }

                // Check if value matches ISO 8601 datetime pattern (contains 'T' and 'Z' or timezone offset)
                if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $value)) {
                    try {
                        // Parse ISO 8601 datetime and convert to MySQL format
                        $row[$dateTimeColumn] = \Illuminate\Support\Carbon::parse($value)->toDateTimeString();
                    } catch (\Throwable) {
                        // If parsing fails, leave the value as-is and let MySQL validation catch it
                        // This ensures we don't silently drop invalid data
                    }
                }
            }

            // Inject ULID/UUID primary key
            if ($primaryKeyColumn) {
                $row[$primaryKeyColumn] = match ($primaryKeyType) {
                    'ulid' => (string) Str::ulid(),
                    'uuid' => (string) Str::uuid(),
                    default => $row[$primaryKeyColumn] ?? null,
                };
            }

            // Inject hashed password
            foreach ($passwordColumns as $passwordColumn) {
                $row[$passwordColumn] = Hash::make('password');
            }

            // Inject foreign key values from resolved parent IDs.
            // For self-referencing tables, parentIds may be empty on initial seed —
            // in that case, set null if the column is nullable.
            foreach ($foreignKeyConstraints as $fkColumn => $parentIds) {
                if (! empty($parentIds)) {
                    $row[$fkColumn] = $parentIds[array_rand($parentIds)];
                } elseif (in_array($fkColumn, $nullableColumns, true)) {
                    $row[$fkColumn] = null;
                }
            }

            // Encode JSON columns
            foreach ($jsonColumns as $jsonColumn) {
                if (! array_key_exists($jsonColumn, $row)) {
                    continue;
                }

                $value = $row[$jsonColumn];

                if (is_null($value)) {
                    continue;
                }

                if (is_array($value)) {
                    $row[$jsonColumn] = json_encode($value, JSON_UNESCAPED_UNICODE);
                } elseif (is_string($value)) {
                    $decoded = json_decode($value, true);

                    if (json_last_error() === JSON_ERROR_NONE && (is_array($decoded) || is_object($decoded))) {
                        $row[$jsonColumn] = $value;
                    } else {
                        $row[$jsonColumn] = json_encode([$value], JSON_UNESCAPED_UNICODE);
                    }
                }
            }

            // Truncate strings exceeding max_length (safety net if AI ignores the constraint)
            foreach ($maxLengths as $columnName => $maxLength) {
                if (isset($row[$columnName]) && is_string($row[$columnName]) && mb_strlen($row[$columnName]) > $maxLength) {
                    $row[$columnName] = mb_substr($row[$columnName], 0, $maxLength);
                }
            }

            // Enforce ENUM constraints (safety net if AI returns an invalid value)
            foreach ($enumConstraints as $columnName => $allowedValues) {
                if (isset($row[$columnName]) && ! in_array($row[$columnName], $allowedValues, true)) {
                    $row[$columnName] = $allowedValues[0];
                }
            }

            // Inject native Laravel timestamps
            foreach ($timestampColumns as $tsColumn) {
                // created_at and updated_at get the current time;
                // deleted_at stays null (soft-deletes should default to not-deleted)
                if ($tsColumn === 'deleted_at') {
                    $row[$tsColumn] = null;
                } else {
                    $row[$tsColumn] = $now;
                }
            }

            return $row;
        }, $rows);
    }

    /**
     * Invoke the AI agent with retry logic.
     *
     * @return array{rows: array<int, array<string, mixed>>, prompt_tokens: int, completion_tokens: int}
     *
     * @throws RuntimeException
     */
    private function invokeWithRetry(string $prompt, int $count, array $columnDefinitions, string $language = 'en'): array
    {
        $maxRetries = (int) config('ai-seeder.max_retries', 3);
        $lastException = null;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $agent = new SeederAgent(
                    schemaDescription: $prompt,
                    count: $count,
                    columnDefinitions: $columnDefinitions,
                    language: $language,
                );

                $response = $agent->prompt($prompt);

                $rows = $this->parseResponse($response);

                $this->validateRowCount($rows, $count);

                // Extract token usage from the AI SDK response
                $promptTokens = 0;
                $completionTokens = 0;

                if (isset($response->usage)) {
                    $promptTokens = $response->usage->promptTokens ?? 0;
                    $completionTokens = $response->usage->completionTokens ?? 0;
                }

                return [
                    'rows' => $rows,
                    'prompt_tokens' => $promptTokens,
                    'completion_tokens' => $completionTokens,
                ];
            } catch (\Throwable $e) {
                $lastException = $e;

                Log::warning("AiSeeder: Attempt {$attempt}/{$maxRetries} failed", [
                    'error' => $e->getMessage(),
                ]);

                if ($attempt < $maxRetries) {
                    usleep(500_000 * $attempt);
                }
            }
        }

        throw new RuntimeException(
            "AiSeeder: Failed to generate data after {$maxRetries} attempts. Last error: {$lastException?->getMessage()}"
        );
    }

    /**
     * Parse the AI response into an array of rows.
     *
     * @return array<int, array<string, mixed>>
     */
    private function parseResponse(mixed $response): array
    {
        if (method_exists($response, 'toArray')) {
            $data = $response->toArray();

            if (isset($data['rows']) && is_array($data['rows'])) {
                return $data['rows'];
            }

            return $data;
        }

        $text = (string) $response;

        $text = $this->stripMarkdownWrappers($text);

        $decoded = json_decode($text, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('AI returned invalid JSON: '.json_last_error_msg());
        }

        if (isset($decoded['rows'])) {
            return $decoded['rows'];
        }

        return $decoded;
    }

    /**
     * Strip markdown code-block wrappers from the AI response.
     */
    private function stripMarkdownWrappers(string $text): string
    {
        $text = trim($text);

        if (str_starts_with($text, '```json')) {
            $text = substr($text, 7);
        } elseif (str_starts_with($text, '```')) {
            $text = substr($text, 3);
        }

        if (str_ends_with($text, '```')) {
            $text = substr($text, 0, -3);
        }

        return trim($text);
    }

    /**
     * Validate that the AI returned the expected number of rows.
     *
     * @throws RuntimeException
     */
    private function validateRowCount(array $rows, int $expected): void
    {
        $actual = count($rows);

        if ($actual !== $expected) {
            throw new RuntimeException(
                "Expected {$expected} rows but AI returned {$actual}."
            );
        }
    }
}
