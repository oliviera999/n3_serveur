# Checklist QA UI - Entrees serveur (Accueil/Login/Nav)

Usage: validation manuelle rapide apres toute modification touchant `layout`, `home`, `login` ou la navigation.
Perimetre: `/`, `/login`, menu principal desktop/mobile, theme clair/sombre.

## 0) Statut de reference des correctifs

Correctifs deja documentes comme implementes (a revalider a chaque regression): focus nav/login, suppression des styles inline login, correction des classes dupliquees sur l'accueil, harmonisation des liens externes, adaptation du polling selon `document.visibilityState`, fermeture menu mobile via `Escape`.

Correctifs restant en backlog: optimisation du chargement conditionnel CSS/JS sur pages d'entree, couverture de tests UI automatiques (smoke + a11y clavier), validation multi-navigateurs du pattern menu mobile.

## 1) Navigation et parcours

- [ ] Le logo renvoie bien vers l'accueil.
- [ ] Les liens principaux du menu sont visibles et cliquables.
- [ ] L'item de navigation actif est coherent avec la page courante.
- [ ] Le lien "Retour a l'accueil" dans `/login` fonctionne.
- [ ] Aucun lien important n'est masque/inactif sans raison.

## 2) Accessibilite clavier

- [ ] Le lien "Aller au contenu" apparait au focus et fonctionne.
- [ ] Tous les liens du menu ont un focus visible net.
- [ ] Tous les champs du formulaire login ont un focus visible net.
- [ ] Le bouton "Se connecter" a un focus visible net.
- [ ] L'ordre de tabulation est logique sur `/` puis `/login`.
- [ ] Sur mobile, l'ouverture/fermeture du menu est utilisable au clavier.
- [ ] La fermeture du menu mobile restitue le focus au bouton menu.

## 3) Accessibilite semantique

- [ ] Les labels du formulaire login sont correctement associes aux champs.
- [ ] Les attributs ARIA des composants interactifs restent pertinents.
- [ ] Les icones decoratives ont `aria-hidden` quand necessaire.
- [ ] Les liens externes respectent la convention projet (meme onglet ou nouvel onglet, de maniere uniforme).

## 4) Coherence visuelle UI

- [ ] Les CTAs de l'accueil ont des styles cohérents (boutons primaires/secondaires).
- [ ] Aucun element n'a de style casse (espacement, couleur, typo).
- [ ] Les cartes projets gardent la meme structure visuelle.
- [ ] Le mode sombre conserve une lisibilite correcte sur titres, textes, badges, boutons.
- [ ] Aucun style inline non justifie n'a ete ajoute.

## 5) Responsive

- [ ] Verification desktop (> 980px): nav horizontale lisible.
- [ ] Verification tablette (737px-980px): alignements et marges corrects.
- [ ] Verification mobile (<= 736px): menu drawer lisible et manipulable.
- [ ] Aucun chevauchement de contenu sur `/` et `/login`.
- [ ] Les boutons CTA restent utilisables tactilement (taille suffisante).

## 6) Performance front (controle rapide)

- [ ] Pas de nouvel asset global lourd charge inutilement sur `/` ou `/login`.
- [ ] Les scripts executes sur l'accueil sont justifies et limites.
- [ ] Le formulaire login reste reactif (pas de blocage visible).
- [ ] Le basculement clair/sombre est instantane et sans clignotement notable.

## 7) Non-regression fonctionnelle

- [ ] Connexion valide: redirection attendue.
- [ ] Connexion invalide: message d'erreur visible et comprehensible.
- [ ] Le menu principal reste fonctionnel apres bascule de theme.
- [ ] Le footer affiche correctement les liens de base.

## 8) Resultat QA

- [ ] GO
- [ ] GO avec reserve (preciser)
- [ ] NO GO (corriger avant livraison)

Notes:
- Date:
- Environnement teste:
- Testeur:
- Anomalies constatees:
