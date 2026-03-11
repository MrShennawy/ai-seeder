<?php

use Shennawy\AiSeeder\DataGenerator;

test('post-processing injects ULIDs for ULID primary key columns', function () {
    $generator = new DataGenerator;
    $schema = [
        'table' => 'courses',
        'columns' => [
            ['name' => 'id', 'type' => 'char', 'nullable' => false, 'unique' => true, 'auto_increment' => false, 'primary_key' => true, 'is_json' => false, 'key_type' => 'ulid', 'max_length' => 26, 'enum_values' => [], 'is_password' => false],
            ['name' => 'title', 'type' => 'varchar', 'nullable' => false, 'unique' => false, 'auto_increment' => false, 'primary_key' => false, 'is_json' => false, 'key_type' => 'none', 'max_length' => 255, 'enum_values' => [], 'is_password' => false],
        ],
        'foreign_keys' => [],
    ];

    // Use reflection to test the private postProcess method
    $reflection = new ReflectionClass($generator);
    $method = $reflection->getMethod('postProcess');

    $rows = [
        ['title' => 'Course A'],
        ['title' => 'Course B'],
    ];

    $result = $method->invoke($generator, $rows, $schema);

    expect($result)->toHaveCount(2);

    // Each row should have a ULID injected
    foreach ($result as $row) {
        expect($row)->toHaveKey('id');
        expect(strlen($row['id']))->toBe(26);
        expect($row['id'])->toMatch('/^[0-9A-Z]{26}$/');
    }

    // ULIDs should be unique
    expect($result[0]['id'])->not->toBe($result[1]['id']);
});

test('post-processing injects UUIDs for UUID primary key columns', function () {
    $generator = new DataGenerator;
    $schema = [
        'table' => 'items',
        'columns' => [
            ['name' => 'id', 'type' => 'char', 'nullable' => false, 'unique' => true, 'auto_increment' => false, 'primary_key' => true, 'is_json' => false, 'key_type' => 'uuid', 'max_length' => 36, 'enum_values' => [], 'is_password' => false],
            ['name' => 'name', 'type' => 'varchar', 'nullable' => false, 'unique' => false, 'auto_increment' => false, 'primary_key' => false, 'is_json' => false, 'key_type' => 'none', 'max_length' => 255, 'enum_values' => [], 'is_password' => false],
        ],
        'foreign_keys' => [],
    ];

    $reflection = new ReflectionClass($generator);
    $method = $reflection->getMethod('postProcess');

    $rows = [['name' => 'Item A']];
    $result = $method->invoke($generator, $rows, $schema);

    expect($result[0])->toHaveKey('id');
    expect($result[0]['id'])->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/');
});

test('post-processing json_encodes array values for JSON columns', function () {
    $generator = new DataGenerator;
    $schema = [
        'table' => 'courses',
        'columns' => [
            ['name' => 'id', 'type' => 'integer', 'nullable' => false, 'unique' => true, 'auto_increment' => true, 'primary_key' => true, 'is_json' => false, 'key_type' => 'auto_increment', 'max_length' => null, 'enum_values' => [], 'is_password' => false],
            ['name' => 'objectives', 'type' => 'json', 'nullable' => false, 'unique' => false, 'auto_increment' => false, 'primary_key' => false, 'is_json' => true, 'key_type' => 'none', 'max_length' => null, 'enum_values' => [], 'is_password' => false],
            ['name' => 'keywords', 'type' => 'json', 'nullable' => true, 'unique' => false, 'auto_increment' => false, 'primary_key' => false, 'is_json' => true, 'key_type' => 'none', 'max_length' => null, 'enum_values' => [], 'is_password' => false],
        ],
        'foreign_keys' => [],
    ];

    $reflection = new ReflectionClass($generator);
    $method = $reflection->getMethod('postProcess');

    $rows = [
        [
            'objectives' => ['Learn MVC', 'Build APIs'],
            'keywords' => null,
        ],
    ];

    $result = $method->invoke($generator, $rows, $schema);

    // Array value should be json_encoded
    expect($result[0]['objectives'])->toBe('["Learn MVC","Build APIs"]');

    // Null should remain null
    expect($result[0]['keywords'])->toBeNull();
});

test('post-processing wraps plain strings in JSON arrays for JSON columns', function () {
    $generator = new DataGenerator;
    $schema = [
        'table' => 'courses',
        'columns' => [
            ['name' => 'id', 'type' => 'integer', 'nullable' => false, 'unique' => true, 'auto_increment' => true, 'primary_key' => true, 'is_json' => false, 'key_type' => 'auto_increment', 'max_length' => null, 'enum_values' => [], 'is_password' => false],
            ['name' => 'tags', 'type' => 'json', 'nullable' => false, 'unique' => false, 'auto_increment' => false, 'primary_key' => false, 'is_json' => true, 'key_type' => 'none', 'max_length' => null, 'enum_values' => [], 'is_password' => false],
        ],
        'foreign_keys' => [],
    ];

    $reflection = new ReflectionClass($generator);
    $method = $reflection->getMethod('postProcess');

    $rows = [
        ['tags' => 'just-a-string'],
    ];

    $result = $method->invoke($generator, $rows, $schema);

    // Plain string should be wrapped in JSON array
    expect($result[0]['tags'])->toBe('["just-a-string"]');
});

test('post-processing preserves valid JSON strings for JSON columns', function () {
    $generator = new DataGenerator;
    $schema = [
        'table' => 'courses',
        'columns' => [
            ['name' => 'id', 'type' => 'integer', 'nullable' => false, 'unique' => true, 'auto_increment' => true, 'primary_key' => true, 'is_json' => false, 'key_type' => 'auto_increment', 'max_length' => null, 'enum_values' => [], 'is_password' => false],
            ['name' => 'config', 'type' => 'json', 'nullable' => false, 'unique' => false, 'auto_increment' => false, 'primary_key' => false, 'is_json' => true, 'key_type' => 'none', 'max_length' => null, 'enum_values' => [], 'is_password' => false],
        ],
        'foreign_keys' => [],
    ];

    $reflection = new ReflectionClass($generator);
    $method = $reflection->getMethod('postProcess');

    $validJson = '{"theme":"dark","lang":"en"}';
    $rows = [['config' => $validJson]];

    $result = $method->invoke($generator, $rows, $schema);

    // Already-valid JSON string should be preserved as-is
    expect($result[0]['config'])->toBe($validJson);
});

