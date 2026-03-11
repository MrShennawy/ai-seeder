<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Shennawy\AiSeeder\RelationshipResolver;
use Symfony\Component\Console\Output\BufferedOutput;

beforeEach(function () {
    Schema::create('ai_seeder_test_categories', function ($table) {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });

    Schema::create('ai_seeder_test_items', function ($table) {
        $table->id();
        $table->foreignId('category_id')->constrained('ai_seeder_test_categories');
        $table->string('title');
        $table->timestamps();
    });

    Schema::create('ai_seeder_test_tree_categories', function ($table) {
        $table->id();
        $table->string('name');
        $table->foreignId('parent_id')->nullable()->constrained('ai_seeder_test_tree_categories');
        $table->timestamps();
    });
});

afterEach(function () {
    Schema::dropIfExists('ai_seeder_test_items');
    Schema::dropIfExists('ai_seeder_test_categories');
    Schema::dropIfExists('ai_seeder_test_tree_categories');
});

test('it resolves foreign key constraints with existing parent records', function () {
    DB::table('ai_seeder_test_categories')->insert([
        ['name' => 'Electronics', 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'Books', 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'Clothing', 'created_at' => now(), 'updated_at' => now()],
    ]);

    $resolver = new RelationshipResolver;

    $constraints = $resolver->resolve([
        [
            'column' => 'category_id',
            'foreign_table' => 'ai_seeder_test_categories',
            'foreign_column' => 'id',
        ],
    ]);

    expect($constraints)->toHaveKey('category_id');
    expect($constraints['category_id'])->toHaveCount(3);
    expect($constraints['category_id'])->toContain(1, 2, 3);
});

test('resolveSingle returns existing parent IDs', function () {
    DB::table('ai_seeder_test_categories')->insert([
        ['name' => 'Electronics', 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'Books', 'created_at' => now(), 'updated_at' => now()],
    ]);

    $resolver = new RelationshipResolver;

    $ids = $resolver->resolveSingle([
        'column' => 'category_id',
        'foreign_table' => 'ai_seeder_test_categories',
        'foreign_column' => 'id',
    ]);

    expect($ids)->toHaveCount(2);
    expect($ids)->toContain(1, 2);
});

test('resolveSingle recursively seeds empty parent table with output streaming', function () {
    Artisan::shouldReceive('call')
        ->once()
        ->with('ai:seed', Mockery::type('array'), Mockery::type(BufferedOutput::class))
        ->andReturnUsing(function (string $command, array $args, BufferedOutput $output) {
            expect($args['table'])->toBe('ai_seeder_test_categories');
            expect($args['--count'])->toBe(3);
            expect($args['--lang'])->toBe('en');
            expect($args['--no-interaction'])->toBeTrue();

            // Simulate the child command inserting records and writing output
            DB::table('ai_seeder_test_categories')->insert([
                ['name' => 'Cat A', 'created_at' => now(), 'updated_at' => now()],
            ]);

            $output->writeln('Seeded ai_seeder_test_categories with 1 row.');

            return 0;
        });

    $messages = [];
    $resolver = new RelationshipResolver;
    $resolver->onProgress(function (string $message) use (&$messages) {
        $messages[] = $message;
    });

    $ids = $resolver->resolveSingle([
        'column' => 'category_id',
        'foreign_table' => 'ai_seeder_test_categories',
        'foreign_column' => 'id',
    ], minimumParentRows: 3);

    expect($ids)->toHaveCount(1);
    expect($messages[0])->toContain('empty');
    expect($messages[1])->toContain('seeded with 1 record(s)');
});

test('resolveSingle streams to parent output when setOutput is called', function () {
    $parentOutput = new BufferedOutput;

    Artisan::shouldReceive('call')
        ->once()
        ->with('ai:seed', Mockery::type('array'), $parentOutput)
        ->andReturnUsing(function (string $command, array $args, BufferedOutput $output) {
            DB::table('ai_seeder_test_categories')->insert([
                ['name' => 'Cat A', 'created_at' => now(), 'updated_at' => now()],
            ]);

            $output->writeln('✅ Seeded categories.');

            return 0;
        });

    $resolver = new RelationshipResolver;
    $resolver->setOutput($parentOutput);

    $ids = $resolver->resolveSingle([
        'column' => 'category_id',
        'foreign_table' => 'ai_seeder_test_categories',
        'foreign_column' => 'id',
    ]);

    expect($ids)->toHaveCount(1);

    // Verify the child command wrote to the parent output
    $output = $parentOutput->fetch();
    expect($output)->toContain('Seeded categories');
});

test('it returns empty constraints for empty foreign key array', function () {
    $resolver = new RelationshipResolver;

    $constraints = $resolver->resolve([]);

    expect($constraints)->toBeEmpty();
});

