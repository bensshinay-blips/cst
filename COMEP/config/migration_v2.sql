-- ============================================================
-- MIGRATION V2 : Système de barèmes par classe
-- À exécuter dans phpMyAdmin dans l'ordre
-- ============================================================

-- 1. Créer la nouvelle table classe_matieres
CREATE TABLE IF NOT EXISTS `classe_matieres` (
  `id`             INT NOT NULL AUTO_INCREMENT,
  `classe_id`      INT NOT NULL,
  `matiere_id`     INT NOT NULL,
  `bareme`         INT NOT NULL DEFAULT 100,
  `annee_scolaire` VARCHAR(20) NOT NULL,
  `created_at`     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_classe_matiere_annee` (`classe_id`, `matiere_id`, `annee_scolaire`),
  KEY `idx_classe_annee` (`classe_id`, `annee_scolaire`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4;

-- 2. Supprimer le coefficient et bareme de la table matieres
--    (si la colonne bareme existe déjà depuis migration v1)
ALTER TABLE `matieres` 
  DROP COLUMN IF EXISTS `coefficient`,
  DROP COLUMN IF EXISTS `bareme`;

-- 3. Vérifier la structure finale de matieres
-- SELECT id, nom, code FROM matieres;

-- 4. Exemple : ajouter des matières pour la classe 7èm A (id=1)
--    pour l'année 2024-2025
--    (à adapter selon vos matières réelles)
-- INSERT INTO classe_matieres (classe_id, matiere_id, bareme, annee_scolaire) VALUES
-- (1, 1, 200, '2024-2025'),   -- Algèbre /200
-- (1, 2, 100, '2024-2025'),   -- Musique /100
-- (1, 3, 300, '2024-2025');   -- Chimie  /300

-- 5. Vérifier
-- SELECT cm.*, c.nom AS classe, m.nom AS matiere
-- FROM classe_matieres cm
-- JOIN classes c ON cm.classe_id = c.id
-- JOIN matieres m ON cm.matiere_id = m.id;
