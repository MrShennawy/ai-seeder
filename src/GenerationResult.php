<?php

declare(strict_types=1);

namespace Shennawy\AiSeeder;

/**
 * Encapsulates the result of a single DataGenerator::generate() call,
 * bundling the generated rows with their token usage metrics.
 */
final readonly class GenerationResult
{
    /**
     * @param  array<int, array<string, mixed>>  $rows  The generated and post-processed database rows.
     * @param  int  $promptTokens  Prompt/input tokens consumed by the AI call.
     * @param  int  $completionTokens  Completion/output tokens consumed by the AI call.
     */
    public function __construct(
        public array $rows,
        public int $promptTokens = 0,
        public int $completionTokens = 0,
    ) {}

    public function totalTokens(): int
    {
        return $this->promptTokens + $this->completionTokens;
    }
}
