<?php

use Shennawy\AiSeeder\TokenUsageTracker;

test('it starts with zero tokens', function () {
    $tracker = new TokenUsageTracker;

    expect($tracker->getPromptTokens())->toBe(0);
    expect($tracker->getCompletionTokens())->toBe(0);
    expect($tracker->getTotalTokens())->toBe(0);
});

test('it accumulates tokens from multiple add calls', function () {
    $tracker = new TokenUsageTracker;

    $tracker->add(100, 200);
    $tracker->add(150, 250);

    expect($tracker->getPromptTokens())->toBe(250);
    expect($tracker->getCompletionTokens())->toBe(450);
    expect($tracker->getTotalTokens())->toBe(700);
});

test('it merges another tracker', function () {
    $tracker1 = new TokenUsageTracker;
    $tracker1->add(100, 200);

    $tracker2 = new TokenUsageTracker;
    $tracker2->add(50, 75);

    $tracker1->merge($tracker2);

    expect($tracker1->getPromptTokens())->toBe(150);
    expect($tracker1->getCompletionTokens())->toBe(275);
    expect($tracker1->getTotalTokens())->toBe(425);
});

test('toArray returns correct summary', function () {
    $tracker = new TokenUsageTracker;
    $tracker->add(500, 800);

    $result = $tracker->toArray();

    expect($result)->toBe([
        'prompt_tokens' => 500,
        'completion_tokens' => 800,
        'total_tokens' => 1300,
    ]);
});
