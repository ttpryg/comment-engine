<?php

declare(strict_types=1);

namespace Ttpryg\CommentEngine\Domain\Exception;

class InvalidCommentBodyException extends CommentEngineException
{
    public static function empty(): self
    {
        return new self('Comment body cannot be empty.');
    }

    public static function tooLong(int $maxLength): self
    {
        return new self("Comment body cannot exceed {$maxLength} characters.");
    }
}
