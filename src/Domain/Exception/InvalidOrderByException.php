<?php

declare(strict_types=1);

namespace Ttpryg\CommentEngine\Domain\Exception;

class InvalidOrderByException extends CommentEngineException
{
    /**
     * @param  array<int, string>  $allowedColumns
     */
    public static function column(string $column, array $allowedColumns): self
    {
        return new self(sprintf(
            "Invalid order-by column '%s'. Allowed columns are: %s.",
            $column,
            implode(', ', $allowedColumns)
        ));
    }
}
