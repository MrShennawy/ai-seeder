<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Shennawy\AiSeeder\SchemaAnalyzer;

beforeEach(function () {
    Schema::create('ai_seeder_test_products', function ($table) {
        $table->id();
        $table->string('name')->unique();
        $table->text('description')->nullable();
        $table->decimal('price', 10, 2);
        $table->boolean('is_active')->default(true);
        $table->timestamps();
    });

    Schema::create('ai_seeder_test_reviews', function ($table) {
        $table->id();
        $table->foreignId('product_id')->constrained('ai_seeder_test_products');
        $table->string('reviewer_name');
        $table->integer('rating');
        $table->text('body')->nullable();
        $table->timestamps();
    });

    Schema::create('ai_seeder_test_courses', function ($table) {
        $table->ulid('id')->primary();
        $table->string('title');
        $table->json('objectives')->nullable();
        $table->json('keywords');
        $table->timestamps();
    });

    Schema::create('ai_seeder_test_users', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->string('password');
        $table->string('language', 2);
        $table->timestamps();
    });
});

afterEach(function () {
    Schema::dropIfExists('ai_seeder_test_reviews');
    Schema::dropIfExists('ai_seeder_test_products');
    Schema::dropIfExists('ai_seeder_test_courses');
    Schema::dropIfExists('ai_seeder_test_users');
});

test('it can detect that a table exists', function () {
    $analyzer = new SchemaAnalyzer;

    expect($analyzer->tableExists('ai_seeder_test_products'))->toBeTrue();
    expect($analyzer->tableExists('nonexistent_table'))->toBeFalse();
});

test('it lists all tables', function () {
    $analyzer = new SchemaAnalyzer;

    $tables = $analyzer->getTables();

    expect($tables)->toBeArray();

    // SQLite may prefix tables with schema name (e.g., "main.table_name")
    $tableNames = array_map(fn ($t) => str_contains($t, '.') ? substr($t, strpos($t, '.') + 1) : $t, $tables);
    expect($tableNames)->toContain('ai_seeder_test_products');
    expect($tableNames)->toContain('ai_seeder_test_reviews');
});

test('it extracts column names and types', function () {
    $analyzer = new SchemaAnalyzer;
    $schema = $analyzer->analyze('ai_seeder_test_products');

    expect($schema['table'])->toBe('ai_seeder_test_products');
    expect($schema['columns'])->toBeArray();

    $columnNames = array_column($schema['columns'], 'name');
    expect($columnNames)->toContain('id');
    expect($columnNames)->toContain('name');
    expect($columnNames)->toContain('description');
    expect($columnNames)->toContain('price');
    expect($columnNames)->toContain('is_active');
});

test('it detects nullable columns', function () {
    $analyzer = new SchemaAnalyzer;
    $schema = $analyzer->analyze('ai_seeder_test_products');

    $columns = collect($schema['columns']);

    $description = $columns->firstWhere('name', 'description');
    expect($description['nullable'])->toBeTrue();

    $name = $columns->firstWhere('name', 'name');
    expect($name['nullable'])->toBeFalse();
});

test('it detects unique columns', function () {
    $analyzer = new SchemaAnalyzer;
    $schema = $analyzer->analyze('ai_seeder_test_products');

    $columns = collect($schema['columns']);

    $name = $columns->firstWhere('name', 'name');
    expect($name['unique'])->toBeTrue();

    $description = $columns->firstWhere('name', 'description');
    expect($description['unique'])->toBeFalse();
});

test('it detects auto-increment columns', function () {
    $analyzer = new SchemaAnalyzer;
    $schema = $analyzer->analyze('ai_seeder_test_products');

    $columns = collect($schema['columns']);

    $id = $columns->firstWhere('name', 'id');
    expect($id['auto_increment'])->toBeTrue();
    expect($id['primary_key'])->toBeTrue();

    $name = $columns->firstWhere('name', 'name');
    expect($name['auto_increment'])->toBeFalse();
    expect($name['primary_key'])->toBeFalse();
});

test('it detects primary key with ULID key type', function () {
    $analyzer = new SchemaAnalyzer;
    $schema = $analyzer->analyze('ai_seeder_test_courses');

    $columns = collect($schema['columns']);

    $id = $columns->firstWhere('name', 'id');
    expect($id['primary_key'])->toBeTrue();
    expect($id['auto_increment'])->toBeFalse();
    expect($id['key_type'])->toBe('ulid');
});

test('it detects JSON columns', function () {
    // SQLite stores JSON columns as TEXT — it cannot distinguish them.
    // This test only passes on MySQL/PostgreSQL where type_name is 'json'/'jsonb'.
    // The DataGenerator's postProcess() handles JSON encoding independently via explicit schema flags.
    $driver = DB::connection()->getDriverName();

    if ($driver === 'sqlite') {
        $this->markTestSkipped('SQLite does not distinguish JSON from TEXT columns.');
    }

    $analyzer = new SchemaAnalyzer;
    $schema = $analyzer->analyze('ai_seeder_test_courses');

    $columns = collect($schema['columns']);

    $objectives = $columns->firstWhere('name', 'objectives');
    expect($objectives['is_json'])->toBeTrue();
    expect($objectives['nullable'])->toBeTrue();

    $keywords = $columns->firstWhere('name', 'keywords');
    expect($keywords['is_json'])->toBeTrue();

    $title = $columns->firstWhere('name', 'title');
    expect($title['is_json'])->toBeFalse();
});

