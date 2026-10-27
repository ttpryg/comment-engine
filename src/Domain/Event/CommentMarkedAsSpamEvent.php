<?php

declare(strict_types=1);

namespace Ttpryg\CommentEngine\Domain\Event;

use Ttpryg\CommentEngine\Domain\Entity\Comment;

final class CommentMarkedAsSpamEvent
{
    public function __construct(
        public readonly Comment $comment,
        public readonly int|string $moderatorId
    ) {}
}
