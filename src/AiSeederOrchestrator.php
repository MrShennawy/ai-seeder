<?php

declare(strict_types=1);

namespace Shennawy\AiSeeder;

use Closure;
use Illuminate\Support\Facades\DB;
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

            DB::table($table)->insert($result->rows);

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
     * Report progress via the registered callback.
     */
    private function reportProgress(string $message, int $current, int $total): void
    {
        if ($this->onProgress) {
            ($this->onProgress)($message, $current, $total);
        }
    }
}
