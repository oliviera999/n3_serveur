<?php

declare(strict_types=1);

namespace App\Mcp\Confirm;

/**
 * Catégories d'opérations exigeant une confirmation par code (hors Claude).
 * `unlock` n'est jamais dispensable via MCP_CONFIRM_SKIP.
 */
final class ConfirmCategory
{
    public const BIO_OVERRIDE = 'bio_override';
    public const PUMP_MAINTENANCE = 'pump_maintenance';
    public const RESET = 'reset';
    public const OTA = 'ota';
    public const PHOTO_DELETE = 'photo_delete';
    public const SQL_DDL = 'sql_ddl';
    public const SQL_UNBOUNDED_WRITE = 'sql_unbounded_write';
    public const SQL_PROD_OUTPUTS = 'sql_prod_outputs';
    public const SQL_ACCOUNT_ADMIN = 'sql_account_admin';
    /** iot_migrate : application d'une migration du dépôt (DDL additive). */
    public const SQL_MIGRATION = 'sql_migration';
    public const UNLOCK = 'unlock';

    /** @var list<string> */
    public const ALL = [
        self::BIO_OVERRIDE,
        self::PUMP_MAINTENANCE,
        self::RESET,
        self::OTA,
        self::PHOTO_DELETE,
        self::SQL_DDL,
        self::SQL_UNBOUNDED_WRITE,
        self::SQL_PROD_OUTPUTS,
        self::SQL_ACCOUNT_ADMIN,
        self::SQL_MIGRATION,
        self::UNLOCK,
    ];

    public static function isKnown(string $category): bool
    {
        return in_array($category, self::ALL, true);
    }
}
