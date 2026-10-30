<?php

declare(strict_types=1);

namespace Ttpryg\CommentEngine\Tests\Unit\Application\Service;

use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Ttpryg\CommentEngine\Application\DTO\CreateCommentRequest;
use Ttpryg\CommentEngine\Application\Service\CommentService;
use Ttpryg\CommentEngine\Domain\Entity\Comment;
use Ttpryg\CommentEngine\Domain\Enum\CommentStatus;
use Ttpryg\CommentEngine\Domain\Event\CommentApprovedEvent;
use Ttpryg\CommentEngine\Domain\Event\CommentCreatedEvent;
use Ttpryg\CommentEngine\Domain\Event\CommentDeletedEvent;
use Ttpryg\CommentEngine\Domain\Event\CommentMarkedAsSpamEvent;
use Ttpryg\CommentEngine\Domain\Event\CommentRejectedEvent;
use Ttpryg\CommentEngine\Domain\Event\CommentUpdatedEvent;
use Ttpryg\CommentEngine\Domain\Exception\CommentAccessDeniedException;
use Ttpryg\CommentEngine\Domain\Exception\CommentNotFoundException;
use Ttpryg\CommentEngine\Domain\Exception\InvalidCommentParentException;
use Ttpryg\CommentEngine\Domain\Exception\InvalidCommentStatusException;
use Ttpryg\CommentEngine\Domain\Exception\InvalidModerationStatusException;
use Ttpryg\CommentEngine\Infrastructure\Persistence\InMemory\InMemoryCommentRepository;

class CommentServiceTest extends TestCase
{
    public function test_creates_a_pending_comment_and_dispatches_the_created_event(): void
    {
        $repository = new InMemoryCommentRepository;
        $dispatcher = new RecordingEventDispatcher;
        $service = new CommentService($repository, $dispatcher);

        $comment = $service->create($this->request(
            commentableType: 'content',
            commentableId: 10,
            authorId: 7,
            body: '  Nice post  '
        ));

        $this->assertSame(1, $comment->getId());
        $this->assertSame('Nice post', $comment->getBody());
        $this->assertSame('pending', $comment->getStatus());
        $this->assertNotNull($repository->findById(tenantId: null, id: $this->idOf($comment)));

        $event = $this->eventOfType($dispatcher, CommentCreatedEvent::class);
        $this->assertSame($comment, $event->comment);
    }

    public function test_creates_replies_for_the_same_commentable_target(): void
    {
        $service = new CommentService(new InMemoryCommentRepository);

        $parent = $service->create($this->request('content', 10, 7, 'Parent'));
        $reply = $service->create($this->request(
            commentableType: 'content',
            commentableId: '10',
            authorId: 8,
            body: 'Reply',
            parentId: $this->idOf($parent)
        ));

        $this->assertSame(2, $reply->getId());
        $this->assertSame($this->idOf($parent), $reply->getParentId());
    }

    public function test_reply_requires_an_existing_parent(): void
    {
        $service = new CommentService(new InMemoryCommentRepository);

        $this->expectException(CommentNotFoundException::class);

        $service->create($this->request('content', 10, 7, 'Reply', 999));
    }

    public function test_reply_must_target_the_same_resource_as_its_parent(): void
    {
        $service = new CommentService(new InMemoryCommentRepository);

        $parent = $service->create($this->request('content', 10, 7, 'Parent'));

        $this->expectException(InvalidCommentParentException::class);

        $service->create($this->request('content', 11, 7, 'Reply', $this->idOf($parent)));
    }

    public function test_author_can_update_their_comment_and_an_event_is_dispatched(): void
    {
        $dispatcher = new RecordingEventDispatcher;
        $service = new CommentService(new InMemoryCommentRepository, $dispatcher);
        $comment = $service->create($this->request('content', 10, 7, 'Original'));

        $dispatcher->reset();

        $updated = $service->update(
            tenantId: null,
            id: $this->idOf($comment),
            body: '  Edited  ',
            actorId: 7
        );

        $this->assertSame('Edited', $updated->getBody());
        $this->assertSame('pending', $updated->getStatus());

        $event = $this->eventOfType($dispatcher, CommentUpdatedEvent::class);
        $this->assertSame($updated, $event->comment);
    }