test('shouldExcludeColumn excludes ULID and UUID primary keys', function () {
    $generator = new DataGenerator;
    $reflection = new ReflectionClass($generator);
    $method = $reflection->getMethod('shouldExcludeColumn');

    // ULID PK — should be excluded
    $ulidPk = ['name' => 'id', 'auto_increment' => false, 'primary_key' => true, 'key_type' => 'ulid', 'is_password' => false];
    expect($method->invoke($generator, $ulidPk))->toBeTrue();

    // UUID PK — should be excluded
    $uuidPk = ['name' => 'id', 'auto_increment' => false, 'primary_key' => true, 'key_type' => 'uuid', 'is_password' => false];
    expect($method->invoke($generator, $uuidPk))->toBeTrue();

    // Auto-increment PK — should be excluded
    $autoInc = ['name' => 'id', 'auto_increment' => true, 'primary_key' => true, 'key_type' => 'auto_increment', 'is_password' => false];
    expect($method->invoke($generator, $autoInc))->toBeTrue();

    // Password column — should be excluded
    $password = ['name' => 'password', 'auto_increment' => false, 'primary_key' => false, 'key_type' => 'none', 'is_password' => true];
    expect($method->invoke($generator, $password))->toBeTrue();

    // Foreign key column — should be excluded when listed as FK
    $fkColumn = ['name' => 'user_id', 'auto_increment' => false, 'primary_key' => false, 'key_type' => 'none', 'is_password' => false];
    expect($method->invoke($generator, $fkColumn, ['user_id', 'course_id']))->toBeTrue();

    // Foreign key column — should NOT be excluded when not listed as FK
    expect($method->invoke($generator, $fkColumn, []))->toBeFalse();
    expect($method->invoke($generator, $fkColumn))->toBeFalse();

    // Regular column — should NOT be excluded
    $regular = ['name' => 'title', 'auto_increment' => false, 'primary_key' => false, 'key_type' => 'none', 'is_password' => false];
    expect($method->invoke($generator, $regular))->toBeFalse();
});

test('post-processing injects hashed passwords for password columns', function () {
    $generator = new DataGenerator;
    $schema = [
        'table' => 'users',
        'columns' => [
            ['name' => 'id', 'type' => 'integer', 'nullable' => false, 'unique' => true, 'auto_increment' => true, 'primary_key' => true, 'is_json' => false, 'key_type' => 'auto_increment', 'max_length' => null, 'enum_values' => [], 'is_password' => false],
            ['name' => 'name', 'type' => 'varchar', 'nullable' => false, 'unique' => false, 'auto_increment' => false, 'primary_key' => false, 'is_json' => false, 'key_type' => 'none', 'max_length' => 255, 'enum_values' => [], 'is_password' => false],
            ['name' => 'password', 'type' => 'varchar', 'nullable' => false, 'unique' => false, 'auto_increment' => false, 'primary_key' => false, 'is_json' => false, 'key_type' => 'none', 'max_length' => 255, 'enum_values' => [], 'is_password' => true],
        ],
        'foreign_keys' => [],
    ];

    $reflection = new ReflectionClass($generator);
    $method = $reflection->getMethod('postProcess');

    // AI doesn't generate password column — postProcess injects it
    $rows = [
        ['name' => 'Alice'],
        ['name' => 'Bob'],
    ];

    $result = $method->invoke($generator, $rows, $schema);

    expect($result)->toHaveCount(2);

    foreach ($result as $row) {
        expect($row)->toHaveKey('password');
        // Hash::make produces a string starting with $2y$ (bcrypt)
        expect($row['password'])->toStartWith('$2y$');
        // Verify it actually validates against 'password'
        expect(\Illuminate\Support\Facades\Hash::check('password', $row['password']))->toBeTrue();
    }
});

test('post-processing truncates strings exceeding max_length', function () {
    $generator = new DataGenerator;
    $schema = [
        'table' => 'users',
        'columns' => [
            ['name' => 'id', 'type' => 'integer', 'nullable' => false, 'unique' => true, 'auto_increment' => true, 'primary_key' => true, 'is_json' => false, 'key_type' => 'auto_increment', 'max_length' => null, 'enum_values' => [], 'is_password' => false],
            ['name' => 'language', 'type' => 'varchar', 'nullable' => false, 'unique' => false, 'auto_increment' => false, 'primary_key' => false, 'is_json' => false, 'key_type' => 'none', 'max_length' => 2, 'enum_values' => [], 'is_password' => false],
        ],
        'foreign_keys' => [],
    ];

    $reflection = new ReflectionClass($generator);
    $method = $reflection->getMethod('postProcess');

    // AI returns "English" which exceeds max_length of 2
    $rows = [['language' => 'English']];

    $result = $method->invoke($generator, $rows, $schema);

    expect(mb_strlen($result[0]['language']))->toBeLessThanOrEqual(2);
    expect($result[0]['language'])->toBe('En');
});

test('post-processing enforces ENUM constraints as safety net', function () {
    $generator = new DataGenerator;
    $schema = [
        'table' => 'orders',
        'columns' => [
            ['name' => 'id', 'type' => 'integer', 'nullable' => false, 'unique' => true, 'auto_increment' => true, 'primary_key' => true, 'is_json' => false, 'key_type' => 'auto_increment', 'max_length' => null, 'enum_values' => [], 'is_password' => false],
            ['name' => 'status', 'type' => 'enum', 'nullable' => false, 'unique' => false, 'auto_increment' => false, 'primary_key' => false, 'is_json' => false, 'key_type' => 'none', 'max_length' => null, 'enum_values' => ['active', 'inactive', 'pending'], 'is_password' => false],
        ],
        'foreign_keys' => [],
    ];

    $reflection = new ReflectionClass($generator);
    $method = $reflection->getMethod('postProcess');

    // AI returns valid value — should be preserved
    $rows = [['status' => 'active']];
    $result = $method->invoke($generator, $rows, $schema);
    expect($result[0]['status'])->toBe('active');

    // AI returns invalid value — should fall back to first allowed value
    $rows = [['status' => 'INVALID_VALUE']];
    $result = $method->invoke($generator, $rows, $schema);
    expect($result[0]['status'])->toBe('active');
});

