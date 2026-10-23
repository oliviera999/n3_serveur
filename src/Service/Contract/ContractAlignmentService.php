<?php

declare(strict_types=1);

namespace App\Service\Contract;

use App\Config\Ffp3GpioMap;
use App\Config\MspGpioMap;
use App\Config\N3ppGpioMap;

/**
 * Compare les contrats JSON aux champs parsés dans les PostDataController
 * et aux *GpioMap PHP. Lecture seule — aucune mutation.
 *
 * Statuts :
 *  - ok    : pas d'écart
 *  - warn  : champs présents dans le code mais absents du contrat
 *  - error : champs du contrat absents du code, ou GPIO divergents
 */
final class ContractAlignmentService
{
    private string $serverRoot;

    public function __construct(
        private ContractLoader $loader,
        ?string $serverRoot = null,
    ) {
        $this->serverRoot = $serverRoot ?? dirname(__DIR__, 3);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function checkAll(): array
    {
        $results = [];
        foreach ($this->loader->loadAll() as $contract) {
            $results[] = $this->checkOne($contract);
        }

        return $results;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function checkById(string $id): ?array
    {
        $contract = $this->loader->loadById($id);
        if ($contract === null) {
            return null;
        }

        return $this->checkOne($contract);
    }

    /**
     * @param array<string, mixed> $contract
     * @return array<string, mixed>
     */
    private function checkOne(array $contract): array
    {
        $id = (string) $contract['id'];
        $label = (string) ($contract['label'] ?? $id);
        $source = is_array($contract['serverSource'] ?? null) ? $contract['serverSource'] : [];

        $contractFields = $this->contractFieldNames($contract);
        $codeFields = $this->extractCodeFields($source);
        $missingInCode = array_values(array_diff($contractFields, $codeFields));
        $missingInContract = array_values(array_diff($codeFields, $contractFields));

        $gpioReport = $this->compareGpio($contract, $source);

        $status = 'ok';
        $issues = [];
        if ($missingInCode !== []) {
            $status = 'error';
            $issues[] = 'Champs du contrat absents du PostDataController : ' . implode(', ', $missingInCode);
        }
        if ($gpioReport['mismatches'] !== [] || $gpioReport['missing_in_code'] !== []) {
            $status = 'error';
            if ($gpioReport['missing_in_code'] !== []) {
                $issues[] = 'GPIO du contrat absents du GpioMap : ' . implode(', ', $gpioReport['missing_in_code']);
            }
            if ($gpioReport['mismatches'] !== []) {
                $issues[] = 'GPIO divergents : ' . implode('; ', $gpioReport['mismatches']);
            }
        }
        if ($gpioReport['missing_in_contract'] !== []) {
            if ($status === 'ok') {
                $status = 'warn';
            }
            $issues[] = 'GPIO du GpioMap absents du contrat : ' . implode(', ', $gpioReport['missing_in_contract']);
        }
        if ($missingInContract !== []) {
            if ($status === 'ok') {
                $status = 'warn';
            }
            $issues[] = 'Champs du code absents du contrat : ' . implode(', ', $missingInContract);
        }

        return [
            'id' => $id,
            'label' => $label,
            'status' => $status,
            'issues' => $issues,
            'fields' => [
                'contract' => $contractFields,
                'code' => $codeFields,
                'missing_in_code' => $missingInCode,
                'missing_in_contract' => $missingInContract,
            ],
            'gpio' => $gpioReport,
            'endpoints' => $contract['endpoints'] ?? [],
            'pages' => $contract['pages'] ?? [],
        ];
    }

    /**
     * @param array<string, mixed> $contract
     * @return list<string>
     */
    private function contractFieldNames(array $contract): array
    {
        $names = [];
        $fields = $contract['fields'] ?? [];
        if (!is_array($fields)) {
            return [];
        }
        foreach ($fields as $field) {
            if (!is_array($field) || !isset($field['name'])) {
                continue;
            }
            $dir = (string) ($field['direction'] ?? 'tx');
            if ($dir === 'rx') {
                continue;
            }
            $names[] = (string) $field['name'];
        }
        sort($names);

        return array_values(array_unique($names));
    }

    /**
     * @param array<string, mixed> $source
     * @return list<string>
     */
    private function extractCodeFields(array $source): array
    {
        $relative = (string) ($source['postDataController'] ?? '');
        if ($relative === '') {
            return [];
        }

        $path = $this->serverRoot . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);
        if (!is_file($path)) {
            return [];
        }

        $code = (string) file_get_contents($path);
        $names = [];

        // AbstractHmac / FFP3 : toFloat('X'), toInt('X'), sanitize('X')
        if (preg_match_all("/(?:toFloat|toInt|sanitize)\\('([^']+)'\\)/", $code, $m)) {
            foreach ($m[1] as $name) {
                $names[] = $name;
            }
        }

        // Helpers email AbstractHmacPostDataController (pas de sanitize('mail') inline)
        if (str_contains($code, 'sanitizeFirmwareEmail')) {
            $names[] = 'mail';
        }
        if (str_contains($code, 'sanitizeFirmwareMailNotif')) {
            $names[] = 'mailNotif';
        }

        // PGL et variantes : $params['field']
        if (($source['fieldExtraction'] ?? '') === 'params'
            && preg_match_all("/\\\$params\\['([^']+)'\\]/", $code, $m2)
        ) {
            foreach ($m2[1] as $name) {
                // Ignorer les clés HMAC / internes non documentées comme données métier
                if (in_array($name, ['timestamp', 'signature', 'post_id'], true)) {
                    continue;
                }
                $names[] = $name;
            }
        }

        sort($names);

        return array_values(array_unique($names));
    }

    /**
     * @param array<string, mixed> $contract
     * @param array<string, mixed> $source
     * @return array{
     *   contract: array<string, string>,
     *   code: array<string, string>,
     *   missing_in_code: list<string>,
     *   missing_in_contract: list<string>,
     *   mismatches: list<string>
     * }
     */
    private function compareGpio(array $contract, array $source): array
    {
        $contractGpio = [];
        $raw = $contract['gpio'] ?? [];
        if (is_array($raw)) {
            foreach ($raw as $gpio => $name) {
                $contractGpio[(string) $gpio] = (string) $name;
            }
        }

        $codeGpio = $this->loadGpioMap($source);
        $missingInCode = [];
        $mismatches = [];
        foreach ($contractGpio as $gpio => $name) {
            if (!isset($codeGpio[$gpio])) {
                $missingInCode[] = $gpio . '=' . $name;
                continue;
            }
            if ($codeGpio[$gpio] !== $name) {
                $mismatches[] = $gpio . ': contrat=' . $name . ' code=' . $codeGpio[$gpio];
            }
        }

        $missingInContract = [];
        foreach ($codeGpio as $gpio => $name) {
            if (!isset($contractGpio[$gpio])) {
                $missingInContract[] = $gpio . '=' . $name;
            }
        }

        ksort($contractGpio);
        ksort($codeGpio);

        return [
            'contract' => $contractGpio,
            'code' => $codeGpio,
            'missing_in_code' => $missingInCode,
            'missing_in_contract' => $missingInContract,
            'mismatches' => $mismatches,
        ];
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, string> gpio => name
     */
    private function loadGpioMap(array $source): array
    {
        $class = $source['gpioMapClass'] ?? null;
        if (!is_string($class) || $class === '') {
            return [];
        }

        $method = (string) ($source['gpioMapMethod'] ?? 'paramGpioMap');

        /** @var array<int|string, string> $map */
        $map = match ($class) {
            MspGpioMap::class => MspGpioMap::paramGpioMap(),
            N3ppGpioMap::class => N3ppGpioMap::paramGpioMap(),
            Ffp3GpioMap::class => $method === 'gpioToProperty'
                ? Ffp3GpioMap::gpioToProperty()
                : array_flip(Ffp3GpioMap::parameterGpioMap()),
            default => class_exists($class) && method_exists($class, $method)
                ? $class::$method()
                : [],
        };

        $out = [];
        foreach ($map as $gpio => $name) {
            $out[(string) $gpio] = (string) $name;
        }

        return $out;
    }
}
