-- =====================================================================
-- Extension de la table `navPages` (cf. 2026_07_nav_pages.sql) :
--   - `admin-users` : lien « Utilisateurs » du menu, désormais piloté par un
--     switch de supervision (et filtré par la permission canManageUsers).
--   - `gallery-<slug>` / `gallery-control-<slug>` : pilotent l'affichage des
--     galeries et de leur lien de contrôle caméra sur la page `/gallery`
--     (PAS dans le menu de navigation ; les clés `gallery-*` en sont exclues).
--
-- Ces lignes sont insérées ACTIVES pour préserver le comportement existant
-- (lien Utilisateurs visible pour les gestionnaires, galeries affichées).
-- Idempotent : ON DUPLICATE KEY UPDATE conserve l'état courant.
-- =====================================================================

INSERT INTO `navPages` (`page_key`, `label`, `url`, `active`, `sort_order`) VALUES
    ('admin-users',          'Utilisateurs',              '/admin/users',          1, 200),
    ('gallery-msp1',         'Galerie potager',           '/gallery/msp1',         1, 900),
    ('gallery-n3pp',         'Galerie élevage',           '/gallery/n3pp',         1, 910),
    ('gallery-ffp3',         'Galerie FFP3',              '/gallery/ffp3',         1, 920),
    ('gallery-control-msp1', 'Contrôle caméra potager',   '/gallery/msp1/control', 1, 930),
    ('gallery-control-n3pp', 'Contrôle caméra élevage',   '/gallery/n3pp/control', 1, 940),
    ('gallery-control-ffp3', 'Contrôle caméra FFP3',      '/gallery/ffp3/control', 1, 950)
ON DUPLICATE KEY UPDATE `page_key` = `page_key`;
