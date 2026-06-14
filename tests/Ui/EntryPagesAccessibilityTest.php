<?php

declare(strict_types=1);

namespace Tests\Ui;

use PHPUnit\Framework\TestCase;

/**
 * Base de non-regression a11y / responsive sur les pages d'entree
 * (accueil, login, navigation).
 *
 * Ces tests sont STATIQUES : ils lisent les fichiers source (templates Twig
 * et feuilles de style) et verrouillent des invariants d'accessibilite deja
 * corriges, afin d'empecher toute regression. Aucun navigateur n'est requis,
 * ce qui les rend executables en CI.
 *
 * Backlog audits UI :
 *  - docs/AUDIT_UI_ACCUEIL_LOGIN_NAV.md (item 10)
 *  - docs/AUDIT_UI_MOBILE_LAPTOP_GLOBAL_2026-03.md (checklist)
 *
 * Inspire du style des tests statiques existants
 * (TwigPartialsCoherenceTest, AssetWhitelistCoherenceTest, RoutesConfigSecurityTest).
 */
class EntryPagesAccessibilityTest extends TestCase
{
    private string $templatesDir;
    private string $cssDir;

    protected function setUp(): void
    {
        $this->templatesDir = __DIR__ . '/../../templates';
        $this->cssDir = __DIR__ . '/../../public/assets/css';

        if (!is_dir($this->templatesDir)) {
            $this->markTestSkipped('Dossier templates absent');
        }
        if (!is_dir($this->cssDir)) {
            $this->markTestSkipped('Dossier public/assets/css absent');
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
     * Le viewport ne doit PAS desactiver le zoom mobile (user-scalable=no),
     * sinon les utilisateurs malvoyants ne peuvent plus agrandir la page.
     */
    public function testLayoutViewportAllowsMobileZoom(): void
    {
        $content = $this->read($this->templatesDir . '/layout_base.twig');

        $this->assertMatchesRegularExpression(
            '/<meta\s+name=["\']viewport["\']/i',
            $content,
            'layout_base.twig doit declarer un meta viewport.'
        );
        $this->assertStringNotContainsStringIgnoringCase(
            'user-scalable=no',
            $content,
            'layout_base.twig : le viewport ne doit PAS contenir user-scalable=no '
            . '(le zoom mobile doit rester autorise).'
        );
        $this->assertStringNotContainsStringIgnoringCase(
            'maximum-scale=1',
            $content,
            'layout_base.twig : le viewport ne doit PAS brider le zoom via maximum-scale=1.'
        );
    }

    /**
     * L'element <html> doit porter un attribut lang (exploitation par les lecteurs d'ecran).
     */
    public function testLayoutHasHtmlLangAttribute(): void
    {
        $content = $this->read($this->templatesDir . '/layout_base.twig');

        $this->assertMatchesRegularExpression(
            '/<html\b[^>]*\blang=["\'][a-zA-Z-]+["\']/i',
            $content,
            'layout_base.twig : la balise <html> doit porter un attribut lang non vide.'
        );
    }

    /**
     * Un lien d'evitement (skip-link) doit exister dans le layout des pages d'entree.
     * Les pages accueil/login etendent layout.twig, qui porte le skip-link.
     */
    public function testEntryLayoutHasSkipLink(): void
    {
        $content = $this->read($this->templatesDir . '/layout.twig');

        $this->assertMatchesRegularExpression(
            '/<a\s+href=["\']#main["\'][^>]*class=["\'][^"\']*skip-to-content[^"\']*["\']/i',
            $content,
            'layout.twig doit contenir un lien d\'evitement (skip-link) '
            . 'pointant vers #main (classe skip-to-content).'
        );
    }

    /**
     * Les pages accueil et login doivent etendre le layout porteur du skip-link.
     */
    public function testEntryPagesExtendSkipLinkLayout(): void
    {
        foreach (['home.twig', 'login.twig'] as $tpl) {
            $content = $this->read($this->templatesDir . '/' . $tpl);
            $this->assertMatchesRegularExpression(
                '/\{%\s*extends\s+["\']layout\.twig["\']/',
                $content,
                "{$tpl} doit etendre layout.twig (porteur du skip-link et des metas a11y)."
            );
        }
    }

    /**
     * La navigation principale doit exposer un focus visible au clavier
     * via :focus-visible (et pas seulement un outline:none qui supprimerait l'indicateur).
     */
    public function testMainCssNavHasFocusVisible(): void
    {
        $content = $this->read($this->cssDir . '/main.css');

        $this->assertMatchesRegularExpression(
            '/#nav\s+ul\.links\s+li\s+a:focus-visible\s*\{[^}]*outline\s*:/i',
            $content,
            'main.css : la navigation principale (#nav ul.links li a) doit definir '
            . 'un style :focus-visible avec un outline (indicateur de focus clavier).'
        );
    }

    /**
     * Le panneau de navigation mobile doit aussi exposer un focus visible.
     */
    public function testMainCssMobileNavHasFocusVisible(): void
    {
        $content = $this->read($this->cssDir . '/main.css');

        $this->assertMatchesRegularExpression(
            '/#navPanel\s+\.links\s+li\s+a:focus-visible\s*\{[^}]*outline\s*:/i',
            $content,
            'main.css : la navigation mobile (#navPanel .links li a) doit definir '
            . 'un style :focus-visible avec un outline.'
        );
    }

    /**
     * Les champs de saisie et le bouton de login doivent exposer un :focus-visible
     * (indispensable pour la navigation au clavier sur la page de connexion).
     */
    public function testLoginCssInputsHaveFocusVisible(): void
    {
        $content = $this->read($this->cssDir . '/login-styles.css');

        $this->assertMatchesRegularExpression(
            '/\.form-group\s+input:focus-visible\s*\{[^}]*outline\s*:/i',
            $content,
            'login-styles.css : les inputs (.form-group input) doivent definir '
            . 'un etat :focus-visible avec un outline.'
        );
        $this->assertMatchesRegularExpression(
            '/\.btn-login:focus-visible\s*\{[^}]*outline\s*:/i',
            $content,
            'login-styles.css : le bouton de connexion (.btn-login) doit definir '
            . 'un etat :focus-visible avec un outline.'
        );
    }

    /**
     * Le fond fixe ne doit pas utiliser 100vw (provoque un debordement horizontal
     * sur mobile a cause de la largeur de la scrollbar) : on prefere 100%.
     */
    public function testBackgroundFixedDoesNotUse100vw(): void
    {
        $content = $this->read($this->cssDir . '/main.css');

        $this->assertMatchesRegularExpression(
            '/#wrapper\s*>\s*\.bg\.fixed\s*\{(?P<body>[^}]*)\}/i',
            $content,
            'main.css : la regle "#wrapper > .bg.fixed" doit exister.'
        );

        preg_match('/#wrapper\s*>\s*\.bg\.fixed\s*\{(?P<body>[^}]*)\}/i', $content, $m);
        $body = $m['body'] ?? '';

        $this->assertDoesNotMatchRegularExpression(
            '/width\s*:\s*100vw/i',
            $body,
            'main.css : "#wrapper > .bg.fixed" ne doit PAS utiliser width:100vw '
            . '(prefere 100% pour eviter le debordement horizontal mobile).'
        );
        $this->assertMatchesRegularExpression(
            '/width\s*:\s*100%/i',
            $body,
            'main.css : "#wrapper > .bg.fixed" doit utiliser width:100%.'
        );
    }

    /**
     * Tous les liens externes (target="_blank") de l'accueil doivent porter
     * rel="noopener noreferrer" (securite : protection contre le tabnabbing).
     */
    public function testHomeExternalLinksHaveRelNoopenerNoreferrer(): void
    {
        $content = $this->read($this->templatesDir . '/home.twig');

        $matched = preg_match_all('/<a\b[^>]*>/i', $content, $anchors);
        $this->assertNotFalse($matched);

        $blankAnchors = array_filter(
            $anchors[0],
            static fn (string $a): bool => (bool) preg_match('/target=["\']_blank["\']/i', $a)
        );

        $this->assertNotEmpty(
            $blankAnchors,
            'home.twig devrait contenir au moins un lien externe target="_blank" a verrouiller.'
        );

        $offenders = [];
        foreach ($blankAnchors as $anchor) {
            if (!preg_match('/\brel=["\'][^"\']*\bnoopener\b[^"\']*["\']/i', $anchor)
                || !preg_match('/\brel=["\'][^"\']*\bnoreferrer\b[^"\']*["\']/i', $anchor)) {
                $offenders[] = trim($anchor);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "home.twig : chaque lien target=\"_blank\" doit porter rel=\"noopener noreferrer\".\n"
            . "Liens fautifs :\n" . implode("\n", $offenders)
        );
    }
}