test('it marks non-json non-pk columns correctly', function () {
    $analyzer = new SchemaAnalyzer;
    $schema = $analyzer->analyze('ai_seeder_test_products');

    $columns = collect($schema['columns']);

    $name = $columns->firstWhere('name', 'name');
    expect($name['is_json'])->toBeFalse();
    expect($name['key_type'])->toBe('none');
    expect($name['is_password'])->toBeFalse();
    expect($name['enum_values'])->toBeEmpty();
});

test('it detects password columns by name', function () {
    $analyzer = new SchemaAnalyzer;
    $schema = $analyzer->analyze('ai_seeder_test_users');

    $columns = collect($schema['columns']);

    $password = $columns->firstWhere('name', 'password');
    expect($password['is_password'])->toBeTrue();

    $name = $columns->firstWhere('name', 'name');
    expect($name['is_password'])->toBeFalse();

    $email = $columns->firstWhere('name', 'email');
    expect($email['is_password'])->toBeFalse();
});

test('it extracts max_length from column type on MySQL', function () {
    // SQLite does not preserve VARCHAR lengths in its schema metadata.
    // On MySQL/PostgreSQL, Schema::getColumns() returns type as "varchar(2)".
    $driver = DB::connection()->getDriverName();

    if ($driver === 'sqlite') {
        $this->markTestSkipped('SQLite does not expose column lengths in schema metadata.');
    }

    $analyzer = new SchemaAnalyzer;
    $schema = $analyzer->analyze('ai_seeder_test_users');

    $columns = collect($schema['columns']);

    $language = $columns->firstWhere('name', 'language');
    expect($language['max_length'])->toBe(2);

    $name = $columns->firstWhere('name', 'name');
    expect($name['max_length'])->toBe(255);
});

test('it returns null max_length for non-string columns', function () {
    $analyzer = new SchemaAnalyzer;
    $schema = $analyzer->analyze('ai_seeder_test_products');

    $columns = collect($schema['columns']);

    $price = $columns->firstWhere('name', 'price');
    expect($price['max_length'])->toBeNull();

    $isActive = $columns->firstWhere('name', 'is_active');
    expect($isActive['max_length'])->toBeNull();

    $description = $columns->firstWhere('name', 'description');
    expect($description['max_length'])->toBeNull();
});

test('it parses enum values from column type on MySQL', function () {
    // SQLite does not support ENUM columns natively.
    $driver = DB::connection()->getDriverName();

    if ($driver === 'sqlite') {
        $this->markTestSkipped('SQLite does not support ENUM columns.');
    }

    // This test is for MySQL where Schema::getColumns() returns
    // type as "enum('active','inactive','pending')".
    $analyzer = new SchemaAnalyzer;

    // We test the parsing logic directly via reflection since we can't create ENUM on SQLite
    $reflection = new ReflectionClass($analyzer);
    $method = $reflection->getMethod('extractEnumValues');

    $result = $method->invoke($analyzer, "enum('active','inactive','pending')", 'enum');
    expect($result)->toBe(['active', 'inactive', 'pending']);
});

test('it returns empty enum_values for non-enum columns', function () {
    $analyzer = new SchemaAnalyzer;
    $schema = $analyzer->analyze('ai_seeder_test_products');

    $columns = collect($schema['columns']);

    foreach ($columns as $column) {
        expect($column['enum_values'])->toBeEmpty();
    }
});

test('it extracts foreign keys', function () {
    $analyzer = new SchemaAnalyzer;
    $schema = $analyzer->analyze('ai_seeder_test_reviews');

    expect($schema['foreign_keys'])->toBeArray();
    expect($schema['foreign_keys'])->toHaveCount(1);

    $fk = $schema['foreign_keys'][0];
    expect($fk['column'])->toBe('product_id');
    expect($fk['foreign_table'])->toBe('ai_seeder_test_products');
    expect($fk['foreign_column'])->toBe('id');
});

test('it returns empty foreign keys for tables without them', function () {
    $analyzer = new SchemaAnalyzer;
    $schema = $analyzer->analyze('ai_seeder_test_products');

    expect($schema['foreign_keys'])->toBeArray();
    expect($schema['foreign_keys'])->toBeEmpty();
});

test('it caches repeated analysis calls', function () {
    $analyzer = new SchemaAnalyzer;

    $first = $analyzer->analyze('ai_seeder_test_products');
    $second = $analyzer->analyze('ai_seeder_test_products');

    expect($first)->toBe($second);
});