test('buildLanguageInstruction returns instruction for single non-English language', function () {
    $generator = new DataGenerator;
    $reflection = new ReflectionClass($generator);
    $method = $reflection->getMethod('buildLanguageInstruction');

    $result = $method->invoke($generator, 'ar');
    expect($result)->toContain('CRITICAL LANGUAGE RULE');
    expect($result)->toContain('ar');
    expect($result)->toContain('MUST be entirely in natural, realistic ar language');
});

test('buildLanguageInstruction returns instruction for any single language code', function () {
    $generator = new DataGenerator;
    $reflection = new ReflectionClass($generator);
    $method = $reflection->getMethod('buildLanguageInstruction');

    $result = $method->invoke($generator, 'fr');
    expect($result)->toContain('CRITICAL LANGUAGE RULE');
    expect($result)->toContain('fr');
    expect($result)->toContain('MUST be entirely in natural, realistic fr language');
});

test('buildLanguageInstruction returns multi-language instruction for comma-separated list', function () {
    $generator = new DataGenerator;
    $reflection = new ReflectionClass($generator);
    $method = $reflection->getMethod('buildLanguageInstruction');

    $result = $method->invoke($generator, 'ar,en');
    expect($result)->toContain('CRITICAL LANGUAGE RULE');
    expect($result)->toContain('ar, en');
    expect($result)->toContain('multi-lingual');

    // Three languages
    $result = $method->invoke($generator, 'es,pt,fr');
    expect($result)->toContain('es, pt, fr');
    expect($result)->toContain('multi-lingual');
});

test('buildLanguageInstruction returns empty string for en', function () {
    $generator = new DataGenerator;
    $reflection = new ReflectionClass($generator);
    $method = $reflection->getMethod('buildLanguageInstruction');

    $result = $method->invoke($generator, 'en');
    expect($result)->toBe('');
});

test('buildLanguageInstruction handles whitespace in comma-separated list', function () {
    $generator = new DataGenerator;
    $reflection = new ReflectionClass($generator);
    $method = $reflection->getMethod('buildLanguageInstruction');

    $result = $method->invoke($generator, ' ar , en , fr ');
    expect($result)->toContain('ar, en, fr');
    expect($result)->toContain('multi-lingual');
});

test('buildPrompt includes language instruction when language is ar', function () {
    $generator = new DataGenerator;
    $reflection = new ReflectionClass($generator);
    $method = $reflection->getMethod('buildPrompt');

    $schema = [
        'table' => 'users',
        'columns' => [
            ['name' => 'id', 'type' => 'integer', 'nullable' => false, 'unique' => true, 'auto_increment' => true, 'primary_key' => true, 'is_json' => false, 'key_type' => 'auto_increment', 'max_length' => null, 'enum_values' => [], 'is_password' => false],
            ['name' => 'name', 'type' => 'varchar', 'nullable' => false, 'unique' => false, 'auto_increment' => false, 'primary_key' => false, 'is_json' => false, 'key_type' => 'none', 'max_length' => 255, 'enum_values' => [], 'is_password' => false],
        ],
        'foreign_keys' => [],
    ];

    $prompt = $method->invoke($generator, $schema, 5, 'ar');
    expect($prompt)->toContain('ar');
    expect($prompt)->toContain('CRITICAL LANGUAGE RULE');
});

test('buildPrompt does not include language instruction for en', function () {
    $generator = new DataGenerator;
    $reflection = new ReflectionClass($generator);
    $method = $reflection->getMethod('buildPrompt');

    $schema = [
        'table' => 'users',
        'columns' => [
            ['name' => 'id', 'type' => 'integer', 'nullable' => false, 'unique' => true, 'auto_increment' => true, 'primary_key' => true, 'is_json' => false, 'key_type' => 'auto_increment', 'max_length' => null, 'enum_values' => [], 'is_password' => false],
            ['name' => 'name', 'type' => 'varchar', 'nullable' => false, 'unique' => false, 'auto_increment' => false, 'primary_key' => false, 'is_json' => false, 'key_type' => 'none', 'max_length' => 255, 'enum_values' => [], 'is_password' => false],
        ],
        'foreign_keys' => [],
    ];

    $prompt = $method->invoke($generator, $schema, 5, 'en');
    expect($prompt)->not->toContain('CRITICAL LANGUAGE RULE');
});

test('buildPrompt includes multi-language instruction for comma-separated languages', function () {
    $generator = new DataGenerator;
    $reflection = new ReflectionClass($generator);
    $method = $reflection->getMethod('buildPrompt');

    $schema = [
        'table' => 'users',
        'columns' => [
            ['name' => 'id', 'type' => 'integer', 'nullable' => false, 'unique' => true, 'auto_increment' => true, 'primary_key' => true, 'is_json' => false, 'key_type' => 'auto_increment', 'max_length' => null, 'enum_values' => [], 'is_password' => false],
            ['name' => 'name', 'type' => 'varchar', 'nullable' => false, 'unique' => false, 'auto_increment' => false, 'primary_key' => false, 'is_json' => false, 'key_type' => 'none', 'max_length' => 255, 'enum_values' => [], 'is_password' => false],
        ],
        'foreign_keys' => [],
    ];

    $prompt = $method->invoke($generator, $schema, 5, 'es,pt,fr');
    expect($prompt)->toContain('CRITICAL LANGUAGE RULE');
    expect($prompt)->toContain('es, pt, fr');
    expect($prompt)->toContain('multi-lingual');
});

test('buildPrompt JSON warning includes object vs array formatting instructions', function () {
    $generator = new DataGenerator;
    $reflection = new ReflectionClass($generator);
    $method = $reflection->getMethod('buildPrompt');

    $schema = [
        'table' => 'events',
        'columns' => [
            ['name' => 'id', 'type' => 'integer', 'nullable' => false, 'unique' => true, 'auto_increment' => true, 'primary_key' => true, 'is_json' => false, 'key_type' => 'auto_increment', 'max_length' => null, 'enum_values' => [], 'is_password' => false],
            ['name' => 'content', 'type' => 'json', 'nullable' => false, 'unique' => false, 'auto_increment' => false, 'primary_key' => false, 'is_json' => true, 'key_type' => 'none', 'max_length' => null, 'enum_values' => [], 'is_password' => false],
        ],
        'foreign_keys' => [],
    ];

    $prompt = $method->invoke($generator, $schema, 3, 'en', null);

    expect($prompt)->toContain('CRITICAL JSON RULE');
    expect($prompt)->toContain('JSON Object');
    expect($prompt)->toContain('dot-notation sub-keys');
    expect($prompt)->toContain('NEVER output a flat sequential array');
});

