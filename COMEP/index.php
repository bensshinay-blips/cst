<?php
/**
 * index.php - Page d'accueil
 * Redirige vers le bon tableau de bord selon le rôle
 */
require_once __DIR__ . '/includes/auth.php';

if (!estConnecte()) {
    header('Location: login.php');
    exit();
}

if (estAdmin()) {
    header('Location: admin/index.php');
} elseif (estEconmat()) {
    header('Location: econmat/index.php');
} else {
    header('Location: professeur/index.php');
}
exit();
