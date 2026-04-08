-- ============================================================
-- MIGRATION V3 : Table de contrôle des périodes de saisie
-- À exécuter dans phpMyAdmin
-- ============================================================

CREATE TABLE IF NOT EXISTS `periodes_saisie` (
  `id`           INT NOT NULL AUTO_INCREMENT,
  `classe_id`    INT NOT NULL,
  `controle_id`  INT NOT NULL,
  `statut`       ENUM('ouvert','ferme') NOT NULL DEFAULT 'ferme',
  `ouvert_le`    DATETIME DEFAULT NULL,
  `ferme_le`     DATETIME DEFAULT NULL,
  `ouvert_par`   INT DEFAULT NULL,
  `ferme_par`    INT DEFAULT NULL,
  `note_admin`   VARCHAR(255) DEFAULT NULL,
  `created_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_classe_controle` (`classe_id`, `controle_id`),
  KEY `idx_statut` (`statut`),
  KEY `idx_classe` (`classe_id`),
  KEY `idx_controle` (`controle_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4;

-- Par défaut tout est FERMÉ
-- L'admin ouvre manuellement quand il est prêt

-- Pré-remplir pour toutes les combinaisons classes x contrôles existantes
INSERT IGNORE INTO `periodes_saisie` (classe_id, controle_id, statut)
SELECT c.id, ctrl.id, 'ferme'
FROM classes c
CROSS JOIN controles ctrl;

-- Vérifier
-- SELECT ps.*, c.nom AS classe, ctrl.nom AS controle
-- FROM periodes_saisie ps
-- JOIN classes c ON ps.classe_id = c.id
-- JOIN controles ctrl ON ps.controle_id = ctrl.id
-- ORDER BY ctrl.numero, c.nom;
