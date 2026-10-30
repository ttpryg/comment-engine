<?php

declare(strict_types=1);

namespace Ttpryg\CommentEngine\Tests\Unit\Domain\Entity;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Ttpryg\CommentEngine\Domain\Entity\Comment;
use Ttpryg\CommentEngine\Domain\Enum\CommentStatus;
use Ttpryg\CommentEngine\Domain\Exception\InvalidCommentAuthorException;
use Ttpryg\CommentEngine\Domain\Exception\InvalidCommentBodyException;
use Ttpryg\CommentEngine\Domain\Exception\InvalidCommentParentException;
use Ttpryg\CommentEngine\Domain\Exception\InvalidCommentStatusException;
use Ttpryg\CommentEngine\Domain\Exception\InvalidCommentTargetException;
use Ttpryg\CommentEngine\Domain\Exception\InvalidCommentTenantException;

class CommentEntityTest extends TestCase
{
    public function test_creates_a_pending_comment_and_trims_the_body(): void
    {
        $comment = new Comment(' content ', '10', 7, '  Hello world  ');

        $this->assertNull($comment->getId());
        $this->assertSame('content', $comment->getCommentableType());
        $this->assertSame('10', $comment->getCommentableId());
        $this->assertSame(7, $comment->getAuthorId());
        $this->assertSame('Hello world', $comment->getBody());
        $this->assertSame('pending', $comment->getStatus());
        $this->assertNull($comment->getTenantId());
        $this->assertNull($comment->getDeletedAt());
        $this->assertInstanceOf(DateTimeImmutable::class, $comment->getCreatedAt());
        $this->assertInstanceOf(DateTimeImmutable::class, $comment->getUpdatedAt());
    }

    public function test_guest_comments_and_replies_are_supported(): void
    {
        $comment = new Comment('product', 55, null, 'Hello', parentId: 3);

        $this->assertNull($comment->getAuthorId());
        $this->assertSame(3, $comment->getParentId());
        $this->assertSame(55, $comment->getCommentableId());
    }

    public function test_parent_cannot_be_the_comment_itself(): void
    {
        $this->expectException(InvalidCommentParentException::class);

        new Comment('content', 1, 7, 'Hello', parentId: 10, id: '10');
    }

    public function test_empty_body_is_rejected(): void
    {
        $this->expectException(InvalidCommentBodyException::class);

        new Comment('content', 1, 7, "   \n ");
    }

    public function test_body_cannot_exceed_the_maximum_length(): void
    {
        $this->expectException(InvalidCommentBodyException::class);

        new Comment('content', 1, 7, str_repeat('a', Comment::MAX_BODY_LENGTH + 1));
    }

    public function test_invalid_commentable_type_is_rejected(): void
    {
        $this->expectException(InvalidCommentTargetException::class);

        new Comment('bad type!', 1, 7, 'Hello');
    }

    public function test_empty_commentable_type_is_rejected(): void
    {
        $this->expectException(InvalidCommentTargetException::class);

        new Comment('   ', 1, 7, 'Hello');
    }

    public function test_empty_commentable_id_is_rejected(): void
    {
        $this->expectException(InvalidCommentTargetException::class);

        new Comment('content', '  ', 7, 'Hello');
    }

    public function test_commentable_id_cannot_exceed_the_schema_length(): void
    {
        $this->expectException(InvalidCommentTargetException::class);

        new Comment('content', str_repeat('a', Comment::MAX_COMMENTABLE_ID_LENGTH + 1), 7, 'Hello');
    }

    public function test_empty_author_id_is_rejected_for_identified_authors(): void
    {
        $this->expectException(InvalidCommentAuthorException::class);

        new Comment('content', 1, '  ', 'Hello');
    }

    public function test_tenant_is_trimmed_and_preserves_numeric_ids(): void
    {
        $this->assertSame('org-1', (new Comment('content', 1, 7, 'Hello', tenantId: ' org-1 '))->getTenantId());
        $this->assertSame(5, (new Comment('content', 1, 7, 'Hello', tenantId: 5))->getTenantId());
    }

    public function test_empty_tenant_id_is_rejected(): void
    {
        $this->expectException(InvalidCommentTenantException::class);

        new Comment('content', 1, 7, 'Hello', tenantId: '  ');
    }

    public function test_tenant_id_cannot_exceed_the_schema_length(): void
    {
        $this->expectException(InvalidCommentTenantException::class);

        new Comment('content', 1, 7, 'Hello', tenantId: str_repeat('a', Comment::MAX_TENANT_ID_LENGTH + 1));
    }

    public function test_invalid_status_is_rejected(): void
    {
        $this->expectException(InvalidCommentStatusException::class);

        new Comment('content', 1, 7, 'Hello', status: 'unknown');
    }

    public function test_accepts_the_status_enum(): void
    {
        $comment = new Comment('content', 1, 7, 'Hello', status: CommentStatus::APPROVED);

        $this->assertSame('approved', $comment->getStatus());
    }

    public function test_moderation_transitions_change_the_status(): void
    {
        $comment = new Comment('content', 1, 7, 'Hello');

        $this->assertSame('approved', $comment->approve()->getStatus());
        $this->assertSame('rejected', $comment->reject()->getStatus());
        $this->assertSame('spam', $comment->markAsSpam()->getStatus());
        $this->assertSame('pending', $comment->setStatus('pending')->getStatus());
    }

    public function test_to_array_exposes_the_comment_fields(): void
    {
        $comment = new Comment('content', 10, 7, 'Hello', parentId: 2, id: 5, tenantId: 'org-1');
        $comment->approve();

        $data = $comment->toArray();

        $this->assertSame(5, $data['id']);
        $this->assertSame('content', $data['commentable_type']);
        $this->assertSame(10, $data['commentable_id']);
        $this->assertSame(2, $data['parent_id']);
        $this->assertSame(7, $data['author_id']);
        $this->assertSame('org-1', $data['tenant_id']);
        $this->assertSame('Hello', $data['body']);
        $this->assertSame('approved', $data['status']);
        $this->assertNull($data['deleted_at']);
    }
}
