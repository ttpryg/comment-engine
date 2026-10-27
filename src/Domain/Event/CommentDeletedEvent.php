<?php

declare(strict_types=1);

namespace Ttpryg\CommentEngine\Domain\Event;

final class CommentDeletedEvent
{
    public function __construct(
        public readonly int|string $commentId,
        public readonly bool $softDeleted
    ) {}
}
