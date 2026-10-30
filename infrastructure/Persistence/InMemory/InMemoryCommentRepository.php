<?php

declare(strict_types=1);

namespace Ttpryg\CommentEngine\Infrastructure\Persistence\InMemory;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use Ttpryg\CommentEngine\Domain\Entity\Comment;
use Ttpryg\CommentEngine\Domain\Enum\CommentStatus;
use Ttpryg\CommentEngine\Domain\Exception\InvalidCommentTenantException;
use Ttpryg\CommentEngine\Domain\Exception\InvalidOrderByException;
use Ttpryg\CommentEngine\Domain\Repository\CommentRepositoryInterface;

class InMemoryCommentRepository implements CommentRepositoryInterface
{
    /** @var array<int|string, Comment> */
    private array $comments = [];

    private int $autoIncrement = 1;

    /**
     * @var array<int, string>
     */
    private const ALLOWED_ORDER_BY_COLUMNS = ['id', 'created_at', 'updated_at'];

    private const MAX_LIMIT = 100;

    public function findById(int|string|null $tenantId, int|string $id, bool $includeTrashed = false): ?Comment
    {
        $tenant = $this->tenantValue($tenantId);
        $comment = $this->comments[$id] ?? null;

        if (! $comment instanceof Comment) {
            return null;
        }

        if ($tenant !== null && ! $this->matchesTenant($comment, $tenant)) {
            return null;
        }

        if (! $includeTrashed && $comment->getDeletedAt() instanceof DateTimeInterface) {
            return null;
        }

        return $comment;
    }

    /** @return list<Comment> */
    public function findByTarget(
        int|string|null $tenantId,
        string $commentableType,
        int|string $commentableId,
        CommentStatus|string|null $status = CommentStatus::APPROVED,
        int $limit = 20,
        int $offset = 0,
        bool $includeTrashed = false
    ): array {
        $limit = min(max(1, $limit), self::MAX_LIMIT);
        $offset = max(0, $offset);

        $tenant = $this->tenantValue($tenantId);
        $commentableType = trim($commentableType);
        $commentableId = (string) $commentableId;
        $statusValue = $status instanceof CommentStatus ? $status->value : $status;

        $results = array_values(array_filter(
            $this->comments,
            function (Comment $comment) use ($tenant, $commentableType, $commentableId, $statusValue, $includeTrashed): bool {
                if ($tenant !== null && ! $this->matchesTenant($comment, $tenant)) {
                    return false;
                }

                if (! $includeTrashed && $comment->getDeletedAt() instanceof DateTimeInterface) {
                    return false;
                }

                if ($comment->getCommentableType() !== $commentableType
                    || (string) $comment->getCommentableId() !== $commentableId) {
                    return false;
                }

                return $statusValue === null || $comment->getStatus() === $statusValue;
            }
        ));

        $results = $this->sort($results, ['created_at' => 'ASC', 'id' => 'ASC']);

        return array_slice($results, $offset, $limit);
    }

    /** @return list<Comment> */
    public function findByAuthorId(
        int|string|null $tenantId,
        int|string $authorId,
        CommentStatus|string|null $status = null,
        int $limit = 20,
        int $offset = 0
    ): array {
        $criteria = ['author_id' => $authorId];

        if ($status !== null) {
            $criteria['status'] = $status;
        }

        return $this->findAll($tenantId, $criteria, $limit, $offset);
    }

    /**
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
    ): array {
        $limit = min(max(1, $limit), self::MAX_LIMIT);
        $offset = max(0, $offset);

        $this->assertCriteriaHasNoTenant($criteria);
        $tenant = $this->tenantValue($tenantId);

        $results = array_values(array_filter(
            $this->comments,
            fn (Comment $comment): bool => $this->matchesCriteria($comment, $criteria, $tenant)
        ));

        $results = $this->sort($results, $orderBy);

        return array_slice($results, $offset, $limit);
    }

    /** @param array<string, mixed> $criteria */
    public function count(int|string|null $tenantId, array $criteria = []): int
    {
        $this->assertCriteriaHasNoTenant($criteria);
        $tenant = $this->tenantValue($tenantId);

        return count(array_filter(
            $this->comments,
            fn (Comment $comment): bool => $this->matchesCriteria($comment, $criteria, $tenant)
        ));
    }

    public function save(Comment $comment): Comment
    {
        $id = $this->autoIncrement++;
        $comment->setId($id);
        $this->comments[$id] = $comment;

        return $comment;
    }

    public function update(Comment $comment): bool
    {
        $id = $comment->getId();

        if ($id === null || ! array_key_exists($id, $this->comments)) {
            return false;
        }

        $stored = $this->comments[$id];
        $tenant = $this->tenantValue($comment->getTenantId());
        if ($tenant !== null && ! $this->matchesTenant($stored, $tenant)) {
            return false;
        }

        // An unscoped update must not erase the tenant stored in the row.
        $this->comments[$id] = $tenant === null && $stored->getTenantId() !== null
            ? $this->withTenant($comment, $stored->getTenantId())
            : $comment;

        return true;
    }

    public function delete(int|string|null $tenantId, int|string $id, bool $softDelete = true): bool
    {
        $tenant = $this->tenantValue($tenantId);
        $comment = $this->comments[$id] ?? null;

        if (! $comment instanceof Comment) {
            return false;
        }

        if ($tenant !== null && ! $this->matchesTenant($comment, $tenant)) {
            return false;
        }

        if ($softDelete) {
            $comment->setDeletedAt(new DateTimeImmutable);

            return true;
        }

        unset($this->comments[$id]);
        $this->deleteDescendants($id);

        return true;
    }

