<?php

declare(strict_types=1);

namespace Shennawy\AiSeeder;

use ReflectionClass;
use RuntimeException;

/**
 * Extracts the raw PHP source code of a given class using Reflection.
 *
 * Used to inject business logic context (FormRequests, Models, etc.)
 * into the AI prompt so the LLM can generate data that respects
 * validation rules, morphs, casts, and other code-defined constraints.
 */
class ContextExtractor
{
    /**
     * Extract the raw source code of the given fully-qualified class name.
     *
     * @throws RuntimeException If the class does not exist or its file cannot be read.
     */
    public function extract(string $className): string
    {
        if (! class_exists($className)) {
            throw new RuntimeException(
                "Context class [{$className}] does not exist. "
                .'Please provide a valid fully-qualified class name (e.g., App\\Http\\Requests\\StorePostRequest).'
            );
        }

        $reflection = new ReflectionClass($className);
        $filePath = $reflection->getFileName();

        if ($filePath === false) {
            throw new RuntimeException(
                "Cannot determine the file path for class [{$className}]. "
                .'It may be a built-in or dynamically defined class.'
            );
        }

        if (! is_readable($filePath)) {
            throw new RuntimeException(
                "Cannot read the source file for class [{$className}] at [{$filePath}]."
            );
        }

        $contents = file_get_contents($filePath);

        if ($contents === false) {
            throw new RuntimeException(
                "Failed to read the contents of [{$filePath}] for class [{$className}]."
            );
        }

        return $contents;
    }

    /**
     * Attempt to extract source code, returning null on failure instead of throwing.
     */
    public function tryExtract(string $className): ?string
    {
        try {
            return $this->extract($className);
        } catch (RuntimeException) {
            return null;
        }
    }
}
