<?php

declare(strict_types=1);

namespace Shennawy\AiSeeder;

/**
 * Aggregates token usage across multiple AI API calls
 * (chunks, recursive parent seeding, retries).
 */
class TokenUsageTracker
{
    private int $promptTokens = 0;

    private int $completionTokens = 0;

    /**
     * Add token usage from a single AI call.
     */
    public function add(int $promptTokens, int $completionTokens): void
    {
        $this->promptTokens += $promptTokens;
        $this->completionTokens += $completionTokens;
    }

    /**
     * Merge another tracker's totals into this one.
     */
    public function merge(self $other): void
    {
        $this->promptTokens += $other->promptTokens;
        $this->completionTokens += $other->completionTokens;
    }

    public function getPromptTokens(): int
    {
        return $this->promptTokens;
    }

    public function getCompletionTokens(): int
    {
        return $this->completionTokens;
    }

    public function getTotalTokens(): int
    {
        return $this->promptTokens + $this->completionTokens;
    }

    /**
     * Return usage as a summary array for display.
     *
     * @return array{prompt_tokens: int, completion_tokens: int, total_tokens: int}
     */
    public function toArray(): array
    {
        return [
            'prompt_tokens' => $this->promptTokens,
            'completion_tokens' => $this->completionTokens,
            'total_tokens' => $this->getTotalTokens(),
        ];
    }
}