    public function test_non_author_cannot_update_a_comment(): void
    {
        $service = new CommentService(new InMemoryCommentRepository);
        $comment = $service->create($this->request('content', 10, 7, 'Original'));

        $this->expectException(CommentAccessDeniedException::class);

        $service->update(tenantId: null, id: $this->idOf($comment), body: 'Hijacked', actorId: 8);
    }

    public function test_update_requires_an_existing_comment(): void
    {
        $service = new CommentService(new InMemoryCommentRepository);

        $this->expectException(CommentNotFoundException::class);

        $service->update(tenantId: null, id: 999, body: 'Missing', actorId: 7);
    }

    public function test_guest_comments_cannot_be_updated(): void
    {
        $service = new CommentService(new InMemoryCommentRepository);
        $comment = $service->create($this->request('content', 10, null, 'Guest comment'));

        $this->expectException(CommentAccessDeniedException::class);

        $service->update(tenantId: null, id: $this->idOf($comment), body: 'Hijacked', actorId: 8);
    }

    public function test_author_can_soft_delete_their_comment_and_an_event_is_dispatched(): void
    {
        $repository = new InMemoryCommentRepository;
        $dispatcher = new RecordingEventDispatcher;
        $service = new CommentService($repository, $dispatcher);
        $comment = $service->create($this->request('content', 10, 7, 'Bye'));
        $commentId = $this->idOf($comment);

        $dispatcher->reset();

        $this->assertTrue($service->delete(tenantId: null, id: $commentId, actorId: 7));
        $this->assertNull($repository->findById(tenantId: null, id: $commentId));

        $event = $this->eventOfType($dispatcher, CommentDeletedEvent::class);
        $this->assertSame($commentId, $event->commentId);
        $this->assertTrue($event->softDeleted);
        $this->assertNull($event->tenantId);
    }

    public function test_moderator_can_delete_another_authors_comment(): void
    {
        $repository = new InMemoryCommentRepository;
        $service = new CommentService($repository);
        $comment = $service->create($this->request('content', 10, 7, 'Spam'));

        $this->assertTrue($service->delete(
            tenantId: null,
            id: $this->idOf($comment),
            actorId: 8,
            asModerator: true
        ));
    }

    public function test_non_author_cannot_delete_without_moderator_authorization(): void
    {
        $service = new CommentService(new InMemoryCommentRepository);
        $comment = $service->create($this->request('content', 10, 7, 'Mine'));

        $this->expectException(CommentAccessDeniedException::class);

        $service->delete(tenantId: null, id: $this->idOf($comment), actorId: 8);
    }

    public function test_delete_requires_an_existing_comment(): void
    {
        $service = new CommentService(new InMemoryCommentRepository);

        $this->expectException(CommentNotFoundException::class);

        $service->delete(tenantId: null, id: 999, actorId: 7);
    }

    public function test_author_can_hard_delete_their_comment(): void
    {
        $repository = new InMemoryCommentRepository;
        $service = new CommentService($repository);
        $comment = $service->create($this->request('content', 10, 7, 'Gone'));
        $commentId = $this->idOf($comment);

        $this->assertTrue($service->delete(
            tenantId: null,
            id: $commentId,
            actorId: 7,
            softDelete: false
        ));
        $this->assertNull($repository->findById(tenantId: null, id: $commentId, includeTrashed: true));
    }

