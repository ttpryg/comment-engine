<?php

declare(strict_types=1);

namespace Ttpryg\CommentEngine\Domain\Enum;

enum CommentStatus: string
{
    case PENDING = 'pending';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
    case SPAM = 'spam';

    public static function isValid(string $status): bool
    {
        return self::tryFrom($status) !== null;
    }
}
