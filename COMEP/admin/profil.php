<?php
/**
 * admin/profil.php - Gestion du profil admin
 * L'admin peut :
 * - Modifier son propre mot de passe
 * - Modifier le mot de passe de l'économat
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
requireAdmin();

$titrePage = 'Mon Profil';
$pdo = getDB();

$erreurAdmin   = '';
$erreurEconmat = '';

// ===== CHANGER MOT DE PASSE ADMIN =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'admin') {

    $actuel    = $_POST['mot_de_passe_actuel'] ?? '';
    $nouveau   = $_POST['nouveau_mot_de_passe'] ?? '';
    $confirmer = $_POST['confirmer_mot_de_passe'] ?? '';

    if (empty($actuel) || empty($nouveau) || empty($confirmer)) {
        $erreurAdmin = 'Tous les champs sont obligatoires.';
    } elseif (strlen($nouveau) < 6) {
        $erreurAdmin = 'Le nouveau mot de passe doit avoir au moins 6 caractères.';
    } elseif ($nouveau !== $confirmer) {
        $erreurAdmin = 'Le nouveau mot de passe et la confirmation ne correspondent pas.';
    } else {
        // Vérifier le mot de passe actuel
        $stmt = $pdo->prepare("SELECT password FROM utilisateurs WHERE id=:id");
        $stmt->execute([':id' => $_SESSION['utilisateur_id']]);
        $hashActuel = $stmt->fetchColumn();

        $valide = password_verify($actuel, $hashActuel)
               || $actuel === $hashActuel; // compatibilité anciens mots de passe en clair

        if (!$valide) {
            $erreurAdmin = 'Le mot de passe actuel est incorrect.';
        } else {
            $nouveauHash = password_hash($nouveau, PASSWORD_BCRYPT);
            $pdo->prepare("UPDATE utilisateurs SET password=:pwd WHERE id=:id")
                ->execute([':pwd' => $nouveauHash, ':id' => $_SESSION['utilisateur_id']]);
            logAction($pdo, 'CHANGEMENT MOT DE PASSE', 'utilisateurs',
                      $_SESSION['utilisateur_id'], 'Admin a changé son mot de passe');
            setMessage('✅ Votre mot de passe a été modifié avec succès.');
            header('Location: profil.php'); exit();
        }
    }
}

// ===== CHANGER MOT DE PASSE ECONMAT =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'econmat') {

    $nouveau   = $_POST['econmat_nouveau'] ?? '';
    $confirmer = $_POST['econmat_confirmer'] ?? '';

    if (empty($nouveau) || empty($confirmer)) {
        $erreurEconmat = 'Tous les champs sont obligatoires.';
    } elseif (strlen($nouveau) < 6) {
        $erreurEconmat = 'Le mot de passe doit avoir au moins 6 caractères.';
    } elseif ($nouveau !== $confirmer) {
        $erreurEconmat = 'Les deux mots de passe ne correspondent pas.';
    } else {
        // Trouver le compte econmat
        $stmt = $pdo->prepare("SELECT id FROM utilisateurs WHERE role='econmat' LIMIT 1");
        $stmt->execute();
        $econmat = $stmt->fetch();

        if (!$econmat) {
            $erreurEconmat = 'Aucun compte économat trouvé. Créez-en un d\'abord.';
        } else {
            $nouveauHash = password_hash($nouveau, PASSWORD_BCRYPT);
            $pdo->prepare("UPDATE utilisateurs SET password=:pwd WHERE id=:id")
                ->execute([':pwd' => $nouveauHash, ':id' => $econmat['id']]);
            logAction($pdo, 'CHANGEMENT MOT DE PASSE ECONMAT', 'utilisateurs',
                      $econmat['id'], 'Admin a changé le mot de passe de l\'économat');
            setMessage('✅ Mot de passe de l\'économat modifié avec succès.');
            header('Location: profil.php'); exit();
        }
    }
}

// Infos admin connecté
$stmt = $pdo->prepare("SELECT * FROM utilisateurs WHERE id=:id");
$stmt->execute([':id' => $_SESSION['utilisateur_id']]);
$adminInfo = $stmt->fetch();

// Infos econmat
$stmt2 = $pdo->prepare("SELECT * FROM utilisateurs WHERE role='econmat' LIMIT 1");
$stmt2->execute();
$econmatInfo = $stmt2->fetch();

require_once __DIR__ . '/../includes/header.php';
?>

<h1 class="page-title"><i class="fas fa-user-shield"></i> Mon Profil & Sécurité</h1>
<?php afficherMessage(); ?>

<div class="row g-4">

    <!-- ===== MOT DE PASSE ADMIN ===== -->
    <div class="col-lg-6">
        <div class="card shadow-sm">
            <div class="card-header d-flex align-items-center gap-2">
                <i class="fas fa-key text-primary"></i>
                <span>Mon mot de passe</span>
                <span class="badge bg-warning text-dark ms-auto">Admin</span>
            </div>
            <div class="card-body">

                <!-- Infos du compte -->
                <div class="d-flex align-items-center gap-3 p-3 rounded mb-4"
                     style="background:#f0f4ff">
                    <div class="rounded-circle d-flex align-items-center justify-content-center fw-bold text-white"
                         style="width:50px;height:50px;background:linear-gradient(135deg,#1a3a5c,#0d6efd);font-size:1.2rem">
                        <?= strtoupper(substr($adminInfo['prenom'], 0, 1)) ?>
                    </div>
                    <div>
                        <div class="fw-bold"><?= h($adminInfo['prenom']) ?> <?= h($adminInfo['nom']) ?></div>
                        <div class="text-muted small"><?= h($adminInfo['email']) ?></div>
                        <span class="badge bg-warning text-dark">Administrateur</span>
                    </div>
                </div>

                <?php if (!empty($erreurAdmin)): ?>
                <div class="alert alert-danger py-2">
                    <i class="fas fa-exclamation-circle me-2"></i><?= h($erreurAdmin) ?>
                </div>
                <?php endif; ?>

                <form method="POST" action="profil.php" autocomplete="off">
                    <input type="hidden" name="form" value="admin">

                    <div class="mb-3">
                        <label class="form-label fw-500">
                            <i class="fas fa-lock me-1 text-muted"></i>
                            Mot de passe actuel <span class="text-danger">*</span>
                        </label>
                        <div class="input-group">
                            <input type="password" name="mot_de_passe_actuel"
                                   id="mdpActuel"
                                   class="form-control" required
                                   autocomplete="current-password">
                            <button type="button" class="btn btn-outline-secondary"
                                    onclick="toggleVoir('mdpActuel','iconActuel')">
                                <i class="fas fa-eye" id="iconActuel"></i>
                            </button>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-500">
                            <i class="fas fa-lock-open me-1 text-muted"></i>
                            Nouveau mot de passe <span class="text-danger">*</span>
                        </label>
                        <div class="input-group">
                            <input type="password" name="nouveau_mot_de_passe"
                                   id="mdpNouveau"
                                   class="form-control" required
                                   minlength="6"
                                   autocomplete="new-password"
                                   oninput="verifierForce(this)">
                            <button type="button" class="btn btn-outline-secondary"
                                    onclick="toggleVoir('mdpNouveau','iconNouveau')">
                                <i class="fas fa-eye" id="iconNouveau"></i>
                            </button>
                        </div>
                        <!-- Barre de force du mot de passe -->
                        <div class="progress mt-2" style="height:6px">
                            <div class="progress-bar" id="barreForce"
                                 style="width:0%;transition:all 0.3s"></div>
                        </div>
                        <small id="texteForce" class="text-muted">Min. 6 caractères</small>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-500">
                            <i class="fas fa-check-double me-1 text-muted"></i>
                            Confirmer le nouveau mot de passe <span class="text-danger">*</span>
                        </label>
                        <div class="input-group">
                            <input type="password" name="confirmer_mot_de_passe"
                                   id="mdpConfirmer"
                                   class="form-control" required
                                   autocomplete="new-password"
                                   oninput="verifierCorrespondance()">
                            <button type="button" class="btn btn-outline-secondary"
                                    onclick="toggleVoir('mdpConfirmer','iconConfirmer')">
                                <i class="fas fa-eye" id="iconConfirmer"></i>
                            </button>
                        </div>
                        <small id="texteCorrespondance" class="text-muted"></small>
                    </div>

                    <div class="d-grid">
                        <button type="submit" class="btn btn-comep">
                            <i class="fas fa-save me-2"></i>
                            Enregistrer mon nouveau mot de passe
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ===== MOT DE PASSE ECONMAT ===== -->
    <div class="col-lg-6">
        <div class="card shadow-sm">
            <div class="card-header d-flex align-items-center gap-2">
                <i class="fas fa-cash-register text-success"></i>
                <span>Mot de passe de l'Économat</span>
                <span class="badge bg-success ms-auto">Économat</span>
            </div>
            <div class="card-body">

                <?php if ($econmatInfo): ?>
                <!-- Infos compte econmat -->
                <div class="d-flex align-items-center gap-3 p-3 rounded mb-4"
                     style="background:#f0fdf4">
                    <div class="rounded-circle d-flex align-items-center justify-content-center fw-bold text-white"
                         style="width:50px;height:50px;background:linear-gradient(135deg,#065f46,#10b981);font-size:1.2rem">
                        <?= strtoupper(substr($econmatInfo['prenom'], 0, 1)) ?>
                    </div>
                    <div>
                        <div class="fw-bold">
                            <?= h($econmatInfo['prenom']) ?> <?= h($econmatInfo['nom']) ?>
                        </div>
                        <div class="text-muted small"><?= h($econmatInfo['email']) ?></div>
                        <span class="badge bg-success">Économat</span>
                        <span class="badge <?= $econmatInfo['status']==='actif'?'bg-success':'bg-secondary' ?> ms-1">
                            <?= ucfirst(h($econmatInfo['status'])) ?>
                        </span>
                    </div>
                </div>

                <?php if (!empty($erreurEconmat)): ?>
                <div class="alert alert-danger py-2">
                    <i class="fas fa-exclamation-circle me-2"></i><?= h($erreurEconmat) ?>
                </div>
                <?php endif; ?>

                <div class="alert alert-warning py-2 mb-3">
                    <i class="fas fa-info-circle me-1"></i>
                    <small>En tant qu'admin, vous pouvez réinitialiser le mot de passe de l'économat
                    sans connaître l'ancien.</small>
                </div>

                <form method="POST" action="profil.php" autocomplete="off">
                    <input type="hidden" name="form" value="econmat">

                    <div class="mb-3">
                        <label class="form-label fw-500">
                            <i class="fas fa-lock-open me-1 text-muted"></i>
                            Nouveau mot de passe économat <span class="text-danger">*</span>
                        </label>
                        <div class="input-group">
                            <input type="password" name="econmat_nouveau"
                                   id="econmatNvx"
                                   class="form-control" required
                                   minlength="6"
                                   autocomplete="new-password">
                            <button type="button" class="btn btn-outline-secondary"
                                    onclick="toggleVoir('econmatNvx','iconEconmat1')">
                                <i class="fas fa-eye" id="iconEconmat1"></i>
                            </button>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-500">
                            <i class="fas fa-check-double me-1 text-muted"></i>
                            Confirmer le mot de passe <span class="text-danger">*</span>
                        </label>
                        <div class="input-group">
                            <input type="password" name="econmat_confirmer"
                                   id="econmatConf"
                                   class="form-control" required
                                   autocomplete="new-password"
                                   oninput="verifierCorrespondanceEconmat()">
                            <button type="button" class="btn btn-outline-secondary"
                                    onclick="toggleVoir('econmatConf','iconEconmat2')">
                                <i class="fas fa-eye" id="iconEconmat2"></i>
                            </button>
                        </div>
                        <small id="texteCorrespondanceEconmat" class="text-muted"></small>
                    </div>

                    <div class="d-grid">
                        <button type="submit" class="btn btn-success"
                                onclick="return confirm('Modifier le mot de passe de l\'économat ?')">
                            <i class="fas fa-save me-2"></i>
                            Modifier le mot de passe économat
                        </button>
                    </div>
                </form>

                <?php else: ?>
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    Aucun compte économat trouvé.<br>
                    <a href="professeurs.php" class="alert-link">
                        Créez un compte avec le rôle "econmat"
                    </a>
                    ou exécutez le fichier <code>migration_v4.sql</code>.
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Conseils sécurité -->
        <div class="card shadow-sm mt-3">
            <div class="card-header">
                <i class="fas fa-shield-alt me-2 text-warning"></i>
                Conseils de sécurité
            </div>
            <div class="card-body p-0">
                <ul class="list-group list-group-flush">
                    <li class="list-group-item small">
                        <i class="fas fa-check text-success me-2"></i>
                        Utilisez au moins <strong>8 caractères</strong>
                    </li>
                    <li class="list-group-item small">
                        <i class="fas fa-check text-success me-2"></i>
                        Mélangez lettres, chiffres et symboles
                    </li>
                    <li class="list-group-item small">
                        <i class="fas fa-check text-success me-2"></i>
                        Ne partagez jamais votre mot de passe
                    </li>
                    <li class="list-group-item small">
                        <i class="fas fa-check text-success me-2"></i>
                        Changez-le régulièrement
                    </li>
                    <li class="list-group-item small">
                        <i class="fas fa-times text-danger me-2"></i>
                        Évitez les dates de naissance ou noms simples
                    </li>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php
$scriptPage = <<<'JS'
<script>
// Afficher/masquer mot de passe
function toggleVoir(champId, iconeId) {
    const champ = document.getElementById(champId);
    const icone = document.getElementById(iconeId);
    if (champ.type === 'password') {
        champ.type = 'text';
        icone.classList.replace('fa-eye', 'fa-eye-slash');
    } else {
        champ.type = 'password';
        icone.classList.replace('fa-eye-slash', 'fa-eye');
    }
}

// Barre de force du mot de passe
function verifierForce(input) {
    const val   = input.value;
    const barre = document.getElementById('barreForce');
    const texte = document.getElementById('texteForce');
    let force   = 0;
    let label   = '';
    let couleur = '';

    if (val.length >= 6)  force++;
    if (val.length >= 10) force++;
    if (/[A-Z]/.test(val)) force++;
    if (/[0-9]/.test(val)) force++;
    if (/[^A-Za-z0-9]/.test(val)) force++;

    switch(force) {
        case 0: case 1:
            label = 'Très faible'; couleur = '#ef4444'; break;
        case 2:
            label = 'Faible'; couleur = '#f97316'; break;
        case 3:
            label = 'Moyen'; couleur = '#eab308'; break;
        case 4:
            label = 'Bon'; couleur = '#22c55e'; break;
        case 5:
            label = 'Excellent'; couleur = '#10b981'; break;
    }

    barre.style.width   = (force * 20) + '%';
    barre.style.background = couleur;
    texte.textContent   = label;
    texte.style.color   = couleur;
}

// Vérifier correspondance admin
function verifierCorrespondance() {
    const nvx  = document.getElementById('mdpNouveau').value;
    const conf = document.getElementById('mdpConfirmer').value;
    const txt  = document.getElementById('texteCorrespondance');
    if (conf === '') { txt.textContent = ''; return; }
    if (nvx === conf) {
        txt.textContent = '✅ Les mots de passe correspondent.';
        txt.style.color = '#10b981';
    } else {
        txt.textContent = '❌ Les mots de passe ne correspondent pas.';
        txt.style.color = '#ef4444';
    }
}

// Vérifier correspondance econmat
function verifierCorrespondanceEconmat() {
    const nvx  = document.getElementById('econmatNvx').value;
    const conf = document.getElementById('econmatConf').value;
    const txt  = document.getElementById('texteCorrespondanceEconmat');
    if (conf === '') { txt.textContent = ''; return; }
    if (nvx === conf) {
        txt.textContent = '✅ Les mots de passe correspondent.';
        txt.style.color = '#10b981';
    } else {
        txt.textContent = '❌ Les mots de passe ne correspondent pas.';
        txt.style.color = '#ef4444';
    }
}
</script>
JS;
?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