    public function test_moderation_applies_the_status_and_dispatches_the_matching_event(): void
    {
        $dispatcher = new RecordingEventDispatcher;
        $service = new CommentService(new InMemoryCommentRepository, $dispatcher);

        $approved = $service->create($this->request('content', 10, 7, 'Approve me'));
        $rejected = $service->create($this->request('content', 10, 7, 'Reject me'));
        $spam = $service->create($this->request('content', 10, 7, 'Spam me'));

        $dispatcher->reset();

        $service->moderate(tenantId: null, id: $this->idOf($approved), status: CommentStatus::APPROVED, moderatorId: 99);
        $service->moderate(tenantId: null, id: $this->idOf($rejected), status: 'rejected', moderatorId: 100);
        $service->moderate(tenantId: null, id: $this->idOf($spam), status: 'spam', moderatorId: 101);

        $this->assertSame('approved', $approved->getStatus());
        $this->assertSame('rejected', $rejected->getStatus());
        $this->assertSame('spam', $spam->getStatus());

        $this->assertSame(99, $this->eventOfType($dispatcher, CommentApprovedEvent::class, 0)->moderatorId);
        $this->assertSame(100, $this->eventOfType($dispatcher, CommentRejectedEvent::class, 1)->moderatorId);
        $this->assertSame(101, $this->eventOfType($dispatcher, CommentMarkedAsSpamEvent::class, 2)->moderatorId);
    }

    public function test_moderation_cannot_apply_the_pending_status(): void
    {
        $service = new CommentService(new InMemoryCommentRepository);
        $comment = $service->create($this->request('content', 10, 7, 'Hello'));

        $this->expectException(InvalidModerationStatusException::class);

        $service->moderate(tenantId: null, id: $this->idOf($comment), status: 'pending', moderatorId: 99);
    }

    public function test_moderation_rejects_an_unknown_status(): void
    {
        $service = new CommentService(new InMemoryCommentRepository);
        $comment = $service->create($this->request('content', 10, 7, 'Hello'));

        $this->expectException(InvalidCommentStatusException::class);

        $service->moderate(tenantId: null, id: $this->idOf($comment), status: 'unknown', moderatorId: 99);
    }

    public function test_moderation_requires_an_existing_comment(): void
    {
        $service = new CommentService(new InMemoryCommentRepository);

        $this->expectException(CommentNotFoundException::class);

        $service->moderate(tenantId: null, id: 999, status: 'approved', moderatorId: 99);
    }

    public function test_public_listing_and_counts_return_approved_comments_only(): void
    {
        $service = new CommentService(new InMemoryCommentRepository);

        $pending = $service->create($this->request('content', 10, 7, 'Pending'));
        $approved = $service->create($this->request('content', 10, 8, 'Approved'));
        $service->moderate(tenantId: null, id: $this->idOf($approved), status: 'approved', moderatorId: 99);

        $this->assertSame([$approved], $service->getComments(
            tenantId: null,
            commentableType: 'content',
            commentableId: 10
        ));
        $this->assertCount(2, $service->getComments(
            tenantId: null,
            commentableType: 'content',
            commentableId: 10,
            status: null
        ));
        $this->assertSame(1, $service->countComments(
            tenantId: null,
            commentableType: 'content',
            commentableId: 10
        ));
        $this->assertSame(2, $service->countComments(
            tenantId: null,
            commentableType: 'content',
            commentableId: 10,
            status: null
        ));
        $this->assertSame($pending, $service->getComment(tenantId: null, id: $this->idOf($pending)));

        $this->expectException(CommentNotFoundException::class);
        $service->getComment(tenantId: null, id: 999);
    }

