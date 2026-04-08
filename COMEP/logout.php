<?php
/**
 * logout.php - Déconnexion
 */
require_once __DIR__ . '/includes/auth.php';

if (estConnecte()) {
    require_once __DIR__ . '/config/database.php';
    try {
        $pdo = getDB();
        logAction($pdo, 'DECONNEXION', 'utilisateurs', $_SESSION['utilisateur_id'], 'Déconnexion');
    } catch (Exception $e) { /* ignorer */ }
}

deconnecterUtilisateur();
header('Location: login.php?msg=deconnecte');
exit();
