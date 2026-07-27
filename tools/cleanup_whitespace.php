<?php
/**
 * Script de nettoyage des lignes vides excessives
 * 
 * Règles appliquées :
 * - Maximum 1 ligne vide entre les méthodes
 * - Aucune ligne vide dans les blocs de code
 * - Pas de lignes vides multiples consécutives
 */

require_once __DIR__ . '/../src/Util/CliGuard.php';
\App\Util\CliGuard::assertCli();

function cleanWhitespace(string $content): string
{
    // Séparer en lignes
    $lines = explode("\n", $content);
    $result = [];
    $previousWasEmpty = false;
    $inDocBlock = false;
    
    foreach ($lines as $line) {
        $trimmed = trim($line);
        
        // Détecter les blocs de commentaires
        if (strpos($trimmed, '/**') === 0) {
            $inDocBlock = true;
        }
        if (strpos($trimmed, '*/') !== false) {
            $inDocBlock = false;
        }
        
        // Si ligne vide
        if ($trimmed === '') {
            // Ne pas ajouter si la précédente était déjà vide
            if (!$previousWasEmpty && !$inDocBlock) {
                $result[] = $line;
                $previousWasEmpty = true;
            }
        } else {
            $result[] = $line;
            $previousWasEmpty = false;
        }
    }
    
    return implode("\n", $result);
}

function processFile(string $filePath): bool
{
    if (!file_exists($filePath)) {
        echo "❌ Fichier non trouvé : $filePath\n";
        return false;
    }
    
    $content = file_get_contents($filePath);
    $cleaned = cleanWhitespace($content);
    
    if ($content !== $cleaned) {
        file_put_contents($filePath, $cleaned);
        echo "✅ Nettoyé : $filePath\n";
        return true;
    }
    
    echo "⏭️  Déjà propre : $filePath\n";
    return false;
}

function processDirectory(string $directory): int
{
    $count = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            if (processFile($file->getPathname())) {
                $count++;
            }
        }
    }
    
    return $count;
}

// Exécution
$baseDir = dirname(__DIR__);
$directories = [
    $baseDir . '/src/Config',
    $baseDir . '/src/Controller',
    $baseDir . '/src/Service',
    $baseDir . '/src/Repository',
    $baseDir . '/src/Security',
    $baseDir . '/src/Command',
    $baseDir . '/src/Domain',
];

echo "🧹 Nettoyage des lignes vides excessives...\n\n";

$totalCleaned = 0;
foreach ($directories as $dir) {
    if (is_dir($dir)) {
        echo "📁 Traitement de : $dir\n";
        $totalCleaned += processDirectory($dir);
        echo "\n";
    }
}

echo "✨ Terminé ! $totalCleaned fichiers nettoyés.\n";

