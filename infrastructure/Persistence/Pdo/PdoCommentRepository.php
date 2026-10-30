<?php

declare(strict_types=1);

namespace Ttpryg\CommentEngine\Infrastructure\Persistence\Pdo;

use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use PDOStatement;
use RuntimeException;
use Ttpryg\CommentEngine\Domain\Entity\Comment;
use Ttpryg\CommentEngine\Domain\Enum\CommentStatus;
use Ttpryg\CommentEngine\Domain\Exception\InvalidCommentTenantException;
use Ttpryg\CommentEngine\Domain\Exception\InvalidOrderByException;
use Ttpryg\CommentEngine\Domain\Repository\CommentRepositoryInterface;

class PdoCommentRepository implements CommentRepositoryInterface
{
    /**
     * Columns allowed in ORDER BY (whitelist to prevent SQL injection).
     *
     * @var array<int, string>
     */
    private const ALLOWED_ORDER_BY_COLUMNS = ['id', 'created_at', 'updated_at'];

    /**
     * Upper pagination bound to keep heavy queries from becoming a DoS vector.
     */
    private const MAX_LIMIT = 100;

    private readonly string $table;

    public function __construct(private readonly PDO $pdo, string $table = 'comments')
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table) !== 1) {
            throw new InvalidArgumentException("Invalid table name '{$table}'.");
        }

        $this->table = $table;
    }

    public function findById(int|string|null $tenantId, int|string $id, bool $includeTrashed = false): ?Comment
    {
        $where = ['id = :id'];
        $params = ['id' => $id];

        $tenant = $this->tenantValue($tenantId);
        if ($tenant !== null) {
            $where[] = 'tenant_id = :tenant_id';
            $params['tenant_id'] = $tenant;
        }

        if (! $includeTrashed) {
            $where[] = 'deleted_at IS NULL';
        }

        $sql = "SELECT * FROM {$this->table} WHERE ".implode(' AND ', $where);

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $data = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($data) ? $this->mapToEntity($data) : null;
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

        $where = [
            'commentable_type = :commentable_type',
            'commentable_id = :commentable_id',
        ];
        $params = [
            'commentable_type' => trim($commentableType),
            'commentable_id' => (string) $commentableId,
        ];

        $tenant = $this->tenantValue($tenantId);
        if ($tenant !== null) {
            $where[] = 'tenant_id = :tenant_id';
            $params['tenant_id'] = $tenant;
        }

        $statusValue = $this->statusValue($status);
        if ($statusValue !== null) {
            $where[] = 'status = :status';
            $params['status'] = $statusValue;
        }

        if (! $includeTrashed) {
            $where[] = 'deleted_at IS NULL';
        }

        $sql = sprintf(
            'SELECT * FROM %s WHERE %s ORDER BY created_at ASC, id ASC LIMIT :limit OFFSET :offset',
            $this->table,
            implode(' AND ', $where)
        );

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $this->fetchAll($stmt);
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

        $statusValue = $this->statusValue($status);
        if ($statusValue !== null) {
            $criteria['status'] = $statusValue;
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

        [$whereSql, $params] = $this->buildWhere($criteria, $tenantId);
        $orderSql = $this->buildOrderBy($orderBy);

        $sql = sprintf(
            'SELECT * FROM %s WHERE %s ORDER BY %s LIMIT :limit OFFSET :offset',
            $this->table,
            $whereSql,
            $orderSql
        );

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $this->fetchAll($stmt);
    }

    /** @param array<string, mixed> $criteria */
    public function count(int|string|null $tenantId, array $criteria = []): int
    {
        [$whereSql, $params] = $this->buildWhere($criteria, $tenantId);

        $sql = "SELECT COUNT(*) FROM {$this->table} WHERE {$whereSql}";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    public function save(Comment $comment): Comment
    {
        $sql = "INSERT INTO {$this->table}
                (commentable_type, commentable_id, parent_id, author_id, tenant_id, body, status, created_at, updated_at, deleted_at)
                VALUES (:commentable_type, :commentable_id, :parent_id, :author_id, :tenant_id, :body, :status, :created_at, :updated_at, :deleted_at)";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'commentable_type' => $comment->getCommentableType(),
            'commentable_id' => (string) $comment->getCommentableId(),
            'parent_id' => $comment->getParentId(),
            'author_id' => $comment->getAuthorId(),
            'tenant_id' => $this->tenantValue($comment->getTenantId()),
            'body' => $comment->getBody(),
            'status' => $comment->getStatus(),
            'created_at' => $comment->getCreatedAt()->format('Y-m-d H:i:s'),
            'updated_at' => $comment->getUpdatedAt()->format('Y-m-d H:i:s'),
            'deleted_at' => $comment->getDeletedAt()?->format('Y-m-d H:i:s'),
        ]);

        $id = $this->pdo->lastInsertId();
        if ($id === false) {
            throw new RuntimeException('Failed to retrieve the inserted comment ID.');
        }

        $comment->setId($id);

        return $comment;
    }

    public function update(Comment $comment): bool
    {
        $id = $comment->getId();
        if ($id === null) {
            return false;
        }

        $tenant = $this->tenantValue($comment->getTenantId());

        $sql = "UPDATE {$this->table}
                SET body = :body,
                    status = :status,
                    updated_at = :updated_at,
                    deleted_at = :deleted_at
                WHERE id = :id";

        $params = [
            'id' => $id,
            'body' => $comment->getBody(),
            'status' => $comment->getStatus(),
            'updated_at' => $comment->getUpdatedAt()->format('Y-m-d H:i:s'),
            'deleted_at' => $comment->getDeletedAt()?->format('Y-m-d H:i:s'),
        ];

        if ($tenant !== null) {
            $sql .= ' AND tenant_id = :tenant_id';
            $params['tenant_id'] = $tenant;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount() > 0 || $this->exists($tenant, $id);
    }

    public function delete(int|string|null $tenantId, int|string $id, bool $softDelete = true): bool
    {
        $tenant = $this->tenantValue($tenantId);

        $where = 'id = :id';
        $params = ['id' => $id];

        if ($tenant !== null) {
            $where .= ' AND tenant_id = :tenant_id';
            $params['tenant_id'] = $tenant;
        }

        if ($softDelete) {
            $sql = "UPDATE {$this->table} SET deleted_at = :deleted_at WHERE {$where}";
            $params['deleted_at'] = (new DateTimeImmutable)->format('Y-m-d H:i:s');
        } else {
            $sql = "DELETE FROM {$this->table} WHERE {$where}";
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount() > 0 || $this->exists($tenantId, $id);
    }

    public function restore(int|string|null $tenantId, int|string $id): bool
    {
        $tenant = $this->tenantValue($tenantId);

        $sql = "UPDATE {$this->table} SET deleted_at = NULL WHERE id = :id";
        $params = ['id' => $id];

        if ($tenant !== null) {
            $sql .= ' AND tenant_id = :tenant_id';
            $params['tenant_id'] = $tenant;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount() > 0 || $this->exists($tenantId, $id);
    }

    private function exists(int|string|null $tenantId, int|string $id): bool
    {
        $tenant = $this->tenantValue($tenantId);

        $sql = "SELECT 1 FROM {$this->table} WHERE id = :id";
        $params = ['id' => $id];

        if ($tenant !== null) {
            $sql .= ' AND tenant_id = :tenant_id';
            $params['tenant_id'] = $tenant;
        }

        $sql .= ' LIMIT 1';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchColumn() !== false;
    }

    private function statusValue(CommentStatus|string|null $status): ?string
    {
        return $status instanceof CommentStatus ? $status->value : $status;
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
     * @param  array<string, mixed>  $criteria
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function buildWhere(array $criteria, int|string|null $tenantId): array
    {
        if (array_key_exists('tenant_id', $criteria)) {
            throw new InvalidArgumentException('Use the $tenantId argument instead of a tenant_id criterion.');
        }

        $where = ['deleted_at IS NULL'];
        $params = [];

        $tenant = $this->tenantValue($tenantId);
        if ($tenant !== null) {
            $where[] = 'tenant_id = :tenant_id';
            $params['tenant_id'] = $tenant;
        }

        if (isset($criteria['commentable_type']) && is_string($criteria['commentable_type'])) {
            $where[] = 'commentable_type = :commentable_type';
            $params['commentable_type'] = trim($criteria['commentable_type']);
        }

        if (isset($criteria['commentable_id'])) {
            $where[] = 'commentable_id = :commentable_id';
            $params['commentable_id'] = (string) $criteria['commentable_id'];
        }

        if (isset($criteria['status'])) {
            $where[] = 'status = :status';
            $params['status'] = $criteria['status'] instanceof CommentStatus
                ? $criteria['status']->value
                : (string) $criteria['status'];
        }

        if (array_key_exists('author_id', $criteria)) {
            if ($criteria['author_id'] === null) {
                $where[] = 'author_id IS NULL';
            } else {
                $where[] = 'author_id = :author_id';
                $params['author_id'] = $criteria['author_id'];
            }
        }

        if (array_key_exists('parent_id', $criteria)) {
            if ($criteria['parent_id'] === null) {
                $where[] = 'parent_id IS NULL';
            } else {
                $where[] = 'parent_id = :parent_id';
                $params['parent_id'] = $criteria['parent_id'];
            }
        }

        return [implode(' AND ', $where), $params];
    }

    /**
     * @param  array<string, 'ASC'|'DESC'>  $orderBy
     */
    private function buildOrderBy(array $orderBy): string
    {
        if ($orderBy === []) {
            $orderBy = ['created_at' => 'DESC'];
        }

        $orderSqls = [];

        foreach ($orderBy as $field => $direction) {
            if (! in_array($field, self::ALLOWED_ORDER_BY_COLUMNS, true)) {
                throw InvalidOrderByException::column($field, self::ALLOWED_ORDER_BY_COLUMNS);
            }

            $orderSqls[] = $field.' '.(strtoupper($direction) === 'ASC' ? 'ASC' : 'DESC');
        }

        return implode(', ', $orderSqls);
    }

    /** @return list<Comment> */
    private function fetchAll(PDOStatement $stmt): array
    {
        $results = [];

        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $results[] = $this->mapToEntity($row);
        }

        return $results;
    }

    /** @param array<string, mixed> $data */
    private function mapToEntity(array $data): Comment
    {
        return new Comment(
            commentableType: (string) ($data['commentable_type'] ?? ''),
            commentableId: $this->idOrString($data['commentable_id'] ?? ''),
            authorId: $this->idOrNull($data['author_id'] ?? null),
            body: (string) ($data['body'] ?? ''),
            status: (string) ($data['status'] ?? 'pending'),
            parentId: $this->idOrNull($data['parent_id'] ?? null),
            id: $this->idOrNull($data['id'] ?? null),
            createdAt: $this->dateTimeOrNull($data['created_at'] ?? null),
            updatedAt: $this->dateTimeOrNull($data['updated_at'] ?? null),
            deletedAt: $this->dateTimeOrNull($data['deleted_at'] ?? null),
            tenantId: $this->idOrNull($data['tenant_id'] ?? null)
        );
    }

    private function idOrNull(mixed $value): int|string|null
    {
        return is_int($value) || is_string($value) ? $value : null;
    }

    private function idOrString(mixed $value): int|string
    {
        return is_int($value) || is_string($value) ? $value : '';
    }

    private function dateTimeOrNull(mixed $value): ?DateTimeImmutable
    {
        return is_string($value) && $value !== '' ? new DateTimeImmutable($value) : null;
    }
}
