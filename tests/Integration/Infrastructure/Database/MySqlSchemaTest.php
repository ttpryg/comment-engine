<?php

declare(strict_types=1);

namespace Ttpryg\CommentEngine\Tests\Integration\Infrastructure\Database;

use PDO;
use PHPUnit\Framework\TestCase;
use Ttpryg\CommentEngine\Domain\Entity\Comment;
use Ttpryg\CommentEngine\Infrastructure\Database\DatabaseMigrator;
use Ttpryg\CommentEngine\Infrastructure\Persistence\Pdo\PdoCommentRepository;

class MySqlSchemaTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $dsn = getenv('COMMENT_ENGINE_MYSQL_DSN');
        if (! is_string($dsn) || $dsn === '') {
            $this->markTestSkipped('COMMENT_ENGINE_MYSQL_DSN is not configured.');
        }

        if (! extension_loaded('pdo_mysql')) {
            $this->markTestSkipped('pdo_mysql is not available.');
        }

        $this->pdo = new PDO(
            $dsn,
            getenv('COMMENT_ENGINE_MYSQL_USER') ?: 'root',
            getenv('COMMENT_ENGINE_MYSQL_PASSWORD') ?: ''
        );
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->resetSchema();
        (new DatabaseMigrator($this->pdo))->migrate();
    }

    public function test_mysql_schema_supports_the_comment_lifecycle_and_cascades_replies(): void
    {
        $repository = new PdoCommentRepository($this->pdo);

        $parent = $repository->save(new Comment('content', 10, 7, 'Parent', status: 'approved'));
        $reply = $repository->save(new Comment(
            'content',
            10,
            8,
            'Reply',
            status: 'approved',
            parentId: $this->idOf($parent)
        ));

        $this->assertCount(2, $repository->findByTarget('content', 10));
        $this->assertTrue($this->indexExists('idx_comments_target_status_created'));

        $this->assertTrue($repository->delete($this->idOf($parent), softDelete: false));
        $this->assertNull($repository->findById($this->idOf($reply), includeTrashed: true));
    }

    private function resetSchema(): void
    {
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach (['comments', 'comment_engine_migrations'] as $table) {
            $this->pdo->exec("DROP TABLE IF EXISTS {$table}");
        }
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    private function indexExists(string $index): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = :table AND index_name = :index'
        );
        $stmt->execute(['table' => 'comments', 'index' => $index]);

        return (int) $stmt->fetchColumn() > 0;
    }

    private function idOf(Comment $comment): int|string
    {
        return $comment->getId() ?? throw new \RuntimeException('Expected a persisted comment.');
    }
}
