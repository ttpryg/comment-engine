<?php

declare(strict_types=1);

namespace Ttpryg\CommentEngine\Infrastructure\Database;

use PDO;
use Ttpryg\DatabaseMigrator\MigrationRunner;

/**
 * Entry point for comment-engine schema migrations.
 *
 * Migrations live in `database/migrations/` and are tracked in the
 * `comment_engine_migrations` table so `migrate()` can be run repeatedly.
 */
final class DatabaseMigrator
{
    private readonly MigrationRunner $migrationRunner;

    public function __construct(
        PDO $pdo,
        ?string $migrationsPath = null,
        string $trackerTable = 'comment_engine_migrations',
        bool $autoCreateTrackerTable = true
    ) {
        $this->migrationRunner = new MigrationRunner(
            $pdo,
            $migrationsPath ?? __DIR__.'/../../database/migrations',
            $trackerTable,
            $autoCreateTrackerTable
        );
    }

    /**
     * Create the migration tracker table if it does not exist yet.
     */
    public function ensureTrackerTable(): void
    {
        $this->migrationRunner->ensureTrackerTable();
    }

    /**
     * @return array<int, string> Names of the migrations executed in this run.
     */
    public function migrate(): array
    {
        return $this->migrationRunner->migrate();
    }

    /**
     * @return array<int, string> Names of the reverted migrations, newest first.
     */
    public function rollback(int $steps = 1): array
    {
        return $this->migrationRunner->rollback($steps);
    }

    /**
     * @return array<int, string> Names of the migrations recorded in the tracker.
     */
    public function appliedMigrations(): array
    {
        return $this->migrationRunner->appliedMigrations();
    }

    /**
     * @return array<int, string> Names of the migrations recorded as baseline.
     */
    public function markAllAsMigrated(): array
    {
        return $this->migrationRunner->markAllAsMigrated();
    }
}
