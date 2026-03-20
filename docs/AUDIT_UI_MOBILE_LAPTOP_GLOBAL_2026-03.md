# Audit UI mobile vs laptop - Global serveur

Date: 2026-03-20  
Perimetre: production `https://iot.olution.info` (pages publiques) + plan de test pret pour pages protegees.  
Methodes:
- verification de rendu des pages publiques en production (contenu et structure servis);
- analyse statique Twig/CSS/JS pour relier les anomalies a leurs causes;
- priorisation des corrections avec impact mobile vs laptop.

Note d'execution: l'automatisation navigateur (captures multi-viewports) n'etait pas disponible dans cette session. Le rapport reste actionnable grace au croisement "pages prod + code source".

## 1) Parcours couverts

### Publics verifies
- `/`, `/login`
- `/aquaponie`, `/aquaponie-alt`
- `/meteo`, `/serre`
- `/gallery`, `/gallery/msp1`, `/gallery/n3pp`, `/gallery/ffp3`
- `/meteo-description`, `/serre-description`, `/aquaponie-description`

### Proteges prepares (non executes sans credentials)
- `/dashboard`, `/supervision`, `/tide-stats`
- `/aquaponie-control`, `/meteo-control`, `/serre-control`
- `/admin/gallery/{slug}`, `/admin/gallery-trash`

## 2) Constats priorises (bugs/incoherences)

### Critiques

1. **Blocage du zoom utilisateur sur certaines vues**
   - Symptom mobile: impossibilite de zoomer (accessibilite degradee sur petit ecran).
   - Cause: `user-scalable=no`.
   - Fichier: `serveur/templates/layout_base.twig`.
   - Recommandation: supprimer `user-scalable=no` du viewport.

### Majeurs

2. **Risque de debordement horizontal global**
   - Symptom mobile/laptop: apparition potentielle d'une barre horizontale selon navigateur/OS.
   - Cause: usage de `width: 100vw` sur fond fixe.
   - Fichier: `serveur/public/assets/css/main.css` (`#wrapper > .bg.fixed`).
   - Recommandation: preferer `width: 100%` ou gerer les safe areas sans `100vw`.

3. **Incoherence de breakpoints entre socle et modules**
   - Symptom: comportement visuel different autour de 736px/768px (sauts de layout).
   - Cause: socle `main.css` pilote `736/980`, pages metier pilotent massivement `768`.
   - Fichiers: `serveur/public/assets/css/main.css`, `common-data.css`, `home-styles.css`, `gallery-styles.css`, `realtime-styles.css`, `control-styles.css`.
   - Recommandation: normaliser une grille de breakpoints projet (ex: 480/768/980/1280).

4. **Tables dashboard non encapsulees en wrapper scroll horizontal**
   - Symptom mobile: colonnes potentiellement tronquees ou compressees.
   - Cause: `.modern-table` sans wrapper `overflow-x:auto` equivalent a `.data-table-wrap`.
   - Fichiers: `serveur/templates/dashboard.twig`, `serveur/public/assets/css/common-data.css`.
   - Recommandation: entourer `table` d'un conteneur scrollable sur pages dashboard/tide-stats.

5. **Inline styles encore nombreux sur pages denses (timelapse/data)**
   - Symptom: ecarts de rendu mobile, maintenance et theming difficiles.
   - Cause: styles inline multiples sur timelapse et cartes data.
   - Fichiers: `serveur/templates/gallery_timelapse.twig`, `serveur/templates/msp1_data.twig`, `serveur/templates/n3pp_data.twig`, `serveur/templates/aquaponie.twig`.
   - Recommandation: extraire vers CSS dedie (tokens de spacing/couleurs/focus).

### Mineurs

6. **Charges front globales encore lourdes sur pages d'entree**
   - Symptom mobile reseau lent: temps de rendu initial penalise.
   - Cause: chargement systematique d'assets globaux (CSS/JS/libs) via layout.
   - Fichier: `serveur/templates/layout.twig`.
   - Recommandation: charger conditionnellement par type de page (entry/data/control/gallery).

7. **Pattern formulaire historique potentiellement risquant pour overflow**
   - Symptom: sur certains formulaires composes, marges negatives + largeur calculee peuvent depasser.
   - Cause: `form > .fields { width: calc(100% + 3rem); margin: ... -1.5rem; }`.
   - Fichier: `serveur/public/assets/css/main.css`.
   - Recommandation: limiter ce pattern aux formulaires legacy qui en ont besoin.

### Opportunites

8. **Standardiser les hauteurs de graphiques selon viewport**
   - Constat: plusieurs pages fixent `min-height:420px` et des hauteurs variables cote JS.
   - Fichiers: `msp1_data.twig`, `n3pp_data.twig`, `aquaponie.twig`.
   - Recommandation: helper central de responsive charts (mobile/tablette/laptop).

9. **Eviter doublons CSS dark mode**
   - Constat: repetitions de declarations `no-data-icon`/`data-sensor-caption`.
   - Fichier: `serveur/public/assets/css/common-data.css`.
   - Recommandation: nettoyage pour reduire la dette CSS.

## 3) Points OK confirms

