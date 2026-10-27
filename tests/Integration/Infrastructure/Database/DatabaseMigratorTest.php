<?php

declare(strict_types=1);

namespace Ttpryg\CommentEngine\Tests\Integration\Infrastructure\Database;

use PDO;
use PHPUnit\Framework\TestCase;
use Ttpryg\CommentEngine\Domain\Entity\Comment;
use Ttpryg\CommentEngine\Infrastructure\Database\DatabaseMigrator;
use Ttpryg\CommentEngine\Infrastructure\Persistence\Pdo\PdoCommentRepository;

class DatabaseMigratorTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
    }

    public function test_migrate_creates_schema_indexes_and_records_migrations(): void
    {
        $migrator = new DatabaseMigrator($this->pdo);

        $executed = $migrator->migrate();

        $this->assertSame(['202610270001_create_comments_table'], $executed);
        $this->assertSame($executed, $migrator->appliedMigrations());
        $this->assertTrue($this->tableExists('comments'));
        $this->assertTrue($this->tableExists('comment_engine_migrations'));
        $this->assertTrue($this->indexExists('idx_comments_target_status_created'));
        $this->assertTrue($this->indexExists('idx_comments_author_created'));
        $this->assertTrue($this->indexExists('idx_comments_status_created'));
    }

    public function test_migrate_is_idempotent(): void
    {
        $migrator = new DatabaseMigrator($this->pdo);
        $migrator->migrate();

        $this->assertSame([], $migrator->migrate());
        $this->assertCount(1, $migrator->appliedMigrations());
    }

    public function test_rollback_drops_the_comments_table(): void
    {
        $migrator = new DatabaseMigrator($this->pdo);
        $migrator->migrate();

        $this->assertSame(['202610270001_create_comments_table'], $migrator->rollback());
        $this->assertFalse($this->tableExists('comments'));
    }

    public function test_hard_deleting_a_parent_cascades_to_replies(): void
    {
        (new DatabaseMigrator($this->pdo))->migrate();
        $repository = new PdoCommentRepository($this->pdo);

        $parent = $repository->save(new Comment('content', 10, 7, 'Parent'));
        $reply = $repository->save(new Comment(
            'content',
            10,
            8,
            'Reply',
            parentId: $this->idOf($parent)
        ));
        $parentId = $this->idOf($parent);
        $replyId = $this->idOf($reply);

        $this->assertTrue($repository->delete($parentId, softDelete: false));
        $this->assertNull($repository->findById($parentId, includeTrashed: true));
        $this->assertNull($repository->findById($replyId, includeTrashed: true));
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = :table");
        $stmt->execute(['table' => $table]);

        return (int) $stmt->fetchColumn() > 0;
    }

    private function indexExists(string $index): bool
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'index' AND name = :index");
        $stmt->execute(['index' => $index]);

        return (int) $stmt->fetchColumn() > 0;
    }

    private function idOf(Comment $comment): int|string
    {
        return $comment->getId() ?? throw new \RuntimeException('Expected a persisted comment.');
    }
}