    public function restore(int|string|null $tenantId, int|string $id): bool
    {
        $tenant = $this->tenantValue($tenantId);
        $comment = $this->comments[$id] ?? null;

        if (! $comment instanceof Comment) {
            return false;
        }

        if ($tenant !== null && ! $this->matchesTenant($comment, $tenant)) {
            return false;
        }

        $comment->setDeletedAt(null);

        return true;
    }

    /**
     * Hard deletes cascade by parent_id only, mirroring the database FK, so
     * descendants are removed regardless of their tenant.
     */
    private function deleteDescendants(int|string $parentId): void
    {
        foreach ($this->comments as $id => $comment) {
            if ($comment->getParentId() === null || (string) $comment->getParentId() !== (string) $parentId) {
                continue;
            }

            unset($this->comments[$id]);
            $this->deleteDescendants($id);
        }
    }

    /** @param array<string, mixed> $criteria */
    private function matchesCriteria(Comment $comment, array $criteria, ?string $tenant): bool
    {
        if ($comment->getDeletedAt() instanceof DateTimeInterface) {
            return false;
        }

        if ($tenant !== null && ! $this->matchesTenant($comment, $tenant)) {
            return false;
        }

        if (isset($criteria['commentable_type']) && is_string($criteria['commentable_type'])
            && $comment->getCommentableType() !== trim($criteria['commentable_type'])) {
            return false;
        }

        if (isset($criteria['commentable_id'])
            && (string) $comment->getCommentableId() !== (string) $criteria['commentable_id']) {
            return false;
        }

        if (isset($criteria['status'])) {
            $status = $criteria['status'] instanceof CommentStatus
                ? $criteria['status']->value
                : (string) $criteria['status'];

            if ($comment->getStatus() !== $status) {
                return false;
            }
        }

        if (array_key_exists('author_id', $criteria)) {
            if ($criteria['author_id'] === null) {
                if ($comment->getAuthorId() !== null) {
                    return false;
                }
            } elseif ((string) $comment->getAuthorId() !== (string) $criteria['author_id']) {
                return false;
            }
        }

        if (array_key_exists('parent_id', $criteria)) {
            if ($criteria['parent_id'] === null) {
                if ($comment->getParentId() !== null) {
                    return false;
                }
            } elseif ((string) $comment->getParentId() !== (string) $criteria['parent_id']) {
                return false;
            }
        }

        return true;
    }

    private function matchesTenant(Comment $comment, string $tenant): bool
    {
        return $comment->getTenantId() !== null && (string) $comment->getTenantId() === $tenant;
    }

    private function withTenant(Comment $comment, int|string $tenantId): Comment
    {
        return new Comment(
            commentableType: $comment->getCommentableType(),
            commentableId: $comment->getCommentableId(),
            authorId: $comment->getAuthorId(),
            body: $comment->getBody(),
            status: $comment->getStatus(),
            parentId: $comment->getParentId(),
            id: $comment->getId(),
            createdAt: $comment->getCreatedAt(),
            updatedAt: $comment->getUpdatedAt(),
            deletedAt: $comment->getDeletedAt(),
            tenantId: $tenantId
        );
    }

    /** @param array<string, mixed> $criteria */
    private function assertCriteriaHasNoTenant(array $criteria): void
    {
        if (array_key_exists('tenant_id', $criteria)) {
            throw new InvalidArgumentException('Use the $tenantId argument instead of a tenant_id criterion.');
        }
    }

    private function tenantValue(int|string|null $tenantId): ?string
    {
        if ($tenantId === null) {
            return null;
        }

        if (is_int($tenantId)) {
            return (string) $tenantId;
        }

        $tenantId = trim($tenantId);

        if ($tenantId === '') {
            throw InvalidCommentTenantException::empty();
        }

        if (mb_strlen($tenantId) > Comment::MAX_TENANT_ID_LENGTH) {
            throw InvalidCommentTenantException::tooLong(Comment::MAX_TENANT_ID_LENGTH);
        }

        return $tenantId;
    }

    /**
     * @param  array<int, Comment>  $comments
     * @param  array<string, string>  $orderBy
     * @return array<int, Comment>
     */
    private function sort(array $comments, array $orderBy): array
    {
        if ($orderBy === []) {
            $orderBy = ['created_at' => 'DESC'];
        }

        foreach ($orderBy as $field => $direction) {
            if (! in_array($field, self::ALLOWED_ORDER_BY_COLUMNS, true)) {
                throw InvalidOrderByException::column($field, self::ALLOWED_ORDER_BY_COLUMNS);
            }
        }

        usort($comments, function (Comment $a, Comment $b) use ($orderBy): int {
            foreach ($orderBy as $field => $direction) {
                $result = $this->compareValues($this->valueFor($a, $field), $this->valueFor($b, $field));

                if ($result !== 0) {
                    return strtoupper($direction) === 'ASC' ? $result : -$result;
                }
            }

            return 0;
        });

        return $comments;
    }

    private function valueFor(Comment $comment, string $field): int|string|null
    {
        return match ($field) {
            'id' => $this->idValue($comment->getId()),
            'created_at' => $comment->getCreatedAt()->getTimestamp(),
            'updated_at' => $comment->getUpdatedAt()->getTimestamp(),
            default => null,
        };
    }

    private function idValue(int|string|null $id): int|string
    {
        if (is_int($id)) {
            return $id;
        }

        if (is_string($id) && is_numeric($id)) {
            return (int) $id;
        }

        return (string) $id;
    }

    private function compareValues(int|string|null $a, int|string|null $b): int
    {
        if ($a === null && $b === null) {
            return 0;
        }

        if ($a === null) {
            return -1;
        }

        if ($b === null) {
            return 1;
        }

        if (is_int($a) && is_int($b)) {
            return $a <=> $b;
        }

        return (string) $a <=> (string) $b;
    }
}
