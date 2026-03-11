<?php

use Shennawy\AiSeeder\ContextExtractor;

test('it extracts source code of an existing class', function () {
    $extractor = new ContextExtractor;

    // Use a class we know exists in the project
    $code = $extractor->extract(ContextExtractor::class);

    expect($code)->toBeString();
    expect($code)->toContain('class ContextExtractor');
    expect($code)->toContain('namespace Shennawy\AiSeeder');
    expect($code)->toContain('function extract');
});

test('it throws for a non-existent class', function () {
    $extractor = new ContextExtractor;

    $extractor->extract('App\\NonExistent\\FakeClass');
})->throws(RuntimeException::class, 'does not exist');

test('tryExtract returns source code for existing class', function () {
    $extractor = new ContextExtractor;

    $code = $extractor->tryExtract(ContextExtractor::class);

    expect($code)->toBeString();
    expect($code)->toContain('class ContextExtractor');
});

test('tryExtract returns null for non-existent class', function () {
    $extractor = new ContextExtractor;

    $result = $extractor->tryExtract('App\\NonExistent\\FakeClass');

    expect($result)->toBeNull();
});

test('extracted code contains the full file content', function () {
    $extractor = new ContextExtractor;

    $code = $extractor->extract(ContextExtractor::class);

    // Should start with PHP open tag
    expect($code)->toStartWith('<?php');

    // Should contain both public methods
    expect($code)->toContain('public function extract');
    expect($code)->toContain('public function tryExtract');

    // Should contain the imports
    expect($code)->toContain('use ReflectionClass');
    expect($code)->toContain('use RuntimeException');
});
