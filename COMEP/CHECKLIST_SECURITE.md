# ============================================================
# CHECKLIST SÉCURITÉ — AVANT MISE EN LIGNE
# Système de Gestion Scolaire CST
# ============================================================
# Cochez chaque point avant de mettre le site en production.
# ============================================================

## ✅ ÉTAPE 1 — Base de données

[ ] Exécuter migration_v2.sql dans phpMyAdmin
[ ] Exécuter migration_v3.sql dans phpMyAdmin
[ ] Exécuter migration_v4.sql dans phpMyAdmin
[ ] Changer les mots de passe en clair :
    → UPDATE utilisateurs SET password = '$2y$10$...' WHERE id = 1;
    (utiliser password_hash() pour générer le hash)
[ ] Créer un utilisateur MySQL dédié (pas "root") avec
    seulement les droits SELECT, INSERT, UPDATE, DELETE
    (PAS les droits DROP, CREATE, ALTER en production)
[ ] Supprimer ou désactiver phpmyadmin en production si possible

## ✅ ÉTAPE 2 — Fichier config/database.php

[ ] MODE_PRODUCTION = true
[ ] DB_USER = utilisateur MySQL dédié (pas root)
[ ] DB_PASS = mot de passe fort (min 16 caractères)
[ ] Vérifier que ANNEE_SCOLAIRE est correcte

## ✅ ÉTAPE 3 — Mots de passe des comptes

[ ] Changer le mot de passe admin (admin@cst.edu)
    → Se connecter → Menu → Mon Profil & Sécurité
[ ] Changer le mot de passe économat (econmat@cst.edu)
    → Admin → Mon Profil & Sécurité → Section Économat
[ ] Informer chaque professeur de changer son mot de passe

## ✅ ÉTAPE 4 — Fichiers .htaccess

[ ] Vérifier que .htaccess est bien à la racine (public_html/)
[ ] Vérifier que config/.htaccess existe
[ ] Vérifier que includes/.htaccess existe
[ ] Tester : aller sur https://votre-site.com/config/database.php
    → Doit afficher "Forbidden" (403), PAS le contenu du fichier

## ✅ ÉTAPE 5 — SSL/HTTPS

[ ] Installer un certificat SSL (gratuit avec Let's Encrypt
    via cPanel → SSL/TLS → Let's Encrypt)
[ ] Une fois SSL installé, mettre dans includes/auth.php :
    ini_set('session.cookie_secure', 1);  // ligne ~12
[ ] Forcer HTTPS dans .htaccess (ajouter ces lignes) :
    RewriteEngine On
    RewriteCond %{HTTPS} off
    RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]

## ✅ ÉTAPE 6 — Permissions des dossiers (FTP/cPanel)

[ ] Dossiers : permission 755 (rwxr-xr-x)
[ ] Fichiers PHP : permission 644 (rw-r--r--)
[ ] config/database.php : permission 600 (rw-------)
    → Seulement le propriétaire peut lire

## ✅ ÉTAPE 7 — Supprimer les fichiers inutiles

[ ] Supprimer tous les fichiers .zip du serveur
[ ] Supprimer les fichiers de migration SQL après exécution
    (ou les garder hors du public_html)
[ ] Supprimer ce fichier CHECKLIST_SECURITE.md du serveur

## ✅ ÉTAPE 8 — Tests finaux

[ ] Tester la connexion admin
[ ] Tester la connexion professeur
[ ] Tester la connexion économat
[ ] Tester le portail élève
[ ] Tester que 5 mauvais mots de passe bloquent la connexion
[ ] Tester qu'on ne peut pas accéder à /admin/ sans être connecté
[ ] Tester qu'un professeur ne peut pas accéder à /admin/
[ ] Vérifier que les erreurs PHP ne s'affichent pas

## ✅ ÉTAPE 9 — Sauvegarde régulière

[ ] Configurer une sauvegarde automatique de la BDD (cPanel → Backups)
[ ] Faire une sauvegarde manuelle avant chaque mise à jour importante
[ ] Tester la restauration de sauvegarde au moins une fois

## ============================================================
## RÉSUMÉ DES COMPTES PAR DÉFAUT (À CHANGER !)
## ============================================================

Admin     : admin@cst.edu        / (à définir)
Économat  : econmat@cst.edu      / (à définir)
Professeur: jean.pierre@cst.edu  / (à définir)

## ============================================================
## EN CAS DE PROBLÈME
## ============================================================

Si le site affiche une erreur blanche :
→ Mettre MODE_PRODUCTION = false temporairement pour voir l'erreur
→ Corriger, puis remettre MODE_PRODUCTION = true

Si impossible de se connecter à la BDD :
→ Vérifier DB_USER, DB_PASS, DB_NAME dans config/database.php
→ Vérifier que l'utilisateur MySQL a bien les droits sur la BDD
