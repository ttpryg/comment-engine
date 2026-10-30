<?php

declare(strict_types=1);

use Ttpryg\DatabaseMigrator\Migration;

/**
 * Add the nullable tenant scope to existing comments tables.
 *
 * The column is nullable so existing rows remain valid; they are visible only
 * to unscoped (null tenant) reads. The composite tenant-leading indexes keep
 * scoped listings and author feeds on the same index prefix as the original
 * target/author indexes.
 *
 * The MySQL composite index is ~1000 bytes with utf8mb4, which requires an
 * InnoDB DYNAMIC row format (3072-byte limit, MySQL 5.7.7+ default).
 */
return new class extends Migration
{
    /**
     * @var array<string, string>
     */
    private const INDEXES = [
        'idx_comments_tenant_target_status_created' => 'tenant_id, commentable_type, commentable_id, status, created_at',
        'idx_comments_tenant_author_created' => 'tenant_id, author_id, created_at',
    ];

    public function up(PDO $pdo): void
    {
        if (! $this->columnExists($pdo, 'comments', 'tenant_id')) {
            $pdo->exec('ALTER TABLE comments ADD COLUMN tenant_id VARCHAR(100) NULL');
        }

        foreach (self::INDEXES as $name => $columns) {
            if ($this->indexExists($pdo, 'comments', $name)) {
                continue;
            }

            $pdo->exec(sprintf('CREATE INDEX %s ON comments (%s)', $name, $columns));
        }
    }

    public function down(PDO $pdo): void
    {
        foreach (array_keys(self::INDEXES) as $name) {
            if ($this->indexExists($pdo, 'comments', $name)) {
                $pdo->exec($this->isMysql($pdo)
                    ? sprintf('DROP INDEX %s ON comments', $name)
                    : sprintf('DROP INDEX %s', $name));
            }
        }

        if (! $this->columnExists($pdo, 'comments', 'tenant_id')) {
            return;
        }

        if (! $this->isMysql($pdo) && ! $this->sqliteSupportsDropColumn($pdo)) {
            throw new RuntimeException(
                'Rolling back tenant_id requires SQLite 3.35 or newer (ALTER TABLE ... DROP COLUMN).'
            );
        }

        $pdo->exec('ALTER TABLE comments DROP COLUMN tenant_id');
    }

    private function columnExists(PDO $pdo, string $table, string $column): bool
    {
        if ($this->isMysql($pdo)) {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = :table AND column_name = :column'
            );
            $stmt->execute(['table' => $table, 'column' => $column]);

            return (int) $stmt->fetchColumn() > 0;
        }

        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM pragma_table_info('comments') WHERE name = :column");
            $stmt->execute(['column' => $column]);

            return (int) $stmt->fetchColumn() > 0;
        }

        return false;
    }

    private function indexExists(PDO $pdo, string $table, string $index): bool
    {
        if ($this->isMysql($pdo)) {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.statistics
                 WHERE table_schema = DATABASE() AND table_name = :table AND index_name = :index'
            );
            $stmt->execute(['table' => $table, 'index' => $index]);

            return (int) $stmt->fetchColumn() > 0;
        }

        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM sqlite_master WHERE type = 'index' AND name = :index AND tbl_name = :table"
            );
            $stmt->execute(['index' => $index, 'table' => $table]);

            return (int) $stmt->fetchColumn() > 0;
        }

        return false;
    }

    private function sqliteSupportsDropColumn(PDO $pdo): bool
    {
        $stmt = $pdo->prepare('SELECT sqlite_version()');
        $stmt->execute();

        return version_compare((string) $stmt->fetchColumn(), '3.35.0', '>=');
    }

    private function isMysql(PDO $pdo): bool
    {
        return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    }
};
