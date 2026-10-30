<?php

declare(strict_types=1);

namespace Ttpryg\CommentEngine\Domain\Repository;

use Ttpryg\CommentEngine\Domain\Entity\Comment;
use Ttpryg\CommentEngine\Domain\Enum\CommentStatus;

/**
 * A null tenant disables tenant filtering (unscoped/legacy mode) and can see
 * comments from every tenant.
 */
interface CommentRepositoryInterface
{
    public function findById(int|string|null $tenantId, int|string $id, bool $includeTrashed = false): ?Comment;

    /**
     * Public listing for one commentable resource, approved comments by default.
     *
     * @return list<Comment>
     */
    public function findByTarget(
        int|string|null $tenantId,
        string $commentableType,
        int|string $commentableId,
        CommentStatus|string|null $status = CommentStatus::APPROVED,
        int $limit = 20,
        int $offset = 0,
        bool $includeTrashed = false
    ): array;

    /** @return list<Comment> */
    public function findByAuthorId(
        int|string|null $tenantId,
        int|string $authorId,
        CommentStatus|string|null $status = null,
        int $limit = 20,
        int $offset = 0
    ): array;

    /**
     * Excludes soft-deleted comments.
     *
     * @param  array<string, mixed>  $criteria
     * @param  array<string, 'ASC'|'DESC'>  $orderBy
     * @return list<Comment>
     */
    public function findAll(
        int|string|null $tenantId,
        array $criteria = [],
        int $limit = 20,
        int $offset = 0,
        array $orderBy = ['created_at' => 'DESC']
    ): array;

    /**
     * Excludes soft-deleted comments.
     *
     * @param  array<string, mixed>  $criteria
     */
    public function count(int|string|null $tenantId, array $criteria = []): int;

    /**
     * Inserts a new comment and assigns its identifier.
     */
    public function save(Comment $comment): Comment;

    /**
     * Persists changes to an existing comment; false when the comment no longer exists.
     */
    public function update(Comment $comment): bool;

    /**
     * Returns false when the comment does not exist within the tenant scope.
     */
    public function delete(int|string|null $tenantId, int|string $id, bool $softDelete = true): bool;

    /**
     * Returns false when the comment does not exist within the tenant scope.
     */
    public function restore(int|string|null $tenantId, int|string $id): bool;
}
