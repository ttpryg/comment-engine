<?php

declare(strict_types=1);

namespace Ttpryg\CommentEngine\Tests\Unit\Infrastructure\Persistence\InMemory;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Ttpryg\CommentEngine\Domain\Entity\Comment;
use Ttpryg\CommentEngine\Domain\Exception\InvalidOrderByException;
use Ttpryg\CommentEngine\Infrastructure\Persistence\InMemory\InMemoryCommentRepository;

class InMemoryCommentRepositoryTest extends TestCase
{
    public function test_saves_and_finds_comments(): void
    {
        $repository = new InMemoryCommentRepository;

        $comment = $repository->save(new Comment('content', 10, 7, 'Hello'));
        $second = $repository->save(new Comment('content', 10, 8, 'World'));

        $this->assertSame(1, $comment->getId());
        $this->assertSame(2, $second->getId());
        $this->assertSame($comment, $repository->findById(1));
        $this->assertSame($second, $repository->findById('2'));
        $this->assertNull($repository->findById(999));
    }

    public function test_public_listing_scopes_to_the_exact_target_and_defaults_to_approved(): void
    {
        $repository = new InMemoryCommentRepository;

        $approved = $repository->save(new Comment('content', 10, 7, 'Approved', status: 'approved'));
        $repository->save(new Comment('content', 10, 8, 'Pending'));
        $repository->save(new Comment('content', 11, 7, 'Other target', status: 'approved'));
        $repository->save(new Comment('product', 10, 7, 'Product', status: 'approved'));

        $public = $repository->findByTarget('content', 10);

        $this->assertCount(1, $public);
        $this->assertSame($approved, $public[0]);
        $this->assertCount(2, $repository->findByTarget('content', '10', null));
        $this->assertCount(1, $repository->findByTarget('product', 10));
        $this->assertCount(0, $repository->findByTarget('content', 99));
    }

    public function test_filters_by_author_parent_status_and_counts(): void
    {
        $repository = new InMemoryCommentRepository;

        $parent = $repository->save(new Comment('content', 10, 7, 'Parent', status: 'approved'));
        $reply = $repository->save(new Comment('content', 10, 8, 'Reply', status: 'approved', parentId: $this->idOf($parent)));
        $repository->save(new Comment('content', 10, 7, 'Other', status: 'pending'));

        $this->assertCount(1, $repository->findByAuthorId(8));
        $this->assertCount(2, $repository->findByAuthorId(7));
        $this->assertCount(1, $repository->findByAuthorId(7, 'approved'));
        $this->assertSame(3, $repository->count(['commentable_type' => 'content', 'commentable_id' => 10]));
        $this->assertCount(2, $repository->findAll(['parent_id' => null]));
        $this->assertSame($reply, $repository->findAll(['parent_id' => $this->idOf($parent)])[0]);
        $this->assertCount(1, $repository->findAll([], limit: 0));
    }

    public function test_filters_guest_comments_by_null_author(): void
    {
        $repository = new InMemoryCommentRepository;

        $repository->save(new Comment('content', 10, null, 'Guest'));
        $repository->save(new Comment('content', 10, 7, 'Member'));

        $guests = $repository->findAll(['author_id' => null]);

        $this->assertCount(1, $guests);
        $this->assertSame('Guest', $guests[0]->getBody());
    }

    public function test_orders_comments_and_rejects_invalid_order_columns(): void
    {
        $repository = new InMemoryCommentRepository;

        $first = $repository->save(new Comment(
            'content',
            10,
            7,
            'First',
            status: 'approved',
            createdAt: new DateTimeImmutable('2026-01-01 10:00:00')
        ));
        $second = $repository->save(new Comment(
            'content',
            10,
            7,
            'Second',
            status: 'approved',
            createdAt: new DateTimeImmutable('2026-01-02 10:00:00')
        ));

        $this->assertSame([$first, $second], $repository->findByTarget('content', 10));

        $this->expectException(InvalidOrderByException::class);
        $repository->findAll(orderBy: ['body; DROP TABLE comments; --' => 'ASC']);
    }

    public function test_soft_deletes_restores_and_cascades_hard_deletes(): void
    {
        $repository = new InMemoryCommentRepository;

        $parent = $repository->save(new Comment('content', 10, 7, 'Parent'));
        $reply = $repository->save(new Comment('content', 10, 8, 'Reply', parentId: $this->idOf($parent)));
        $parentId = $this->idOf($parent);
        $replyId = $this->idOf($reply);

        $this->assertTrue($repository->delete($parentId));
        $this->assertNull($repository->findById($parentId));
        $this->assertSame($parent, $repository->findById($parentId, includeTrashed: true));
        $this->assertSame($reply, $repository->findById($replyId));
        $this->assertCount(1, $repository->findByTarget('content', 10, status: null));
        $this->assertCount(2, $repository->findByTarget('content', 10, status: null, includeTrashed: true));

        $this->assertTrue($repository->restore($parentId));
        $this->assertSame($parent, $repository->findById($parentId));

        $this->assertTrue($repository->delete($parentId, softDelete: false));
        $this->assertNull($repository->findById($parentId, includeTrashed: true));
        $this->assertNull($repository->findById($replyId, includeTrashed: true));

        $this->assertFalse($repository->delete(999));
        $this->assertFalse($repository->restore(999));
    }

    public function test_save_always_assigns_a_new_identifier(): void
    {
        $repository = new InMemoryCommentRepository;

        $comment = new Comment('content', 10, 7, 'Hello', id: 50);

        $this->assertSame($comment, $repository->save($comment));
        $this->assertSame(1, $comment->getId());
    }

    public function test_update_requires_an_existing_comment(): void
    {
        $repository = new InMemoryCommentRepository;

        $this->assertFalse($repository->update(new Comment('content', 10, 7, 'Missing', id: 404)));

        $comment = $repository->save(new Comment('content', 10, 7, 'Original'));
        $comment->setBody('Updated');

        $this->assertTrue($repository->update($comment));
        $this->assertSame('Updated', $repository->findById($this->idOf($comment))?->getBody());
    }

    private function idOf(Comment $comment): int|string
    {
        return $comment->getId() ?? throw new \RuntimeException('Expected a persisted comment.');
    }
}
