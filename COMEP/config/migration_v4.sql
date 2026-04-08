-- ============================================================
-- MIGRATION V4 : Portail élève + rôle econmat
-- À exécuter dans phpMyAdmin dans l'ordre
-- ============================================================

-- 1. Ajouter le rôle econmat dans la table utilisateurs
ALTER TABLE `utilisateurs`
MODIFY COLUMN `role` ENUM('admin','professeur','econmat') NOT NULL DEFAULT 'professeur';

-- 2. Créer la table acces_bulletins
CREATE TABLE IF NOT EXISTS `acces_bulletins` (
  `id`             INT NOT NULL AUTO_INCREMENT,
  `eleve_id`       INT NOT NULL,
  `annee_scolaire` VARCHAR(20) NOT NULL,
  `acces`          ENUM('autorise','bloque') NOT NULL DEFAULT 'bloque',
  `motif_blocage`  VARCHAR(255) DEFAULT 'Frais scolaires impayés',
  `autorise_par`   INT DEFAULT NULL,
  `autorise_le`    DATETIME DEFAULT NULL,
  `bloque_par`     INT DEFAULT NULL,
  `bloque_le`      DATETIME DEFAULT NULL,
  `created_at`     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_eleve_annee` (`eleve_id`, `annee_scolaire`),
  KEY `idx_acces`  (`acces`),
  KEY `idx_eleve`  (`eleve_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4;

-- 3. Pré-remplir toutes les lignes à BLOQUÉ pour tous les élèves actifs
INSERT IGNORE INTO `acces_bulletins` (eleve_id, annee_scolaire, acces)
SELECT id, annee_scolaire, 'bloque'
FROM eleves
WHERE status = 'actif';

-- 4. Créer le compte econmat par défaut
INSERT INTO `utilisateurs`
    (nom, prenom, email, password, role, telephone, status)
VALUES
    ('Econmat', 'COMEP', 'econmat@comep.edu',
     '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
     'econmat', NULL, 'actif');
-- Mot de passe par défaut : password
-- CHANGEZ-LE IMMÉDIATEMENT après connexion !

-- 5. Vérifier
-- SELECT * FROM acces_bulletins LIMIT 10;
-- SELECT id, nom, prenom, email, role FROM utilisateurs WHERE role='econmat';
