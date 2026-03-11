<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Shennawy\AiSeeder\AiSeederOrchestrator;
use Shennawy\AiSeeder\Contracts\DataGeneratorInterface;
use Shennawy\AiSeeder\Contracts\RelationshipResolverInterface;
use Shennawy\AiSeeder\Contracts\SchemaAnalyzerInterface;
use Shennawy\AiSeeder\GenerationResult;

beforeEach(function () {
    Schema::create('ai_seeder_test_authors', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->timestamps();
    });

    Schema::create('ai_seeder_test_courses', function ($table) {
        $table->ulid('id')->primary();
        $table->string('title');
        $table->json('objectives')->nullable();
        $table->json('keywords');
        $table->timestamps();
    });
});

afterEach(function () {
    Schema::dropIfExists('ai_seeder_test_courses');
    Schema::dropIfExists('ai_seeder_test_authors');
});

function mockAuthorSchema(): array
{
    return [
        'table' => 'ai_seeder_test_authors',
        'columns' => [
            ['name' => 'id', 'type' => 'integer', 'nullable' => false, 'unique' => true, 'auto_increment' => true, 'primary_key' => true, 'is_json' => false, 'key_type' => 'auto_increment', 'max_length' => null, 'enum_values' => [], 'is_password' => false],
            ['name' => 'name', 'type' => 'varchar', 'nullable' => false, 'unique' => false, 'auto_increment' => false, 'primary_key' => false, 'is_json' => false, 'key_type' => 'none', 'max_length' => 255, 'enum_values' => [], 'is_password' => false],
            ['name' => 'email', 'type' => 'varchar', 'nullable' => false, 'unique' => true, 'auto_increment' => false, 'primary_key' => false, 'is_json' => false, 'key_type' => 'none', 'max_length' => 255, 'enum_values' => [], 'is_password' => false],
            ['name' => 'created_at', 'type' => 'timestamp', 'nullable' => true, 'unique' => false, 'auto_increment' => false, 'primary_key' => false, 'is_json' => false, 'key_type' => 'none', 'max_length' => null, 'enum_values' => [], 'is_password' => false],
            ['name' => 'updated_at', 'type' => 'timestamp', 'nullable' => true, 'unique' => false, 'auto_increment' => false, 'primary_key' => false, 'is_json' => false, 'key_type' => 'none', 'max_length' => null, 'enum_values' => [], 'is_password' => false],
        ],
        'foreign_keys' => [],
    ];
}

test('it seeds a table using the orchestrator with mocked AI', function () {
    $schemaAnalyzer = Mockery::mock(SchemaAnalyzerInterface::class);
    $schemaAnalyzer->shouldReceive('analyze')
        ->with('ai_seeder_test_authors')
        ->andReturn(mockAuthorSchema());

    $relationshipResolver = Mockery::mock(RelationshipResolverInterface::class);
    $relationshipResolver->shouldReceive('resolve')
        ->andReturn([]);

    $dataGenerator = Mockery::mock(DataGeneratorInterface::class);
    $dataGenerator->shouldReceive('generate')
        ->once()
        ->andReturn(new GenerationResult(
            rows: [
                ['name' => 'Alice Johnson', 'email' => 'alice@example.com', 'created_at' => '2025-01-01 00:00:00', 'updated_at' => '2025-01-01 00:00:00'],
                ['name' => 'Bob Smith', 'email' => 'bob@example.com', 'created_at' => '2025-01-02 00:00:00', 'updated_at' => '2025-01-02 00:00:00'],
                ['name' => 'Carol White', 'email' => 'carol@example.com', 'created_at' => '2025-01-03 00:00:00', 'updated_at' => '2025-01-03 00:00:00'],
            ],
            promptTokens: 150,
            completionTokens: 200,
        ));

    $orchestrator = new AiSeederOrchestrator($schemaAnalyzer, $relationshipResolver, $dataGenerator);

    $inserted = $orchestrator->seed('ai_seeder_test_authors', 3);

    expect($inserted)->toBe(3);
    expect(DB::table('ai_seeder_test_authors')->count())->toBe(3);
    expect(DB::table('ai_seeder_test_authors')->where('name', 'Alice Johnson')->exists())->toBeTrue();
});

