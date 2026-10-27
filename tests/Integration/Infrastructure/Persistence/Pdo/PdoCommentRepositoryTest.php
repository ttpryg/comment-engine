<?php

declare(strict_types=1);

namespace Ttpryg\CommentEngine\Tests\Integration\Infrastructure\Persistence\Pdo;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Ttpryg\CommentEngine\Domain\Entity\Comment;
use Ttpryg\CommentEngine\Domain\Exception\InvalidOrderByException;
use Ttpryg\CommentEngine\Infrastructure\Database\DatabaseMigrator;
use Ttpryg\CommentEngine\Infrastructure\Persistence\Pdo\PdoCommentRepository;

class PdoCommentRepositoryTest extends TestCase
{
    private PDO $pdo;

    private PdoCommentRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('PRAGMA foreign_keys = ON');

        (new DatabaseMigrator($this->pdo))->migrate();

        $this->repository = new PdoCommentRepository($this->pdo);
    }

    public function test_saves_and_finds_a_comment(): void
    {
        $comment = $this->repository->save(new Comment('content', 'article-1', 7, 'Hello'));

        $this->assertNotNull($comment->getId());

        $found = $this->repository->findById($this->idOf($comment));

        $this->assertNotNull($found);
        $this->assertSame('Hello', $found->getBody());
        $this->assertSame('content', $found->getCommentableType());
        $this->assertSame('article-1', $found->getCommentableId());
        $this->assertSame('pending', $found->getStatus());
        $this->assertSame(7, $found->getAuthorId());
    }

    public function test_public_listing_scopes_to_the_exact_target_and_defaults_to_approved(): void
    {
        $this->repository->save(new Comment('content', 10, 7, 'Approved', status: 'approved'));
        $this->repository->save(new Comment('content', 10, 8, 'Pending'));
        $this->repository->save(new Comment('content', 11, 7, 'Other target', status: 'approved'));
        $this->repository->save(new Comment('product', 10, 7, 'Product', status: 'approved'));

        $public = $this->repository->findByTarget('content', 10);

        $this->assertCount(1, $public);
        $this->assertSame('Approved', $public[0]->getBody());

        $allStatuses = $this->repository->findByTarget('content', '10', null);
        $this->assertCount(2, $allStatuses);
        $this->assertSame(['Approved', 'Pending'], array_map(
            static fn (Comment $comment): string => $comment->getBody(),
            $allStatuses
        ));

        $this->assertCount(1, $this->repository->findByTarget('product', 10));
        $this->assertCount(0, $this->repository->findByTarget('content', 99));
    }

    public function test_filters_by_author_parent_and_counts(): void
    {
        $parent = $this->repository->save(new Comment('content', 10, 7, 'Parent', status: 'approved'));
        $this->repository->save(new Comment(
            'content',
            10,
            8,
            'Reply',
            status: 'approved',
            parentId: $this->idOf($parent)
        ));
        $this->repository->save(new Comment('content', 10, 7, 'Other', status: 'pending'));

        $this->assertCount(1, $this->repository->findByAuthorId(8));
        $this->assertCount(2, $this->repository->findByAuthorId(7));
        $this->assertCount(1, $this->repository->findByAuthorId(7, 'approved'));
        $this->assertSame(3, $this->repository->count(['commentable_type' => 'content', 'commentable_id' => 10]));
        $this->assertCount(2, $this->repository->findAll(['parent_id' => null]));
        $this->assertCount(1, $this->repository->findAll(['parent_id' => $this->idOf($parent)]));
    }

    public function test_filters_guest_comments_by_null_author(): void
    {
        $this->repository->save(new Comment('content', 10, null, 'Guest'));
        $this->repository->save(new Comment('content', 10, 7, 'Member'));

        $guests = $this->repository->findAll(['author_id' => null]);

        $this->assertCount(1, $guests);
        $this->assertSame('Guest', $guests[0]->getBody());
        $this->assertNull($guests[0]->getAuthorId());
    }

    public function test_updates_the_body_and_status(): void
    {
        $comment = $this->repository->save(new Comment('content', 10, 7, 'Original'));

        $comment->setBody('Edited');
        $comment->approve();

        $this->assertTrue($this->repository->update($comment));

        $found = $this->repository->findById($this->idOf($comment));

        $this->assertNotNull($found);
        $this->assertSame('Edited', $found->getBody());
        $this->assertSame('approved', $found->getStatus());
    }

    public function test_soft_deletes_and_restores_a_comment(): void
    {
        $comment = $this->repository->save(new Comment('content', 10, 7, 'Bye', status: 'approved'));
        $commentId = $this->idOf($comment);

        $this->assertTrue($this->repository->delete($commentId));

        $this->assertNull($this->repository->findById($commentId));
        $this->assertNotNull($this->repository->findById($commentId, includeTrashed: true));
        $this->assertCount(0, $this->repository->findByTarget('content', 10));
        $this->assertCount(1, $this->repository->findByTarget('content', 10, includeTrashed: true));

        $this->assertTrue($this->repository->restore($commentId));

        $this->assertNotNull($this->repository->findById($commentId));
        $this->assertCount(1, $this->repository->findByTarget('content', 10));
    }

    public function test_mutations_report_failure_for_a_missing_comment(): void
    {
        $this->assertFalse($this->repository->update(new Comment('content', 10, 7, 'Missing', id: 999)));
        $this->assertFalse($this->repository->delete(999));
        $this->assertFalse($this->repository->delete(999, softDelete: false));
        $this->assertFalse($this->repository->restore(999));
    }

    public function test_rejects_an_unsafe_table_name(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PdoCommentRepository($this->pdo, 'comments; DROP TABLE comments');
    }

    public function test_rejects_a_non_whitelisted_order_by_column(): void
    {
        $this->expectException(InvalidOrderByException::class);

        $this->repository->findAll(orderBy: ['body; DROP TABLE comments; --' => 'ASC']);
    }

    public function test_clamps_the_pagination_limit(): void
    {
        $this->repository->save(new Comment('content', 10, 7, 'One'));
        $this->repository->save(new Comment('content', 10, 7, 'Two'));

        $this->assertCount(1, $this->repository->findAll(limit: 0));
    }

    private function idOf(Comment $comment): int|string
    {
        return $comment->getId() ?? throw new \RuntimeException('Expected a persisted comment.');
    }
}
