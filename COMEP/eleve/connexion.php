<?php
/**
 * eleve/connexion.php - Page de connexion du portail élève
 * Accès par Nom + Prénom + Date de naissance
 */
session_start();
require_once __DIR__ . '/../config/database.php';

// Si déjà connecté comme élève, rediriger
if (!empty($_SESSION['eleve_id'])) {
    header('Location: bulletin.php'); exit();
}

$erreur = '';
$nom    = '';
$prenom = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nom    = trim($_POST['nom']    ?? '');
    $prenom = trim($_POST['prenom'] ?? '');
    $dateN  = trim($_POST['date_naissance'] ?? '');

    if (empty($nom) || empty($prenom) || empty($dateN)) {
        $erreur = 'Veuillez remplir tous les champs.';
    } else {
        try {
            $pdo = getDB();

            // Chercher l'élève par nom + prénom + date de naissance
            $stmt = $pdo->prepare("
                SELECT e.*, c.nom AS classe_nom
                FROM eleves e
                LEFT JOIN classes c ON e.classe_id = c.id
                WHERE LOWER(e.nom)    = LOWER(:nom)
                AND   LOWER(e.prenom) = LOWER(:prenom)
                AND   e.date_naissance = :dn
                AND   e.status = 'actif'
                LIMIT 1
            ");
            $stmt->execute([
                ':nom'    => $nom,
                ':prenom' => $prenom,
                ':dn'     => $dateN,
            ]);
            $eleve = $stmt->fetch();

            if (!$eleve) {
                $erreur = 'Aucun élève trouvé avec ces informations. Vérifiez votre nom, prénom et date de naissance.';
            } else {
                // Vérifier si l'accès est autorisé (paiement OK)
                $acces = $pdo->prepare("
                    SELECT acces FROM acces_bulletins
                    WHERE eleve_id=:eid AND annee_scolaire=:annee
                    LIMIT 1
                ");
                $acces->execute([':eid'=>$eleve['id'], ':annee'=>ANNEE_SCOLAIRE]);
                $statutAcces = $acces->fetchColumn();

                if ($statutAcces !== 'autorise') {
                    $erreur = '🔒 Votre accès au bulletin est bloqué. Veuillez contacter l\'économat de l\'école pour régulariser votre situation.';
                } else {
                    // Connexion réussie — créer la session élève
                    $_SESSION['eleve_id']    = $eleve['id'];
                    $_SESSION['eleve_nom']   = $eleve['nom'];
                    $_SESSION['eleve_prenom']= $eleve['prenom'];
                    $_SESSION['eleve_matricule'] = $eleve['matricule'];
                    $_SESSION['eleve_classe']= $eleve['classe_nom'];
                    $_SESSION['eleve_annee'] = $eleve['annee_scolaire'];

                    header('Location: bulletin.php'); exit();
                }
            }
        } catch (PDOException $e) {
            $erreur = 'Erreur de connexion. Veuillez réessayer.';
            error_log($e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Élève — CST</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #065f46 0%, #0d6efd 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }
        .login-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 25px 60px rgba(0,0,0,0.3);
            width: 100%;
            max-width: 440px;
            overflow: hidden;
        }
        .login-header {
            background: linear-gradient(135deg, #065f46, #0d9488);
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
            border-color: #0d9488;
            box-shadow: 0 0 0 0.2rem rgba(13,148,136,.2);
        }
        .btn-eleve {
            background: linear-gradient(135deg, #065f46, #0d9488);
            border: none;
            border-radius: 10px;
            padding: 0.75rem;
            font-weight: 600;
            color: white;
            transition: all 0.3s;
        }
        .btn-eleve:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(13,148,136,.4);
            color: white;
        }
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
        <p class="mb-0 opacity-75 small">Consulter mon bulletin</p>
    </div>

    <!-- Formulaire -->
    <div class="login-body">
        <h2 class="h5 fw-semibold text-center mb-1">Accès à mon bulletin</h2>
        <p class="text-muted text-center small mb-4">
            Entrez vos informations pour consulter vos résultats
        </p>

        <?php if (!empty($erreur)): ?>
        <div class="alert alert-danger alert-dismissible d-flex gap-2">
            <i class="fas fa-exclamation-circle mt-1"></i>
            <div><?= htmlspecialchars($erreur, ENT_QUOTES, 'UTF-8') ?></div>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <form method="POST" action="connexion.php">

            <div class="mb-3">
                <label class="form-label fw-500">
                    <i class="fas fa-user me-1 text-muted"></i> Nom
                </label>
                <input type="text" name="nom" class="form-control"
                       placeholder="Votre nom"
                       value="<?= htmlspecialchars($nom, ENT_QUOTES, 'UTF-8') ?>"
                       required autocomplete="family-name" autofocus>
            </div>

            <div class="mb-3">
                <label class="form-label fw-500">
                    <i class="fas fa-user me-1 text-muted"></i> Prénom
                </label>
                <input type="text" name="prenom" class="form-control"
                       placeholder="Votre prénom"
                       value="<?= htmlspecialchars($prenom, ENT_QUOTES, 'UTF-8') ?>"
                       required autocomplete="given-name">
            </div>

            <div class="mb-4">
                <label class="form-label fw-500">
                    <i class="fas fa-calendar me-1 text-muted"></i> Date de naissance
                </label>
                <input type="date" name="date_naissance" class="form-control"
                       required>
                <div class="form-text">Format : Mois/Jour/Année</div>
            </div>

            <button type="submit" class="btn btn-eleve w-100">
                Consulter mes résultats
            </button>
        </form>
    </div>

    <div class="login-footer">
        <small class="text-muted">
            <i class="fas fa-info-circle me-1 text-success"></i>
            Problème d'accès ? Contactez l'économat de l'école.
        </small>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