test('buildColumnDescription for JSON column mentions object formatting', function () {
    $generator = new DataGenerator;
    $reflection = new ReflectionClass($generator);
    $method = $reflection->getMethod('buildColumnDescription');

    $column = [
        'name' => 'content',
        'type' => 'json',
        'nullable' => false,
        'unique' => false,
        'is_json' => true,
        'max_length' => null,
        'enum_values' => [],
    ];

    $result = $method->invoke($generator, $column);

    expect($result)->toContain('JSON Object');
    expect($result)->toContain('NEVER return a plain string');
    expect($result)->toContain('flat alternating array');
});

test('post-processing injects random foreign key values from resolved parent IDs', function () {
    $generator = new DataGenerator;
    $schema = [
        'table' => 'orders',
        'columns' => [
            ['name' => 'id', 'type' => 'integer', 'nullable' => false, 'unique' => true, 'auto_increment' => true, 'primary_key' => true, 'is_json' => false, 'key_type' => 'auto_increment', 'max_length' => null, 'enum_values' => [], 'is_password' => false],
            ['name' => 'user_id', 'type' => 'char', 'nullable' => false, 'unique' => false, 'auto_increment' => false, 'primary_key' => false, 'is_json' => false, 'key_type' => 'none', 'max_length' => 26, 'enum_values' => [], 'is_password' => false],
            ['name' => 'title', 'type' => 'varchar', 'nullable' => false, 'unique' => false, 'auto_increment' => false, 'primary_key' => false, 'is_json' => false, 'key_type' => 'none', 'max_length' => 255, 'enum_values' => [], 'is_password' => false],
        ],
        'foreign_keys' => [
            ['column' => 'user_id', 'foreign_table' => 'users', 'foreign_column' => 'id'],
        ],
    ];

    $reflection = new ReflectionClass($generator);
    $method = $reflection->getMethod('postProcess');

    $parentIds = ['01HXYZ123456789ABCDEFGHIJ', '01HXYZ987654321ZYXWVUTSRQ', '01HABC000000000000000DEFG'];

    // AI doesn't generate user_id — postProcess injects it from resolved parent IDs
    $rows = [
        ['title' => 'Order A'],
        ['title' => 'Order B'],
        ['title' => 'Order C'],
    ];

    $result = $method->invoke($generator, $rows, $schema, ['user_id' => $parentIds]);

    expect($result)->toHaveCount(3);

    foreach ($result as $row) {
        expect($row)->toHaveKey('user_id');
        expect($row['user_id'])->toBeIn($parentIds);
        expect($row)->toHaveKey('title');
    }
});

test('shouldExcludeColumn excludes foreign key columns', function () {
    $generator = new DataGenerator;
    $reflection = new ReflectionClass($generator);
    $method = $reflection->getMethod('shouldExcludeColumn');

    $fkColumn = ['name' => 'course_unit_id', 'auto_increment' => false, 'primary_key' => false, 'key_type' => 'none', 'is_password' => false];

    // Excluded when listed as a FK column
    expect($method->invoke($generator, $fkColumn, ['course_unit_id', 'user_id']))->toBeTrue();

    // NOT excluded when not listed as FK
    expect($method->invoke($generator, $fkColumn, []))->toBeFalse();
    expect($method->invoke($generator, $fkColumn))->toBeFalse();
});

test('getForeignKeyColumns extracts column names from schema foreign_keys', function () {
    $generator = new DataGenerator;
    $reflection = new ReflectionClass($generator);
    $method = $reflection->getMethod('getForeignKeyColumns');

    $schema = [
        'table' => 'orders',
        'columns' => [],
        'foreign_keys' => [
            ['column' => 'user_id', 'foreign_table' => 'users', 'foreign_column' => 'id'],
            ['column' => 'course_id', 'foreign_table' => 'courses', 'foreign_column' => 'id'],
        ],
    ];

    $result = $method->invoke($generator, $schema);
    expect($result)->toBe(['user_id', 'course_id']);

    // Empty foreign keys
    $schemaNoFk = ['table' => 'users', 'columns' => [], 'foreign_keys' => []];
    expect($method->invoke($generator, $schemaNoFk))->toBe([]);
});

test('buildPrompt does not include foreign key columns or FK instructions', function () {
    $generator = new DataGenerator;
    $reflection = new ReflectionClass($generator);
    $method = $reflection->getMethod('buildPrompt');

    $schema = [
        'table' => 'orders',
        'columns' => [
            ['name' => 'id', 'type' => 'integer', 'nullable' => false, 'unique' => true, 'auto_increment' => true, 'primary_key' => true, 'is_json' => false, 'key_type' => 'auto_increment', 'max_length' => null, 'enum_values' => [], 'is_password' => false],
            ['name' => 'user_id', 'type' => 'char', 'nullable' => false, 'unique' => false, 'auto_increment' => false, 'primary_key' => false, 'is_json' => false, 'key_type' => 'none', 'max_length' => 26, 'enum_values' => [], 'is_password' => false],
            ['name' => 'title', 'type' => 'varchar', 'nullable' => false, 'unique' => false, 'auto_increment' => false, 'primary_key' => false, 'is_json' => false, 'key_type' => 'none', 'max_length' => 255, 'enum_values' => [], 'is_password' => false],
        ],
        'foreign_keys' => [
            ['column' => 'user_id', 'foreign_table' => 'users', 'foreign_column' => 'id'],
        ],
    ];

    $prompt = $method->invoke($generator, $schema, 5, 'en');

    // FK column should NOT appear in the prompt
    expect($prompt)->not->toContain('user_id');
    expect($prompt)->not->toContain('FK');
    expect($prompt)->not->toContain('FOREIGN KEY');

    // Regular column should still appear
    expect($prompt)->toContain('title');
});

test('SeederAgent instructions do not mention foreign keys', function () {
    $agent = new \Shennawy\AiSeeder\Agents\SeederAgent(
        schemaDescription: 'test',
        count: 1,
        columnDefinitions: [],
    );

    $instructions = $agent->instructions();
    expect($instructions)->not->toContain('foreign key columns, ONLY use the EXACT values');
    expect($instructions)->toContain('foreign key');
    expect($instructions)->toContain('generated by the application');
});

