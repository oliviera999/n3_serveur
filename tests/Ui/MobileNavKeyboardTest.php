<?php

declare(strict_types=1);

namespace Tests\Ui;

use PHPUnit\Framework\TestCase;

/**
 * Garde-fous de NON-REGRESSION sur les affordances clavier du menu mobile.
 *
 * Contexte : docs/AUDIT_UI_ACCUEIL_LOGIN_NAV.md (item 2 « menu mobile » et
 * point restant « Verifier en test clavier reel la robustesse du menu mobile
 * sur tous navigateurs cibles »).
 *
 * Un vrai test clavier multi-navigateurs ne peut PAS s'executer ici (aucune
 * automatisation navigateur disponible). Ces tests sont donc STATIQUES : ils
 * lisent les sources JS (public/assets/js/main.js, util.js) et le template
 * (templates/layout.twig) et verrouillent la PRESENCE des affordances clavier
 * deja implementees, afin qu'aucun correctif futur ne les supprime par megarde.
 *
 * Ils ne PROUVENT pas le bon comportement runtime ; ce volet est couvert par le
 * plan de test manuel multi-navigateurs : docs/QA_CLAVIER_NAV_MOBILE.md.
 *
 * Inspire du style de EntryPagesAccessibilityTest (lecture de fichiers source +
 * assertions par expression reguliere, executable en CI sans navigateur).
 */
class MobileNavKeyboardTest extends TestCase
{
    private string $jsDir;
    private string $templatesDir;

    protected function setUp(): void
    {
        $this->jsDir = __DIR__ . '/../../public/assets/js';
        $this->templatesDir = __DIR__ . '/../../templates';

        if (!is_dir($this->jsDir)) {
            $this->markTestSkipped('Dossier public/assets/js absent');
        }
        if (!is_dir($this->templatesDir)) {
            $this->markTestSkipped('Dossier templates absent');
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
     * Le panneau mobile doit demander explicitement la fermeture sur Echap
     * (config `hideOnEscape: true` passee a panel()).
     */
    public function testNavPanelEnablesHideOnEscape(): void
    {
        $content = $this->read($this->jsDir . '/main.js');

        $this->assertMatchesRegularExpression(
            '/hideOnEscape\s*:\s*true/i',
            $content,
            'main.js : le panneau de navigation mobile doit etre configure avec '
            . 'hideOnEscape:true (fermeture du menu via la touche Echap).'
        );
    }

    /**
     * util.js (mecanisme panel) doit reellement cabler un handler keydown qui
     * ferme le panneau sur la touche Echap (keyCode 27), sous condition
     * hideOnEscape. Sans ce cablage, hideOnEscape:true serait inerte.
     */
    public function testPanelMechanismBindsEscapeKeydown(): void
    {
        $content = $this->read($this->jsDir . '/util.js');

        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*config\.hideOnEscape\s*\)/i',
            $content,
            'util.js : panel() doit conditionner le handler de fermeture a '
            . 'config.hideOnEscape.'
        );
        $this->assertMatchesRegularExpression(
            '/\.on\(\s*[\'"]keydown[\'"]/i',
            $content,
            'util.js : panel() doit ecouter l\'evenement keydown (fermeture clavier).'
        );
        $this->assertMatchesRegularExpression(
            '/event\.keyCode\s*==?=?\s*27/',
            $content,
            'util.js : panel() doit fermer le panneau sur Echap (keyCode 27).'
        );
    }

    /**
     * Un piege a focus (focus trap) sur Tab doit exister dans main.js pour
     * empecher le focus de quitter le panneau ouvert au clavier.
     */
    public function testNavPanelHasTabFocusTrap(): void
    {
        $content = $this->read($this->jsDir . '/main.js');

        // Handler keydown dedie au piege a focus du panneau.
        $this->assertMatchesRegularExpression(
            '/on\(\s*[\'"]keydown[^\'"]*[\'"]/i',
            $content,
            'main.js : un handler keydown dedie doit gerer le piege a focus du menu mobile.'
        );
        // Detection de la touche Tab.
        $this->assertMatchesRegularExpression(
            '/key\s*!==?\s*[\'"]Tab[\'"]|event\.keyCode\s*!==?\s*9/',
            $content,
            'main.js : le piege a focus doit detecter la touche Tab.'
        );
        // Gestion Shift+Tab (sens arriere) et appels focus() de bouclage.
        $this->assertMatchesRegularExpression(
            '/event\.shiftKey/',
            $content,
            'main.js : le piege a focus doit gerer Shift+Tab (navigation arriere).'
        );
        $this->assertMatchesRegularExpression(
            '/(firstEl|lastEl)\.focus\(\)/',
            $content,
            'main.js : le piege a focus doit reboucler le focus (firstEl/lastEl.focus()).'
        );
    }

