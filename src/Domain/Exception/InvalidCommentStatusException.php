<?php

declare(strict_types=1);

namespace Ttpryg\CommentEngine\Domain\Exception;

class InvalidCommentStatusException extends CommentEngineException
{
    public function __construct(string $status)
    {
        parent::__construct("Invalid comment status '{$status}'. Allowed values are 'pending', 'approved', 'rejected', 'spam'.");
    }
}
