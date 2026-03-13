<?php

declare(strict_types=1);

namespace Shennawy\AiSeeder\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Shennawy\AiSeeder\ContextExtractor;
use Shennawy\AiSeeder\Contracts\DataGeneratorInterface;
use Shennawy\AiSeeder\Contracts\RelationshipResolverInterface;
use Shennawy\AiSeeder\Contracts\SchemaAnalyzerInterface;
use Shennawy\AiSeeder\GenerationResult;
use Shennawy\AiSeeder\TokenUsageTracker;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\note;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\progress;
use function Laravel\Prompts\search;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\table;
use function Laravel\Prompts\text;
use function Laravel\Prompts\warning;

class AiSeedCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'ai:seed
        {table? : The database table to seed}
        {--count=10 : Number of rows to generate}
        {--chunk=50 : Rows per AI request chunk}
        {--lang= : Language(s) for generated text — single code (e.g., fr) or comma-separated (e.g., ar,en)}
        {--context=* : Context sources — class names, file paths, or inline text (repeatable)}';

    /**
     * The console command description.
     */
    protected $description = 'Generate smart, context-aware dummy data for a database table using AI';

    public function __construct(
        private readonly SchemaAnalyzerInterface $schemaAnalyzer,
        private readonly RelationshipResolverInterface $relationshipResolver,
        private readonly DataGeneratorInterface $dataGenerator,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $table = $this->resolveTable();
        $count = $this->resolveCount();
        $chunkSize = (int) $this->option('chunk');

        intro('AiSeeder — Smart Database Seeder');

        // ── Step 1: Validate table existence ──
        if (! $this->schemaAnalyzer->tableExists($table)) {
            error("Table [{$table}] does not exist in the database.");

            return self::FAILURE;
        }

        // ── Step 2: Analyze schema ──
        info("🔍 Analyzing schema for table: [{$table}]...");

        $schema = spin(
            callback: fn () => $this->schemaAnalyzer->analyze($table),
            message: 'Reading columns, indexes, and constraints...',
        );

        $this->displaySchemaInfo($table, $schema, $count);

        // ── Step 2b: Resolve language ──
        $language = $this->resolveLanguage();

        // ── Step 2c: Resolve code context ──
        $contextCode = $this->resolveContext();

        // ── Step 3: Resolve relationships ──
        $foreignKeyConstraints = [];

        if (! empty($schema['foreign_keys'])) {
            info('🔗 Resolving relationships...');

            if ($this->relationshipResolver instanceof \Shennawy\AiSeeder\RelationshipResolver) {
                $this->relationshipResolver->setCurrentTable($table);
                $this->relationshipResolver->setOutput($this->output);
                $this->relationshipResolver->setLanguage($language);
                $this->relationshipResolver->onProgress(function (string $message): void {
                    warning($message);
                });
            }

            $minimumParentRows = min($count, 5);

            foreach ($schema['foreign_keys'] as $fk) {
                $column = $fk['column'];
                $foreignTable = $fk['foreign_table'];
                $foreignColumn = $fk['foreign_column'];

                note("🔍 Resolving: {$column} → {$foreignTable}.{$foreignColumn}");

                // No spin() here — if recursive seeding is triggered, the child
                // command will print its own spinners/progress directly to our output.
                $ids = $this->relationshipResolver->resolveSingle($fk, $minimumParentRows);

                $foreignKeyConstraints[$column] = $ids;

                info('  ✅ Fetched '.count($ids)." ID(s) from [{$foreignTable}].");
            }
        } else {
            note('  No foreign keys detected — skipping relationship resolution.');
        }

        // ── Step 4: Confirm before proceeding ──
        $chunks = (int) ceil($count / $chunkSize);
        $languageLabel = strtoupper($language);
        note("⚙️  Plan: {$count} rows in {$chunks} chunk(s) of up to {$chunkSize} rows each. Language: {$languageLabel}.");

        if (! confirm(label: "Proceed with seeding [{$table}]?", default: true)) {
            warning('Aborted by user.');

            return self::SUCCESS;
        }

        // ── Step 5: Generate & insert per chunk ──
        $totalInserted = 0;
        $tokenTracker = new TokenUsageTracker;

        try {
            for ($chunk = 1; $chunk <= $chunks; $chunk++) {
                $remaining = $count - $totalInserted;
                $currentChunkSize = min($chunkSize, $remaining);

                // 5a: AI generation with spinner
                info("🧠 Generating chunk {$chunk}/{$chunks} ({$currentChunkSize} rows)...");

                /** @var GenerationResult $result */
                $result = spin(
                    callback: fn () => $this->dataGenerator->generate(
                        $schema,
                        $currentChunkSize,
                        $foreignKeyConstraints,
                        $language,
                        $contextCode,
                    ),
                    message: 'Waiting for AI to generate data (this may take a moment)...',
                );

                $tokenTracker->add($result->promptTokens, $result->completionTokens);

                note('  ✓ AI returned '.count($result->rows)." row(s). Tokens: {$result->promptTokens} prompt + {$result->completionTokens} completion.");

                // 5b: Database insertion with progress bar
                $label = "💾 Inserting chunk {$chunk}/{$chunks} into [{$table}]";

                progress(
                    label: $label,
                    steps: $result->rows,
                    callback: function (array $row) use ($table): void {
                        DB::table($table)->insert($row);
                    },
                    hint: 'Inserting rows one-by-one...',
                );

                $totalInserted += count($result->rows);
            }
        } catch (\Throwable $e) {
            $this->newLine();
            error("Failed to seed [{$table}]: {$e->getMessage()}");

            if ($totalInserted > 0) {
                warning("⚠  {$totalInserted} row(s) were already inserted before the error.");
            }

            $this->displayTokenSummary($tokenTracker);

            return self::FAILURE;
        }

        // ── Step 6: Summary ──
        $this->newLine();
        outro("✅ Successfully seeded [{$table}] with {$totalInserted} rows.");

        $this->displayTokenSummary($tokenTracker);

        return self::SUCCESS;
    }

    /**
     * Resolve the table name — from argument or interactive prompt.
     */
    private function resolveTable(): string
    {
        $table = $this->argument('table');

        if ($table) {
            return $table;
        }

        $tables = $this->schemaAnalyzer->getTables();

        return search(
            label: 'Which table would you like to seed?',
            options: fn (string $search) => collect($tables)
                ->filter(fn (string $t) => str_contains($t, $search))
                ->values()
                ->all(),
            placeholder: 'Start typing a table name...',
        );
    }

    /**
     * Resolve the row count — from option or interactive prompt.
     */
    private function resolveCount(): int
    {
        $count = (int) $this->option('count');

        if ($count > 0) {
            return $count;
        }

        return (int) text(
            label: 'How many rows would you like to generate?',
            placeholder: '10',
            default: '10',
            validate: fn (string $value) => is_numeric($value) && (int) $value > 0
                ? null
                : 'Please enter a positive number.',
        );
    }

    /**
     * Resolve the language(s) — from --lang option or interactive prompt.
     *
     * Accepts any language code or comma-separated list (e.g., 'fr', 'ar,en', 'es,pt,fr').
     * Defaults to 'en'.
     */
    private function resolveLanguage(): string
    {
        $lang = $this->option('lang');

        if (is_string($lang) && trim($lang) !== '') {
            return trim($lang);
        }

        if ($this->input->isInteractive()) {
            return text(
                label: '🌐 What language(s) should the generated data be in? (comma-separated for multiple, e.g., ar,en)',
                placeholder: 'en',
                default: 'en',
            );
        }

        return 'en';
    }

    /**
     * Resolve the code/file context — from --context option(s).
     *
     * Accepts multiple sources: PHP class names, file paths (any type), or raw inline text.
     * Each --context value is resolved independently and concatenated.
     * Returns null if no context is provided.
     */
    private function resolveContext(): ?string
    {
        /** @var array<int, string> $contextInputs */
        $contextInputs = $this->option('context');

        if (empty($contextInputs)) {
            return null;
        }

        $extractor = new ContextExtractor;
        $contextBlocks = [];

        foreach ($contextInputs as $input) {
            $input = trim($input);

            if ($input === '') {
                continue;
            }

            info("📄 Loading context: {$input}");

            try {
                $result = $extractor->extract($input);

                $lineCount = substr_count($result['content'], "\n") + 1;
                note("  ✓ [{$result['label']}] — {$lineCount} lines loaded.");

                $contextBlocks[] = $result;
            } catch (\RuntimeException $e) {
                error("  ✗ {$e->getMessage()}");
                warning('  Skipping this context source.');
            }
        }

        if (empty($contextBlocks)) {
            return null;
        }

        // Combine all context blocks into a single string
        $combined = '';

        foreach ($contextBlocks as $index => $block) {
            $number = $index + 1;
            $combined .= "\n--- Context Source #{$number}: {$block['label']} ---\n";
            $combined .= $block['content'];
            $combined .= "\n";
        }

        return $combined;
    }

    /**
     * Display a schema summary using laravel/prompts table.
     */
    private function displaySchemaInfo(string $table, array $schema, int $count): void
    {
        note("📋 Table: {$table} — {$count} row(s) to generate");

        $columnRows = [];

        foreach ($schema['columns'] as $column) {
            $flags = [];

            if ($column['primary_key'] ?? false) {
                $keyType = strtoupper($column['key_type'] ?? 'PK');
                $flags[] = "PK ({$keyType})";
            }

            if ($column['auto_increment'] ?? false) {
                $flags[] = 'AUTO_INC';
            }

            if ($column['unique'] ?? false) {
                $flags[] = 'UNIQUE';
            }

            if ($column['nullable'] ?? false) {
                $flags[] = 'NULL';
            }

            if ($column['is_json'] ?? false) {
                $flags[] = 'JSON';
            }

            if ($column['is_password'] ?? false) {
                $flags[] = 'PASSWORD';
            }

            if (($column['max_length'] ?? null) !== null) {
                $flags[] = "LEN({$column['max_length']})";
            }

            if (! empty($column['enum_values'] ?? [])) {
                $flags[] = 'ENUM('.implode('|', $column['enum_values']).')';
            }

            $columnRows[] = [
                $column['name'],
                $column['type'],
                implode(', ', $flags) ?: '—',
            ];
        }

        table(
            headers: ['Column', 'Type', 'Flags'],
            rows: $columnRows,
        );
    }

    /**
     * Display a token usage summary table.
     */
    private function displayTokenSummary(TokenUsageTracker $tracker): void
    {
        if ($tracker->getTotalTokens() === 0) {
            return;
        }

        $this->newLine();
        note('📊 Token Usage Summary');

        table(
            headers: ['Metric', 'Tokens'],
            rows: [
                ['Prompt tokens', number_format($tracker->getPromptTokens())],
                ['Completion tokens', number_format($tracker->getCompletionTokens())],
                ['Total tokens', number_format($tracker->getTotalTokens())],
            ],
        );
    }
}
