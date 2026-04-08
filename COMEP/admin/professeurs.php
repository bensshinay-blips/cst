<?php
/**
 * admin/professeurs.php - Gestion des professeurs (CRUD)
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
requireAdmin();

$titrePage = 'Gestion des Professeurs';
$pdo    = getDB();
$action = $_GET['action'] ?? 'liste';
$id     = (int)($_GET['id'] ?? 0);
$erreur = '';

// ===== TRAITEMENT POST =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nom       = trim($_POST['nom']       ?? '');
    $prenom    = trim($_POST['prenom']    ?? '');
    $email     = trim($_POST['email']     ?? '');
    $telephone = trim($_POST['telephone'] ?? '');
    $adresse   = trim($_POST['adresse']   ?? '');
    $password  = trim($_POST['password']  ?? '');
    $status    = $_POST['status']         ?? 'actif';
    $idPost    = (int)($_POST['id']       ?? 0);

    if (empty($nom) || empty($prenom) || empty($email)) {
        $erreur = 'Nom, prénom et email sont obligatoires.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $erreur = 'Adresse email invalide.';
    } elseif ($_POST['action_form'] === 'ajouter' && empty($password)) {
        $erreur = 'Le mot de passe est obligatoire pour un nouveau professeur.';
    } elseif (!empty($password) && strlen($password) < 6) {
        $erreur = 'Le mot de passe doit contenir au moins 6 caractères.';
    } else {
        try {
            if ($_POST['action_form'] === 'ajouter') {
                // Vérifier unicité email
                $check = $pdo->prepare("SELECT id FROM utilisateurs WHERE email=:email");
                $check->execute([':email'=>$email]);
                if ($check->fetch()) {
                    $erreur = "L'email « {$email} » est déjà utilisé.";
                } else {
                    $hash = password_hash($password, PASSWORD_BCRYPT);
                    $stmt = $pdo->prepare("
                        INSERT INTO utilisateurs (nom,prenom,email,password,role,telephone,adresse,status)
                        VALUES (:nom,:prenom,:email,:pwd,'professeur',:tel,:adr,'actif')
                    ");
                    $stmt->execute([':nom'=>$nom,':prenom'=>$prenom,':email'=>$email,
                                    ':pwd'=>$hash,':tel'=>$telephone,':adr'=>$adresse]);
                    logAction($pdo,'AJOUT PROFESSEUR','utilisateurs',(int)$pdo->lastInsertId(),"$prenom $nom");
                    setMessage("Professeur « {$prenom} {$nom} » ajouté(e).");
                    header('Location: professeurs.php'); exit();
                }
            } else {
                // Modification
                $setClauses = "nom=:nom,prenom=:prenom,email=:email,telephone=:tel,adresse=:adr,status=:status";
                $params     = [':nom'=>$nom,':prenom'=>$prenom,':email'=>$email,
                               ':tel'=>$telephone,':adr'=>$adresse,':status'=>$status,':id'=>$idPost];
                if (!empty($password)) {
                    $setClauses .= ",password=:pwd";
                    $params[':pwd'] = password_hash($password, PASSWORD_BCRYPT);
                }
                $pdo->prepare("UPDATE utilisateurs SET {$setClauses} WHERE id=:id AND role='professeur'")
                    ->execute($params);
                logAction($pdo,'MODIFICATION PROFESSEUR','utilisateurs',$idPost,"$prenom $nom");
                setMessage("Professeur « {$prenom} {$nom} » modifié(e).");
                header('Location: professeurs.php'); exit();
            }
        } catch (PDOException $e) {
            $erreur = "Erreur : " . $e->getMessage();
        }
    }
    $action = ($_POST['action_form'] === 'ajouter') ? 'ajouter' : 'modifier';
}

// ===== TOGGLE STATUT =====
if ($action === 'toggle' && $id > 0) {
    try {
        $stmt = $pdo->prepare("SELECT status,nom,prenom FROM utilisateurs WHERE id=:id AND role='professeur'");
        $stmt->execute([':id'=>$id]);
        $prof = $stmt->fetch();
        if ($prof) {
            $newStatus = $prof['status'] === 'actif' ? 'inactif' : 'actif';
            $pdo->prepare("UPDATE utilisateurs SET status=:s WHERE id=:id")->execute([':s'=>$newStatus,':id'=>$id]);
            setMessage("Compte de {$prof['prenom']} {$prof['nom']} : {$newStatus}.");
        }
    } catch (PDOException $e) {
        setMessage("Erreur : " . $e->getMessage(), 'erreur');
    }
    header('Location: professeurs.php'); exit();
}

// ===== SUPPRESSION =====
if ($action === 'supprimer' && $id > 0) {
    try {
        $stmt = $pdo->prepare("SELECT nom,prenom FROM utilisateurs WHERE id=:id");
        $stmt->execute([':id'=>$id]);
        $prof = $stmt->fetch();
        $pdo->prepare("DELETE FROM utilisateurs WHERE id=:id AND role='professeur'")->execute([':id'=>$id]);
        logAction($pdo,'SUPPRESSION PROFESSEUR','utilisateurs',$id,$prof['prenom'].' '.$prof['nom']);
        setMessage("Professeur supprimé.");
    } catch (PDOException $e) {
        $msg = str_replace('SQLSTATE[45000]: <<Unknown error>>: 1644 ', '', $e->getMessage());
        setMessage("Impossible de supprimer : " . $msg, 'erreur');
    }
    header('Location: professeurs.php'); exit();
}

$profEdit = null;
if ($action === 'modifier' && $id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM utilisateurs WHERE id=:id AND role='professeur'");
    $stmt->execute([':id'=>$id]);
    $profEdit = $stmt->fetch();
    if (!$profEdit) { header('Location: professeurs.php'); exit(); }
}

// Liste professeurs avec nombre de classes
$professeurs = $pdo->query("
    SELECT u.*, COUNT(DISTINCT pc.id) AS nb_classes
    FROM utilisateurs u
    LEFT JOIN professeur_classes pc ON pc.professeur_id = u.id AND pc.annee_scolaire = '".ANNEE_SCOLAIRE."'
    WHERE u.role = 'professeur'
    GROUP BY u.id
    ORDER BY u.nom, u.prenom
")->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<h1 class="page-title">Gestion des Professeurs</h1>
<?php afficherMessage(); ?>
<?php if (!empty($erreur)): ?>
    <div class="alert alert-danger"><i class="fas fa-exclamation-circle me-2"></i><?= h($erreur) ?></div>
<?php endif; ?>

<div class="row g-4">

    <!-- Formulaire -->
    <div class="col-lg-4">
        <div class="card shadow-sm">
            <div class="card-header d-flex align-items-center gap-2">
                
                <span><?= $action==='modifier' ? 'Modifier le professeur' : 'Ajouter un professeur' ?></span>
            </div>
            <div class="card-body">
                <form method="POST" action="professeurs.php" autocomplete="off">
                    <input type="hidden" name="action_form" value="<?= $action==='modifier' ? 'modifier' : 'ajouter' ?>">
                    <?php if ($action==='modifier' && $profEdit): ?>
                        <input type="hidden" name="id" value="<?= h($profEdit['id']) ?>">
                    <?php endif; ?>

                    <div class="row g-2 mb-2">
                        <div class="col-6">
                            <label class="form-label">Nom <span class="text-danger"></span></label>
                            <input type="text" name="nom" class="form-control form-control-sm"
                                   value="<?= h($profEdit['nom'] ?? ($_POST['nom'] ?? '')) ?>" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label">Prénom <span class="text-danger"></span></label>
                            <input type="text" name="prenom" class="form-control form-control-sm"
                                   value="<?= h($profEdit['prenom'] ?? ($_POST['prenom'] ?? '')) ?>" required>
                        </div>
                    </div>

                    <div class="mb-2">
                        <label class="form-label">Email <span class="text-danger"></span></label>
                        <input type="email" name="email" class="form-control form-control-sm"
                               value="<?= h($profEdit['email'] ?? ($_POST['email'] ?? '')) ?>" required>
                    </div>

                    <div class="mb-2">
                        <label class="form-label">Téléphone</label>
                        <input type="text" name="telephone" class="form-control form-control-sm"
                               placeholder="509-XXXX-XXXX"
                               value="<?= h($profEdit['telephone'] ?? '') ?>">
                    </div>

                    <div class="mb-2">
                        <label class="form-label">
                            <?= $action==='modifier' ? 'Nouveau mot de passe (laisser vide = inchangé)' : 'Mot de passe ' ?>
                        </label>
                        <input type="password" name="password" class="form-control form-control-sm"
                               <?= $action!=='modifier' ? 'required' : '' ?>
                               autocomplete="new-password"
                               placeholder="Min. 6 caractères">
                    </div>

                    <?php if ($action === 'modifier'): ?>
                    <div class="mb-3">
                        <label class="form-label">Statut</label>
                        <select name="status" class="form-select form-select-sm">
                            <option value="actif"   <?= ($profEdit['status']??'')==='actif'  ?'selected':'' ?>>Actif</option>
                            <option value="inactif" <?= ($profEdit['status']??'')==='inactif'?'selected':'' ?>>Inactif</option>
                        </select>
                    </div>
                    <?php endif; ?>

                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-comep btn-sm">
                            <i class="fas fa-save me-1"></i>
                            <?= $action==='modifier' ? 'Enregistrer' : 'Ajouter' ?>
                        </button>
                        <?php if ($action==='modifier'): ?>
                            <a href="professeurs.php" class="btn btn-outline-secondary btn-sm">
                                <i class="fas fa-times me-1"></i> Annuler
                            </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Liste -->
    <div class="col-lg-8">
        <div class="card shadow-sm">
            <div class="card-header">
                <i class="fas fa-list me-2 text-primary"></i>Professeurs (<?= count($professeurs) ?>)
            </div>
            <div class="card-body p-0">
                <?php if (empty($professeurs)): ?>
                    <p class="text-muted text-center py-4">Aucun professeur enregistré.</p>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-comep table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Nom & Prénom</th>
                                <th>Email</th>
                                <th>Téléphone</th>
                                <th class="text-center">Classes</th>
                                <th class="text-center">Statut</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($professeurs as $p): ?>
                            <tr>
                                <td class="fw-500"><?= h($p['prenom']) ?> <?= h($p['nom']) ?></td>
                                <td><small><?= h($p['email']) ?></small></td>
                                <td><small><?= h($p['telephone'] ?? '—') ?></small></td>
                                <td class="text-center">
                                    <span class="badge bg-info text-dark"><?= h($p['nb_classes']) ?></span>
                                </td>
                                <td class="text-center">
                                    <span class="badge <?= $p['status']==='actif' ? 'bg-success' : 'bg-secondary' ?>">
                                        <?= ucfirst(h($p['status'])) ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <a href="professeurs.php?action=modifier&id=<?= $p['id'] ?>"
                                       class="btn btn-warning btn-sm btn-action me-1" title="Modifier">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <a href="professeurs.php?action=toggle&id=<?= $p['id'] ?>"
                                       class="btn <?= $p['status']==='actif' ? 'btn-secondary' : 'btn-success' ?> btn-sm btn-action me-1"
                                       title="<?= $p['status']==='actif' ? 'Désactiver' : 'Activer' ?>">
                                        <i class="fas <?= $p['status']==='actif' ? 'fa-ban' : 'fa-check' ?>"></i>
                                    </a>
                                    <a href="professeurs.php?action=supprimer&id=<?= $p['id'] ?>"
                                       class="btn btn-danger btn-sm btn-action"
                                       onclick="return confirmerSuppression('le professeur <?= h(addslashes($p['prenom'].' '.$p['nom'])) ?>')">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
