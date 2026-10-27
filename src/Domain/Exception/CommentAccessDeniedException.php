<?php

declare(strict_types=1);

namespace Ttpryg\CommentEngine\Domain\Exception;

class CommentAccessDeniedException extends CommentEngineException
{
    public static function update(int|string $id): self
    {
        return new self("Actor is not allowed to update comment '{$id}'.");
    }

    public static function delete(int|string $id): self
    {
        return new self("Actor is not allowed to delete comment '{$id}'.");
    }
}