    /**
     * Le piege a focus doit ne s'activer que lorsque le panneau est visible
     * (sinon il capturerait le focus de toute la page).
     */
    public function testFocusTrapGuardedByPanelVisibility(): void
    {
        $content = $this->read($this->jsDir . '/main.js');

        $this->assertMatchesRegularExpression(
            '/!\$body\.hasClass\(\s*[\'"]is-navPanel-visible[\'"]\s*\)/',
            $content,
            'main.js : le piege a focus doit se desactiver quand le panneau est '
            . 'ferme (garde sur is-navPanel-visible).'
        );
    }

    /**
     * A l'ouverture clavier, le focus doit etre deplace dans le panneau
     * (premier lien). A la fermeture, il doit revenir au bouton declencheur.
     */
    public function testFocusMovesIntoPanelAndReturnsToToggle(): void
    {
        $content = $this->read($this->jsDir . '/main.js');

        // Focus envoye dans le panneau a l'ouverture.
        $this->assertMatchesRegularExpression(
            '/\$navPanelInner\b[\s\S]{0,400}?\.trigger\(\s*[\'"]focus[\'"]\s*\)/',
            $content,
            'main.js : a l\'ouverture, le focus doit etre place sur le premier '
            . 'lien du panneau (navPanelInner ... trigger("focus")).'
        );
        // Retour de focus au bouton sandwich a la fermeture.
        $this->assertMatchesRegularExpression(
            '/\$navPanelToggle\.trigger\(\s*[\'"]focus[\'"]\s*\)/',
            $content,
            'main.js : a la fermeture, le focus doit revenir au bouton menu '
            . '(navPanelToggle.trigger("focus")).'
        );
    }

    /**
     * Le bouton declencheur du menu mobile doit exposer les attributs ARIA
     * aria-expanded et aria-controls, et leur etat doit etre synchronise.
     */
    public function testToggleButtonHasAriaExpandedAndControls(): void
    {
        $content = $this->read($this->jsDir . '/main.js');

        $this->assertMatchesRegularExpression(
            '/id=["\']navPanelToggle["\'][^>]*aria-controls=["\']navPanel["\']/',
            $content,
            'main.js : le bouton #navPanelToggle doit porter aria-controls="navPanel".'
        );
        $this->assertMatchesRegularExpression(
            '/id=["\']navPanelToggle["\'][^>]*aria-expanded=["\']false["\']/',
            $content,
            'main.js : le bouton #navPanelToggle doit initialiser aria-expanded="false".'
        );
        // Synchronisation dynamique de aria-expanded a chaque ouverture/fermeture.
        $this->assertMatchesRegularExpression(
            '/attr\(\s*[\'"]aria-expanded[\'"]\s*,/',
            $content,
            'main.js : aria-expanded doit etre mis a jour dynamiquement '
            . '(synchronisation a l\'ouverture/fermeture).'
        );
    }

    /**
     * Le panneau mobile doit etre une region nommee (aria-label) contenant une
     * <nav> labellisee, et un bouton de fermeture accessible.
     */
    public function testNavPanelMarkupHasAccessibleNames(): void
    {
        $content = $this->read($this->jsDir . '/main.js');

        $this->assertMatchesRegularExpression(
            '/id=["\']navPanel["\'][^>]*aria-label=/',
            $content,
            'main.js : le conteneur #navPanel doit porter un aria-label.'
        );
        $this->assertMatchesRegularExpression(
            '/<nav[^>]*aria-label=/',
            $content,
            'main.js : la <nav> interne du panneau mobile doit porter un aria-label.'
        );
        $this->assertMatchesRegularExpression(
            '/class=["\']close["\'][^>]*aria-label=/',
            $content,
            'main.js : le bouton de fermeture du panneau doit porter un aria-label.'
        );
    }

    /**
     * Le lien d'evitement (skip-link) doit exister dans layout.twig, pointer
     * vers #main et rester focusable (c'est le premier element tabulable, donc
     * il ne doit pas etre rendu inerte via tabindex="-1").
     */
    public function testSkipLinkPresentAndFocusable(): void
    {
        $content = $this->read($this->templatesDir . '/layout.twig');

        $this->assertMatchesRegularExpression(
            '/<a\s+href=["\']#main["\'][^>]*class=["\'][^"\']*skip-to-content[^"\']*["\']/i',
            $content,
            'layout.twig : un lien d\'evitement (skip-link) vers #main '
            . '(classe skip-to-content) doit exister.'
        );

        // Le skip-link ne doit pas etre retire de l'ordre de tabulation.
        $this->assertDoesNotMatchRegularExpression(
            '/<a\s+href=["\']#main["\'][^>]*\btabindex=["\']-1["\']/i',
            $content,
            'layout.twig : le skip-link ne doit pas porter tabindex="-1" '
            . '(il doit rester focusable au clavier).'
        );

        // La cible #main doit exister pour que le skip-link soit utile.
        $this->assertMatchesRegularExpression(
            '/id=["\']main["\']/',
            $content,
            'layout.twig : la cible #main du skip-link doit exister.'
        );
    }
}
