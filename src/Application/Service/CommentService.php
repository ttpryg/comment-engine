<?php

declare(strict_types=1);

namespace Ttpryg\CommentEngine\Application\Service;

use Psr\EventDispatcher\EventDispatcherInterface;
use Ttpryg\CommentEngine\Application\DTO\CreateCommentRequest;
use Ttpryg\CommentEngine\Application\UseCase\CreateCommentUseCase;
use Ttpryg\CommentEngine\Application\UseCase\DeleteCommentUseCase;
use Ttpryg\CommentEngine\Application\UseCase\ModerateCommentUseCase;
use Ttpryg\CommentEngine\Application\UseCase\UpdateCommentUseCase;
use Ttpryg\CommentEngine\Domain\Entity\Comment;
use Ttpryg\CommentEngine\Domain\Enum\CommentStatus;
use Ttpryg\CommentEngine\Domain\Exception\CommentNotFoundException;
use Ttpryg\CommentEngine\Domain\Repository\CommentRepositoryInterface;

/**
 * Convenience facade over the comment use cases.
 *
 * Methods that take several int|string identifiers (tenant, comment, actor)
 * should be called with named arguments to avoid silently swapping them.
 */
class CommentService
{
    private readonly CreateCommentUseCase $createCommentUseCase;

    private readonly UpdateCommentUseCase $updateCommentUseCase;

    private readonly DeleteCommentUseCase $deleteCommentUseCase;

    private readonly ModerateCommentUseCase $moderateCommentUseCase;

    public function __construct(
        private readonly CommentRepositoryInterface $commentRepository,
        ?EventDispatcherInterface $eventDispatcher = null
    ) {
        $this->createCommentUseCase = new CreateCommentUseCase($commentRepository, $eventDispatcher);
        $this->updateCommentUseCase = new UpdateCommentUseCase($commentRepository, $eventDispatcher);
        $this->deleteCommentUseCase = new DeleteCommentUseCase($commentRepository, $eventDispatcher);
        $this->moderateCommentUseCase = new ModerateCommentUseCase($commentRepository, $eventDispatcher);
    }

    public function create(CreateCommentRequest $request): Comment
    {
        return $this->createCommentUseCase->execute($request);
    }

    public function update(
        int|string|null $tenantId,
        int|string $id,
        string $body,
        int|string $actorId
    ): Comment {
        return $this->updateCommentUseCase->execute($tenantId, $id, $body, $actorId);
    }

    public function delete(
        int|string|null $tenantId,
        int|string $id,
        int|string $actorId,
        bool $asModerator = false,
        bool $softDelete = true
    ): bool {
        return $this->deleteCommentUseCase->execute($tenantId, $id, $actorId, $asModerator, $softDelete);
    }

    public function moderate(
        int|string|null $tenantId,
        int|string $id,
        CommentStatus|string $status,
        int|string $moderatorId
    ): Comment {
        return $this->moderateCommentUseCase->execute($tenantId, $id, $status, $moderatorId);
    }

    /** @return list<Comment> */
    public function getComments(
        int|string|null $tenantId,
        string $commentableType,
        int|string $commentableId,
        CommentStatus|string|null $status = CommentStatus::APPROVED,
        int $limit = 20,
        int $offset = 0
    ): array {
        return $this->commentRepository->findByTarget(
            $tenantId,
            $commentableType,
            $commentableId,
            $status,
            $limit,
            $offset
        );
    }

    public function getComment(
        int|string|null $tenantId,
        int|string $id,
        bool $includeTrashed = false
    ): Comment {
        return $this->commentRepository->findById($tenantId, $id, $includeTrashed)
            ?? throw CommentNotFoundException::byId($id);
    }

    public function countComments(
        int|string|null $tenantId,
        string $commentableType,
        int|string $commentableId,
        CommentStatus|string|null $status = CommentStatus::APPROVED
    ): int {
        return $this->commentRepository->count($tenantId, [
            'commentable_type' => $commentableType,
            'commentable_id' => $commentableId,
            'status' => $status,
        ]);
    }
}
