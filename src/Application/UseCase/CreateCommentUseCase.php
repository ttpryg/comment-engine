<?php

declare(strict_types=1);

namespace Ttpryg\CommentEngine\Application\UseCase;

use Psr\EventDispatcher\EventDispatcherInterface;
use Ttpryg\CommentEngine\Application\DTO\CreateCommentRequest;
use Ttpryg\CommentEngine\Domain\Entity\Comment;
use Ttpryg\CommentEngine\Domain\Event\CommentCreatedEvent;
use Ttpryg\CommentEngine\Domain\Exception\CommentNotFoundException;
use Ttpryg\CommentEngine\Domain\Exception\InvalidCommentParentException;
use Ttpryg\CommentEngine\Domain\Repository\CommentRepositoryInterface;

final class CreateCommentUseCase
{
    public function __construct(
        private readonly CommentRepositoryInterface $commentRepository,
        private readonly ?EventDispatcherInterface $eventDispatcher = null
    ) {}

    public function execute(CreateCommentRequest $request): Comment
    {
        $comment = new Comment(
            commentableType: $request->commentableType,
            commentableId: $request->commentableId,
            authorId: $request->authorId,
            body: $request->body,
            parentId: $request->parentId,
            tenantId: $request->tenantId
        );

        $parentId = $comment->getParentId();

        if ($parentId !== null) {
            $parent = $this->commentRepository->findById($request->tenantId, $parentId);

            if (! $parent instanceof Comment) {
                throw CommentNotFoundException::byId($parentId);
            }

            if ($parent->getCommentableType() !== $comment->getCommentableType()
                || (string) $parent->getCommentableId() !== (string) $comment->getCommentableId()) {
                throw InvalidCommentParentException::differentTarget();
            }
        }

        $savedComment = $this->commentRepository->save($comment);

        $this->eventDispatcher?->dispatch(new CommentCreatedEvent($savedComment));

        return $savedComment;
    }
}
