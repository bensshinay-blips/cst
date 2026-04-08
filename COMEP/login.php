<?php
/**
 * login.php - Page de connexion
 * Système de gestion scolaire CST
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';

// Si déjà connecté, rediriger selon le rôle
if (estConnecte()) {
    if (estAdmin()) {
        header('Location: admin/index.php');
    } elseif (estEconmat()) {
        header('Location: econmat/index.php');
    } else {
        header('Location: professeur/index.php');
    }
    exit();
}

$erreur = '';
$email  = '';

// ===== PROTECTION ANTI-BRUTE FORCE =====
if (!isset($_SESSION['tentatives_login'])) {
    $_SESSION['tentatives_login']   = 0;
    $_SESSION['premiere_tentative'] = time();
}

// Réinitialiser après 15 minutes
if ((time() - ($_SESSION['premiere_tentative'] ?? 0)) > 900) {
    $_SESSION['tentatives_login']   = 0;
    $_SESSION['premiere_tentative'] = time();
}

$tropDeTentatives = $_SESSION['tentatives_login'] >= 5;
$tempsRestant     = 0;

if ($tropDeTentatives) {
    $tempsRestant = 900 - (time() - $_SESSION['premiere_tentative']);
    if ($tempsRestant <= 0) {
        $_SESSION['tentatives_login']   = 0;
        $_SESSION['premiere_tentative'] = time();
        $tropDeTentatives               = false;
    }
}

// ===== TRAITEMENT DU FORMULAIRE =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if ($tropDeTentatives) {
        $minutesRestantes = ceil($tempsRestant / 60);
        $erreur = "Trop de tentatives. Réessayez dans {$minutesRestantes} minute(s).";

    } else {

        $email    = trim($_POST['email']    ?? '');
        $password = trim($_POST['password'] ?? '');

        if (empty($email) || empty($password)) {
            $erreur = 'Veuillez remplir tous les champs.';

        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $erreur = 'Adresse email invalide.';

        } else {
            try {
                $pdo  = getDB();
                $stmt = $pdo->prepare("
                    SELECT id, nom, prenom, email, password, role, status
                    FROM utilisateurs
                    WHERE email = :email
                    LIMIT 1
                ");
                $stmt->execute([':email' => $email]);
                $utilisateur = $stmt->fetch();

                if (!$utilisateur) {
                    // Utilisateur introuvable
                    $_SESSION['tentatives_login']++;
                    $restantes = 5 - $_SESSION['tentatives_login'];
                    $erreur    = 'Email ou mot de passe incorrect.';
                    if ($restantes > 0) {
                        $erreur .= " ({$restantes} tentative(s) restante(s))";
                    }

                } elseif ($utilisateur['status'] !== 'actif') {
                    $erreur = 'Votre compte est désactivé. Contactez l\'administrateur.';

                } else {
                    // Vérifier le mot de passe
                    $motDePasseValide = false;

                    if (password_verify($password, $utilisateur['password'])) {
                        $motDePasseValide = true;
                    } elseif ($password === $utilisateur['password']) {
                        // Migration : mot de passe en clair → hashé automatiquement
                        $motDePasseValide = true;
                        $newHash = password_hash($password, PASSWORD_BCRYPT);
                        $pdo->prepare("UPDATE utilisateurs SET password=:pwd WHERE id=:id")
                            ->execute([':pwd' => $newHash, ':id' => $utilisateur['id']]);
                    }

                    if ($motDePasseValide) {
                        // Connexion réussie — réinitialiser le compteur
                        $_SESSION['tentatives_login']   = 0;
                        $_SESSION['premiere_tentative'] = time();

                        connecterUtilisateur($utilisateur);
                        logAction($pdo, 'CONNEXION', 'utilisateurs',
                                  $utilisateur['id'], 'Connexion réussie');

                        // Rediriger selon le rôle
                        if ($utilisateur['role'] === 'admin') {
                            header('Location: admin/index.php');
                        } elseif ($utilisateur['role'] === 'econmat') {
                            header('Location: econmat/index.php');
                        } else {
                            header('Location: professeur/index.php');
                        }
                        exit();

                    } else {
                        // Mauvais mot de passe
                        $_SESSION['tentatives_login']++;
                        $restantes = 5 - $_SESSION['tentatives_login'];
                        $erreur    = 'Email ou mot de passe incorrect.';
                        if ($restantes > 0) {
                            $erreur .= " ({$restantes} tentative(s) restante(s))";
                        }
                    }
                }

            } catch (PDOException $e) {
                $erreur = 'Erreur de connexion à la base de données. Veuillez réessayer.';
                error_log("Erreur login PDO : " . $e->getMessage());
            }
        }
    }
}

// Message depuis l'URL (session expirée, permission refusée)
if (empty($erreur) && !empty($_GET['erreur'])) {
    $codes = [
        'session'    => 'Votre session a expiré. Veuillez vous reconnecter.',
        'permission' => 'Vous n\'avez pas les permissions pour accéder à cette page.',
    ];
    $erreur = $codes[$_GET['erreur']] ?? '';
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connexion | CST</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="css/style.css" rel="stylesheet">

    <style>
        body {
            background: linear-gradient(135deg, #1a3a5c 0%, #0d6efd 60%, #198754 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Inter', sans-serif;
        }
        .login-card {
            background: #fff;
            border-radius: 20px;
            box-shadow: 0 25px 60px rgba(0,0,0,0.3);
            overflow: hidden;
            width: 100%;
            max-width: 440px;
        }
        .login-header {
            background: linear-gradient(135deg, #1a3a5c, #0d6efd);
            color: white;
            padding: 2.5rem 2rem 2rem;
            text-align: center;
        }
        .school-icon {
            width: 80px; height: 80px;
            background: rgba(255,255,255,0.15);
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 1rem;
            font-size: 2rem;
        }
        .login-body { padding: 2rem; }
        .login-footer {
            background: #f8f9fa;
            padding: 1rem 2rem;
            text-align: center;
            border-top: 1px solid #e9ecef;
        }
        .form-control:focus {
            border-color: #0d6efd;
            box-shadow: 0 0 0 0.2rem rgba(13,110,253,.15);
        }
        .btn-login {
            background: linear-gradient(135deg, #1a3a5c, #0d6efd);
            border: none;
            border-radius: 10px;
            padding: 0.75rem;
            font-size: 1rem;
            font-weight: 600;
            transition: all 0.3s;
        }
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(13,110,253,.4);
        }
        .toggle-password {
            cursor: pointer;
            color: #6c757d;
        }
        .toggle-password:hover { color: #0d6efd; }
    </style>
</head>
<body>

<div class="login-card">

    <!-- En-tête -->
    <div class="login-header">
        <div class="school-icon">
            <i class="fas fa-graduation-cap"></i>
        </div>
        <h1 class="h4 fw-bold mb-1">CST</h1>
        <p class="mb-0 opacity-75 small">Système de Gestion Scolaire</p>
    </div>

    <!-- Formulaire -->
    <div class="login-body">

        <h2 class="h5 fw-semibold text-center mb-1 text-dark">Connexion</h2>
        <p class="text-muted text-center small mb-4">Entrez vos informations d'accès</p>

        <!-- Alerte blocage -->
        <?php if ($tropDeTentatives): ?>
        <div class="alert alert-danger text-center">
            <i class="fas fa-lock fa-lg mb-2 d-block"></i>
            <strong>Compte temporairement bloqué</strong><br>
            <small>Trop de tentatives. Réessayez dans <?= ceil($tempsRestant / 60) ?> minute(s).</small>
        </div>
        <?php endif; ?>

        <!-- Message d'erreur -->
        <?php if (!empty($erreur) && !$tropDeTentatives): ?>
        <div class="alert alert-danger alert-dismissible d-flex align-items-center gap-2">
            <i class="fas fa-exclamation-circle"></i>
            <div><?= h($erreur) ?></div>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- Formulaire -->
        <form method="POST" action="login.php" novalidate>

            <div class="form-floating mb-3">
                <input type="email" class="form-control" id="email" name="email"
                       placeholder="votre@email.com"
                       value="<?= h($email) ?>"
                       required autocomplete="email" autofocus
                       <?= $tropDeTentatives ? 'disabled' : '' ?>>
                <label for="email">
                    <i class="fas fa-envelope me-2 text-muted"></i>Adresse email
                </label>
            </div>

            <div class="form-floating mb-4 position-relative">
                <input type="password" class="form-control pe-5" id="password" name="password"
                       placeholder="Mot de passe"
                       required autocomplete="current-password"
                       <?= $tropDeTentatives ? 'disabled' : '' ?>>
                <label for="password">
                    <i class="fas fa-lock me-2 text-muted"></i>Mot de passe
                </label>
                <span class="toggle-password position-absolute top-50 end-0 translate-middle-y me-3"
                      onclick="togglePassword()">
                    <i class="fas fa-eye" id="toggleIcon"></i>
                </span>
            </div>

            <button type="submit" class="btn btn-primary btn-login w-100 text-white"
                    <?= $tropDeTentatives ? 'disabled' : '' ?>>
                <i class="fas fa-sign-in-alt me-2"></i> Se connecter
            </button>

        </form>
    </div>

    <div class="login-footer">
        <small class="text-muted">
            <i class="fas fa-shield-alt me-1 text-success"></i>
            Connexion sécurisée &nbsp;|&nbsp; Problème ? Contactez l'administrateur.
        </small>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function togglePassword() {
    const champ = document.getElementById('password');
    const icone = document.getElementById('toggleIcon');
    if (champ.type === 'password') {
        champ.type = 'text';
        icone.classList.replace('fa-eye', 'fa-eye-slash');
    } else {
        champ.type = 'password';
        icone.classList.replace('fa-eye-slash', 'fa-eye');
    }
}
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.alert-dismissible').forEach(function(a) {
        setTimeout(() => bootstrap.Alert.getOrCreateInstance(a)?.close(), 6000);
    });
});
</script>

</body>
</html>
