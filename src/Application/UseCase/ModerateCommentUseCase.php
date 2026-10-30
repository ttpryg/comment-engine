<?php

declare(strict_types=1);

namespace Ttpryg\CommentEngine\Application\UseCase;

use DateTimeImmutable;
use Psr\EventDispatcher\EventDispatcherInterface;
use Ttpryg\CommentEngine\Domain\Entity\Comment;
use Ttpryg\CommentEngine\Domain\Enum\CommentStatus;
use Ttpryg\CommentEngine\Domain\Event\CommentApprovedEvent;
use Ttpryg\CommentEngine\Domain\Event\CommentMarkedAsSpamEvent;
use Ttpryg\CommentEngine\Domain\Event\CommentRejectedEvent;
use Ttpryg\CommentEngine\Domain\Exception\CommentNotFoundException;
use Ttpryg\CommentEngine\Domain\Exception\InvalidCommentStatusException;
use Ttpryg\CommentEngine\Domain\Exception\InvalidModerationStatusException;
use Ttpryg\CommentEngine\Domain\Repository\CommentRepositoryInterface;

final class ModerateCommentUseCase
{
    public function __construct(
        private readonly CommentRepositoryInterface $commentRepository,
        private readonly ?EventDispatcherInterface $eventDispatcher = null
    ) {}

    public function execute(
        int|string|null $tenantId,
        int|string $id,
        CommentStatus|string $status,
        int|string $moderatorId
    ): Comment {
        $moderationStatus = $this->normalizeStatus($status);

        if (! in_array($moderationStatus, [CommentStatus::APPROVED, CommentStatus::REJECTED, CommentStatus::SPAM], true)) {
            throw InvalidModerationStatusException::forStatus($moderationStatus);
        }

        $comment = $this->commentRepository->findById($tenantId, $id) ?? throw CommentNotFoundException::byId($id);

        $comment->setStatus($moderationStatus);
        $comment->setUpdatedAt(new DateTimeImmutable);

        if (! $this->commentRepository->update($comment)) {
            throw CommentNotFoundException::byId($id);
        }

        $event = match ($moderationStatus) {
            CommentStatus::APPROVED => new CommentApprovedEvent($comment, $moderatorId),
            CommentStatus::REJECTED => new CommentRejectedEvent($comment, $moderatorId),
            CommentStatus::SPAM => new CommentMarkedAsSpamEvent($comment, $moderatorId),
        };

        $this->eventDispatcher?->dispatch($event);

        return $comment;
    }

    private function normalizeStatus(CommentStatus|string $status): CommentStatus
    {
        if ($status instanceof CommentStatus) {
            return $status;
        }

        return CommentStatus::tryFrom($status) ?? throw new InvalidCommentStatusException($status);
    }
}