test('it processes large counts in chunks', function () {
    $schemaAnalyzer = Mockery::mock(SchemaAnalyzerInterface::class);
    $schemaAnalyzer->shouldReceive('analyze')
        ->with('ai_seeder_test_authors')
        ->andReturn(mockAuthorSchema());

    $relationshipResolver = Mockery::mock(RelationshipResolverInterface::class);
    $relationshipResolver->shouldReceive('resolve')->andReturn([]);

    $callCount = 0;
    $dataGenerator = Mockery::mock(DataGeneratorInterface::class);
    $dataGenerator->shouldReceive('generate')
        ->times(3)
        ->andReturnUsing(function ($schema, $count) use (&$callCount) {
            $callCount++;
            $rows = [];

            for ($i = 0; $i < $count; $i++) {
                $idx = (($callCount - 1) * 50) + $i + 1;
                $rows[] = [
                    'name' => "User {$idx}",
                    'email' => "user{$idx}@example.com",
                    'created_at' => '2025-01-01 00:00:00',
                    'updated_at' => '2025-01-01 00:00:00',
                ];
            }

            return new GenerationResult(rows: $rows, promptTokens: 100, completionTokens: 150);
        });

    $orchestrator = new AiSeederOrchestrator($schemaAnalyzer, $relationshipResolver, $dataGenerator);

    $inserted = $orchestrator->seed('ai_seeder_test_authors', 120, chunkSize: 50);

    expect($inserted)->toBe(120);
    expect(DB::table('ai_seeder_test_authors')->count())->toBe(120);
});

test('it reports progress via callback', function () {
    $schemaAnalyzer = Mockery::mock(SchemaAnalyzerInterface::class);
    $schemaAnalyzer->shouldReceive('analyze')
        ->andReturn(mockAuthorSchema());

    $relationshipResolver = Mockery::mock(RelationshipResolverInterface::class);
    $relationshipResolver->shouldReceive('resolve')->andReturn([]);

    $dataGenerator = Mockery::mock(DataGeneratorInterface::class);
    $dataGenerator->shouldReceive('generate')
        ->andReturn(new GenerationResult(
            rows: [
                ['name' => 'Test', 'email' => 'test@example.com', 'created_at' => '2025-01-01 00:00:00', 'updated_at' => '2025-01-01 00:00:00'],
            ],
            promptTokens: 50,
            completionTokens: 80,
        ));

    $messages = [];
    $orchestrator = new AiSeederOrchestrator($schemaAnalyzer, $relationshipResolver, $dataGenerator);
    $orchestrator->onProgress(function (string $message) use (&$messages) {
        $messages[] = $message;
    });

    $orchestrator->seed('ai_seeder_test_authors', 1);

    expect($messages)->not->toBeEmpty();
    expect($messages[0])->toContain('Generating chunk');
});