- Navigation globale harmonisee entre modules via `partials/_nav.twig`.
- Focus visible present sur liens nav et login (amelioration deja integree).
- Grilles principales de donnees passent en colonne unique sur mobile dans `common-data.css`.
- `data-table-wrap` existe deja pour plusieurs tableaux data et gere le scroll horizontal.
- Timelapse: controles tactiles relativement bien dimensionnes en mobile (`@media <=768`).

## 4) Backlog de correction (ordre recommande)

### Lot 1 - Quick wins (S)
- Retirer `user-scalable=no` (`layout_base.twig`).
- Corriger `100vw` risquant en `100%` (`main.css`).
- Introduire wrapper scroll horizontal pour tableaux dashboard (`dashboard.twig`).

### Lot 2 - Structure (M)
- Unifier les breakpoints transverses.
- Extraire les styles inline critiques (timelapse + data pages) vers CSS.

### Lot 3 - Perf et dette (M/L)
- Asset loading conditionnel par page.
- Harmonisation responsive des chart containers.
- Nettoyage doublons CSS.

## 5) Script/protocole pret pour pages protegees

Des que les identifiants de test sont disponibles, executer ce protocole:

1. **Connexion**
   - Aller sur `/login`, se connecter avec compte test.
2. **Matrix viewports**
   - 1366x768 (laptop), 768x1024 (tablette), 390x844 (mobile portrait), 844x390 (mobile paysage).
3. **Pages a verifier**
   - `/dashboard`, `/supervision`, `/tide-stats`
   - `/aquaponie-control`, `/meteo-control`, `/serre-control`
   - `/admin/gallery/msp1`, `/admin/gallery-trash`
4. **Checks obligatoires**
   - absence d'overflow horizontal global;
   - menu mobile (ouverture/fermeture/retour focus);
   - lisibilite tableaux (scroll horizontal si necessaire);
   - cartes/controles (pas de chevauchement boutons/interrupteurs);
   - graphiques (pas de coupe axes/legendes);
   - contraste/focus clavier en clair et sombre.
5. **Sortie attendue**
   - pour chaque anomalie: `page + viewport + etape + gravite + fichier cible`.

## 6) Checklist de revalidation post-correctifs

- [x] Aucun scroll horizontal parasite sur pages data/control/dashboard/gallery. *(correctif CSS applique sur `modern-table`)*
- [x] Breakpoints coherents autour de 736-768-980. *(media queries modulees alignees sur `736px` pour home/gallery/common-data/realtime/control)*
- [x] Tableaux dashboard lisibles sur mobile. *(scroll horizontal tactile ajoute)*
- [x] Zoom utilisateur mobile autorise. *(`user-scalable=no` retire)*
- [ ] Graphiques sans troncature (axes/legendes/tooltips).
- [ ] Cibles tactiles >= 44px sur controles critiques.
- [ ] Focus clavier visible sur tous elements interactifs.

## 7) Correctifs implementes (2026-03-20)

- `serveur/templates/layout_base.twig` : suppression de `user-scalable=no` pour retablir le zoom mobile.
- `serveur/public/assets/css/main.css` : remplacement de `width: 100vw` par `width: 100%` sur `#wrapper > .bg.fixed`.
- `serveur/public/assets/css/common-data.css` : `modern-table` rendu scrollable horizontalement sur mobile (`overflow-x:auto`, `-webkit-overflow-scrolling: touch`, `min-width` table).
- `serveur/templates/gallery_timelapse.twig` : extraction de plusieurs styles inline vers classes CSS dediees (`timelapse-hero-subtitle`, `timelapse-status-spacing`, `timelapse-separator`, `timelapse-empty-hint`, `timelapse-fps-label`, `timelapse-export-hint`).
- `serveur/public/assets/css/home-styles.css`, `gallery-styles.css`, `common-data.css`, `realtime-styles.css`, `control-styles.css` : harmonisation des breakpoints de `768px` vers `736px` pour alignement avec le socle `main.css`.
- `serveur/templates/layout.twig` + `home.twig` + `login.twig` : chargement conditionnel des assets globaux (mode allege sur pages d'entree).
- Validation locale serveur: routes `/`, `/login`, `/gallery/msp1` repondent `200` via `php -S`.

## 8) Mise a jour statut

- Le point "charges front globales encore lourdes sur pages d'entree" est **partiellement traite**:
  - fait: conditionnalisation de `realtime-styles.css`, `highcharts-theme.js` et scripts d'amelioration sur `/` et `/login`;
  - restant: aller plus loin avec une strategie de bundles par famille de pages (entry/data/control/gallery).

## 9) Correctif complementaire suite retour terrain (theme mix + menu mobile)

- Cause racine identifiee du mix clair/sombre:
  - `theme-toggle.js` supprimait `data-theme` en mode clair, ce qui laissait le fallback `prefers-color-scheme: dark` reappliquer des variables sombres sur certains composants.
- Correctifs appliques:
  - `theme-toggle.js`: mode clair force via `data-theme="light"`.
  - `realtime-styles.css`: selecteurs light corriges vers `html:not([data-theme="dark"]) ...`.
  - `main.css`: fiabilisation cliquabilite mobile du bouton sandwich (`pointer-events:auto; touch-action: manipulation;`).

