# Plan de test manuel - Clavier & menu mobile multi-navigateurs

Objet : valider, **en navigateur reel**, la robustesse clavier du menu mobile
(drawer `#navPanel`). Ce plan complete les garde-fous STATIQUES automatises de
`tests/Ui/MobileNavKeyboardTest.php` (qui verrouillent la *presence* des
affordances dans le code mais ne peuvent pas prouver le *comportement* runtime).

Reference audit : `docs/AUDIT_UI_ACCUEIL_LOGIN_NAV.md`
- item 2 (« menu mobile declare comme dialogue... »),
- section 7, point restant : « Verifier en test clavier reel la robustesse
  complete du menu mobile sur tous navigateurs cibles ».

> IMPORTANT : ce volet n'est PAS automatisable dans l'environnement CI actuel
> (aucune automatisation navigateur). Il doit etre execute a la main avant toute
> livraison touchant `layout.twig`, `partials/_nav.twig`, `main.js` ou `util.js`.

## 1) Pre-requis

- Largeur viewport <= 980px pour faire apparaitre la barre mobile et le drawer
  (`#nav` desktop masquee en CSS <= 980px ; bascule de contenu a <= medium / 736-980px).
- Tester en theme clair ET sombre.
- Clavier physique branche (pour iOS/Android : clavier Bluetooth) afin de tester
  un vrai parcours Tab.
- Pages cibles : `/` (accueil) et `/login`.

## 2) Affordances implementees (referencees par les tests statiques)

| Affordance | Source | Test statique |
|---|---|---|
| Fermeture sur Echap (`hideOnEscape:true` + handler keydown keyCode 27) | `main.js`, `util.js` | `testNavPanelEnablesHideOnEscape`, `testPanelMechanismBindsEscapeKeydown` |
| Piege a focus Tab / Shift+Tab (bouclage first/last) | `main.js` | `testNavPanelHasTabFocusTrap` |
| Piege a focus inactif quand panneau ferme | `main.js` | `testFocusTrapGuardedByPanelVisibility` |
| Focus deplace dans le panneau a l'ouverture | `main.js` | `testFocusMovesIntoPanelAndReturnsToToggle` |
| Retour de focus au bouton menu a la fermeture | `main.js` | `testFocusMovesIntoPanelAndReturnsToToggle` |
| `aria-expanded` / `aria-controls` sur le bouton + synchro | `main.js` | `testToggleButtonHasAriaExpandedAndControls` |
| Noms accessibles (aria-label panneau, nav, bouton fermer) | `main.js` | `testNavPanelMarkupHasAccessibleNames` |
| Skip-link focusable vers #main | `layout.twig` | `testSkipLinkPresentAndFocusable` |

## 3) Navigateurs cibles

| ID | Navigateur | Plateforme | Notes |
|---|---|---|---|
| CHR | Chrome (derniere stable) | Windows/macOS/Linux desktop | moteur Blink |
| FF  | Firefox (derniere stable) | desktop | moteur Gecko (gestion `:focus-visible` differente) |
| SAF | Safari (derniere stable) | macOS desktop | moteur WebKit ; verifier l'option « Tab met en surbrillance chaque element » des Preferences |
| IOS | Safari | iOS (iPhone) + clavier Bluetooth | WebKit mobile ; gestion focus/visibilite specifique |
| AND | Chrome | Android + clavier Bluetooth | Blink mobile |

## 4) Cas de test clavier

Pour chaque cas, executer sur `/` puis `/login`, en clair puis sombre.