test('it recursively seeds empty parent tables via Artisan command', function () {
    Artisan::shouldReceive('call')
        ->once()
        ->with('ai:seed', Mockery::type('array'), Mockery::type(BufferedOutput::class))
        ->andReturnUsing(function (string $command, array $args) {
            expect($args['table'])->toBe('ai_seeder_test_categories');
            expect($args['--count'])->toBe(5);
            expect($args['--no-interaction'])->toBeTrue();

            DB::table('ai_seeder_test_categories')->insert([
                ['name' => 'Seeded Cat 1', 'created_at' => now(), 'updated_at' => now()],
                ['name' => 'Seeded Cat 2', 'created_at' => now(), 'updated_at' => now()],
            ]);

            return 0;
        });

    $messages = [];
    $resolver = new RelationshipResolver;
    $resolver->onProgress(function (string $message) use (&$messages) {
        $messages[] = $message;
    });

    $constraints = $resolver->resolve([
        [
            'column' => 'category_id',
            'foreign_table' => 'ai_seeder_test_categories',
            'foreign_column' => 'id',
        ],
    ]);

    expect($constraints)->toHaveKey('category_id');
    expect($constraints['category_id'])->toHaveCount(2);

    expect($messages[0])->toContain('empty');
    expect($messages[0])->toContain('ai_seeder_test_categories');
    expect($messages[1])->toContain('seeded with 2 record(s)');
});

test('it throws with captured output when recursive seeding fails', function () {
    Artisan::shouldReceive('call')
        ->once()
        ->with('ai:seed', Mockery::type('array'), Mockery::type(BufferedOutput::class))
        ->andReturnUsing(function (string $command, array $args, BufferedOutput $output) {
            $output->writeln('SQLSTATE[22001]: Data too long for column "language"');

            return 1;
        });

    Artisan::shouldReceive('output')
        ->never();

    $resolver = new RelationshipResolver;

    try {
        $resolver->resolve([
            [
                'column' => 'category_id',
                'foreign_table' => 'ai_seeder_test_categories',
                'foreign_column' => 'id',
            ],
        ]);
        $this->fail('Expected RuntimeException was not thrown.');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('Failed to recursively seed parent table [ai_seeder_test_categories]');
        expect($e->getMessage())->toContain('Data too long');
        expect($e->getMessage())->toContain('language');
    }
});

test('it throws with output from parent stream when recursive seeding fails with setOutput', function () {
    $parentOutput = new BufferedOutput;

    Artisan::shouldReceive('call')
        ->once()
        ->with('ai:seed', Mockery::type('array'), $parentOutput)
        ->andReturn(1);

    Artisan::shouldReceive('output')
        ->once()
        ->andReturn('SQLSTATE[42S02]: Table not found');

    $resolver = new RelationshipResolver;
    $resolver->setOutput($parentOutput);

    try {
        $resolver->resolveSingle([
            'column' => 'category_id',
            'foreign_table' => 'ai_seeder_test_categories',
            'foreign_column' => 'id',
        ]);
        $this->fail('Expected RuntimeException was not thrown.');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('Failed to recursively seed');
        expect($e->getMessage())->toContain('Table not found');
    }
});

test('recursive seeding propagates the configured language to child commands', function () {
    Artisan::shouldReceive('call')
        ->once()
        ->with('ai:seed', Mockery::on(function (array $args) {
            return $args['table'] === 'ai_seeder_test_categories'
                && $args['--lang'] === 'ar,en'
                && $args['--no-interaction'] === true;
        }), Mockery::type(BufferedOutput::class))
        ->andReturnUsing(function (string $command, array $args) {
            expect($args['--lang'])->toBe('ar,en');

            DB::table('ai_seeder_test_categories')->insert([
                ['name' => 'فئة ١', 'created_at' => now(), 'updated_at' => now()],
            ]);

            return 0;
        });

    $resolver = new RelationshipResolver;
    $resolver->setLanguage('ar,en');

    $ids = $resolver->resolveSingle([
        'column' => 'category_id',
        'foreign_table' => 'ai_seeder_test_categories',
        'foreign_column' => 'id',
    ]);

    expect($ids)->toHaveCount(1);
});

test('setLanguage defaults to en when not called', function () {
    Artisan::shouldReceive('call')
        ->once()
        ->with('ai:seed', Mockery::on(function (array $args) {
            return $args['--lang'] === 'en';
        }), Mockery::type(BufferedOutput::class))
        ->andReturnUsing(function () {
            DB::table('ai_seeder_test_categories')->insert([
                ['name' => 'Category', 'created_at' => now(), 'updated_at' => now()],
            ]);

            return 0;
        });

    $resolver = new RelationshipResolver;

    $ids = $resolver->resolveSingle([
        'column' => 'category_id',
        'foreign_table' => 'ai_seeder_test_categories',
        'foreign_column' => 'id',
    ]);

    expect($ids)->toHaveCount(1);
});

// ─── Self-Referencing FK Tests ───────────────────────────────────────

