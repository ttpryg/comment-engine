<?php

declare(strict_types=1);

namespace Ttpryg\CommentEngine\Domain\Exception;

class CommentNotFoundException extends CommentEngineException
{
    public static function byId(int|string $id): self
    {
        return new self("Comment with ID '{$id}' was not found.");
    }
}