test('SeederAgent includes language rule for single non-English language', function () {
    $agent = new \Shennawy\AiSeeder\Agents\SeederAgent(
        schemaDescription: 'test',
        count: 1,
        columnDefinitions: [],
        language: 'ar',
    );

    $instructions = $agent->instructions();
    expect($instructions)->toContain('LANGUAGE');
    expect($instructions)->toContain('ar');
    expect($instructions)->toContain('12.');
});

test('SeederAgent includes multi-language rule for comma-separated languages', function () {
    $agent = new \Shennawy\AiSeeder\Agents\SeederAgent(
        schemaDescription: 'test',
        count: 1,
        columnDefinitions: [],
        language: 'ar,en,fr',
    );

    $instructions = $agent->instructions();
    expect($instructions)->toContain('LANGUAGE');
    expect($instructions)->toContain('ar, en, fr');
    expect($instructions)->toContain('multi-lingual');
    expect($instructions)->toContain('12.');
});

test('SeederAgent does not include language rule for en', function () {
    $agent = new \Shennawy\AiSeeder\Agents\SeederAgent(
        schemaDescription: 'test',
        count: 1,
        columnDefinitions: [],
        language: 'en',
    );

    $instructions = $agent->instructions();
    expect($instructions)->not->toContain('12.');
    expect($instructions)->not->toContain('LANGUAGE:');
});

test('buildContextSection returns empty string when contextCode is null', function () {
    $generator = new DataGenerator;
    $reflection = new ReflectionClass($generator);
    $method = $reflection->getMethod('buildContextSection');

    expect($method->invoke($generator, null))->toBe('');
    expect($method->invoke($generator, ''))->toBe('');
    expect($method->invoke($generator, '   '))->toBe('');
});

test('buildContextSection injects PHP code when provided', function () {
    $generator = new DataGenerator;
    $reflection = new ReflectionClass($generator);
    $method = $reflection->getMethod('buildContextSection');

    $fakeCode = '<?php class FakeRequest { public function rules() { return ["name" => "required"]; } }';

    $result = $method->invoke($generator, $fakeCode);

    expect($result)->toContain('BUSINESS LOGIC & VALIDATION RULES');
    expect($result)->toContain('deeply analyze this code');
    expect($result)->toContain($fakeCode);
    expect($result)->toContain('```php');
});

test('buildPrompt includes context section when contextCode is provided', function () {
    $generator = new DataGenerator;
    $reflection = new ReflectionClass($generator);
    $method = $reflection->getMethod('buildPrompt');

    $schema = [
        'table' => 'orders',
        'columns' => [
            ['name' => 'id', 'type' => 'integer', 'nullable' => false, 'unique' => true, 'auto_increment' => true, 'primary_key' => true, 'is_json' => false, 'key_type' => 'auto_increment', 'max_length' => null, 'enum_values' => [], 'is_password' => false],
            ['name' => 'total', 'type' => 'decimal', 'nullable' => false, 'unique' => false, 'auto_increment' => false, 'primary_key' => false, 'is_json' => false, 'key_type' => 'none', 'max_length' => null, 'enum_values' => [], 'is_password' => false],
        ],
        'foreign_keys' => [],
    ];

    $contextCode = '<?php class OrderRequest { public function rules() { return ["total" => "required|numeric|min:0"]; } }';

    $prompt = $method->invoke($generator, $schema, 3, 'en', $contextCode);

    expect($prompt)->toContain('BUSINESS LOGIC & VALIDATION RULES');
    expect($prompt)->toContain('OrderRequest');
    expect($prompt)->toContain('required|numeric|min:0');
});

test('buildPrompt does not include context section when contextCode is null', function () {
    $generator = new DataGenerator;
    $reflection = new ReflectionClass($generator);
    $method = $reflection->getMethod('buildPrompt');

    $schema = [
        'table' => 'orders',
        'columns' => [
            ['name' => 'id', 'type' => 'integer', 'nullable' => false, 'unique' => true, 'auto_increment' => true, 'primary_key' => true, 'is_json' => false, 'key_type' => 'auto_increment', 'max_length' => null, 'enum_values' => [], 'is_password' => false],
            ['name' => 'total', 'type' => 'decimal', 'nullable' => false, 'unique' => false, 'auto_increment' => false, 'primary_key' => false, 'is_json' => false, 'key_type' => 'none', 'max_length' => null, 'enum_values' => [], 'is_password' => false],
        ],
        'foreign_keys' => [],
    ];

    $prompt = $method->invoke($generator, $schema, 3, 'en', null);

    expect($prompt)->not->toContain('BUSINESS LOGIC & VALIDATION RULES');
});

test('SeederAgent schema does not contain additionalProperties key (Gemini compatibility)', function () {
    $agent = new \Shennawy\AiSeeder\Agents\SeederAgent(
        schemaDescription: 'test',
        count: 2,
        columnDefinitions: [
            'name' => ['type' => 'string', 'nullable' => false, 'unique' => false, 'is_json' => false, 'max_length' => 255, 'enum_values' => [], 'description' => 'User name'],
            'email' => ['type' => 'string', 'nullable' => false, 'unique' => true, 'is_json' => false, 'max_length' => 255, 'enum_values' => [], 'description' => 'User email'],
        ],
    );

    $jsonSchema = new \Illuminate\JsonSchema\JsonSchemaTypeFactory;
    $result = $agent->schema($jsonSchema);
    $serialized = json_encode($result['rows']->toArray(), JSON_PRETTY_PRINT);

    expect($serialized)->not->toContain('additionalProperties');
});

test('SeederAgent schema does not use array type for nullable columns (Gemini compatibility)', function () {
    $agent = new \Shennawy\AiSeeder\Agents\SeederAgent(
        schemaDescription: 'test',
        count: 1,
        columnDefinitions: [
            'name' => ['type' => 'string', 'nullable' => false, 'unique' => false, 'is_json' => false, 'max_length' => 255, 'enum_values' => [], 'description' => 'Required name'],
            'bio' => ['type' => 'string', 'nullable' => true, 'unique' => false, 'is_json' => false, 'max_length' => null, 'enum_values' => [], 'description' => 'Optional bio'],
        ],
    );

    $jsonSchema = new \Illuminate\JsonSchema\JsonSchemaTypeFactory;
    $result = $agent->schema($jsonSchema);
    $serialized = json_encode($result['rows']->toArray(), JSON_PRETTY_PRINT);

    expect($serialized)->not->toMatch('/"type"\s*:\s*\[/');
    expect($serialized)->toContain('NULLABLE');
});

