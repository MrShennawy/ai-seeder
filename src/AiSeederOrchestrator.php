<?php

declare(strict_types=1);

namespace Shennawy\AiSeeder;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Shennawy\AiSeeder\Contracts\DataGeneratorInterface;
use Shennawy\AiSeeder\Contracts\RelationshipResolverInterface;
use Shennawy\AiSeeder\Contracts\SchemaAnalyzerInterface;

class AiSeederOrchestrator
{
    /**
     * Optional progress callback for console output.
     */
    private ?Closure $onProgress = null;

    /**
     * Tables already seeded during this run, to prevent infinite loops.
     *
     * @var array<string, bool>
     */
    private array $seeded = [];

    /**
     * Aggregated token usage tracker.
     */
    private TokenUsageTracker $tokenTracker;

    public function __construct(
        private readonly SchemaAnalyzerInterface $schemaAnalyzer,
        private readonly RelationshipResolverInterface $relationshipResolver,
        private readonly DataGeneratorInterface $dataGenerator,
    ) {
        $this->tokenTracker = new TokenUsageTracker;
    }

    /**
     * Set a progress callback for reporting status.
     *
     * The callback receives: (string $message, int $current, int $total)
     */
    public function onProgress(Closure $callback): self
    {
        $this->onProgress = $callback;

        return $this;
    }

    /**
     * Get the aggregated token usage tracker.
     */
    public function getTokenTracker(): TokenUsageTracker
    {
        return $this->tokenTracker;
    }

    /**
     * Seed a table with AI-generated data.
     *
     * @return int The number of rows inserted.
     */
    public function seed(string $table, int $count = 10, int $chunkSize = 50, string $language = 'en', ?string $contextCode = null): int
    {
        if (isset($this->seeded[$table])) {
            $this->reportProgress("Table [{$table}] already seeded in this run, skipping.", 0, 0);

            return 0;
        }

        $this->seeded[$table] = true;

        $schema = $this->schemaAnalyzer->analyze($table);

        if ($this->relationshipResolver instanceof RelationshipResolver) {
            $this->relationshipResolver->setCurrentTable($table);
        }

        $foreignKeyConstraints = $this->relationshipResolver->resolve(
            $schema['foreign_keys'],
            minimumParentRows: min($count, 5),
        );

        $nonMutableColumns = $this->resolveNonMutableColumns($schema);
        $columnMaxLengths = $this->resolveColumnMaxLengths($schema);

        $totalInserted = 0;
        $chunks = (int) ceil($count / $chunkSize);

        for ($chunk = 1; $chunk <= $chunks; $chunk++) {
            $remaining = $count - $totalInserted;
            $currentChunkSize = min($chunkSize, $remaining);

            $this->reportProgress(
                "Generating chunk {$chunk}/{$chunks} ({$currentChunkSize} rows) for [{$table}]...",
                $totalInserted,
                $count,
            );

            $result = $this->dataGenerator->generate($schema, $currentChunkSize, $foreignKeyConstraints, $language, $contextCode);

            $this->tokenTracker->add($result->promptTokens, $result->completionTokens);

            foreach ($result->rows as $row) {
                $this->insertWithRetry($table, $row, $nonMutableColumns, $columnMaxLengths);
            }

            $totalInserted += count($result->rows);

            $this->reportProgress(
                "Inserted chunk {$chunk}/{$chunks} for [{$table}].",
                $totalInserted,
                $count,
            );
        }

        return $totalInserted;
    }

