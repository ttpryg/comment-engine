<?php

declare(strict_types=1);

namespace Ttpryg\CommentEngine\Tests\Unit\Domain\Event;

use PHPUnit\Framework\TestCase;
use Ttpryg\CommentEngine\Domain\Entity\Comment;
use Ttpryg\CommentEngine\Domain\Event\CommentApprovedEvent;
use Ttpryg\CommentEngine\Domain\Event\CommentCreatedEvent;
use Ttpryg\CommentEngine\Domain\Event\CommentDeletedEvent;
use Ttpryg\CommentEngine\Domain\Event\CommentMarkedAsSpamEvent;
use Ttpryg\CommentEngine\Domain\Event\CommentRejectedEvent;
use Ttpryg\CommentEngine\Domain\Event\CommentUpdatedEvent;

class CommentEventsTest extends TestCase
{
    public function test_comment_events_expose_the_comment(): void
    {
        $comment = new Comment('content', 10, 7, 'Hello');

        $this->assertSame($comment, (new CommentCreatedEvent($comment))->comment);
        $this->assertSame($comment, (new CommentUpdatedEvent($comment))->comment);
    }

    public function test_deleted_event_exposes_the_id_and_delete_mode(): void
    {
        $event = new CommentDeletedEvent(commentId: 42, softDeleted: true);

        $this->assertSame(42, $event->commentId);
        $this->assertTrue($event->softDeleted);
    }

    public function test_moderation_events_expose_the_moderator(): void
    {
        $comment = new Comment('content', 10, 7, 'Hello');

        $approved = new CommentApprovedEvent($comment, 99);
        $rejected = new CommentRejectedEvent($comment, 100);
        $spam = new CommentMarkedAsSpamEvent($comment, 101);

        $this->assertSame($comment, $approved->comment);
        $this->assertSame(99, $approved->moderatorId);
        $this->assertSame(100, $rejected->moderatorId);
        $this->assertSame(101, $spam->moderatorId);
    }
}