test('SeederAgent schema serializes all column types as single strings (Gemini compatibility)', function () {
    $agent = new \Shennawy\AiSeeder\Agents\SeederAgent(
        schemaDescription: 'test',
        count: 1,
        columnDefinitions: [
            'title' => ['type' => 'string', 'nullable' => true, 'unique' => false, 'is_json' => false, 'max_length' => 100, 'enum_values' => [], 'description' => 'Title'],
            'count' => ['type' => 'integer', 'nullable' => true, 'unique' => false, 'is_json' => false, 'max_length' => null, 'enum_values' => [], 'description' => 'Count'],
            'price' => ['type' => 'float', 'nullable' => true, 'unique' => false, 'is_json' => false, 'max_length' => null, 'enum_values' => [], 'description' => 'Price'],
            'active' => ['type' => 'boolean', 'nullable' => true, 'unique' => false, 'is_json' => false, 'max_length' => null, 'enum_values' => [], 'description' => 'Active flag'],
            'tags' => ['type' => 'json', 'nullable' => true, 'unique' => false, 'is_json' => true, 'max_length' => null, 'enum_values' => [], 'description' => 'Tags'],
        ],
    );

    $jsonSchema = new \Illuminate\JsonSchema\JsonSchemaTypeFactory;
    $result = $agent->schema($jsonSchema);
    $serialized = $result['rows']->toArray();

    $properties = $serialized['items']['properties'] ?? [];

    foreach ($properties as $propName => $propSchema) {
        expect($propSchema['type'])->toBeString("Column [{$propName}] type should be a string, not an array");
    }
});

test('SeederAgent instructions include JSON object formatting rule', function () {
    $agent = new \Shennawy\AiSeeder\Agents\SeederAgent(
        schemaDescription: 'test',
        count: 1,
        columnDefinitions: [],
    );

    $instructions = $agent->instructions();
    expect($instructions)->toContain('11.');
    expect($instructions)->toContain('JSON Object');
    expect($instructions)->toContain('dot-notation');
    expect($instructions)->toContain('NEVER output a flat sequential array');
});

test('SeederAgent maps JSON columns to string type with object formatting description', function () {
    $agent = new \Shennawy\AiSeeder\Agents\SeederAgent(
        schemaDescription: 'test',
        count: 1,
        columnDefinitions: [
            'content' => ['type' => 'json', 'nullable' => false, 'unique' => false, 'is_json' => true, 'max_length' => null, 'enum_values' => [], 'description' => 'Content payload'],
            'name' => ['type' => 'string', 'nullable' => false, 'unique' => false, 'is_json' => false, 'max_length' => 255, 'enum_values' => [], 'description' => 'Name'],
        ],
    );

    $jsonSchema = new \Illuminate\JsonSchema\JsonSchemaTypeFactory;
    $result = $agent->schema($jsonSchema);
    $serialized = $result['rows']->toArray();

    $properties = $serialized['items']['properties'] ?? [];

    expect($properties['content']['type'])->toBe('string');
    expect($properties['content']['description'])->toContain('JSON Object');
    expect($properties['content']['description'])->toContain('NEVER output a flat sequential array');

    expect($properties['name']['type'])->toBe('string');
});

test('shouldExcludeColumn excludes timestamp columns', function () {
    $generator = new DataGenerator;
    $reflection = new ReflectionClass($generator);
    $method = $reflection->getMethod('shouldExcludeColumn');

    $createdAt = ['name' => 'created_at', 'auto_increment' => false, 'primary_key' => false, 'key_type' => 'none', 'is_password' => false];
    expect($method->invoke($generator, $createdAt))->toBeTrue();

    $updatedAt = ['name' => 'updated_at', 'auto_increment' => false, 'primary_key' => false, 'key_type' => 'none', 'is_password' => false];
    expect($method->invoke($generator, $updatedAt))->toBeTrue();

    $deletedAt = ['name' => 'deleted_at', 'auto_increment' => false, 'primary_key' => false, 'key_type' => 'none', 'is_password' => false];
    expect($method->invoke($generator, $deletedAt))->toBeTrue();

    // Regular column should NOT be excluded
    $regular = ['name' => 'title', 'auto_increment' => false, 'primary_key' => false, 'key_type' => 'none', 'is_password' => false];
    expect($method->invoke($generator, $regular))->toBeFalse();
});

test('post-processing injects native timestamps for created_at and updated_at', function () {
    $generator = new DataGenerator;
    $schema = [
        'table' => 'posts',
        'columns' => [
            ['name' => 'id', 'type' => 'integer', 'nullable' => false, 'unique' => true, 'auto_increment' => true, 'primary_key' => true, 'is_json' => false, 'key_type' => 'auto_increment', 'max_length' => null, 'enum_values' => [], 'is_password' => false],
            ['name' => 'title', 'type' => 'varchar', 'nullable' => false, 'unique' => false, 'auto_increment' => false, 'primary_key' => false, 'is_json' => false, 'key_type' => 'none', 'max_length' => 255, 'enum_values' => [], 'is_password' => false],
            ['name' => 'created_at', 'type' => 'timestamp', 'nullable' => true, 'unique' => false, 'auto_increment' => false, 'primary_key' => false, 'is_json' => false, 'key_type' => 'none', 'max_length' => null, 'enum_values' => [], 'is_password' => false],
            ['name' => 'updated_at', 'type' => 'timestamp', 'nullable' => true, 'unique' => false, 'auto_increment' => false, 'primary_key' => false, 'is_json' => false, 'key_type' => 'none', 'max_length' => null, 'enum_values' => [], 'is_password' => false],
        ],
        'foreign_keys' => [],
    ];

    $reflection = new ReflectionClass($generator);
    $method = $reflection->getMethod('postProcess');

    $rows = [['title' => 'Hello World']];
    $result = $method->invoke($generator, $rows, $schema);

    expect($result[0])->toHaveKey('created_at');
    expect($result[0])->toHaveKey('updated_at');
    expect($result[0]['created_at'])->toMatch('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/');
    expect($result[0]['updated_at'])->toMatch('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/');
    expect($result[0]['created_at'])->toBe($result[0]['updated_at']);
});

