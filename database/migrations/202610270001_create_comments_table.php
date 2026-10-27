<?php

declare(strict_types=1);

use Ttpryg\DatabaseMigrator\Migration;

/**
 * Create the comments table and its lookup indexes.
 *
 * Table definitions are driver-aware so the same migration works on SQLite
 * (tests/embedded) and MySQL/MariaDB. The self-referencing parent foreign key
 * cascades hard deletes to replies.
 */
return new class extends Migration
{
    /**
     * @var array<string, string>
     */
    private const INDEXES = [
        'idx_comments_target_status_created' => 'commentable_type, commentable_id, status, created_at',
        'idx_comments_author_created' => 'author_id, created_at',
        'idx_comments_status_created' => 'status, created_at',
    ];

    public function up(PDO $pdo): void
    {
        $pdo->exec($this->commentsTable($pdo));

        foreach (self::INDEXES as $name => $columns) {
            if ($this->indexExists($pdo, 'comments', $name)) {
                continue;
            }

            $pdo->exec(sprintf('CREATE INDEX %s ON comments (%s)', $name, $columns));
        }
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS comments');
    }

    private function commentsTable(PDO $pdo): string
    {
        if ($this->isMysql($pdo)) {
            return 'CREATE TABLE IF NOT EXISTS comments (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                commentable_type VARCHAR(50) NOT NULL,
                commentable_id VARCHAR(100) NOT NULL,
                parent_id BIGINT UNSIGNED NULL,
                author_id BIGINT UNSIGNED NULL,
                body TEXT NOT NULL,
                status ENUM(\'pending\', \'approved\', \'rejected\', \'spam\') NOT NULL DEFAULT \'pending\',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                deleted_at TIMESTAMP NULL DEFAULT NULL,
                CONSTRAINT fk_comments_parent FOREIGN KEY (parent_id) REFERENCES comments(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        }

        return 'CREATE TABLE IF NOT EXISTS comments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            commentable_type VARCHAR(50) NOT NULL,
            commentable_id VARCHAR(100) NOT NULL,
            parent_id INTEGER NULL,
            author_id INTEGER NULL,
            body TEXT NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT \'pending\',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            deleted_at DATETIME NULL DEFAULT NULL,
            FOREIGN KEY (parent_id) REFERENCES comments(id) ON DELETE CASCADE
        )';
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

    private function isMysql(PDO $pdo): bool
    {
        return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    }
};
