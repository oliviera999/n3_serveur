<?php

declare(strict_types=1);

namespace App\Controller\Ffp3;

use App\Config\Paths;
use App\Service\OutputCacheService;
use App\Service\TemplateRenderer;
use App\Util\ResponseHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class CacheController
{
    public function __construct(
        private ?OutputCacheService $outputCacheService = null,
        private ?TemplateRenderer $renderer = null,
    ) {
    }

    /**
     * Vide tous les caches du site : Twig, DI Container, OpCache PHP, cache outputs (tous environnements).
     *
     * Route: GET /admin/clear-cache
     * Protection par middleware d'authentification ($applyAuth)
     */
    public function clearCache(Request $request, Response $response): Response
    {
        $projectRoot = Paths::getProjectRoot();
        $cacheDirs = [
            $projectRoot . '/var/cache/twig',
            $projectRoot . '/var/cache/di',
        ];

        $results = [];
        $totalDeleted = 0;
        $errors = [];

        foreach ($cacheDirs as $cacheDir) {
            $dirName = basename($cacheDir);

            if (!is_dir($cacheDir)) {
                $results[$dirName] = [
                    'status' => 'skipped',
                    'message' => "N'existe pas encore, rien à vider",
                    'deleted' => 0
                ];
                continue;
            }

            try {
                $deleted = $this->deleteRecursive($cacheDir);
                $totalDeleted += $deleted;

                // Recréer le dossier vide avec les bonnes permissions
                if (!is_dir($cacheDir)) {
                    mkdir($cacheDir, 0755, true);
                }

                $results[$dirName] = [
                    'status' => 'success',
                    'message' => "{$deleted} fichier(s) supprimé(s)",
                    'deleted' => $deleted
                ];
            } catch (\Exception $e) {
                $errors[] = "Erreur sur {$dirName}: " . $e->getMessage();
                $results[$dirName] = [
                    'status' => 'error',
                    'message' => $e->getMessage(),
                    'deleted' => 0
                ];
            }
        }

        // Vider l'opcache PHP si disponible
        $opcacheStatus = $this->clearOpCache();

        // Vider le cache outputs (tous environnements) si le service est disponible
        $outputCacheCleared = false;
        if ($this->outputCacheService !== null) {
            $this->outputCacheService->invalidateAllEnvironments();
            $outputCacheCleared = true;
        }

        $success = empty($errors);

        // Réponse JSON
        $msg = $success
            ? "Cache vidé avec succès ! ({$totalDeleted} fichier(s) au total)"
            . ($opcacheStatus['success'] ? " + Opcache vidé" : "")
            . ($outputCacheCleared ? " + cache outputs (tous environnements)" : "")
            : "Le cache a été partiellement vidé avec " . count($errors) . " erreur(s)";
        $jsonResponse = [
            'success' => $success,
            'total_deleted' => $totalDeleted,
            'results' => $results,
            'opcache' => $opcacheStatus,
            'output_cache_cleared' => $outputCacheCleared,
            'message' => $msg,
            'errors' => $errors
        ];

        return ResponseHelper::json($response, $jsonResponse, $success ? 200 : 500);
    }

    /**
     * Affiche le script de déploiement (DEPLOY_NOW.sh) en lecture seule.
     * Route: GET /admin/deploy-script
     * Protection par middleware d'authentification ($applyAuth)
     */
    public function showDeployScript(Request $request, Response $response): Response
    {
        $projectRoot = Paths::getProjectRoot();
        $scriptPath = $projectRoot . '/ffp3/DEPLOY_NOW.sh';
        $missing = !is_file($scriptPath);
        $content = $missing ? '' : (string) file_get_contents($scriptPath);

        $html = $this->renderTemplate('admin/deploy_script.twig', [
            'missing' => $missing,
            'expected_path' => 'ffp3/DEPLOY_NOW.sh',
            'script_content' => $content,
            'nav_active' => 'supervision',
        ], function () use ($missing, $content) {
            // Fallback minimal si TemplateRenderer indisponible (cas DI non re-cache)
            if ($missing) {
                return '<!DOCTYPE html><html lang="fr"><meta charset="UTF-8">'
                    . '<title>Script de déploiement</title><body>'
                    . '<h1>Script de déploiement</h1>'
                    . '<p>Fichier DEPLOY_NOW.sh introuvable.</p>'
                    . '<p><a href="/supervision">Retour à la supervision</a></p></body></html>';
            }
            $safe = htmlspecialchars($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            return '<!DOCTYPE html><html lang="fr"><meta charset="UTF-8">'
                . '<title>Script de déploiement</title><body>'
                . '<h1>Script de déploiement (DEPLOY_NOW.sh)</h1><pre>' . $safe . '</pre>'
                . '<p><a href="/supervision">Retour à la supervision</a></p></body></html>';
        });

        $response->getBody()->write($html);
        return $response
            ->withStatus($missing ? 404 : 200)
            ->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * Affiche une page HTML simple pour vider le cache
     *
     * Route: GET /admin/clear-cache-page
     * Protection par middleware d'authentification ($applyAuth)
     */
    public function clearCachePage(Request $request, Response $response): Response
    {
        $html = $this->renderTemplate('admin/cache_admin.twig', [
            'nav_active' => 'supervision',
        ], static function (): string {
            return self::clearCacheFallbackHtml();
        });

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * Rend un template Twig ou retombe sur un HTML minimal si TemplateRenderer absent.
     */
    private function renderTemplate(string $template, array $context, callable $fallback): string
    {
        if ($this->renderer !== null) {
            try {
                return $this->renderer->render($template, $context);
            } catch (\Throwable $e) {
                error_log('[CacheController] template render failed (' . $template . '): ' . $e->getMessage());
            }
        }
        return $fallback();
    }

    /**
     * Fallback HTML brut (volontairement court) si le template Twig n'est pas disponible.
     */
    private static function clearCacheFallbackHtml(): string
    {
        $html = <<<'HTML'
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FFP3 - Vider le cache</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            max-width: 800px;
            margin: 50px auto;
            padding: 20px;
            background-color: #f5f5f5;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #2c3e50;
            border-bottom: 3px solid #008B74;
            padding-bottom: 10px;
        }
        .btn {
            display: inline-block;
            padding: 12px 30px;
            background: linear-gradient(135deg, #008B74 0%, #00B794 100%);
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            text-decoration: none;
            margin: 10px 5px;
            transition: all 0.3s;
            box-shadow: 0 4px 12px rgba(0,139,116,0.3);
        }
        .btn:hover {
            transform: scale(1.05);
            box-shadow: 0 6px 20px rgba(0,139,116,0.4);
        }
        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        .result {
            margin-top: 20px;
            padding: 15px;
            border-radius: 8px;
            display: none;
        }
        .result.success {
            background-color: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }
        .result.error {
            background-color: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
        }
        .result.info {
            background-color: #d1ecf1;
            border: 1px solid #bee5eb;
            color: #0c5460;
        }
        .loading {
            display: none;
            text-align: center;
            margin: 20px 0;
        }
        .spinner {
            border: 4px solid #f3f3f3;
            border-top: 4px solid #008B74;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 0 auto;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        .details {
            margin-top: 15px;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 5px;
            font-family: monospace;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🧹 Vider le cache serveur</h1>
        <p>Cette action va supprimer les caches Twig et DI Container pour forcer la recompilation.</p>

        <button id="clearBtn" class="btn" onclick="clearCache()">Vider le cache</button>
        <a href="/supervision" class="btn" style="background: #6c757d;">Retour à supervision</a>
        <a href="/" class="btn" style="background: #6c757d;">Retour à l'accueil</a>

        <div id="loading" class="loading">
            <div class="spinner"></div>
            <p>Vidage en cours...</p>
        </div>

        <div id="result" class="result"></div>
    </div>

    <script>
        async function clearCache() {
            const btn = document.getElementById('clearBtn');
            const loading = document.getElementById('loading');
            const result = document.getElementById('result');

            btn.disabled = true;
            loading.style.display = 'block';
            result.style.display = 'none';

            try {
                const response = await fetch(window.location.origin + '/admin/clear-cache');
                const data = await response.json();

                loading.style.display = 'none';
                result.style.display = 'block';

                if (data.success) {
                    result.className = 'result success';
                    let details = Object.entries(data.results).map(([dir, info]) =>
                        `${dir}: ${info.message}`
                    ).join('<br>');

                    if (data.opcache) {
                        details += '<br><br><strong>OpCache PHP:</strong><br>';
                        details += data.opcache.available
                            ? (data.opcache.success ? '✅ ' : '⚠️ ') + data.opcache.message
                            : 'ℹ️ ' + data.opcache.message;
                    }

                    result.innerHTML = `
                        <h3>✅ ${data.message}</h3>
                        <div class="details">
                            <strong>Détails:</strong><br>
                            ${details}
                        </div>
                    `;
                } else {
                    result.className = 'result error';
                    result.innerHTML = `
                        <h3>❌ ${data.message}</h3>
                        <div class="details">
                            ${data.errors.join('<br>')}
                        </div>
                    `;
                }
            } catch (error) {
                loading.style.display = 'none';
                result.style.display = 'block';
                result.className = 'result error';
                result.innerHTML = `<h3>❌ Erreur</h3><p>${error.message}</p>`;
            } finally {
                btn.disabled = false;
            }
        }
    </script>
</body>
</html>
HTML;

        return $html;
    }

    /**
     * Vide l'opcache PHP si disponible
     *
     * @return array Statut du vidage de l'opcache
     */
    private function clearOpCache(): array
    {
        $result = [
            'available' => false,
            'success' => false,
            'message' => ''
        ];

        // Vérifier si opcache est disponible
        if (!function_exists('opcache_reset')) {
            $result['message'] = 'OpCache non disponible (fonction opcache_reset() absente)';
            return $result;
        }

        // Vérifier si opcache est activé
        if (!opcache_get_status() || !opcache_get_configuration()) {
            $result['message'] = 'OpCache non activé';
            return $result;
        }

        $result['available'] = true;

        // Vider l'opcache
        if (opcache_reset()) {
            $result['success'] = true;
            $result['message'] = 'OpCache vidé avec succès';
        } else {
            $result['message'] = 'Échec du vidage de l\'OpCache';
        }

        return $result;
    }

    /**
     * Supprime récursivement un répertoire et son contenu
     *
     * @param string $dir Chemin du répertoire à supprimer
     * @return int Nombre de fichiers supprimés
     */
    private function deleteRecursive(string $dir): int
    {
        $count = 0;

        if (!is_dir($dir)) {
            return 0;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getRealPath());
            } else {
                unlink($item->getRealPath());
                $count++;
            }
        }

        return $count;
    }
}