| # | Cas | Etapes | Critere de reussite |
|---|---|---|---|
| C1 | Skip-link | Charger la page, appuyer sur Tab une fois | Le lien « Aller au contenu » devient visible et a un focus net ; Entree deplace le focus dans `#main` |
| C2 | Ordre de tabulation page | Tabuler du haut vers le bas | Ordre logique (skip-link -> bouton theme barre mobile -> bouton menu -> contenu) ; aucun piege hors menu ferme |
| C3 | Ouverture menu au clavier | Focus sur le bouton menu, Entree (puis tester Espace) | Le drawer s'ouvre ; `aria-expanded` passe a `true` ; le focus arrive sur le 1er lien du panneau |
| C4 | Tabulation dans le menu ouvert | Tab plusieurs fois | Le focus reste dans le panneau et boucle du dernier au premier element |
| C5 | Shift+Tab dans le menu | Depuis le 1er element, Shift+Tab | Le focus boucle vers le dernier element (ne sort pas du panneau) |
| C6 | Fermeture via Echap | Menu ouvert, touche Echap | Le drawer se ferme ; `aria-expanded` repasse a `false` ; le focus revient sur le bouton menu |
| C7 | Fermeture via bouton « Fermer » | Tabuler jusqu'au bouton « Fermer le menu », Entree | Le drawer se ferme ; focus restitue au bouton menu |
| C8 | Activation d'un lien au clavier | Menu ouvert, Tab jusqu'a un lien, Entree | Navigation vers la cible ; le menu se ferme (hideOnClick) |
| C9 | Retour de focus apres fermeture overlay | Fermer en cliquant l'overlay (souris) apres ouverture clavier | `aria-expanded` synchronise a `false` ; focus restitue au bouton |
| C10 | Re-ouverture / etat coherent | Ouvrir, fermer, rouvrir plusieurs fois | Pas d'etat incoherent ; `aria-expanded` toujours coherent avec l'etat visuel |
| C11 | Bascule breakpoint | Ouvrir le menu, agrandir la fenetre au-dela de 980px | Le panneau se ferme proprement, la nav desktop reapparait, pas de focus perdu/piege |
| C12 | Focus visible | Sur chaque element focusable du menu | Indicateur de focus visible et contraste suffisant (clair ET sombre) |

## 5) Matrice navigateurs x cas (a remplir)

Renseigner : `OK` / `KO (detail)` / `N/A`.

| Cas \ Nav | CHR | FF | SAF | IOS | AND |
|---|---|---|---|---|---|
| C1 Skip-link | | | | | |
| C2 Ordre Tab | | | | | |
| C3 Ouverture clavier | | | | | |
| C4 Tab boucle | | | | | |
| C5 Shift+Tab boucle | | | | | |
| C6 Echap + retour focus | | | | | |
| C7 Bouton fermer | | | | | |
| C8 Activation lien | | | | | |
| C9 Fermeture overlay | | | | | |
| C10 Etat coherent | | | | | |
| C11 Bascule breakpoint | | | | | |
| C12 Focus visible | | | | | |

## 6) Points d'attention specifiques par navigateur

- **Safari desktop (SAF)** : par defaut, Tab ne cible que les champs de
  formulaire et les liens selon les reglages systeme. Activer « Appuyer sur Tab
  pour mettre en surbrillance chaque element de la page web » (Reglages Safari >
  Avance) avant le test, sinon C2/C3 peuvent sembler en echec a tort.
- **iOS Safari (IOS)** : tester avec clavier Bluetooth ; verifier que Echap (si
  present sur le clavier) ou la fermeture tactile restituent bien le focus.
- **Firefox (FF)** : verifier que `:focus-visible` produit un anneau visible
  (Gecko applique parfois des heuristiques differentes de Blink).
- **Android Chrome (AND)** : avec clavier Bluetooth, valider le bouclage du
  focus (C4/C5), souvent plus fragile sur mobile.

## 7) Limites honnetes - ce qui exige un vrai navigateur

Les tests statiques NE PROUVENT PAS, et seul ce plan manuel peut valider :

- le **comportement runtime reel** du piege a focus (l'ordre DOM effectif des
  elements focusables, le bouclage first/last) ;
- la **restitution effective du focus** au bouton menu apres Echap / clic
  overlay / clic lien (depend du timing `requestAnimationFrame` et du
  `MutationObserver`) ;
- la **mise a jour effective de `aria-expanded`** telle que percue par un
  lecteur d'ecran ;
- les **divergences de moteurs** (Blink/Gecko/WebKit) sur `:focus-visible`, la
  capture clavier, la propagation Echap ;
- le **comportement clavier mobile** (iOS/Android + clavier externe), non
  reproductible sans appareil/emulateur ;
- la **visibilite et le contraste** de l'indicateur de focus en clair/sombre.

## 8) Resultat

- [ ] GO (tous cas OK sur tous navigateurs cibles)
- [ ] GO avec reserve (preciser)
- [ ] NO GO

Notes :
- Date :
- Build / commit teste :
- Testeur :
- Anomalies constatees :
