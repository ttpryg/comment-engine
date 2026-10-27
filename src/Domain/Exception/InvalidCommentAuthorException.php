<?php

declare(strict_types=1);

namespace Ttpryg\CommentEngine\Domain\Exception;

class InvalidCommentAuthorException extends CommentEngineException
{
    public static function empty(): self
    {
        return new self('Comment author ID cannot be empty. Use null for guest comments.');
    }
}
