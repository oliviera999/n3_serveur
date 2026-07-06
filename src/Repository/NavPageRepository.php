<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * Accès à la table GLOBALE `navPages` : pages affichées dans la barre de
 * navigation. Remplace l'ancien stockage localStorage des toggles de la page
 * de supervision par un état serveur partagé (tous les visiteurs) et permanent.
 *
 * La table n'est pas liée à un environnement (pas de TableConfig) : le menu est
 * commun à toutes les familles. Le schéma est créé/semé à la volée si absent,
 * afin que le menu reste peuplé même avant l'exécution de la migration SQL.
 */
class NavPageRepository extends AbstractRepository
{
    private const TABLE = 'navPages';

    /**
     * Préfixe des clés qui NE pilotent PAS le menu de navigation mais
     * l'affichage sur la page « toutes les galeries » (`/gallery`) :
     * `gallery-<slug>` (afficher la galerie) et `gallery-control-<slug>`
     * (afficher le lien de contrôle caméra). La clé générique `gallery`
     * (sans tiret) reste, elle, le lien « Galeries » du menu.
     */
    private const GALLERY_PAGE_PREFIX = 'gallery-';

    /**
     * Pages activées par défaut. Format : [clé, label, url, ordre].
     *
     * - Les 6 premières entrées sont les liens historiques du menu.
     * - `admin-users` est un lien de menu supplémentaire, filtré par la
     *   permission `canManageUsers` (cf. TemplateRenderer).
     * - Les clés `gallery-*` pilotent la page `/gallery` (pas le menu) et sont
     *   seedées actives pour préserver l'affichage actuel des galeries.
     *
     * @var list<array{0:string,1:string,2:string,3:int}>
     */
    private const SEED = [
        ['home',        'Accueil',        '/',            10],
        ['aquaponie',   'Aquaponie',      '/aquaponie',   20],
        ['potager',     'Potager',        '/meteo',       30],
        ['elevage',     'Élevage',        '/serre',       40],
        ['pgl',         'Poissonglouton', '/pgl',         50],
        ['gallery',     'Galeries',       '/gallery',     60],
        ['admin-users', 'Utilisateurs',   '/admin/users', 200],
        ['gallery-msp1', 'Galerie potager', '/gallery/msp1', 900],
        ['gallery-n3pp', 'Galerie élevage', '/gallery/n3pp', 910],
        ['gallery-ffp3', 'Galerie FFP3',    '/gallery/ffp3', 920],
        ['gallery-control-msp1', 'Contrôle caméra potager', '/gallery/msp1/control', 930],
        ['gallery-control-n3pp', 'Contrôle caméra élevage', '/gallery/n3pp/control', 940],
        ['gallery-control-ffp3', 'Contrôle caméra FFP3',    '/gallery/ffp3/control', 950],
    ];

    private bool $schemaEnsured = false;

    public function __construct(PDO $pdo)
    {
        parent::__construct($pdo);
    }

    /**
     * Pages actives, ordonnées, pour le rendu du menu.
     *
     * @return list<array{key: string, label: string, url: string}>
     */
    public function getActivePages(): array
    {
        $rows = $this->selectWithSelfHeal(
            'SELECT page_key, label, url FROM `' . self::TABLE . '`'
            . ' WHERE active = 1 ORDER BY sort_order ASC, label ASC'
        );

        $pages = [];
        foreach ($rows as $row) {
            $key = (string) $row['page_key'];
            // Les clés de galerie pilotent la page /gallery, pas le menu.
            if (str_starts_with($key, self::GALLERY_PAGE_PREFIX)) {
                continue;
            }
            $pages[] = [
                'key' => $key,
                'label' => (string) $row['label'],
                'url' => (string) $row['url'],
            ];
        }

        return $pages;
    }

    /**
     * État de chaque page connue (active ou non), pour cocher les switchs de la
     * page de supervision. Clé => booléen actif.
     *
     * @return array<string, bool>
     */
    public function getAllStates(): array
    {
        $rows = $this->selectWithSelfHeal(
            'SELECT page_key, active FROM `' . self::TABLE . '`'
        );

        $states = [];
        foreach ($rows as $row) {
            $states[(string) $row['page_key']] = (int) $row['active'] === 1;
        }

        return $states;
    }

    /**
     * Active ou désactive une page (upsert). Le label et l'url ne sont mis à
     * jour que lors d'une activation (les données proviennent alors du switch).
     */
    public function setActive(string $key, string $label, string $url, bool $active, ?string $user): void
    {
        $this->ensureSchema();

        $sql = 'INSERT INTO `' . self::TABLE . '`'
            . ' (page_key, label, url, active, sort_order, updated_by)'
            . ' VALUES (:key, :label, :url, :active, :sort_order, :user)'
            . ' ON DUPLICATE KEY UPDATE'
            . ' label = VALUES(label), url = VALUES(url),'
            . ' active = VALUES(active), updated_by = VALUES(updated_by)';

        $this->execute($sql, [
            ':key' => $key,
            ':label' => $label,
            ':url' => $url,
            ':active' => $active ? 1 : 0,
            ':sort_order' => 1000,
            ':user' => $user,
        ]);
    }

    /**
     * Exécute un SELECT en créant/semant la table à la volée si elle est absente.
     *
     * @return array<int, array<string, mixed>>
     */
    private function selectWithSelfHeal(string $sql): array
    {
        try {
            return $this->fetchAll($sql);
        } catch (\PDOException $e) {
            if (!$this->isMissingTable($e)) {
                throw $e;
            }
            $this->ensureSchema();

            return $this->fetchAll($sql);
        }
    }

    private function isMissingTable(\PDOException $e): bool
    {
        // MySQL 1146 = table inexistante (SQLSTATE 42S02).
        return (string) $e->getCode() === '42S02' || str_contains($e->getMessage(), '1146');
    }

    /**
     * Crée la table si nécessaire et insère le seed de base une seule fois.
     */
    private function ensureSchema(): void
    {
        if ($this->schemaEnsured) {
            return;
        }
        $this->schemaEnsured = true;

        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS `' . self::TABLE . '` ('
            . ' `page_key` VARCHAR(64) NOT NULL,'
            . ' `label` VARCHAR(128) NOT NULL,'
            . ' `url` VARCHAR(255) NOT NULL,'
            . ' `active` TINYINT(1) NOT NULL DEFAULT 0,'
            . ' `sort_order` INT NOT NULL DEFAULT 1000,'
            . ' `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,'
            . ' `updated_by` VARCHAR(64) DEFAULT NULL,'
            . ' PRIMARY KEY (`page_key`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $seedSql = 'INSERT INTO `' . self::TABLE . '`'
            . ' (page_key, label, url, active, sort_order)'
            . ' VALUES (:key, :label, :url, 1, :sort_order)'
            . ' ON DUPLICATE KEY UPDATE page_key = page_key';

        foreach (self::SEED as [$key, $label, $url, $sortOrder]) {
            $this->execute($seedSql, [
                ':key' => $key,
                ':label' => $label,
                ':url' => $url,
                ':sort_order' => $sortOrder,
            ]);
        }
    }
}
