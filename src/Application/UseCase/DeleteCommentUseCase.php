<?php

declare(strict_types=1);

namespace Ttpryg\CommentEngine\Application\UseCase;

use Psr\EventDispatcher\EventDispatcherInterface;
use Ttpryg\CommentEngine\Domain\Event\CommentDeletedEvent;
use Ttpryg\CommentEngine\Domain\Exception\CommentAccessDeniedException;
use Ttpryg\CommentEngine\Domain\Exception\CommentNotFoundException;
use Ttpryg\CommentEngine\Domain\Repository\CommentRepositoryInterface;

final class DeleteCommentUseCase
{
    public function __construct(
        private readonly CommentRepositoryInterface $commentRepository,
        private readonly ?EventDispatcherInterface $eventDispatcher = null
    ) {}

    /**
     * @param  bool  $asModerator  Must only be true when the caller has already
     *                             authorized the actor as a moderator.
     */
    public function execute(
        int|string $id,
        int|string $actorId,
        bool $asModerator = false,
        bool $softDelete = true
    ): bool {
        $comment = $this->commentRepository->findById($id) ?? throw CommentNotFoundException::byId($id);

        if (! $asModerator
            && ($comment->getAuthorId() === null || (string) $comment->getAuthorId() !== (string) $actorId)) {
            throw CommentAccessDeniedException::delete($id);
        }

        $result = $this->commentRepository->delete($id, $softDelete);

        if ($result) {
            $this->eventDispatcher?->dispatch(new CommentDeletedEvent($id, $softDelete));
        }

        return $result;
    }
}
