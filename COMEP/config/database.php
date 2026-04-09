<?php
/**
 * config/database.php - Configuration de la base de données
 * Système de gestion scolaire CST
 */

// Afficher les erreurs (désactiver en production)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// ===== CONFIGURATION BASE DE DONNÉES RAILWAY =====
define('DB_HOST',    'mysql.railway.internal');     // Sa ki fini pa .rlwy.net la
define('DB_PORT',    '3306');     // Mete 3306
define('DB_NAME',    'railway'); // Non database Railway la
define('DB_USER',    'root');     // Souvan se root
define('DB_PASS',    'nxCgCCSilIhoYlRFgKcUoEeTdqBIedYz'); // Gwo modpas long lan
define('DB_CHARSET', 'utf8mb4');
// Année scolaire active
define('ANNEE_SCOLAIRE', '2024-2025');

// Année scolaire active
define('ANNEE_SCOLAIRE', '2024-2025');

/**
 * Retourne une connexion PDO
 */
function getDB(): PDO {
    static $pdo = null;

    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_FOUND_ROWS   => true,
        ];

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            die('Erreur de connexion : ' . $e->getMessage());
        }
    }

    return $pdo;
}
