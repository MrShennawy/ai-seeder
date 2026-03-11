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
                $this->insertWithRetry($table, $row, $nonMutableColumns);
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
     * @param  array<string, mixed>  $row
     * @param  array<int, string>  $nonMutableColumns
     *
     * @throws QueryException If the error is not a unique constraint violation or all retries fail.
     */
    private function insertWithRetry(string $table, array $row, array $nonMutableColumns, int $maxRetries = 3): void
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

                foreach ($row as $column => $value) {
                    if (in_array($column, $nonMutableColumns, true)) {
                        continue;
                    }

                    if (is_string($value) && $value !== '') {
                        $row[$column] = $value.'-'.Str::random(4);
                    }
                }

                Log::warning("AiSeeder: Duplicate entry for [{$table}] — mutated row and retrying (attempt {$attempt}/{$maxRetries}).");
            }
        }
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
     * Report progress via the registered callback.
     */
    private function reportProgress(string $message, int $current, int $total): void
    {
        if ($this->onProgress) {
            ($this->onProgress)($message, $current, $total);
        }
    }
}
