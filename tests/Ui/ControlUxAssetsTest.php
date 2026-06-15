<?php

declare(strict_types=1);

namespace Tests\Ui;

use PHPUnit\Framework\TestCase;

/**
 * Tests STATIQUES de non-régression UX pour le panneau de contrôle distant.
 *
 * Backlog : docs/AUDIT_PAGE_CONTROL_DISTANT (priorité Moyenne « Améliorer l'UX »).
 *
 * Ces tests lisent les fichiers source (JS / Twig / CSS) sans navigateur et
 * verrouillent deux invariants ajoutés :
 *  - un helper de retry réseau borné (fetchWithRetry) sur les écritures
 *    (toggle, parameters, OTA) qui NE retente PAS les 4xx ;
 *  - le maintien obligatoire de l'en-tête X-CSRF-Token (lecture du meta
 *    csrf-token) sur chaque écriture (invariant du lot sécurité déjà mergé) ;
 *  - des indicateurs de chargement / succès / échec cohérents.
 *
 * Inspiré du style des tests statiques existants
 * (EntryPagesAccessibilityTest, AssetWhitelistCoherenceTest).
 */
class ControlUxAssetsTest extends TestCase
{
    private string $jsDir;
    private string $cssDir;
    private string $templatesDir;

    protected function setUp(): void
    {
        $this->jsDir = __DIR__ . '/../../public/assets/js';
        $this->cssDir = __DIR__ . '/../../public/assets/css';
        $this->templatesDir = __DIR__ . '/../../templates';

        if (!is_dir($this->jsDir)) {
            $this->markTestSkipped('Dossier public/assets/js absent');
        }
    }

    private function read(string $path): string
    {
        $this->assertFileExists($path, "Fichier source attendu absent : {$path}");
        $content = file_get_contents($path);
        $this->assertIsString($content, "Lecture impossible : {$path}");

        return $content;
    }

    /**
     * control-actions.js doit définir le helper de retry global window.fetchWithRetry.
     */
    public function testFetchWithRetryHelperIsDefined(): void
    {
        $content = $this->read($this->jsDir . '/control-actions.js');

        $this->assertStringContainsString(
            'window.fetchWithRetry',
            $content,
            'control-actions.js doit exposer window.fetchWithRetry (helper de retry borné).'
        );
    }

    /**
     * Le retry doit être borné (backoff exponentiel) ET ne PAS retenter les 4xx
     * (403 CSRF, 400 validation). On vérifie statiquement la garde sur le statut.
     */
    public function testRetryHelperIsBoundedAndSkips4xx(): void
    {
        $content = $this->read($this->jsDir . '/control-actions.js');

        // Backoff borné : présence d'un plafond via Math.min(...).
        $this->assertMatchesRegularExpression(
            '/Math\.min\s*\(/',
            $content,
            'fetchWithRetry doit borner le backoff (Math.min).'
        );

        // Ne retente pas les 4xx : on retourne la réponse pour status >= 400 && < 500.
        $this->assertMatchesRegularExpression(
            '/status\s*>=\s*400\s*&&\s*response\.status\s*<\s*500/',
            $content,
            'fetchWithRetry doit traiter les 4xx comme définitifs (pas de retry).'
        );

        // Retente bien les 5xx.
        $this->assertMatchesRegularExpression(
            '/status\s*>=\s*500/',
            $content,
            'fetchWithRetry doit retenter sur les 5xx.'
        );
    }

    /**
     * INVARIANT SÉCURITÉ : chaque écriture (toggle, parameters, OTA) doit envoyer
     * l'en-tête X-CSRF-Token lu depuis le meta csrf-token.
     */
    public function testCsrfHeaderPreservedOnAllWrites(): void
    {
        $files = [
            $this->jsDir . '/control-actions.js',
            $this->jsDir . '/control-auto-save.js',
            $this->templatesDir . '/control.twig',
        ];

        foreach ($files as $file) {
            $content = $this->read($file);

            $this->assertStringContainsString(
                'X-CSRF-Token',
                $content,
                basename($file) . ' doit envoyer l\'en-tête X-CSRF-Token sur les écritures.'
            );
            $this->assertStringContainsString(
                'meta[name="csrf-token"]',
                $content,
                basename($file) . ' doit lire le token CSRF depuis le meta csrf-token.'
            );
        }
    }

    /**
     * Le helper de retry doit (re)injecter X-CSRF-Token à chaque tentative.
     */
    public function testRetryHelperReinjectsCsrfPerAttempt(): void
    {
        $content = $this->read($this->jsDir . '/control-actions.js');

        $this->assertMatchesRegularExpression(
            '/buildOptions[\s\S]{0,400}meta\[name="csrf-token"\]/',
            $content,
            'fetchWithRetry doit relire le meta csrf-token dans buildOptions (par tentative).'
        );
    }

    /**
     * control-auto-save.js et le handler OTA doivent utiliser fetchWithRetry.
     */
    public function testWritesUseFetchWithRetry(): void
    {
        $autoSave = $this->read($this->jsDir . '/control-auto-save.js');
        $this->assertStringContainsString(
            'window.fetchWithRetry',
            $autoSave,
            'control-auto-save.js doit utiliser window.fetchWithRetry pour la sauvegarde.'
        );

        $template = $this->read($this->templatesDir . '/control.twig');
        $this->assertStringContainsString(
            'window.fetchWithRetry',
            $template,
            'control.twig (handler OTA) doit utiliser window.fetchWithRetry.'
        );
    }

    /**
     * Anti-double-clic : un registre des requêtes en vol doit exister.
     */
    public function testConcurrentRequestsAreGuarded(): void
    {
        $actions = $this->read($this->jsDir . '/control-actions.js');
        $this->assertStringContainsString(
            'inFlight',
            $actions,
            'control-actions.js doit garder les toggles concurrents (inFlight).'
        );

        $autoSave = $this->read($this->jsDir . '/control-auto-save.js');
        $this->assertStringContainsString(
            'inFlight',
            $autoSave,
            'control-auto-save.js doit garder les sauvegardes concurrentes (inFlight).'
        );
    }

    /**
     * Indicateurs de chargement / succès / échec : classes CSS présentes.
     */
    public function testLoadingStateStylesExist(): void
    {
        $css = $this->read($this->cssDir . '/control-styles.css');

        foreach (['.action-card.is-updating', '.action-card.is-success', '.action-card.is-error'] as $selector) {
            $this->assertStringContainsString(
                $selector,
                $css,
                "control-styles.css doit définir l'état visuel {$selector}."
            );
        }

        $this->assertMatchesRegularExpression(
            '/\[data-parameter-wrapper\]\.is-error/',
            $css,
            'control-styles.css doit définir un état d\'échec pour les wrappers de paramètres.'
        );
    }
}
