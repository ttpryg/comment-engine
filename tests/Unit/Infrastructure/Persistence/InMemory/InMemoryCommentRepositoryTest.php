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
        $this->assertSame($comment, $repository->findById(tenantId: null, id: 1));
        $this->assertSame($second, $repository->findById(tenantId: null, id: '2'));
        $this->assertNull($repository->findById(tenantId: null, id: 999));
    }

    public function test_public_listing_scopes_to_the_exact_target_and_defaults_to_approved(): void
    {
        $repository = new InMemoryCommentRepository;

        $approved = $repository->save(new Comment('content', 10, 7, 'Approved', status: 'approved'));
        $repository->save(new Comment('content', 10, 8, 'Pending'));
        $repository->save(new Comment('content', 11, 7, 'Other target', status: 'approved'));
        $repository->save(new Comment('product', 10, 7, 'Product', status: 'approved'));

        $public = $repository->findByTarget(tenantId: null, commentableType: 'content', commentableId: 10);

        $this->assertCount(1, $public);
        $this->assertSame($approved, $public[0]);
        $this->assertCount(2, $repository->findByTarget(
            tenantId: null,
            commentableType: 'content',
            commentableId: '10',
            status: null
        ));
        $this->assertCount(1, $repository->findByTarget(
            tenantId: null,
            commentableType: 'product',
            commentableId: 10
        ));
        $this->assertCount(0, $repository->findByTarget(
            tenantId: null,
            commentableType: 'content',
            commentableId: 99
        ));
    }

    public function test_filters_by_author_parent_status_and_counts(): void
    {
        $repository = new InMemoryCommentRepository;

        $parent = $repository->save(new Comment('content', 10, 7, 'Parent', status: 'approved'));
        $reply = $repository->save(new Comment('content', 10, 8, 'Reply', status: 'approved', parentId: $this->idOf($parent)));
        $repository->save(new Comment('content', 10, 7, 'Other', status: 'pending'));

        $this->assertCount(1, $repository->findByAuthorId(tenantId: null, authorId: 8));
        $this->assertCount(2, $repository->findByAuthorId(tenantId: null, authorId: 7));
        $this->assertCount(1, $repository->findByAuthorId(tenantId: null, authorId: 7, status: 'approved'));
        $this->assertSame(3, $repository->count(tenantId: null, criteria: [
            'commentable_type' => 'content',
            'commentable_id' => 10,
        ]));
        $this->assertCount(2, $repository->findAll(tenantId: null, criteria: ['parent_id' => null]));
        $this->assertSame($reply, $repository->findAll(
            tenantId: null,
            criteria: ['parent_id' => $this->idOf($parent)]
        )[0]);
        $this->assertCount(1, $repository->findAll(tenantId: null, limit: 0));
    }

    public function test_filters_guest_comments_by_null_author(): void
    {
        $repository = new InMemoryCommentRepository;

        $repository->save(new Comment('content', 10, null, 'Guest'));
        $repository->save(new Comment('content', 10, 7, 'Member'));

        $guests = $repository->findAll(tenantId: null, criteria: ['author_id' => null]);

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

        $this->assertSame([$first, $second], $repository->findByTarget(
            tenantId: null,
            commentableType: 'content',
            commentableId: 10
        ));

        $this->expectException(InvalidOrderByException::class);
        $repository->findAll(tenantId: null, orderBy: ['body; DROP TABLE comments; --' => 'ASC']);
    }

    public function test_soft_deletes_restores_and_cascades_hard_deletes(): void
    {
        $repository = new InMemoryCommentRepository;

        $parent = $repository->save(new Comment('content', 10, 7, 'Parent'));
        $reply = $repository->save(new Comment('content', 10, 8, 'Reply', parentId: $this->idOf($parent)));
        $parentId = $this->idOf($parent);
        $replyId = $this->idOf($reply);

        $this->assertTrue($repository->delete(tenantId: null, id: $parentId));
        $this->assertNull($repository->findById(tenantId: null, id: $parentId));
        $this->assertSame($parent, $repository->findById(tenantId: null, id: $parentId, includeTrashed: true));
        $this->assertSame($reply, $repository->findById(tenantId: null, id: $replyId));
        $this->assertCount(1, $repository->findByTarget(
            tenantId: null,
            commentableType: 'content',
            commentableId: 10,
            status: null
        ));
        $this->assertCount(2, $repository->findByTarget(
            tenantId: null,
            commentableType: 'content',
            commentableId: 10,
            status: null,
            includeTrashed: true
        ));

        $this->assertTrue($repository->restore(tenantId: null, id: $parentId));
        $this->assertSame($parent, $repository->findById(tenantId: null, id: $parentId));

        $this->assertTrue($repository->delete(tenantId: null, id: $parentId, softDelete: false));
        $this->assertNull($repository->findById(tenantId: null, id: $parentId, includeTrashed: true));
        $this->assertNull($repository->findById(tenantId: null, id: $replyId, includeTrashed: true));

        $this->assertFalse($repository->delete(tenantId: null, id: 999));
        $this->assertFalse($repository->restore(tenantId: null, id: 999));
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
        $this->assertSame('Updated', $repository->findById(tenantId: null, id: $this->idOf($comment))?->getBody());
    }

    public function test_tenant_round_trips_and_normalizes_strings(): void
    {
        $repository = new InMemoryCommentRepository;

        $comment = $repository->save(new Comment('content', 10, 7, 'Hello', tenantId: ' 5 '));

        $this->assertEquals('5', $comment->getTenantId());
        $this->assertSame($comment, $repository->findById(tenantId: 5, id: $this->idOf($comment)));
        $this->assertSame($comment, $repository->findById(tenantId: '5', id: $this->idOf($comment)));
    }

    public function test_scopes_reads_by_tenant(): void
    {
        $repository = new InMemoryCommentRepository;

        $tenantA = $repository->save(new Comment('content', 10, 7, 'A', status: 'approved', tenantId: 'a'));
        $tenantB = $repository->save(new Comment('content', 10, 8, 'B', status: 'approved', tenantId: 'b'));
        $unscoped = $repository->save(new Comment('content', 10, 9, 'Legacy', status: 'approved'));

        $this->assertSame($tenantA, $repository->findById(tenantId: 'a', id: $this->idOf($tenantA)));
        $this->assertNull($repository->findById(tenantId: 'a', id: $this->idOf($tenantB)));
        $this->assertNull($repository->findById(tenantId: 'a', id: $this->idOf($unscoped)));

        $this->assertCount(1, $repository->findByTarget(
            tenantId: 'a',
            commentableType: 'content',
            commentableId: 10
        ));
        $this->assertCount(3, $repository->findByTarget(
            tenantId: null,
            commentableType: 'content',
            commentableId: 10
        ));
        $this->assertCount(1, $repository->findByAuthorId(tenantId: 'b', authorId: 8));
        $this->assertCount(0, $repository->findByAuthorId(tenantId: 'a', authorId: 8));
        $this->assertCount(1, $repository->findAll(tenantId: 'a'));
        $this->assertCount(3, $repository->findAll(tenantId: null));
        $this->assertSame(1, $repository->count(
            tenantId: 'a',
            criteria: ['commentable_type' => 'content', 'commentable_id' => 10]
        ));
    }

    public function test_mutations_are_scoped_to_the_tenant(): void
    {
        $repository = new InMemoryCommentRepository;

        $comment = $repository->save(new Comment('content', 10, 7, 'Original', tenantId: 'a'));
        $commentId = $this->idOf($comment);

        $foreign = new Comment('content', 10, 7, 'Hijacked', id: $commentId, tenantId: 'b');
        $this->assertFalse($repository->update($foreign));
        $this->assertSame('Original', $repository->findById(tenantId: 'a', id: $commentId)?->getBody());

        $this->assertFalse($repository->delete(tenantId: 'b', id: $commentId));
        $this->assertNotNull($repository->findById(tenantId: 'a', id: $commentId));

        $this->assertTrue($repository->delete(tenantId: 'a', id: $commentId));
        $this->assertFalse($repository->restore(tenantId: 'b', id: $commentId));
        $this->assertTrue($repository->restore(tenantId: 'a', id: $commentId));
        $this->assertNotNull($repository->findById(tenantId: 'a', id: $commentId));
    }

    public function test_unscoped_mutations_can_touch_tenant_rows(): void
    {
        $repository = new InMemoryCommentRepository;

        $comment = $repository->save(new Comment('content', 10, 7, 'Original', tenantId: 'a'));
        $commentId = $this->idOf($comment);

        $unscoped = new Comment('content', 10, 7, 'Edited unscoped', id: $commentId);

        $this->assertTrue($repository->update($unscoped));
        $this->assertSame('Edited unscoped', $repository->findById(tenantId: 'a', id: $commentId)?->getBody());
    }

    public function test_rejects_a_tenant_criterion(): void
    {
        $repository = new InMemoryCommentRepository;

        $this->expectException(\InvalidArgumentException::class);

        $repository->findAll(tenantId: null, criteria: ['tenant_id' => 1]);
    }

    public function test_rejects_an_empty_tenant_argument(): void
    {
        $repository = new InMemoryCommentRepository;

        $this->expectException(\Ttpryg\CommentEngine\Domain\Exception\InvalidCommentTenantException::class);

        $repository->findById(tenantId: '  ', id: 1);
    }

    public function test_rejects_an_overlong_tenant_argument(): void
    {
        $repository = new InMemoryCommentRepository;

        $this->expectException(\Ttpryg\CommentEngine\Domain\Exception\InvalidCommentTenantException::class);

        $repository->findById(tenantId: str_repeat('a', 101), id: 1);
    }

    private function idOf(Comment $comment): int|string
    {
        return $comment->getId() ?? throw new \RuntimeException('Expected a persisted comment.');
    }
}
