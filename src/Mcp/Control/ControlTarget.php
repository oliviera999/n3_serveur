<?php

declare(strict_types=1);

namespace App\Mcp\Control;

use App\Mcp\McpConfig;
use App\Mcp\Support\EnvironmentScope;
use App\Mcp\Support\FieldCatalog;
use App\Mcp\ToolException;

/**
 * Cible RÉELLE d'une commande de pilotage : la table outputs (et la board) que
 * {@see \App\Config\TableConfig} résout pour un couple (famille, environnement).
 *
 * Pourquoi : un environnement n'est « de test » que pour SA famille. En `n3pp_test`,
 * `msp_test` ou `energie_test`, `TableConfig::getOutputsTable()` renvoie `ffp3Outputs`
 * (aquaponie RÉELLE) ; en `test`, `test3`, `s3test`, les tables N3PP / MSP1 restent celles
 * de production. Se fier au nom de l'environnement a permis (relecture sécurité PR #100)
 * d'écrire sur la production depuis un « environnement de test ».
 *
 * La décision de production compare donc la TABLE réellement résolue à la table de
 * production de la famille (résolue elle aussi par TableConfig, en `prod`) — elle ne
 * s'appuie sur aucune liste de noms d'environnements.
 */
final class ControlTarget
{
    private function __construct(
        public readonly string $family,
        public readonly string $env,
        public readonly string $table,
        public readonly string $productionTable,
        public readonly ?int $board,
    ) {
    }

    public static function resolve(string $family, string $env): self
    {
        if (!in_array($family, FieldCatalog::OUTPUT_FAMILIES, true)) {
            throw new ToolException('Famille sans sorties pilotables : ' . $family, 'refused_by_policy');
        }
        if (!in_array($env, McpConfig::ALL_ENVIRONMENTS, true)) {
            throw new ToolException('Environnement inconnu : ' . $env, 'env_not_allowed');
        }
        $table = EnvironmentScope::run($env, static fn (): string => FieldCatalog::outputsTable($family));
        $production = EnvironmentScope::run('prod', static fn (): string => FieldCatalog::outputsTable($family));

        return new self($family, $env, $table, $production, FieldCatalog::BOARDS[$family] ?? null);
    }

    /**
     * Vrai si la table réellement ciblée est une table de PRODUCTION de la famille
     * (table `prod`, ou table S3 de production `ffp3OutputsS3`).
     */
    public function isProduction(): bool
    {
        if ($this->table === $this->productionTable) {
            return true;
        }
        // s3 = production (aquamobile) : sa table dédiée l'est aussi.
        $s3 = EnvironmentScope::run('s3', fn (): string => FieldCatalog::outputsTable($this->family));

        return $this->table === $s3;
    }

    public function key(): string
    {
        return $this->family . ':' . $this->env;
    }

    /**
     * Identifiant de la cible physique (table + board) : sert au plafond de nourrissage,
     * pour que deux environnements résolus vers le même bac partagent le même compteur.
     */
    public function physicalKey(): string
    {
        return $this->table . '#' . ($this->board ?? 'all');
    }

    /**
     * Refuse la cible si elle n'est pas autorisée par la configuration, ou si elle vise
     * une table de production sans le drapeau explicite MCP_CONTROL_ALLOW_PRODUCTION.
     *
     * @throws ToolException
     */
    public function assertAllowed(McpConfig $config): void
    {
        if (!in_array($this->key(), $config->controlAllowedTargets(), true)) {
            throw new ToolException(sprintf(
                'Cible %s non pilotable (MCP_CONTROL_ALLOWED_TARGETS). Cibles autorisées : %s.',
                $this->key(),
                $config->controlAllowedTargets() === [] ? '(aucune)' : implode(', ', $config->controlAllowedTargets())
            ), 'target_not_allowed');
        }
        if ($this->isProduction() && !$config->isProductionControlAllowed()) {
            throw new ToolException(sprintf(
                'Refusé : %s écrit dans la table de PRODUCTION %s (installation réelle). '
                . 'Le pilotage de la production exige MCP_CONTROL_ALLOW_PRODUCTION=true.',
                $this->key(),
                $this->table
            ), 'production_target_refused');
        }
    }
}
