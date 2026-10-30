<?php

declare(strict_types=1);

namespace Ttpryg\CommentEngine\Domain\Entity;

use DateTimeImmutable;
use DateTimeInterface;
use Ttpryg\CommentEngine\Domain\Enum\CommentStatus;
use Ttpryg\CommentEngine\Domain\Exception\InvalidCommentAuthorException;
use Ttpryg\CommentEngine\Domain\Exception\InvalidCommentBodyException;
use Ttpryg\CommentEngine\Domain\Exception\InvalidCommentParentException;
use Ttpryg\CommentEngine\Domain\Exception\InvalidCommentStatusException;
use Ttpryg\CommentEngine\Domain\Exception\InvalidCommentTargetException;
use Ttpryg\CommentEngine\Domain\Exception\InvalidCommentTenantException;

class Comment
{
    public const MAX_BODY_LENGTH = 10000;

    public const MAX_COMMENTABLE_ID_LENGTH = 100;

    public const MAX_TENANT_ID_LENGTH = 100;

    private readonly string $commentableType;

    private readonly int|string $commentableId;

    private readonly int|string|null $authorId;

    private readonly int|string|null $tenantId;

    private readonly int|string|null $parentId;

    private string $body;

    private CommentStatus $status;

    private readonly DateTimeInterface $createdAt;

    private DateTimeInterface $updatedAt;

    public function __construct(
        string $commentableType,
        int|string $commentableId,
        int|string|null $authorId,
        string $body,
        CommentStatus|string $status = CommentStatus::PENDING,
        int|string|null $parentId = null,
        private int|string|null $id = null,
        ?DateTimeInterface $createdAt = null,
        ?DateTimeInterface $updatedAt = null,
        private ?DateTimeInterface $deletedAt = null,
        int|string|null $tenantId = null
    ) {
        $this->commentableType = $this->normalizeCommentableType($commentableType);
        $this->commentableId = $this->normalizeCommentableId($commentableId);
        $this->authorId = $this->normalizeAuthorId($authorId);
        $this->tenantId = $this->normalizeTenantId($tenantId);
        $this->parentId = $this->normalizeParentId($parentId);

        if ($this->id !== null && $this->parentId !== null && (string) $this->id === (string) $this->parentId) {
            throw InvalidCommentParentException::selfReference();
        }

        $this->body = $this->normalizeBody($body);
        $this->status = $this->normalizeStatus($status);
        $this->createdAt = $createdAt ?? new DateTimeImmutable;
        $this->updatedAt = $updatedAt ?? new DateTimeImmutable;
    }

    public function getId(): int|string|null
    {
        return $this->id;
    }

    public function setId(int|string $id): self
    {
        $this->id = $id;

        return $this;
    }

    public function getCommentableType(): string
    {
        return $this->commentableType;
    }

    public function getCommentableId(): int|string
    {
        return $this->commentableId;
    }

    public function getParentId(): int|string|null
    {
        return $this->parentId;
    }

    public function getAuthorId(): int|string|null
    {
        return $this->authorId;
    }

    public function getTenantId(): int|string|null
    {
        return $this->tenantId;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function setBody(string $body): self
    {
        $this->body = $this->normalizeBody($body);

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status->value;
    }

    public function setStatus(CommentStatus|string $status): self
    {
        $this->status = $this->normalizeStatus($status);

        return $this;
    }

    public function approve(): self
    {
        return $this->setStatus(CommentStatus::APPROVED);
    }

    public function reject(): self
    {
        return $this->setStatus(CommentStatus::REJECTED);
    }

    public function markAsSpam(): self
    {
        return $this->setStatus(CommentStatus::SPAM);
    }

    public function getCreatedAt(): DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(DateTimeInterface $updatedAt): self
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    public function getDeletedAt(): ?DateTimeInterface
    {
        return $this->deletedAt;
    }

    public function setDeletedAt(?DateTimeInterface $deletedAt): self
    {
        $this->deletedAt = $deletedAt;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'commentable_type' => $this->commentableType,
            'commentable_id' => $this->commentableId,
            'parent_id' => $this->parentId,
            'author_id' => $this->authorId,
            'tenant_id' => $this->tenantId,
            'body' => $this->body,
            'status' => $this->status->value,
            'created_at' => $this->createdAt->format(DateTimeInterface::ATOM),
            'updated_at' => $this->updatedAt->format(DateTimeInterface::ATOM),
            'deleted_at' => $this->deletedAt?->format(DateTimeInterface::ATOM),
        ];
    }

    private function normalizeCommentableType(string $commentableType): string
    {
        $commentableType = trim($commentableType);

        if ($commentableType === '') {
            throw InvalidCommentTargetException::emptyType();
        }

        if (preg_match('/^[A-Za-z][A-Za-z0-9_.-]{0,49}\z/', $commentableType) !== 1) {
            throw InvalidCommentTargetException::invalidType();
        }

        return $commentableType;
    }

    private function normalizeCommentableId(int|string $commentableId): int|string
    {
        if (is_string($commentableId)) {
            $commentableId = trim($commentableId);

            if ($commentableId === '') {
                throw InvalidCommentTargetException::emptyId();
            }

            if (mb_strlen($commentableId) > self::MAX_COMMENTABLE_ID_LENGTH) {
                throw InvalidCommentTargetException::idTooLong(self::MAX_COMMENTABLE_ID_LENGTH);
            }
        }

        return $commentableId;
    }

    private function normalizeAuthorId(int|string|null $authorId): int|string|null
    {
        if (is_string($authorId)) {
            $authorId = trim($authorId);

            if ($authorId === '') {
                throw InvalidCommentAuthorException::empty();
            }
        }

        return $authorId;
    }

    private function normalizeTenantId(int|string|null $tenantId): int|string|null
    {
        if (is_string($tenantId)) {
            $tenantId = trim($tenantId);

            if ($tenantId === '') {
                throw InvalidCommentTenantException::empty();
            }

            if (mb_strlen($tenantId) > self::MAX_TENANT_ID_LENGTH) {
                throw InvalidCommentTenantException::tooLong(self::MAX_TENANT_ID_LENGTH);
            }
        }

        return $tenantId;
    }

    private function normalizeParentId(int|string|null $parentId): int|string|null
    {
        if (is_string($parentId)) {
            $parentId = trim($parentId);

            if ($parentId === '') {
                throw InvalidCommentParentException::emptyId();
            }
        }

        return $parentId;
    }

    private function normalizeBody(string $body): string
    {
        $body = trim($body);

        if ($body === '') {
            throw InvalidCommentBodyException::empty();
        }

        if (mb_strlen($body) > self::MAX_BODY_LENGTH) {
            throw InvalidCommentBodyException::tooLong(self::MAX_BODY_LENGTH);
        }

        return $body;
    }

    private function normalizeStatus(CommentStatus|string $status): CommentStatus
    {
        if ($status instanceof CommentStatus) {
            return $status;
        }

        return CommentStatus::tryFrom($status) ?? throw new InvalidCommentStatusException($status);
    }
}
