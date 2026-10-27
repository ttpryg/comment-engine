# Changelog

All notable changes to `ttpryg/comment-engine` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
- PDO and in-memory repository adapters, with order-by whitelisting, clamped
  pagination, validated table names, and parameterized queries.
- Driver-aware `comments` table migration with listing, author, and moderation
  indexes plus a self-referencing parent foreign key that cascades hard deletes
  to replies.
- Unit and integration test suites covering domain validation, use cases,
  in-memory and PDO persistence, migrations, and cascade behaviour.

[Unreleased]: https://github.com/ttpryg/comment-engine/commits/HEAD
