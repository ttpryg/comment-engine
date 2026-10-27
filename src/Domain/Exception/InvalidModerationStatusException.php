<?php

declare(strict_types=1);

namespace Ttpryg\CommentEngine\Domain\Exception;

use Ttpryg\CommentEngine\Domain\Enum\CommentStatus;

class InvalidModerationStatusException extends CommentEngineException
{
    public static function forStatus(CommentStatus $status): self
    {
        return new self(
            "Status '{$status->value}' cannot be applied by moderation. Allowed moderation statuses are 'approved', 'rejected', 'spam'."
        );
    }
}
