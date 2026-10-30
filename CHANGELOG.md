# Changelog

All notable changes to `ttpryg/comment-engine` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Optional tenant scoping. Comments carry a nullable `tenant_id`;
  `CommentService`, the use cases, and the repository port accept an explicit
  `tenantId` and only see/mutate rows for that tenant. A `null` tenant keeps the
  previous unscoped behaviour. Cross-tenant access by ID surfaces as
  `CommentNotFoundException`.
- `InvalidCommentTenantException` for empty or overlong tenant identifiers.
- Migration `202610300001_add_tenant_id_to_comments_table` adds the column and
  tenant-leading listing/author indexes.
- `CommentDeletedEvent` exposes an optional `tenantId`.

### Changed (BC)

- `CreateCommentRequest` now requires `tenantId` as its first argument.
- `CommentService::update()`, `delete()`, `moderate()`, `getComments()`,
  `getComment()`, and `countComments()` take `tenantId` as their first argument.
- `CommentRepositoryInterface` scopes `findById`, `findByTarget`,
  `findByAuthorId`, `findAll`, `count`, `delete`, and `restore` by `tenantId`.
  The `tenant_id` key is rejected inside `findAll()`/`count()` criteria.
- A major version bump is recommended because the public API and repository port
  changed.

## [1.0.0] - 2026-10-27

### Added

- Initial comment engine: threaded comments attached to polymorphic
  `commentable_type` / `commentable_id` targets, with optional author attribution.
- Moderation lifecycle (`pending`, `approved`, `rejected`, `spam`) with public
  listings defaulting to approved comments.
- PSR-14 domain events: `CommentCreatedEvent`, `CommentUpdatedEvent`,
  `CommentDeletedEvent`, `CommentApprovedEvent`, `CommentRejectedEvent`, and
  `CommentMarkedAsSpamEvent`, dispatched through
  `Psr\EventDispatcher\EventDispatcherInterface`.
- Explicit create, update, delete, and moderate use cases with a `CommentService`
  facade. Author ownership is enforced for updates and deletes; moderator
  deletion requires explicit authorization from the caller.
- PDO and in-memory repository adapters with consistent semantics: `save()`
  inserts a comment and assigns its identifier, while `update()`, `delete()`, and
  `restore()` report failure for comments that no longer exist.
- Order-by whitelisting, clamped pagination, validated table names, and
  parameterized queries.
- Driver-aware `comments` table migration with listing, author, and moderation
  indexes plus a self-referencing parent foreign key that cascades hard deletes
  to replies.
- Composer metadata declares the required `mbstring` extension and suggests the
  PDO drivers needed by the bundled adapters.
- Unit and integration test suites covering domain validation, use cases,
  in-memory and PDO persistence, migrations, rollback, and cascade behaviour.

[Unreleased]: https://github.com/ttpryg/comment-engine/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/ttpryg/comment-engine/releases/tag/v1.0.0