test('self-referencing FK returns empty array when table is empty', function () {
    $messages = [];
    $resolver = new RelationshipResolver;
    $resolver->setCurrentTable('ai_seeder_test_tree_categories');
    $resolver->onProgress(function (string $message) use (&$messages) {
        $messages[] = $message;
    });

    $ids = $resolver->resolveSingle([
        'column' => 'parent_id',
        'foreign_table' => 'ai_seeder_test_tree_categories',
        'foreign_column' => 'id',
    ]);

    expect($ids)->toBeEmpty();
    expect($messages[0])->toContain('Self-referencing');
    expect($messages[0])->toContain('will use NULL');
});

test('self-referencing FK returns existing IDs when table has records', function () {
    DB::table('ai_seeder_test_tree_categories')->insert([
        ['name' => 'Root A', 'parent_id' => null, 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'Root B', 'parent_id' => null, 'created_at' => now(), 'updated_at' => now()],
    ]);

    $messages = [];
    $resolver = new RelationshipResolver;
    $resolver->setCurrentTable('ai_seeder_test_tree_categories');
    $resolver->onProgress(function (string $message) use (&$messages) {
        $messages[] = $message;
    });

    $ids = $resolver->resolveSingle([
        'column' => 'parent_id',
        'foreign_table' => 'ai_seeder_test_tree_categories',
        'foreign_column' => 'id',
    ]);

    expect($ids)->toHaveCount(2);
    expect($ids)->toContain(1, 2);
    expect($messages[0])->toContain('Self-referencing');
    expect($messages[0])->toContain('fetched 2 existing ID(s)');
});

test('self-referencing FK does NOT trigger recursive Artisan call', function () {
    // Artisan::call should NEVER be invoked for self-referencing FKs
    Artisan::shouldReceive('call')->never();

    $resolver = new RelationshipResolver;
    $resolver->setCurrentTable('ai_seeder_test_tree_categories');

    $ids = $resolver->resolveSingle([
        'column' => 'parent_id',
        'foreign_table' => 'ai_seeder_test_tree_categories',
        'foreign_column' => 'id',
    ]);

    expect($ids)->toBeEmpty();
});

test('self-referencing FK does NOT trigger circular dependency exception', function () {
    $resolver = new RelationshipResolver;
    $resolver->setCurrentTable('ai_seeder_test_tree_categories');

    // This should NOT throw — self-references are exempted from circular dependency checks
    $ids = $resolver->resolveSingle([
        'column' => 'parent_id',
        'foreign_table' => 'ai_seeder_test_tree_categories',
        'foreign_column' => 'id',
    ]);

    expect($ids)->toBeArray();
});

test('non-self-referencing FK still triggers recursive seeding when parent is empty', function () {
    Artisan::shouldReceive('call')
        ->once()
        ->with('ai:seed', Mockery::type('array'), Mockery::type(BufferedOutput::class))
        ->andReturnUsing(function () {
            DB::table('ai_seeder_test_categories')->insert([
                ['name' => 'Seeded', 'created_at' => now(), 'updated_at' => now()],
            ]);

            return 0;
        });

    $resolver = new RelationshipResolver;
    // Current table is 'items', FK points to 'categories' — NOT a self-reference
    $resolver->setCurrentTable('ai_seeder_test_items');

    $ids = $resolver->resolveSingle([
        'column' => 'category_id',
        'foreign_table' => 'ai_seeder_test_categories',
        'foreign_column' => 'id',
    ]);

    expect($ids)->toHaveCount(1);
});

test('resolve handles mixed self-referencing and normal FKs in same call', function () {
    Artisan::shouldReceive('call')
        ->once()
        ->with('ai:seed', Mockery::on(function (array $args) {
            return $args['table'] === 'ai_seeder_test_categories';
        }), Mockery::type(BufferedOutput::class))
        ->andReturnUsing(function () {
            DB::table('ai_seeder_test_categories')->insert([
                ['name' => 'External Cat', 'created_at' => now(), 'updated_at' => now()],
            ]);

            return 0;
        });

    $resolver = new RelationshipResolver;
    $resolver->setCurrentTable('ai_seeder_test_tree_categories');

    $constraints = $resolver->resolve([
        // Self-referencing FK — should return [] without Artisan::call
        [
            'column' => 'parent_id',
            'foreign_table' => 'ai_seeder_test_tree_categories',
            'foreign_column' => 'id',
        ],
        // Normal FK — should trigger recursive seeding
        [
            'column' => 'category_id',
            'foreign_table' => 'ai_seeder_test_categories',
            'foreign_column' => 'id',
        ],
    ]);

    expect($constraints)->toHaveKey('parent_id');
    expect($constraints['parent_id'])->toBeEmpty();

    expect($constraints)->toHaveKey('category_id');
    expect($constraints['category_id'])->toHaveCount(1);
});
