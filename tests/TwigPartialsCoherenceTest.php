<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * Verifie que tous les partials Twig inclus dans les templates existent reellement.
 * Detecte les erreurs Twig LoaderError previsibles.
 */
class TwigPartialsCoherenceTest extends TestCase
{
    private string $templatesDir;

    protected function setUp(): void
    {
        $this->templatesDir = __DIR__ . '/../templates';
        if (!is_dir($this->templatesDir)) {
            $this->markTestSkipped('Dossier templates absent');
        }
    }

    public function testAllIncludedPartialsExist(): void
    {
        $allTemplates = array_merge(
            glob($this->templatesDir . '/*.twig') ?: [],
            glob($this->templatesDir . '/partials/*.twig') ?: []
        );

        $missing = [];
        foreach ($allTemplates as $tpl) {
            $content = file_get_contents($tpl);
            // {% include 'partials/xxx.twig' ... %} et {% embed 'partials/xxx.twig' ... %}
            if (preg_match_all('#\{%\s*(?:include|embed)\s+[\'"]([^\'"]+)[\'"]#', $content, $matches)) {
                foreach ($matches[1] as $ref) {
                    $fullPath = $this->templatesDir . '/' . $ref;
                    if (!is_file($fullPath)) {
                        $missing[] = basename($tpl) . ' -> ' . $ref;
                    }
                }
            }
        }

        $this->assertEmpty(
            $missing,
            "Partial(s) Twig inclus mais absent(s) du disque:\n" . implode("\n", $missing)
        );
    }

    public function testAllExtendedLayoutsExist(): void
    {
        $allTemplates = glob($this->templatesDir . '/*.twig') ?: [];

        $missing = [];
        foreach ($allTemplates as $tpl) {
            $content = file_get_contents($tpl);
            if (preg_match('#\{%\s*extends\s+[\'"]([^\'"]+)[\'"]#', $content, $match)) {
                $fullPath = $this->templatesDir . '/' . $match[1];
                if (!is_file($fullPath)) {
                    $missing[] = basename($tpl) . ' extends ' . $match[1];
                }
            }
        }

        $this->assertEmpty(
            $missing,
            "Layout(s) Twig etendus mais absent(s) du disque:\n" . implode("\n", $missing)
        );
    }
}
