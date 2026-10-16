<?php

namespace App\Support\Database;

use Illuminate\Support\Facades\DB;

/**
 * Database-level guards the schema builder cannot express. Applied only on
 * MySQL/MariaDB, the supported engines for every environment.
 */
class SchemaConstraints
{
    public static function supported(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }

    public static function check(string $table, string $name, string $expression): void
    {
        if (static::supported()) {
            DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$name}` CHECK ({$expression})");
        }
    }

    public static function dropCheck(string $table, string $name): void
    {
        if (static::supported()) {
            DB::statement("ALTER TABLE `{$table}` DROP CONSTRAINT `{$name}`");
        }
    }

    /**
     * Reject UPDATE and DELETE on an append-only table.
     */
    public static function appendOnly(string $table): void
    {
        if (! static::supported()) {
            return;
        }

        foreach (['UPDATE', 'DELETE'] as $operation) {
            $trigger = static::triggerName($table, $operation);
            $verb = strtolower($operation);

            DB::unprepared("DROP TRIGGER IF EXISTS `{$trigger}`");
            DB::unprepared(
                "CREATE TRIGGER `{$trigger}` BEFORE {$operation} ON `{$table}` FOR EACH ROW ".
                "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$table} is append-only; {$verb} is not allowed'"
            );
        }
    }

    public static function dropAppendOnly(string $table): void
    {
        if (! static::supported()) {
            return;
        }

        foreach (['UPDATE', 'DELETE'] as $operation) {
            DB::unprepared('DROP TRIGGER IF EXISTS `'.static::triggerName($table, $operation).'`');
        }
    }

    protected static function triggerName(string $table, string $operation): string
    {
        return "{$table}_no_".strtolower($operation);
    }
}
