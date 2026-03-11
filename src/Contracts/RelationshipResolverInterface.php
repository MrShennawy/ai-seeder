<?php

declare(strict_types=1);

namespace Shennawy\AiSeeder\Contracts;

interface RelationshipResolverInterface
{
    /**
     * Resolve foreign key constraints for a given table schema.
     *
     * Returns a map of column names to their available parent IDs.
     * If a parent table is empty, it recursively seeds that table first.
     *
     * @param  array<int, array{column: string, foreign_table: string, foreign_column: string}>  $foreignKeys
     * @return array<string, array<int, int|string>>
     */
    public function resolve(array $foreignKeys, int $minimumParentRows): array;

    /**
     * Resolve a single foreign key constraint.
     *
     * Returns the available parent IDs for the given FK column.
     * If the parent table is empty, it recursively seeds it first.
     *
     * @param  array{column: string, foreign_table: string, foreign_column: string}  $foreignKey
     * @return array<int, int|string>
     */
    public function resolveSingle(array $foreignKey, int $minimumParentRows): array;
}
