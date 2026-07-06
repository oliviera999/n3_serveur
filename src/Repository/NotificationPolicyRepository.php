<?php

declare(strict_types=1);

namespace App\Repository;

use App\Config\NotificationPolicyGpioMap;
use App\Config\TableConfig;
use App\Notification\NotificationCategory;
use App\Notification\NotificationFamily;
use App\Notification\NotificationMode;
use App\Notification\NotificationPolicy;
use App\Util\TableValidator;
use PDO;

/**
 * Lecture / écriture de la politique de notifications par famille (GPIO server-only + mailNotif).
 */
class NotificationPolicyRepository extends AbstractRepository
{
    /** @var array<string, array{table: string, board: int}> */
    private const FAMILY_STORAGE = [
        'ffp3' => ['table' => 'ffp3Outputs', 'board' => 1],
        'msp1' => ['table' => 'msp1Outputs', 'board' => 2],
        'n3pp' => ['table' => 'n3ppOutputs', 'board' => 3],
        'gallery_ffp3' => ['table' => 'UploadPhoto1Outputs', 'board' => 5],
        'gallery_msp1' => ['table' => 'UploadPhoto2Outputs', 'board' => 6],
        'gallery_n3pp' => ['table' => 'UploadPhoto3Outputs', 'board' => 7],
    ];

    public function __construct(PDO $pdo)
    {
        parent::__construct($pdo);
    }

    /**
     * Politique stockée en BDD, ou null si aucune ligne notifMode (repli .env).
     */
    public function getStoredPolicy(NotificationFamily $family): ?NotificationPolicy
    {
        $gpios = NotificationPolicyGpioMap::gpiosForFamily($family);
        if ($gpios === null) {
            return null;
        }

        $storage = $this->resolveStorage($family);
        if ($storage === null) {
            return null;
        }

        $modeRaw = $this->readGpioState($storage['table'], $storage['board'], $gpios['notifMode']);
        if ($modeRaw === null) {
            return null;
        }

        $mode = NotificationMode::fromString($modeRaw, NotificationMode::Important);
        $categoriesRaw = $this->readGpioState($storage['table'], $storage['board'], $gpios['notifCategories']) ?? '';
        $disabled = $this->disabledFromEnabledCsv($categoriesRaw);

        return new NotificationPolicy($mode, $disabled);
    }

    /**
     * État UI : mode effectif, catégories activées, indicateur de repli env.
     *
     * @return array{
     *   mode: string,
     *   categories_enabled: list<string>,
     *   from_env: bool,
     *   mail_notif_firmware: string
     * }
     */
    public function getUiState(NotificationFamily $family): array
    {
        $gpios = NotificationPolicyGpioMap::gpiosForFamily($family);
        $storage = $this->resolveStorage($family);
        $envPolicy = NotificationPolicy::fromEnv();

        if ($gpios === null || $storage === null) {
            return $this->uiStateFromPolicy($envPolicy, true, 'false');
        }

        $modeRaw = $this->readGpioState($storage['table'], $storage['board'], $gpios['notifMode']);
        $mailNotif = $this->readGpioState($storage['table'], $storage['board'], $gpios['mailNotif']) ?? 'false';

        if ($modeRaw === null) {
            return $this->uiStateFromPolicy($envPolicy, true, $mailNotif);
        }

        $policy = $this->getStoredPolicy($family) ?? $envPolicy;

        return $this->uiStateFromPolicy($policy, false, $mailNotif);
    }

    /**
     * @param list<NotificationCategory> $enabledCategories
     */
    public function savePolicy(NotificationFamily $family, NotificationMode $mode, array $enabledCategories): bool
    {
        $gpios = NotificationPolicyGpioMap::gpiosForFamily($family);
        $storage = $this->resolveStorage($family);
        if ($gpios === null || $storage === null) {
            return false;
        }

        $table = $this->validateTable($storage['table'], $family);
        $board = $storage['board'];
        $enabledCsv = $this->enabledToCsv($enabledCategories);
        if (count($enabledCategories) === count(NotificationCategory::cases())) {
            $enabledCsv = '';
        }
        $mailNotif = $this->mailNotifValueForFirmware($family, $mode);

        return $this->executeInTransaction(function () use ($table, $board, $gpios, $mode, $enabledCsv, $mailNotif, $family): bool {
            $this->ensurePolicyRows($family);
            $this->writeGpioState($table, $board, $gpios['notifMode'], $mode->value);
            $this->writeGpioState($table, $board, $gpios['notifCategories'], $enabledCsv);
            $this->writeGpioState($table, $board, $gpios['mailNotif'], $mailNotif);

            return true;
        });
    }

    public function ensurePolicyRows(NotificationFamily $family): void
    {
        $gpios = NotificationPolicyGpioMap::gpiosForFamily($family);
        $storage = $this->resolveStorage($family);
        if ($gpios === null || $storage === null) {
            return;
        }

        $table = $this->validateTable($storage['table'], $family);
        $board = $storage['board'];

        $this->ensureRow($table, $board, $gpios['notifMode'], 'notifMode', 'important');
        $this->ensureRow($table, $board, $gpios['notifCategories'], 'notifCategories', '');
    }

    /**
     * @param list<string> $categoryValues
     *
     * @return array{ok: bool, mode?: NotificationMode, categories?: list<NotificationCategory>, error?: string}
     */
    public function validatePayload(string $modeValue, array $categoryValues): array
    {
        $mode = NotificationMode::fromString($modeValue, NotificationMode::None);
        if ($modeValue !== '' && NotificationMode::tryFrom(strtolower(trim($modeValue))) === null) {
            return ['ok' => false, 'error' => 'Mode de notification invalide'];
        }

        $categories = [];
        foreach ($categoryValues as $raw) {
            $cat = NotificationCategory::fromString(is_string($raw) ? $raw : null);
            if ($cat === null) {
                return ['ok' => false, 'error' => 'Catégorie invalide : ' . (string) $raw];
            }
            $categories[] = $cat;
        }

        return ['ok' => true, 'mode' => $mode, 'categories' => $categories];
    }

