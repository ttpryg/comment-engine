<?php

declare(strict_types=1);

namespace Ttpryg\CommentEngine\Domain\Exception;

class InvalidCommentTenantException extends CommentEngineException
{
    public static function empty(): self
    {
        return new self('Comment tenant ID cannot be empty. Use null for unscoped operations.');
    }

    public static function tooLong(int $maxLength): self
    {
        return new self(sprintf('Comment tenant ID cannot be longer than %d characters.', $maxLength));
    }
}
