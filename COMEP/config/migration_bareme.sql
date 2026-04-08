-- ============================================================
-- MIGRATION : Ajout du barème par matière
-- À exécuter dans phpMyAdmin avant de déployer les nouveaux fichiers
-- ============================================================

-- 1. Ajouter la colonne bareme à la table matieres
ALTER TABLE `matieres` 
ADD COLUMN `bareme` INT NOT NULL DEFAULT 20 AFTER `coefficient`;

-- 2. Toutes les matières existantes restent sur /20 par défaut
UPDATE `matieres` SET `bareme` = 20;

-- 3. Vérifier le résultat
SELECT id, nom, code, coefficient, bareme FROM `matieres`;
