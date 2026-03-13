<?php

declare(strict_types=1);

namespace Shennawy\AiSeeder;

use ReflectionClass;
use RuntimeException;

/**
 * Extracts context from multiple sources for AI prompt injection.
 *
 * Supports:
 * - Fully-qualified PHP class names (uses Reflection to read source)
 * - File paths (any file type: .php, .md, .txt, .json, etc.)
 * - Raw inline text strings
 */
class ContextExtractor
{
    /**
     * Extract context from a single input string.
     *
     * Detection order:
     * 1. If the string looks like a fully-qualified class name and the class exists → read via Reflection.
     * 2. If the string looks like a file path and the file exists → read via file_get_contents.
     * 3. Otherwise → treat as raw inline text.
     *
     * @return array{label: string, content: string}
     *
     * @throws RuntimeException If a detected file/class cannot be read.
     */
    public function extract(string $input): array
    {
        $input = trim($input);

        if ($input === '') {
            throw new RuntimeException('Context input cannot be empty.');
        }

        // 1. Try as a PHP class (must look like a namespace and actually exist)
        if ($this->looksLikeClassName($input) && class_exists($input)) {
            return $this->extractFromClass($input);
        }

        // 2. Try as a file path
        if ($this->looksLikeFilePath($input) && file_exists($input)) {
            return $this->extractFromFile($input);
        }

        // 3. If it looks like a class but doesn't exist, throw a helpful error
        if ($this->looksLikeClassName($input)) {
            throw new RuntimeException(
                "Context class [{$input}] does not exist. "
                .'Please provide a valid fully-qualified class name.'
            );
        }

        // 4. If it looks like a file path but doesn't exist, throw a helpful error
        if ($this->looksLikeFilePath($input)) {
            throw new RuntimeException(
                "Context file [{$input}] does not exist or is not readable."
            );
        }

        // 5. Treat as raw inline text
        return $this->extractAsText($input);
    }

    /**
     * Extract context from multiple inputs.
     *
     * @param  array<int, string>  $inputs
     * @return array<int, array{label: string, content: string}>
     *
     * @throws RuntimeException If any input cannot be resolved.
     */
    public function extractMany(array $inputs): array
    {
        $results = [];

        foreach ($inputs as $input) {
            $results[] = $this->extract($input);
        }

        return $results;
    }

    /**
     * Attempt to extract context, returning null on failure instead of throwing.
     *
     * @return array{label: string, content: string}|null
     */
    public function tryExtract(string $input): ?array
    {
        try {
            return $this->extract($input);
        } catch (RuntimeException) {
            return null;
        }
    }

    /**
     * Extract source code of a fully-qualified PHP class via Reflection.
     *
     * @return array{label: string, content: string}
     */
    private function extractFromClass(string $className): array
    {
        $reflection = new ReflectionClass($className);
        $filePath = $reflection->getFileName();

        if ($filePath === false) {
            throw new RuntimeException(
                "Cannot determine the file path for class [{$className}]. "
                .'It may be a built-in or dynamically defined class.'
            );
        }

        $contents = $this->readFile($filePath);

        return [
            'label' => "PHP Class: {$className}",
            'content' => $contents,
        ];
    }

    /**
     * Extract raw content from any file path.
     *
     * @return array{label: string, content: string}
     */
    private function extractFromFile(string $filePath): array
    {
        $realPath = realpath($filePath);

        if ($realPath === false) {
            throw new RuntimeException(
                "Context file [{$filePath}] could not be resolved to a real path."
            );
        }

        $contents = $this->readFile($realPath);
        $extension = strtoupper(pathinfo($realPath, PATHINFO_EXTENSION) ?: 'FILE');
        $basename = basename($realPath);

        return [
            'label' => "{$extension} File: {$basename} ({$realPath})",
            'content' => $contents,
        ];
    }

    /**
     * Wrap a raw text string as a context item.
     *
     * @return array{label: string, content: string}
     */
    private function extractAsText(string $text): array
    {
        $preview = mb_strlen($text) > 60
            ? mb_substr($text, 0, 60).'…'
            : $text;

        return [
            'label' => "Inline text: \"{$preview}\"",
            'content' => $text,
        ];
    }

    /**
     * Read a file's contents with validation.
     */
    private function readFile(string $filePath): string
    {
        if (! is_readable($filePath)) {
            throw new RuntimeException(
                "Cannot read file [{$filePath}]. Check permissions."
            );
        }

        $contents = file_get_contents($filePath);

        if ($contents === false) {
            throw new RuntimeException(
                "Failed to read the contents of [{$filePath}]."
            );
        }

        return $contents;
    }

    /**
     * Determine if the input looks like a fully-qualified PHP class name.
     *
     * Matches patterns like: App\Http\Requests\StorePostRequest, Modules\Course\Model
     */
    private function looksLikeClassName(string $input): bool
    {
        return (bool) preg_match('/^[A-Za-z_\\\\][A-Za-z0-9_\\\\]*\\\\[A-Za-z0-9_]+$/', $input);
    }

    /**
     * Determine if the input looks like a file path.
     *
     * Matches paths containing slashes, starting with . or ~, or being a single
     * token (no spaces) with a recognizable file extension.
     */
    private function looksLikeFilePath(string $input): bool
    {
        if (str_contains($input, '/') || str_contains($input, DIRECTORY_SEPARATOR)) {
            return true;
        }

        if (str_starts_with($input, '.') || str_starts_with($input, '~')) {
            return true;
        }

        // Single token (no spaces) with a file extension (e.g., schema.md, rules.json)
        if (! str_contains($input, ' ') && preg_match('/\.\w{1,10}$/', $input)) {
            return true;
        }

        return false;
    }
}