    public function test_create_stores_the_tenant_and_scopes_reads(): void
    {
        $service = new CommentService(new InMemoryCommentRepository);

        $tenantA = $service->create($this->request('content', 10, 7, 'A', tenantId: 'a'));
        $tenantB = $service->create($this->request('content', 10, 8, 'B', tenantId: 'b'));
        $service->moderate(tenantId: 'a', id: $this->idOf($tenantA), status: 'approved', moderatorId: 99);
        $service->moderate(tenantId: 'b', id: $this->idOf($tenantB), status: 'approved', moderatorId: 99);

        $this->assertSame('a', $tenantA->getTenantId());
        $this->assertSame([$tenantA], $service->getComments(
            tenantId: 'a',
            commentableType: 'content',
            commentableId: 10
        ));
        $this->assertSame(1, $service->countComments(tenantId: 'b', commentableType: 'content', commentableId: 10));
        $this->assertSame(2, $service->countComments(tenantId: null, commentableType: 'content', commentableId: 10));
    }

    public function test_cross_tenant_operations_look_like_not_found(): void
    {
        $service = new CommentService(new InMemoryCommentRepository);
        $comment = $service->create($this->request('content', 10, 7, 'Mine', tenantId: 'a'));
        $commentId = $this->idOf($comment);

        $operations = [
            fn (): Comment => $service->getComment(tenantId: 'b', id: $commentId),
            fn (): Comment => $service->update(tenantId: 'b', id: $commentId, body: 'Hijacked', actorId: 7),
            fn (): Comment => $service->moderate(tenantId: 'b', id: $commentId, status: 'approved', moderatorId: 99),
            fn (): bool => $service->delete(tenantId: 'b', id: $commentId, actorId: 7, asModerator: true),
        ];

        foreach ($operations as $operation) {
            $this->assertNotFound($operation);
        }

        $this->assertSame('Mine', $service->getComment(tenantId: 'a', id: $commentId)->getBody());
    }

    public function test_reply_parent_must_belong_to_the_same_tenant(): void
    {
        $service = new CommentService(new InMemoryCommentRepository);
        $parent = $service->create($this->request('content', 10, 7, 'Parent', tenantId: 'a'));

        $this->expectException(CommentNotFoundException::class);

        $service->create($this->request(
            'content',
            10,
            8,
            'Reply',
            parentId: $this->idOf($parent),
            tenantId: 'b'
        ));
    }

    public function test_delete_event_carries_the_tenant(): void
    {
        $dispatcher = new RecordingEventDispatcher;
        $service = new CommentService(new InMemoryCommentRepository, $dispatcher);
        $comment = $service->create($this->request('content', 10, 7, 'Bye', tenantId: 'a'));

        $dispatcher->reset();

        $this->assertTrue($service->delete(tenantId: 'a', id: $this->idOf($comment), actorId: 7));

        $event = $this->eventOfType($dispatcher, CommentDeletedEvent::class);
        $this->assertSame('a', $event->tenantId);
    }

    private function assertNotFound(callable $operation): void
    {
        try {
            $operation();
        } catch (CommentNotFoundException) {
            return;
        }

        $this->fail('Expected CommentNotFoundException.');
    }

    private function request(
        string $commentableType,
        int|string $commentableId,
        int|string|null $authorId,
        string $body,
        int|string|null $parentId = null,
        int|string|null $tenantId = null
    ): CreateCommentRequest {
        return new CreateCommentRequest(
            tenantId: $tenantId,
            commentableType: $commentableType,
            commentableId: $commentableId,
            authorId: $authorId,
            body: $body,
            parentId: $parentId
        );
    }

    private function idOf(Comment $comment): int|string
    {
        return $comment->getId() ?? throw new \RuntimeException('Expected a persisted comment.');
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $class
     * @return T
     */
    private function eventOfType(RecordingEventDispatcher $dispatcher, string $class, int $index = 0): object
    {
        $event = $dispatcher->events[$index] ?? null;

        if (! $event instanceof $class) {
            throw new \RuntimeException(sprintf('Expected an event of type %s at index %d.', $class, $index));
        }

        return $event;
    }
}

final class RecordingEventDispatcher implements EventDispatcherInterface
{
    /** @var list<object> */
    public array $events = [];

    public function dispatch(object $event): object
    {
        $this->events[] = $event;

        return $event;
    }

    public function reset(): void
    {
        $this->events = [];
    }
}
