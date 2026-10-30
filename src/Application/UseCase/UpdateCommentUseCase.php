<?php

declare(strict_types=1);

namespace Ttpryg\CommentEngine\Application\UseCase;

use DateTimeImmutable;
use Psr\EventDispatcher\EventDispatcherInterface;
use Ttpryg\CommentEngine\Domain\Entity\Comment;
use Ttpryg\CommentEngine\Domain\Event\CommentUpdatedEvent;
use Ttpryg\CommentEngine\Domain\Exception\CommentAccessDeniedException;
use Ttpryg\CommentEngine\Domain\Exception\CommentNotFoundException;
use Ttpryg\CommentEngine\Domain\Repository\CommentRepositoryInterface;

final class UpdateCommentUseCase
{
    public function __construct(
        private readonly CommentRepositoryInterface $commentRepository,
        private readonly ?EventDispatcherInterface $eventDispatcher = null
    ) {}

    public function execute(int|string|null $tenantId, int|string $id, string $body, int|string $actorId): Comment
    {
        $comment = $this->commentRepository->findById($tenantId, $id) ?? throw CommentNotFoundException::byId($id);

        if ($comment->getAuthorId() === null || (string) $comment->getAuthorId() !== (string) $actorId) {
            throw CommentAccessDeniedException::update($id);
        }

        $comment->setBody($body);
        $comment->setUpdatedAt(new DateTimeImmutable);

        if (! $this->commentRepository->update($comment)) {
            throw CommentNotFoundException::byId($id);
        }

        $this->eventDispatcher?->dispatch(new CommentUpdatedEvent($comment));

        return $comment;
    }
}
