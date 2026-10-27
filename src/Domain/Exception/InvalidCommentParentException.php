<?php

declare(strict_types=1);

namespace Ttpryg\CommentEngine\Domain\Exception;

class InvalidCommentParentException extends CommentEngineException
{
    public static function emptyId(): self
    {
        return new self('Parent comment ID cannot be empty. Use null for a top-level comment.');
    }

    public static function differentTarget(): self
    {
        return new self('A reply must target the same commentable resource as its parent comment.');
    }

    public static function selfReference(): self
    {
        return new self('A comment cannot be its own parent.');
    }
}