test('it injects ULIDs for ULID primary key tables via DataGenerator post-processing', function () {
    $courseSchema = [
        'table' => 'ai_seeder_test_courses',
        'columns' => [
            ['name' => 'id', 'type' => 'char', 'nullable' => false, 'unique' => true, 'auto_increment' => false, 'primary_key' => true, 'is_json' => false, 'key_type' => 'ulid', 'max_length' => 26, 'enum_values' => [], 'is_password' => false],
            ['name' => 'title', 'type' => 'varchar', 'nullable' => false, 'unique' => false, 'auto_increment' => false, 'primary_key' => false, 'is_json' => false, 'key_type' => 'none', 'max_length' => 255, 'enum_values' => [], 'is_password' => false],
            ['name' => 'objectives', 'type' => 'json', 'nullable' => true, 'unique' => false, 'auto_increment' => false, 'primary_key' => false, 'is_json' => true, 'key_type' => 'none', 'max_length' => null, 'enum_values' => [], 'is_password' => false],
            ['name' => 'keywords', 'type' => 'json', 'nullable' => false, 'unique' => false, 'auto_increment' => false, 'primary_key' => false, 'is_json' => true, 'key_type' => 'none', 'max_length' => null, 'enum_values' => [], 'is_password' => false],
            ['name' => 'created_at', 'type' => 'timestamp', 'nullable' => true, 'unique' => false, 'auto_increment' => false, 'primary_key' => false, 'is_json' => false, 'key_type' => 'none', 'max_length' => null, 'enum_values' => [], 'is_password' => false],
            ['name' => 'updated_at', 'type' => 'timestamp', 'nullable' => true, 'unique' => false, 'auto_increment' => false, 'primary_key' => false, 'is_json' => false, 'key_type' => 'none', 'max_length' => null, 'enum_values' => [], 'is_password' => false],
        ],
        'foreign_keys' => [],
    ];

    $schemaAnalyzer = Mockery::mock(SchemaAnalyzerInterface::class);
    $schemaAnalyzer->shouldReceive('analyze')
        ->with('ai_seeder_test_courses')
        ->andReturn($courseSchema);

    $relationshipResolver = Mockery::mock(RelationshipResolverInterface::class);
    $relationshipResolver->shouldReceive('resolve')->andReturn([]);

    // Simulate what the DataGenerator returns AFTER post-processing:
    // The AI generates the non-PK fields, then DataGenerator injects ULID + json_encode
    $dataGenerator = Mockery::mock(DataGeneratorInterface::class);
    $dataGenerator->shouldReceive('generate')
        ->once()
        ->andReturnUsing(function () {
            // Simulate post-processed rows (ULID injected, JSON encoded)
            return new GenerationResult(
                rows: [
                    [
                        'id' => (string) \Illuminate\Support\Str::ulid(),
                        'title' => 'Intro to Laravel',
                        'objectives' => json_encode(['Learn MVC', 'Build APIs']),
                        'keywords' => json_encode(['laravel', 'php', 'web']),
                        'created_at' => '2025-06-01 10:00:00',
                        'updated_at' => '2025-06-01 10:00:00',
                    ],
                    [
                        'id' => (string) \Illuminate\Support\Str::ulid(),
                        'title' => 'Advanced AI',
                        'objectives' => null,
                        'keywords' => json_encode(['ai', 'ml']),
                        'created_at' => '2025-06-02 10:00:00',
                        'updated_at' => '2025-06-02 10:00:00',
                    ],
                ],
                promptTokens: 300,
                completionTokens: 400,
            );
        });

    $orchestrator = new AiSeederOrchestrator($schemaAnalyzer, $relationshipResolver, $dataGenerator);
    $inserted = $orchestrator->seed('ai_seeder_test_courses', 2);

    expect($inserted)->toBe(2);

    $rows = DB::table('ai_seeder_test_courses')->get();
    expect($rows)->toHaveCount(2);

    // Verify ULIDs are 26 chars
    foreach ($rows as $row) {
        expect(strlen($row->id))->toBe(26);
    }

    // Verify first row has valid JSON objectives
    $first = $rows->first();
    $objectives = json_decode($first->objectives, true);
    expect($objectives)->toBeArray();
    expect($objectives)->toContain('Learn MVC');
});

test('it aggregates token usage across chunks', function () {
    $schemaAnalyzer = Mockery::mock(SchemaAnalyzerInterface::class);
    $schemaAnalyzer->shouldReceive('analyze')
        ->andReturn(mockAuthorSchema());

    $relationshipResolver = Mockery::mock(RelationshipResolverInterface::class);
    $relationshipResolver->shouldReceive('resolve')->andReturn([]);

    $chunkCall = 0;
    $dataGenerator = Mockery::mock(DataGeneratorInterface::class);
    $dataGenerator->shouldReceive('generate')
        ->times(2)
        ->andReturnUsing(function ($schema, $count) use (&$chunkCall) {
            $chunkCall++;
            $rows = [];
            for ($i = 0; $i < $count; $i++) {
                $idx = (($chunkCall - 1) * 5) + $i + 1;
                $rows[] = [
                    'name' => "User {$idx}",
                    'email' => "user{$idx}@test.com",
                    'created_at' => '2025-01-01 00:00:00',
                    'updated_at' => '2025-01-01 00:00:00',
                ];
            }

            return new GenerationResult(
                rows: $rows,
                promptTokens: 100 * $chunkCall,
                completionTokens: 200 * $chunkCall,
            );
        });

    $orchestrator = new AiSeederOrchestrator($schemaAnalyzer, $relationshipResolver, $dataGenerator);
    $orchestrator->seed('ai_seeder_test_authors', 10, chunkSize: 5);

    $tracker = $orchestrator->getTokenTracker();

    // Chunk 1: 100 prompt + 200 completion, Chunk 2: 200 prompt + 400 completion
    expect($tracker->getPromptTokens())->toBe(300);
    expect($tracker->getCompletionTokens())->toBe(600);
    expect($tracker->getTotalTokens())->toBe(900);
});
