# CommentEngine Library

`ttpryg/comment-engine` is a framework-agnostic, PSR-14 native PHP library for threaded comments with moderation, polymorphic commentable resources, optional author attribution, soft deletes, and a database schema managed by [`ttpryg/database-migrator`](https://github.com/ttpryg/database-migrator).

## 🌟 Key Features

- **Framework Agnostic**: Works in any PHP 8.1+ project (Vanilla PHP, Slim, Laravel, Symfony, CodeIgniter).
- **PSR-14 Events**: `CommentCreatedEvent`, `CommentUpdatedEvent`, `CommentDeletedEvent`, `CommentApprovedEvent`, `CommentRejectedEvent`, and `CommentMarkedAsSpamEvent` are dispatched through `Psr\EventDispatcher\EventDispatcherInterface`, so any compliant dispatcher can be used.
- **Threaded Comments**: Optional `parentId` for replies; replies must reference an existing parent on the same commentable resource.
- **Moderation Lifecycle**: Comments start as `pending` and can be `approved`, `rejected`, or marked as `spam`.
- **Polymorphic Targets**: `commentable_type` + `commentable_id` attach comments to content, products, or any other resource without schema changes.
- **Author Ownership**: `author_id` links comments to users from `auth-user`; guest comments use `null`.
- **Explicit Use Cases**: create, update, delete, and moderate actions live in `Application\UseCase`, with `CommentService` as a convenience facade.
- **Security Hardening**: order-by whitelist, clamped pagination (1–100 rows), validated table names, and parameterized PDO queries throughout.
- **Persistence Adapters**: PDO for production (MySQL/MariaDB/SQLite) and an in-memory repository for tests and prototypes.

---

## 📦 Installation

```bash
composer require ttpryg/comment-engine
```

> **Requirements:** PHP 8.1 or higher with the `mbstring` extension. The bundled PDO adapter also needs `pdo` plus the driver for your database.
>
> Install `ttpryg/event-dispatcher` if you need a PSR-14 dispatcher implementation.

---

## 🚀 Quick Start

```php
use Psr\EventDispatcher\EventDispatcherInterface;
use Ttpryg\CommentEngine\Application\DTO\CreateCommentRequest;
use Ttpryg\CommentEngine\Application\Service\CommentService;
use Ttpryg\CommentEngine\Domain\Event\CommentCreatedEvent;
use Ttpryg\CommentEngine\Infrastructure\Persistence\Pdo\PdoCommentRepository;
use Ttpryg\EventDispatcher\Dispatcher\EventDispatcher;
use Ttpryg\EventDispatcher\Provider\ListenerProvider;

// 1. Wire a PSR-14 dispatcher (ttpryg/event-dispatcher shown here)
$provider = new ListenerProvider;
$provider->addListener(CommentCreatedEvent::class, function (CommentCreatedEvent $event): void {
    // notify the moderator queue, write an audit entry, etc.
});
$dispatcher = new EventDispatcher($provider);

// 2. Build the service with any CommentRepositoryInterface implementation
$comments = new CommentService(new PdoCommentRepository($pdo), $dispatcher);

// 3. Create a top-level comment (starts as "pending")
$comment = $comments->create(new CreateCommentRequest(
    commentableType: 'content',
    commentableId: 42,
    authorId: 7,
    body: 'Great article!'
));

// 4. Reply to it (parent must exist on the same target)
$comments->create(new CreateCommentRequest(
    commentableType: 'content',
    commentableId: 42,
    authorId: 8,
    body: 'Agreed!',
    parentId: $comment->getId()
));

// 5. Moderate and read the public, approved listing
$comments->moderate($comment->getId(), 'approved', moderatorId: 1);
$approved = $comments->getComments('content', 42);
```

The event dispatcher is optional. Pass `null` (or omit it) when the module is used without events.

---

## 🧱 Public API

### `CommentService`

| Method | Behaviour |
| --- | --- |
| `create(CreateCommentRequest): Comment` | Creates a `pending` comment or reply and dispatches `CommentCreatedEvent`. |
| `update(int\|string $id, string $body, int\|string $actorId): Comment` | The author edits the body; dispatches `CommentUpdatedEvent`. |
| `delete(int\|string $id, int\|string $actorId, bool $asModerator = false, bool $softDelete = true): bool` | Author (or authorized moderator) deletes; dispatches `CommentDeletedEvent`. |
| `moderate(int\|string $id, CommentStatus\|string $status, int\|string $moderatorId): Comment` | Applies `approved`, `rejected`, or `spam`; dispatches the matching moderation event. |
| `getComments(string $type, int\|string $id, CommentStatus\|string\|null $status = CommentStatus::APPROVED, int $limit = 20, int $offset = 0): array` | Chronological listing for one target; approved by default. Pass `null` for all statuses. |
| `getComment(int\|string $id, bool $includeTrashed = false): Comment` | Fetches one comment or throws `CommentNotFoundException`. |
| `countComments(string $type, int\|string $id, CommentStatus\|string\|null $status = CommentStatus::APPROVED): int` | Counts comments for one target. |

### Repository port

`Ttpryg\CommentEngine\Domain\Repository\CommentRepositoryInterface` is free of SQL and framework types. Bundled adapters:

- `Infrastructure\Persistence\Pdo\PdoCommentRepository` (MySQL/MariaDB/SQLite).
- `Infrastructure\Persistence\InMemory\InMemoryCommentRepository` (tests/prototypes).

`save()` inserts a new comment and assigns its identifier. `update()`, `delete()`, and `restore()` return `false` when the comment no longer exists. `findAll()` and `count()` exclude soft-deleted comments; use `findById(..., includeTrashed: true)` or `findByTarget(..., includeTrashed: true)` to inspect trashed rows.

### Statuses

`pending` → `approved` | `rejected` | `spam`. Moderation cannot apply `pending`; a moderator can change a decision later.

### Authorization

`update()` and `delete()` enforce author ownership in the application layer. `delete(..., asModerator: true)` skips the ownership check, so only set it after your own authorization policy has verified that the actor is a moderator. The same applies to `moderate()`: the caller is responsible for authorizing the moderator before invoking it.

---

## 🗄️ Database Schema

The migration creates the `comments` table plus indexes for target listings, author feeds, and moderation queues:

```sql
CREATE TABLE comments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    commentable_type VARCHAR(50) NOT NULL,
    commentable_id VARCHAR(100) NOT NULL,
    parent_id BIGINT UNSIGNED NULL,
    author_id BIGINT UNSIGNED NULL,
    body TEXT NOT NULL,
    status ENUM('pending', 'approved', 'rejected', 'spam') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL DEFAULT NULL,
    CONSTRAINT fk_comments_parent FOREIGN KEY (parent_id) REFERENCES comments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Indexes: `(commentable_type, commentable_id, status, created_at)`, `(author_id, created_at)`, and `(status, created_at)`.

The self-referencing foreign key cascades **hard** deletes to replies. Soft deletes affect only the selected comment, so approved replies remain visible in public listings; use a hard delete to remove the whole reply subtree. The migration is driver-aware and works on both MySQL/MariaDB and SQLite. SQLite enforces foreign keys only when the connection enables them, so run `PRAGMA foreign_keys = ON` on SQLite connections if you rely on the cascade.

---

## 🔄 Database Migrations

Migrations live in `database/migrations/` and run through `ttpryg/database-migrator`. Applied migrations are tracked in the `comment_engine_migrations` table; each migration is recorded only after it succeeds.

```php
use Ttpryg\CommentEngine\Infrastructure\Database\DatabaseMigrator;

$migrator = new DatabaseMigrator($pdo);
$migrator->migrate();              // create/upgrade the schema
$migrator->rollback();             // revert the latest migration
$migrator->appliedMigrations();    // applied history
```

Migrations are never run automatically by the module. Run them from your deployment process.

> **Warning:** `rollback()` reverts the latest migration, and the initial migration's `down()` drops the `comments` table with all comment data. Back up the database before rolling back in production.

---

## 🛠️ Development

```bash
composer test      # PHPUnit (unit + integration on in-memory SQLite)
composer analyse   # PHPStan level 8
composer fix       # Rector + Pint (auto-fix)
composer fix:check # Rector dry-run + Pint test + PHPStan (CI gate)
```

MySQL integration tests are skipped unless `COMMENT_ENGINE_MYSQL_DSN` is set (optionally `COMMENT_ENGINE_MYSQL_USER` and `COMMENT_ENGINE_MYSQL_PASSWORD`).

### Docker

A PHP 8.1 CLI image and a MySQL 8 service are provided so the full suite — including MySQL integration — runs without a local PHP installation. The container runs as UID/GID `1000` by default; pass your own IDs if they differ:

```bash
docker compose build
UID=$(id -u) GID=$(id -g) docker compose build   # only if your IDs are not 1000

docker compose run --rm app composer install
docker compose run --rm app composer test
docker compose run --rm app composer fix:check
```

The `app` service waits for MySQL to become healthy before starting, so `composer test` exercises `MySqlSchemaTest` automatically. MySQL uses the `comment_engine_test` database and is exposed only on `127.0.0.1:3307` to avoid clashing with a local server; override the port with `MYSQL_PORT`. The MySQL integration test refuses to drop tables unless the database name ends with `_test`.

Run an interactive shell with `docker compose run --rm app bash`.

CI runs the same checks on PHP 8.1, 8.2, and 8.3 via GitHub Actions (`.github/workflows/tests.yml`), plus a dedicated MySQL 8 integration job. Dependabot keeps Composer and GitHub Actions dependencies up to date.

---

## 📄 License

MIT License.
