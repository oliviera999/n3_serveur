# Audit UI serveur - Accueil, Login, Navigation globale

Date: 2026-03-20
Perimetre: `/`, `/login`, `layout.twig`, `partials/_nav.twig`, styles/scripts transverses charges sur ces pages.
Methode: audit statique code (Twig/CSS/JS) + analyse UX/UI/a11y/responsive/performance.

## 1) Grille d'audit et bareme

### Axes et poids

| Axe | Poids | Criteres |
|---|---:|---|
| UX parcours | 25% | Clarte des actions principales, charge cognitive, feedback utilisateur |
| UI coherence | 20% | Coherence composants, hierarchie, espacement, lisibilite |
| Accessibilite | 25% | Focus visible, clavier, ARIA, semantique, contrastes |
| Responsive | 15% | Comportement mobile/tablette, navigation, stabilite |
| Performance front | 15% | Poids assets, ordre de chargement, scripts globaux |

### Niveaux de severite

| Niveau | Definition |
|---|---|
| Critique | Bloquant UX/a11y, risque fort d'erreur utilisateur ou non-conformite importante |
| Majeur | Impact significatif sur ergonomie, accessibilite, ou maintenabilite |
| Mineur | Defaut local avec impact limite |
| Opportunite | Amelioration qualitative sans urgence |

### Score synthetique (etat actuel)

| Axe | Score / 100 | Commentaire |
|---|---:|---|
| UX parcours | 78 | Parcours globalement clair, mais quelques incoherences d'interactions |
| UI coherence | 72 | Bonne base visuelle, ecarts de conventions (classes/inline styles) |
| Accessibilite | 62 | Socle present, mais points majeurs sur focus et menu mobile |
| Responsive | 80 | Structure mobile existante, a securiser cote clavier/menu |
| Performance front | 58 | Sur-chargement global des pages d'entree |
| **Score global pondere** | **70** | **Base solide, priorites claires sur a11y/perf** |

## 2) Constat detaille avec preuves

### Critiques

1. **Attribut `class` duplique sur plusieurs liens de l'accueil**
   - Fichiers: `serveur/templates/home.twig`
   - Preuve: liens avec deux attributs `class` sur le meme element (`project-link ...` puis `class="project-link-spaced"`).
   - Impact: comportement HTML ambigu (perte possible de classes), style/focus non fiable sur CTAs critiques.
   - Recommandation: fusionner en un seul attribut `class` par lien.
   - Effort: S

2. **Menu mobile declare comme dialogue sans mecanisme de dialogue complet**
   - Fichiers: `serveur/public/assets/js/main.js`, `serveur/public/assets/js/util.js`
   - Preuve: `#navPanel` utilise `role="dialog"` mais pas de focus trap dedie, ni fermeture Escape explicite via config `hideOnEscape`.
   - Impact: navigation clavier/screen reader fragile sur mobile; experience possiblement confuse.
   - Recommandation: soit passer en semantique navigation/drawer non-dialogue, soit implementer un vrai pattern dialogue accessible (focus trap, Escape, retour focus robuste).
   - Effort: M

### Majeurs

3. **Suppression explicite de l'indicateur de focus sur les liens du menu principal**
   - Fichier: `serveur/public/assets/css/main.css`
   - Preuve: `#nav ul.links li a { outline: none; }` sans style de remplacement `:focus-visible`.
   - Impact: perte de reperes clavier, non-conformite a11y probable.
   - Recommandation: restaurer un style `:focus-visible` fort (outline + offset) coherent theme clair/sombre.
   - Effort: S

4. **Formulaire de login: focus masque sur inputs et pas d'etat focus-visible explicite sur bouton**
   - Fichier: `serveur/public/assets/css/login-styles.css`
   - Preuve: `.form-group input:focus { outline: none; ... }`, pas de variante `:focus-visible` dediee; bouton `.btn-login` sans style focus explicite.
   - Impact: experience clavier degradee, feedback de focus insuffisant selon contextes navigateur/OS.
   - Recommandation: separer `:focus` et `:focus-visible`, ajouter un focus ring net sur le bouton.
   - Effort: S

5. **Styles inline sur login**
   - Fichier: `serveur/templates/login.twig`
   - Preuve: `style="display:none;"` et styles inline sur lien retour.
   - Impact: incoherence de design system, maintenance plus couteuse, theming moins fiable.
   - Recommandation: deplacer vers `login-styles.css` avec classes dediees.
   - Effort: S

6. **Chargement front global trop lourd sur pages d'entree**
   - Fichiers: `serveur/templates/layout.twig`, `serveur/public/assets/css/*`, `serveur/public/assets/js/*`
   - Preuve: `main.css` ~87 KB + `realtime-styles.css` ~43 KB + libs globales (jQuery ~87 KB, util/main, etc.) sur login/accueil.
   - Impact: temps de rendu initial et interactivite penalises, surtout mobile/reseau limite.
   - Recommandation: charger conditionnellement (par page), extraire bundle "entry pages" minimal.
   - Effort: M/L

### Mineurs

