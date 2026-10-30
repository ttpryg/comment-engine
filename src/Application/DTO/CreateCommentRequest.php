<?php

declare(strict_types=1);

namespace Ttpryg\CommentEngine\Application\DTO;

final class CreateCommentRequest
{
    public function __construct(
        public readonly int|string|null $tenantId,
        public readonly string $commentableType,
        public readonly int|string $commentableId,
        public readonly int|string|null $authorId,
        public readonly string $body,
        public readonly int|string|null $parentId = null
    ) {}
}