    /**
     * @return array{table: string, board: int}|null
     */
    private function resolveStorage(NotificationFamily $family): ?array
    {
        $key = $family->storageKey();
        $base = self::FAMILY_STORAGE[$key] ?? null;
        if ($base === null) {
            return null;
        }

        $table = match ($key) {
            'ffp3' => TableConfig::getOutputsTable(),
            'msp1' => TableConfig::getMspOutputsTable(),
            'n3pp' => TableConfig::getN3ppOutputsTable(),
            default => $base['table'],
        };

        return ['table' => $table, 'board' => $base['board']];
    }

    private function validateTable(string $table, NotificationFamily $family): string
    {
        if (str_starts_with($family->storageKey(), 'gallery_')) {
            return $table;
        }

        return TableValidator::validateOutputsTable($table);
    }

    private function readGpioState(string $table, int $board, int $gpio): ?string
    {
        $sql = "SELECT state FROM `{$table}` WHERE board = :board AND gpio = :gpio LIMIT 1";
        $value = $this->fetchScalar($sql, [':board' => $board, ':gpio' => $gpio]);
        if (!is_scalar($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function writeGpioState(string $table, int $board, int $gpio, string $state): void
    {
        $sql = "UPDATE `{$table}` SET state = :state, requestTime = NOW() WHERE board = :board AND gpio = :gpio";
        $this->execute($sql, [':state' => $state, ':board' => $board, ':gpio' => $gpio]);
    }

    private function ensureRow(string $table, int $board, int $gpio, string $name, string $defaultState): void
    {
        $existing = $this->fetchOne(
            "SELECT id FROM `{$table}` WHERE board = :board AND gpio = :gpio LIMIT 1",
            [':board' => $board, ':gpio' => $gpio]
        );
        if ($existing !== null) {
            return;
        }

        $sql = "INSERT INTO `{$table}` (board, gpio, name, state, requestTime) VALUES (:board, :gpio, :name, :state, NOW())";
        $this->execute($sql, [
            ':board' => $board,
            ':gpio' => $gpio,
            ':name' => $name,
            ':state' => $defaultState,
        ]);
    }

    /**
     * @return list<NotificationCategory>
     */
    private function disabledFromEnabledCsv(string $enabledCsv): array
    {
        $enabled = $this->parseEnabledCsv($enabledCsv);
        if ($enabled === []) {
            return [];
        }

        $enabledSet = [];
        foreach ($enabled as $cat) {
            $enabledSet[$cat->value] = true;
        }

        $disabled = [];
        foreach (NotificationCategory::cases() as $cat) {
            if (!isset($enabledSet[$cat->value])) {
                $disabled[] = $cat;
            }
        }

        return $disabled;
    }

    /**
     * @return list<NotificationCategory>
     */
    private function parseEnabledCsv(string $csv): array
    {
        $csv = trim($csv);
        if ($csv === '') {
            return [];
        }

        $result = [];
        foreach (explode(',', $csv) as $token) {
            $cat = NotificationCategory::fromString($token);
            if ($cat !== null) {
                $result[] = $cat;
            }
        }

        return $result;
    }

    /**
     * @param list<NotificationCategory> $enabled
     */
    private function enabledToCsv(array $enabled): string
    {
        if ($enabled === []) {
            return '';
        }

        $values = array_map(static fn (NotificationCategory $c): string => $c->value, $enabled);

        return implode(',', $values);
    }

    /**
     * @return array{mode: string, categories_enabled: list<string>, from_env: bool, mail_notif_firmware: string}
     */
    private function uiStateFromPolicy(NotificationPolicy $policy, bool $fromEnv, string $mailNotif): array
    {
        $all = NotificationCategory::cases();
        $enabled = [];
        foreach ($all as $cat) {
            if (!$policy->isCategoryDisabled($cat)) {
                $enabled[] = $cat->value;
            }
        }

        return [
            'mode' => $policy->mode->value,
            'categories_enabled' => $enabled,
            'from_env' => $fromEnv,
            'mail_notif_firmware' => $mailNotif,
        ];
    }

    /**
     * Valeur du GPIO mailNotif (101 / 103) poussée au firmware.
     *
     * Toutes les familles reçoivent désormais le MODE GRADUÉ
     * (`none`/`important`/`partial`/`full`) : le firmware le parse via
     * `n3NotifModeFromString` (lib partagée `n3_notify`) et filtre chaque mail
     * par sévérité P1-P4. La rétro-compat legacy est assurée côté firmware
     * (`checked`->Full, `unchecked`/`0`->None, valeur inconnue -> Full).
     *
     * ⚠️ FFP3/ffp5cs : jusqu'à la v6.8.7 cette famille recevait un booléen
     * `'1'/'0'` car l'ancien firmware ffp5cs interprétait mailNotif en on/off.
     * Le firmware ffp5cs sachant désormais parser le mode (harmonisation flotte),
     * on aligne FFP3 sur les autres familles. DÉPLOIEMENT : mettre à jour le
     * firmware ffp5cs (OTA) AVANT ce serveur — un ffp5cs non mis à jour lit
     * `important`/`partial`/`full` comme "faux" et couperait ses alertes.
     */
    private function mailNotifValueForFirmware(NotificationFamily $family, NotificationMode $mode): string
    {
        return $mode->value;
    }
}
