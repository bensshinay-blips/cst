<?php
/**
 * includes/auth.php - Gestion des sessions et permissions
 * Système de gestion scolaire CST
 *
 * IMPORTANT : Toutes les fonctions sont définies EN PREMIER
 * avant d'être appelées — c'est crucial en PHP.
 */

// =====================================================================
// FONCTIONS — définies en premier avant tout appel
// =====================================================================

/**
 * Retourne le chemin racine selon le dossier courant
 */
function racine(): string {
    $dossier = basename(dirname($_SERVER['PHP_SELF'] ?? ''));
    if (in_array($dossier, ['admin', 'professeur', 'econmat', 'eleve'])) {
        return '../';
    }
    return '';
}

/**
 * Vérifie si l'utilisateur est connecté
 */
function estConnecte(): bool {
    return isset($_SESSION['utilisateur_id']) && !empty($_SESSION['utilisateur_id']);
}

/**
 * Vérifie si admin
 */
function estAdmin(): bool {
    return estConnecte() && ($_SESSION['role'] ?? '') === 'admin';
}

/**
 * Vérifie si professeur
 */
function estProfesseur(): bool {
    return estConnecte() && ($_SESSION['role'] ?? '') === 'professeur';
}

/**
 * Vérifie si économat
 */
function estEconmat(): bool {
    return estConnecte() && ($_SESSION['role'] ?? '') === 'econmat';
}

/**
 * Oblige l'utilisateur à être admin
 */
function requireAdmin(): void {
    if (!estConnecte()) {
        header('Location: ' . racine() . 'login.php?erreur=session');
        exit();
    }
    if (!estAdmin()) {
        header('Location: ' . racine() . 'login.php?erreur=permission');
        exit();
    }
}

/**
 * Oblige l'utilisateur à être professeur ou admin
 */
function requireProfesseur(): void {
    if (!estConnecte()) {
        header('Location: ' . racine() . 'login.php?erreur=session');
        exit();
    }
    if (!estAdmin() && !estProfesseur()) {
        header('Location: ' . racine() . 'login.php?erreur=permission');
        exit();
    }
}

/**
 * Oblige l'utilisateur à être économat ou admin
 */
function requireEconmat(): void {
    if (!estConnecte()) {
        header('Location: ' . racine() . 'login.php?erreur=session');
        exit();
    }
    if (!estAdmin() && !estEconmat()) {
        header('Location: ' . racine() . 'login.php?erreur=permission');
        exit();
    }
}

/**
 * Connecte un utilisateur (crée la session)
 */
function connecterUtilisateur(array $utilisateur): void {
    $_SESSION['utilisateur_id'] = $utilisateur['id'];
    $_SESSION['nom']            = $utilisateur['nom'];
    $_SESSION['prenom']         = $utilisateur['prenom'];
    $_SESSION['email']          = $utilisateur['email'];
    $_SESSION['role']           = $utilisateur['role'];
    $_SESSION['connecte_le']    = time();
    $_SESSION['derniere_regen'] = time();
}

/**
 * Déconnecte l'utilisateur
 */
function deconnecterUtilisateur(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(), '', time() - 42000,
            $params['path'], $params['domain'],
            $params['secure'], $params['httponly']
        );
    }
    session_destroy();
}

/**
 * Enregistre une action dans les logs
 */
function logAction(PDO $pdo, string $action, string $table = '', int $recordId = 0, string $details = ''): void {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO logs (utilisateur_id, action, table_name, record_id, details, ip_address)
            VALUES (:uid, :action, :table, :record_id, :details, :ip)
        ");
        $stmt->execute([
            ':uid'       => $_SESSION['utilisateur_id'] ?? null,
            ':action'    => $action,
            ':table'     => $table,
            ':record_id' => $recordId ?: null,
            ':details'   => $details,
            ':ip'        => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
        ]);
    } catch (PDOException $e) {
        error_log("Erreur log : " . $e->getMessage());
    }
}

/**
 * Échappe le HTML pour affichage sécurisé (protection XSS)
 */
function h(mixed $valeur): string {
    return htmlspecialchars((string)($valeur ?? ''), ENT_QUOTES, 'UTF-8');
}

/**
 * Affiche un message flash et le supprime de la session
 */
function afficherMessage(): void {
    if (!empty($_SESSION['message'])) {
        $type  = $_SESSION['message']['type'] ?? 'info';
        $texte = h($_SESSION['message']['texte'] ?? '');
        $classes = [
            'succes' => 'alert-success',
            'erreur' => 'alert-danger',
            'info'   => 'alert-info',
            'avert'  => 'alert-warning',
        ];
        $classe = $classes[$type] ?? 'alert-info';
        echo "<div class=\"alert {$classe} alert-dismissible fade show\" role=\"alert\">
                {$texte}
                <button type=\"button\" class=\"btn-close\" data-bs-dismiss=\"alert\"></button>
              </div>";
        unset($_SESSION['message']);
    }
}

/**
 * Définit un message flash
 */
function setMessage(string $texte, string $type = 'succes'): void {
    $_SESSION['message'] = ['texte' => $texte, 'type' => $type];
}

// =====================================================================
// DÉMARRAGE SESSION — après la définition des fonctions
// =====================================================================

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_secure', 0);
    ini_set('session.use_strict_mode', 1);
    ini_set('session.gc_maxlifetime', 7200);
    ini_set('session.cookie_lifetime', 0);
    session_start();
}

// Regénérer l'ID de session toutes les 30 minutes
if (!isset($_SESSION['derniere_regen'])) {
    $_SESSION['derniere_regen'] = time();
} elseif ((time() - $_SESSION['derniere_regen']) > 1800) {
    session_regenerate_id(true);
    $_SESSION['derniere_regen'] = time();
}

// Vérifier expiration session (2 heures d'inactivité)
if (isset($_SESSION['utilisateur_id'])) {
    if (isset($_SESSION['connecte_le']) && (time() - $_SESSION['connecte_le']) > 7200) {
        // Session expirée — déconnecter proprement
        $role = $_SESSION['role'] ?? '';
        session_unset();
        session_destroy();
        session_start();
        $redirect = in_array($role, ['admin','professeur','econmat'])
            ? '../login.php?erreur=session'
            : 'login.php?erreur=session';
        header('Location: ' . $redirect);
        exit();
    }
    $_SESSION['connecte_le'] = time();
}
