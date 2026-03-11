<?php

declare(strict_types=1);

namespace Shennawy\AiSeeder;

use Closure;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Shennawy\AiSeeder\Contracts\RelationshipResolverInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

class RelationshipResolver implements RelationshipResolverInterface
{
    /**
     * Tables currently being resolved, used to detect circular dependencies.
     *
     * @var array<string, bool>
     */
    private array $resolving = [];

    /**
     * Optional callback for reporting progress messages to the console.
     */
    private ?Closure $onProgress = null;

    /**
     * Optional output interface for streaming recursive command output.
     */
    private ?OutputInterface $output = null;

    /**
     * Language code(s) to propagate to recursively seeded parent tables.
     */
    private string $language = 'en';

    /**
     * The table currently being seeded, used to detect self-referencing FKs.
     */
    private ?string $currentTable = null;

    /**
     * Set a progress callback for reporting status during recursive seeding.
     */
    public function onProgress(Closure $callback): self
    {
        $this->onProgress = $callback;

        return $this;
    }

    /**
     * Set the output interface for streaming recursive command output.
     *
     * When set, recursive ai:seed calls will write directly to this output,
     * making child command progress visible in the parent console.
     */
    public function setOutput(OutputInterface $output): self
    {
        $this->output = $output;

        return $this;
    }

    /**
     * Set the language code(s) to propagate to recursively seeded parent tables.
     *
     * Ensures parent tables are seeded in the same language as the main command.
     */
    public function setLanguage(string $language): self
    {
        $this->language = $language;

        return $this;
    }

    /**
     * Set the table currently being seeded.
     *
     * Used to detect self-referencing foreign keys (e.g., categories.parent_id → categories.id)
     * and handle them gracefully without triggering recursive seeding.
     */
    public function setCurrentTable(string $table): self
    {
        $this->currentTable = $table;

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function resolve(array $foreignKeys, int $minimumParentRows = 5): array
    {
        $constraints = [];

        foreach ($foreignKeys as $fk) {
            $constraints[$fk['column']] = $this->resolveSingle($fk, $minimumParentRows);
        }

        return $constraints;
    }

    /**
     * {@inheritDoc}
     */
    public function resolveSingle(array $foreignKey, int $minimumParentRows = 5): array
    {
        $foreignTable = $foreignKey['foreign_table'];
        $foreignColumn = $foreignKey['foreign_column'];

        // Self-referencing FK (e.g., categories.parent_id → categories.id).
        // Never trigger recursive seeding — just return whatever IDs exist.
        // If the table is empty (first seed), return [] so postProcess sets null.
        if ($this->currentTable !== null && $foreignTable === $this->currentTable) {
            $existingIds = DB::table($foreignTable)
                ->pluck($foreignColumn)
                ->all();

            $this->reportProgress(
                empty($existingIds)
                    ? "🔄 Self-referencing FK [{$foreignKey['column']}] on [{$foreignTable}] — table is empty, will use NULL."
                    : '🔄 Self-referencing FK ['.$foreignKey['column'].'] on ['.$foreignTable.'] — fetched '.count($existingIds).' existing ID(s).',
            );

            return $existingIds;
        }

        $existingIds = DB::table($foreignTable)
            ->pluck($foreignColumn)
            ->all();

        if (empty($existingIds)) {
            $existingIds = $this->seedParentTable($foreignTable, $foreignColumn, $minimumParentRows);
        }

        return $existingIds;
    }

    /**
     * Recursively seed a parent table that has no records
     * by calling the ai:seed Artisan command.
     *
     * Output is streamed to the parent console when an OutputInterface is set.
     * On failure, the captured output is included in the exception for debugging.
     *
     * @return array<int, int|string>
     */
    private function seedParentTable(string $table, string $column, int $count): array
    {
        if (isset($this->resolving[$table])) {
            throw new RuntimeException(
                "Circular dependency detected: table [{$table}] is already being resolved. "
                .'Please manually seed this table first.'
            );
        }

        $this->resolving[$table] = true;

        try {
            $this->reportProgress("⚠️  Parent table [{$table}] is empty. Recursively seeding it first...");

            $arguments = [
                'table' => $table,
                '--count' => $count,
                '--lang' => $this->language,
                '--no-interaction' => true,
            ];

            // Use a BufferedOutput as a fallback to capture output for error messages,
            // but prefer streaming to the parent console when an output is set.
            $outputBuffer = new BufferedOutput;

            if ($this->output) {
                // Stream directly to the parent console so the user sees everything.
                $exitCode = Artisan::call('ai:seed', $arguments, $this->output);
            } else {
                // No parent output — capture into buffer for error diagnostics.
                $exitCode = Artisan::call('ai:seed', $arguments, $outputBuffer);
            }

            if ($exitCode !== 0) {
                $captured = $this->output
                    ? Artisan::output()
                    : $outputBuffer->fetch();

                $errorDetail = trim($captured);
                $message = "Failed to recursively seed parent table [{$table}]. Exit code: {$exitCode}.";

                if ($errorDetail !== '') {
                    $message .= "\n\n--- Output from recursive command ---\n{$errorDetail}\n--- End of output ---";
                }

                throw new RuntimeException($message);
            }

            $ids = DB::table($table)->pluck($column)->all();

            if (empty($ids)) {
                throw new RuntimeException(
                    "Recursive seeding of parent table [{$table}] completed but no records were found. "
                    .'Please seed it manually first.'
                );
            }

            $this->reportProgress("✅ Parent table [{$table}] seeded with ".count($ids).' record(s).');

            return $ids;
        } finally {
            unset($this->resolving[$table]);
        }
    }

    /**
     * Report progress via the registered callback.
     */
    private function reportProgress(string $message): void
    {
        if ($this->onProgress) {
            ($this->onProgress)($message);
        }
    }
}