test('post-processing sets deleted_at to null for soft-delete tables', function () {
    $generator = new DataGenerator;
    $schema = [
        'table' => 'posts',
        'columns' => [
            ['name' => 'id', 'type' => 'integer', 'nullable' => false, 'unique' => true, 'auto_increment' => true, 'primary_key' => true, 'is_json' => false, 'key_type' => 'auto_increment', 'max_length' => null, 'enum_values' => [], 'is_password' => false],
            ['name' => 'title', 'type' => 'varchar', 'nullable' => false, 'unique' => false, 'auto_increment' => false, 'primary_key' => false, 'is_json' => false, 'key_type' => 'none', 'max_length' => 255, 'enum_values' => [], 'is_password' => false],
            ['name' => 'deleted_at', 'type' => 'timestamp', 'nullable' => true, 'unique' => false, 'auto_increment' => false, 'primary_key' => false, 'is_json' => false, 'key_type' => 'none', 'max_length' => null, 'enum_values' => [], 'is_password' => false],
        ],
        'foreign_keys' => [],
    ];

    $reflection = new ReflectionClass($generator);
    $method = $reflection->getMethod('postProcess');

    $rows = [['title' => 'Hello World']];
    $result = $method->invoke($generator, $rows, $schema);

    expect($result[0])->toHaveKey('deleted_at');
    expect($result[0]['deleted_at'])->toBeNull();
});

test('post-processing sanitizes string "null" to actual null for nullable columns', function () {
    $generator = new DataGenerator;
    $schema = [
        'table' => 'users',
        'columns' => [
            ['name' => 'id', 'type' => 'integer', 'nullable' => false, 'unique' => true, 'auto_increment' => true, 'primary_key' => true, 'is_json' => false, 'key_type' => 'auto_increment', 'max_length' => null, 'enum_values' => [], 'is_password' => false],
            ['name' => 'name', 'type' => 'varchar', 'nullable' => false, 'unique' => false, 'auto_increment' => false, 'primary_key' => false, 'is_json' => false, 'key_type' => 'none', 'max_length' => 255, 'enum_values' => [], 'is_password' => false],
            ['name' => 'bio', 'type' => 'text', 'nullable' => true, 'unique' => false, 'auto_increment' => false, 'primary_key' => false, 'is_json' => false, 'key_type' => 'none', 'max_length' => null, 'enum_values' => [], 'is_password' => false],
            ['name' => 'phone', 'type' => 'varchar', 'nullable' => true, 'unique' => false, 'auto_increment' => false, 'primary_key' => false, 'is_json' => false, 'key_type' => 'none', 'max_length' => 20, 'enum_values' => [], 'is_password' => false],
        ],
        'foreign_keys' => [],
    ];

    $reflection = new ReflectionClass($generator);
    $method = $reflection->getMethod('postProcess');

    $rows = [
        ['name' => 'Alice', 'bio' => 'null', 'phone' => 'NULL'],
        ['name' => 'Bob', 'bio' => '', 'phone' => ' null '],
        ['name' => 'Charlie', 'bio' => 'A real bio', 'phone' => '+1234567890'],
    ];

    $result = $method->invoke($generator, $rows, $schema);

    // String "null" / "NULL" → actual null
    expect($result[0]['bio'])->toBeNull();
    expect($result[0]['phone'])->toBeNull();

    // Empty string → null for nullable columns
    expect($result[1]['bio'])->toBeNull();
    // " null " with whitespace → null
    expect($result[1]['phone'])->toBeNull();

    // Real values should be preserved
    expect($result[2]['bio'])->toBe('A real bio');
    expect($result[2]['phone'])->toBe('+1234567890');

    // Non-nullable column should NOT be sanitized even if it contained "null"
    expect($result[0]['name'])->toBe('Alice');
});

test('post-processing does not sanitize string "null" for non-nullable columns', function () {
    $generator = new DataGenerator;
    $schema = [
        'table' => 'users',
        'columns' => [
            ['name' => 'id', 'type' => 'integer', 'nullable' => false, 'unique' => true, 'auto_increment' => true, 'primary_key' => true, 'is_json' => false, 'key_type' => 'auto_increment', 'max_length' => null, 'enum_values' => [], 'is_password' => false],
            ['name' => 'name', 'type' => 'varchar', 'nullable' => false, 'unique' => false, 'auto_increment' => false, 'primary_key' => false, 'is_json' => false, 'key_type' => 'none', 'max_length' => 255, 'enum_values' => [], 'is_password' => false],
        ],
        'foreign_keys' => [],
    ];

    $reflection = new ReflectionClass($generator);
    $method = $reflection->getMethod('postProcess');

    // If AI somehow returns "null" for a non-nullable column, it should be preserved as-is
    // (the DB will reject it, which is correct — we don't want to silently null a required field)
    $rows = [['name' => 'null']];
    $result = $method->invoke($generator, $rows, $schema);

    expect($result[0]['name'])->toBe('null');
});

test('buildPrompt excludes timestamp columns', function () {
    $generator = new DataGenerator;
    $reflection = new ReflectionClass($generator);
    $method = $reflection->getMethod('buildPrompt');

    $schema = [
        'table' => 'posts',
        'columns' => [
            ['name' => 'id', 'type' => 'integer', 'nullable' => false, 'unique' => true, 'auto_increment' => true, 'primary_key' => true, 'is_json' => false, 'key_type' => 'auto_increment', 'max_length' => null, 'enum_values' => [], 'is_password' => false],
            ['name' => 'title', 'type' => 'varchar', 'nullable' => false, 'unique' => false, 'auto_increment' => false, 'primary_key' => false, 'is_json' => false, 'key_type' => 'none', 'max_length' => 255, 'enum_values' => [], 'is_password' => false],
            ['name' => 'created_at', 'type' => 'timestamp', 'nullable' => true, 'unique' => false, 'auto_increment' => false, 'primary_key' => false, 'is_json' => false, 'key_type' => 'none', 'max_length' => null, 'enum_values' => [], 'is_password' => false],
            ['name' => 'updated_at', 'type' => 'timestamp', 'nullable' => true, 'unique' => false, 'auto_increment' => false, 'primary_key' => false, 'is_json' => false, 'key_type' => 'none', 'max_length' => null, 'enum_values' => [], 'is_password' => false],
            ['name' => 'deleted_at', 'type' => 'timestamp', 'nullable' => true, 'unique' => false, 'auto_increment' => false, 'primary_key' => false, 'is_json' => false, 'key_type' => 'none', 'max_length' => null, 'enum_values' => [], 'is_password' => false],
        ],
        'foreign_keys' => [],
    ];

    $prompt = $method->invoke($generator, $schema, 3, 'en');

    // Timestamp columns should NOT appear in the prompt
    expect($prompt)->not->toContain('created_at');
    expect($prompt)->not->toContain('updated_at');
    expect($prompt)->not->toContain('deleted_at');

    // Regular column should still appear
    expect($prompt)->toContain('title');

    // Prompt should mention timestamps are auto-generated
    expect($prompt)->toContain('timestamps');
});