7. **Expression Twig complexe pour route Aquaponie dans la nav**
   - Fichier: `serveur/templates/partials/_nav.twig`
   - Preuve: ternaire imbrique pour `test/test3/s3/prod` dans l'attribut `href`.
   - Impact: lisibilite et risque de regression a la prochaine variante d'environnement.
   - Recommandation: precalculer l'URL cible dans le controleur ou variable Twig dediee.
   - Effort: S

8. **Incoherence de comportement des liens externes (ouverture onglet)**
   - Fichier: `serveur/templates/home.twig`
   - Preuve: certains liens externes avec `target="_blank" rel="noopener noreferrer"`, d'autres sans `target`.
   - Impact: comportement non uniforme pour l'utilisateur.
   - Recommandation: definir une convention UX (meme onglet ou nouvel onglet) et l'appliquer partout.
   - Effort: S

### Opportunites

9. **Poll live sur accueil non adapte a la visibilite onglet**
   - Fichier: `serveur/templates/home.twig` (script inline)
   - Preuve: `setInterval(..., 15000)` permanent sans pause sur onglet inactif.
   - Impact: trafic/API et cout CPU evitable.
   - Recommandation: suspendre/reprendre selon `document.visibilityState`.
   - Effort: S/M

10. **Absence de tests UI automatiques sur le perimetre d'entree**
    - Perimetre: `serveur/tests` (pas de E2E/visuel dedie accueil/login/nav)
    - Impact: regressions UI/a11y detectees tardivement.
    - Recommandation: ajouter checks minimaux (smoke E2E + a11y clavier + snapshots ciblés).
    - Effort: M

## 3) Backlog priorise par lots

### Lot 1 - Quick wins (S)

| Action | Impact | Effort |
|---|---|---|
| Corriger les `class` dupliques dans `home.twig` | Stabilite UI immediate | S |
| Restaurer focus visible menu principal (`main.css`) | Accessibilite critique | S |
| Ajouter `:focus-visible` inputs/bouton login | Accessibilite formulaire | S |
| Supprimer styles inline login (classes CSS) | Coherence design system | S |
| Normaliser convention liens externes | Consistance UX | S |

### Lot 2 - Correctifs structurels (M)

| Action | Impact | Effort |
|---|---|---|
| Refactorer pattern menu mobile (drawer accessible) | UX mobile + a11y clavier | M |
| Simplifier logique d'URL nav (environnement) | Maintenabilite | M |
| Extraire script live accueil vers fichier JS dedie | Lisibilite + testabilite | M |

### Lot 3 - Optimisations (M/L)

| Action | Impact | Effort |
|---|---|---|
| Charger CSS/JS conditionnellement par page | Performance percue | M/L |
| Introduire base de tests UI (smoke + a11y) | Non-regression durable | M |
| Rationaliser dependances legacy globales | Dette technique front | L |

## 4) Plan de validation post-corrections

1. Parcours clavier complet (`Tab`/`Shift+Tab`/`Enter`/`Escape`) sur `/` et `/login`.
2. Verification claire/sombre de tous les etats focus, hover, actif.
3. Verification mobile (<= 736px) du menu et de son retour focus.
4. Controle des CTA d'accueil (styles, espacement, comportements identiques).
5. Mesure comparative poids/chargement avant/apres (entry pages).

## 5) Conclusion

L'interface d'entree du serveur dispose deja d'un socle propre (theme variables, dark mode, skip link, navigation commune), mais les priorites se concentrent sur trois sujets: **focus clavier**, **robustesse du menu mobile**, et **reduction du chargement global**. Les quick wins du Lot 1 peuvent deja faire monter significativement la qualite percue et l'accessibilite.

## 6) Mise en oeuvre realisee (2026-03-20)

- Correction des attributs `class` dupliques sur les CTA de `home.twig`.
- Uniformisation des liens externes de la section "Liens utiles" (`target="_blank"` + `rel="noopener noreferrer"`).
- Renforcement du focus clavier sur la navigation principale (`main.css`) et sur le formulaire login (`login-styles.css`).
- Suppression des styles inline dans `login.twig` au profit de classes CSS dediees.
- Simplification de la logique d'URL Aquaponie dans `partials/_nav.twig`.
- Reduction du polling inutile sur l'accueil (pause/reprise selon `document.visibilityState`).
- Ajustement du panneau nav mobile (`main.js`) avec activation de `hideOnEscape` et semantique nav mobile explicite.

## 7) Statut actuel (fait vs restant)

### Correctifs implémentés (confirmés)

- Classes dupliquées supprimées sur les CTA d'accueil.
- Focus clavier renforcé sur la navigation principale et le formulaire de login.
- Styles inline retirés de la page login au profit de classes CSS.
- Convention des liens externes harmonisée dans la section "Liens utiles".
- Polling de l'accueil adapté à la visibilité de l'onglet.
- Comportement du menu mobile amélioré (dont fermeture via `Escape`).

### Points restant à traiter ou à valider

- Vérifier en test clavier réel la robustesse complète du menu mobile sur tous navigateurs cibles.
- Mettre en place le chargement conditionnel des assets pour réduire le poids des pages d'entrée.
- Ajouter une base de tests UI non-régression (smoke + accessibilité clavier) sur accueil/login/nav.