    /**
     * Insert a single row with auto-mutation retry on unique constraint violations.
     *
     * Uses a smart, column-name-aware mutation strategy:
     * - Phone columns: replaces trailing digits to keep a valid format.
     * - Email columns: injects a random tag before the @ sign.
     * - Other known unique-prone columns: appends a short suffix.
     * - Generic long strings (>15 chars): appends a suffix as a fallback.
     * - Short generic strings: never touched.
     *
     * @param  array<string, mixed>  $row
     * @param  array<int, string>  $nonMutableColumns
     * @param  array<string, int>  $columnMaxLengths
     *
     * @throws QueryException If the error is not a unique constraint violation or all retries fail.
     */
    private function insertWithRetry(string $table, array $row, array $nonMutableColumns, array $columnMaxLengths = [], int $maxRetries = 3): void
    {
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                DB::table($table)->insert($row);

                return;
            } catch (QueryException $e) {
                if ($e->errorInfo[0] !== '23000' || ($e->errorInfo[1] ?? 0) != 1062) {
                    throw $e;
                }

                if ($attempt === $maxRetries) {
                    throw $e;
                }

                $row = $this->mutateRowForUniqueness($row, $nonMutableColumns, $columnMaxLengths);

                Log::warning("AiSeeder: Duplicate entry for [{$table}] — mutated row and retrying (attempt {$attempt}/{$maxRetries}).");
            }
        }
    }

    /**
     * Mutate a row's string values to resolve a unique constraint violation.
     *
     * @param  array<string, mixed>  $row
     * @param  array<int, string>  $nonMutableColumns
     * @param  array<string, int>  $columnMaxLengths
     * @return array<string, mixed>
     */
    private function mutateRowForUniqueness(array $row, array $nonMutableColumns, array $columnMaxLengths): array
    {
        $knownUniqueColumns = ['email', 'phone', 'code', 'slug', 'username', 'sku', 'mobile', 'telephone', 'coupon_code'];

        foreach ($row as $column => $value) {
            if (in_array($column, $nonMutableColumns, true)) {
                continue;
            }

            if (! is_string($value) || $value === '') {
                continue;
            }

            $lowerColumn = strtolower($column);
            $isKnownUnique = in_array($lowerColumn, $knownUniqueColumns, true)
                || str_contains($lowerColumn, 'phone')
                || str_contains($lowerColumn, 'email')
                || str_contains($lowerColumn, 'slug');

            if (! $isKnownUnique && mb_strlen($value) <= 15) {
                continue;
            }

            $maxLen = $columnMaxLengths[$column] ?? null;

            // Phone columns: replace trailing digits to keep a valid phone format
            if (str_contains($lowerColumn, 'phone') || str_contains($lowerColumn, 'mobile') || $lowerColumn === 'telephone') {
                $row[$column] = preg_replace('/\d{4}$/', (string) rand(1000, 9999), $value) ?? $value;
                continue;
            }

            // Email columns: inject a random tag before the @
            if (str_contains($lowerColumn, 'email')) {
                $atPos = strpos($value, '@');
                if ($atPos !== false) {
                    $local = substr($value, 0, $atPos);
                    $domain = substr($value, $atPos);
                    $mutated = $local.'+'.Str::random(4).$domain;
                    $row[$column] = $maxLen !== null ? mb_substr($mutated, 0, $maxLen) : $mutated;
                }
                continue;
            }

            // All other mutable columns: append a short random suffix
            $suffixLength = 5;
            $suffix = '-'.Str::random(4);

            if ($maxLen !== null) {
                $baseLen = max(1, $maxLen - $suffixLength);
                $row[$column] = mb_substr($value, 0, $baseLen).$suffix;
            } else {
                $row[$column] = $value.$suffix;
            }
        }

        return $row;
    }

    /**
     * Resolve column names that should NOT be mutated during duplicate-entry retries.
     *
     * @return array<int, string>
     */
    private function resolveNonMutableColumns(array $schema): array
    {
        $nonMutable = [];
        $foreignKeyColumns = array_map(fn (array $fk) => $fk['column'], $schema['foreign_keys'] ?? []);

        foreach ($schema['columns'] as $column) {
            $name = $column['name'];

            $shouldSkip = ($column['primary_key'] ?? false)
                || ($column['auto_increment'] ?? false)
                || ($column['is_json'] ?? false)
                || ($column['is_password'] ?? false)
                || ($column['is_datetime'] ?? false)
                || ! empty($column['enum_values'] ?? [])
                || in_array($name, $foreignKeyColumns, true)
                || in_array($name, ['created_at', 'updated_at', 'deleted_at'], true);

            if ($shouldSkip) {
                $nonMutable[] = $name;
            }
        }

        return $nonMutable;
    }

    /**
     * Resolve a map of column name → max_length from the schema.
     *
     * @return array<string, int>
     */
    private function resolveColumnMaxLengths(array $schema): array
    {
        $maxLengths = [];

        foreach ($schema['columns'] as $column) {
            if (($column['max_length'] ?? null) !== null) {
                $maxLengths[$column['name']] = $column['max_length'];
            }
        }

        return $maxLengths;
    }

    /**
     * Report progress via the registered callback.
     */
    private function reportProgress(string $message, int $current, int $total): void
    {
        if ($this->onProgress) {
            ($this->onProgress)($message, $current, $total);
        }
    }
}