test('SeederAgent instructions mention timestamp exclusion', function () {
    $agent = new \Shennawy\AiSeeder\Agents\SeederAgent(
        schemaDescription: 'test',
        count: 1,
        columnDefinitions: [],
    );

    $instructions = $agent->instructions();
    expect($instructions)->toContain('timestamp columns');
    expect($instructions)->toContain('created_at');
    expect($instructions)->toContain('deleted_at');
});

// ─── Self-Referencing FK / Empty Parent ID Tests ─────────────────────

test('post-processing sets null for nullable FK when parent IDs array is empty', function () {
    $generator = new DataGenerator;
    $schema = [
        'table' => 'categories',
        'columns' => [
            ['name' => 'id', 'type' => 'integer', 'nullable' => false, 'unique' => true, 'auto_increment' => true, 'primary_key' => true, 'is_json' => false, 'key_type' => 'auto_increment', 'max_length' => null, 'enum_values' => [], 'is_password' => false],
            ['name' => 'name', 'type' => 'varchar', 'nullable' => false, 'unique' => false, 'auto_increment' => false, 'primary_key' => false, 'is_json' => false, 'key_type' => 'none', 'max_length' => 255, 'enum_values' => [], 'is_password' => false],
            ['name' => 'parent_id', 'type' => 'bigint', 'nullable' => true, 'unique' => false, 'auto_increment' => false, 'primary_key' => false, 'is_json' => false, 'key_type' => 'none', 'max_length' => null, 'enum_values' => [], 'is_password' => false],
        ],
        'foreign_keys' => [
            ['column' => 'parent_id', 'foreign_table' => 'categories', 'foreign_column' => 'id'],
        ],
    ];

    $reflection = new ReflectionClass($generator);
    $method = $reflection->getMethod('postProcess');

    $rows = [
        ['name' => 'Root Category'],
        ['name' => 'Another Root'],
    ];

    // Empty parent IDs → self-referencing table with no existing records
    $result = $method->invoke($generator, $rows, $schema, ['parent_id' => []]);

    expect($result)->toHaveCount(2);

    foreach ($result as $row) {
        expect($row)->toHaveKey('parent_id');
        expect($row['parent_id'])->toBeNull();
    }
});

test('post-processing assigns random FK when parent IDs array is NOT empty', function () {
    $generator = new DataGenerator;
    $schema = [
        'table' => 'categories',
        'columns' => [
            ['name' => 'id', 'type' => 'integer', 'nullable' => false, 'unique' => true, 'auto_increment' => true, 'primary_key' => true, 'is_json' => false, 'key_type' => 'auto_increment', 'max_length' => null, 'enum_values' => [], 'is_password' => false],
            ['name' => 'name', 'type' => 'varchar', 'nullable' => false, 'unique' => false, 'auto_increment' => false, 'primary_key' => false, 'is_json' => false, 'key_type' => 'none', 'max_length' => 255, 'enum_values' => [], 'is_password' => false],
            ['name' => 'parent_id', 'type' => 'bigint', 'nullable' => true, 'unique' => false, 'auto_increment' => false, 'primary_key' => false, 'is_json' => false, 'key_type' => 'none', 'max_length' => null, 'enum_values' => [], 'is_password' => false],
        ],
        'foreign_keys' => [
            ['column' => 'parent_id', 'foreign_table' => 'categories', 'foreign_column' => 'id'],
        ],
    ];

    $reflection = new ReflectionClass($generator);
    $method = $reflection->getMethod('postProcess');

    $rows = [
        ['name' => 'Child Category'],
    ];

    $existingParentIds = [10, 20, 30];
    $result = $method->invoke($generator, $rows, $schema, ['parent_id' => $existingParentIds]);

    expect($result[0])->toHaveKey('parent_id');
    expect($result[0]['parent_id'])->toBeIn($existingParentIds);
});

test('post-processing does NOT set null for non-nullable FK when parent IDs are empty', function () {
    $generator = new DataGenerator;
    $schema = [
        'table' => 'orders',
        'columns' => [
            ['name' => 'id', 'type' => 'integer', 'nullable' => false, 'unique' => true, 'auto_increment' => true, 'primary_key' => true, 'is_json' => false, 'key_type' => 'auto_increment', 'max_length' => null, 'enum_values' => [], 'is_password' => false],
            ['name' => 'user_id', 'type' => 'bigint', 'nullable' => false, 'unique' => false, 'auto_increment' => false, 'primary_key' => false, 'is_json' => false, 'key_type' => 'none', 'max_length' => null, 'enum_values' => [], 'is_password' => false],
            ['name' => 'total', 'type' => 'decimal', 'nullable' => false, 'unique' => false, 'auto_increment' => false, 'primary_key' => false, 'is_json' => false, 'key_type' => 'none', 'max_length' => null, 'enum_values' => [], 'is_password' => false],
        ],
        'foreign_keys' => [
            ['column' => 'user_id', 'foreign_table' => 'users', 'foreign_column' => 'id'],
        ],
    ];

    $reflection = new ReflectionClass($generator);
    $method = $reflection->getMethod('postProcess');

    $rows = [
        ['total' => 99.99],
    ];

    // Empty parent IDs but user_id is NOT nullable — should NOT inject null
    $result = $method->invoke($generator, $rows, $schema, ['user_id' => []]);

    // user_id should not have been set at all (no key in row)
    expect($result[0])->not->toHaveKey('user_id');
});
