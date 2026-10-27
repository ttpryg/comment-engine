<?php

declare(strict_types=1);

namespace Ttpryg\CommentEngine\Domain\Exception;

class InvalidCommentTargetException extends CommentEngineException
{
    public static function emptyType(): self
    {
        return new self('Commentable type cannot be empty.');
    }

    public static function invalidType(): self
    {
        return new self('Commentable type must start with a letter and contain only letters, digits, dots, underscores, or dashes (max 50 characters).');
    }

    public static function emptyId(): self
    {
        return new self('Commentable ID cannot be empty.');
    }

    public static function idTooLong(int $maxLength): self
    {
        return new self("Commentable ID cannot exceed {$maxLength} characters.");
    }
}
