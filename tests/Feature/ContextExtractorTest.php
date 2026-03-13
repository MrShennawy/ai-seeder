<?php

use Shennawy\AiSeeder\ContextExtractor;

test('it extracts source code of an existing class', function () {
    $extractor = new ContextExtractor;

    $result = $extractor->extract(ContextExtractor::class);

    expect($result)->toBeArray();
    expect($result)->toHaveKeys(['label', 'content']);
    expect($result['label'])->toContain('PHP Class:');
    expect($result['content'])->toContain('class ContextExtractor');
    expect($result['content'])->toContain('namespace Shennawy\AiSeeder');
});

test('it throws for a non-existent class', function () {
    $extractor = new ContextExtractor;

    $extractor->extract('App\\NonExistent\\FakeClass');
})->throws(RuntimeException::class, 'does not exist');

test('tryExtract returns context array for existing class', function () {
    $extractor = new ContextExtractor;

    $result = $extractor->tryExtract(ContextExtractor::class);

    expect($result)->toBeArray();
    expect($result['content'])->toContain('class ContextExtractor');
});

test('tryExtract returns null for non-existent class', function () {
    $extractor = new ContextExtractor;

    $result = $extractor->tryExtract('App\\NonExistent\\FakeClass');

    expect($result)->toBeNull();
});

test('extracted class code contains the full file content', function () {
    $extractor = new ContextExtractor;

    $result = $extractor->extract(ContextExtractor::class);

    expect($result['content'])->toStartWith('<?php');
    expect($result['content'])->toContain('public function extract');
    expect($result['content'])->toContain('public function tryExtract');
    expect($result['content'])->toContain('use ReflectionClass');
});

test('it extracts content from a file path', function () {
    $tmpFile = tempnam(sys_get_temp_dir(), 'ai_seeder_test_');
    file_put_contents($tmpFile, "# Test Markdown\n\nSome rules here.");

    $extractor = new ContextExtractor;

    $result = $extractor->extract($tmpFile);

    expect($result)->toBeArray();
    expect($result['label'])->toContain('File:');
    expect($result['content'])->toContain('# Test Markdown');
    expect($result['content'])->toContain('Some rules here.');

    unlink($tmpFile);
});

test('it extracts content from a markdown file', function () {
    $tmpFile = tempnam(sys_get_temp_dir(), 'ai_ctx_').'.md';
    file_put_contents($tmpFile, "# Domain Rules\n\n- Users must have unique emails\n- Status must be active or inactive");

    $extractor = new ContextExtractor;

    $result = $extractor->extract($tmpFile);

    expect($result['label'])->toContain('MD File:');
    expect($result['content'])->toContain('Domain Rules');
    expect($result['content'])->toContain('Status must be active or inactive');

    unlink($tmpFile);
});

test('it treats non-class non-file strings as inline text', function () {
    $extractor = new ContextExtractor;

    $result = $extractor->extract('All names must be realistic Arabic names from the Gulf region');

    expect($result['label'])->toContain('Inline text:');
    expect($result['content'])->toBe('All names must be realistic Arabic names from the Gulf region');
});

test('it throws for empty input', function () {
    $extractor = new ContextExtractor;

    $extractor->extract('');
})->throws(RuntimeException::class, 'cannot be empty');

test('it throws for a file path that does not exist', function () {
    $extractor = new ContextExtractor;

    $extractor->extract('/tmp/this_file_does_not_exist_12345.md');
})->throws(RuntimeException::class, 'does not exist or is not readable');

test('extractMany handles multiple inputs of different types', function () {
    // Create a temp file
    $tmpFile = tempnam(sys_get_temp_dir(), 'ai_multi_').'.txt';
    file_put_contents($tmpFile, 'File content here');

    $extractor = new ContextExtractor;

    $results = $extractor->extractMany([
        ContextExtractor::class,                   // PHP class
        $tmpFile,                                  // File path
        'Ensure all emails end with @example.com', // Inline text
    ]);

    expect($results)->toHaveCount(3);

    // First = class
    expect($results[0]['label'])->toContain('PHP Class:');
    expect($results[0]['content'])->toContain('class ContextExtractor');

    // Second = file
    expect($results[1]['label'])->toContain('File:');
    expect($results[1]['content'])->toBe('File content here');

    // Third = inline text
    expect($results[2]['label'])->toContain('Inline text:');
    expect($results[2]['content'])->toContain('@example.com');

    unlink($tmpFile);
});

test('inline text label truncates long strings', function () {
    $extractor = new ContextExtractor;

    $longText = str_repeat('A', 200);
    $result = $extractor->extract($longText);

    expect($result['label'])->toContain('Inline text:');
    // Label preview should be truncated (not contain the full 200 chars)
    expect(mb_strlen($result['label']))->toBeLessThan(100);
    // But the full content is preserved
    expect($result['content'])->toBe($longText);
});
