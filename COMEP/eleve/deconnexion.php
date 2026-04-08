<?php
/**
 * eleve/deconnexion.php - Déconnexion du portail élève
 */
session_start();
// Détruire uniquement la session élève
$_SESSION = [];
session_destroy();
header('Location: connexion.php');
exit();
