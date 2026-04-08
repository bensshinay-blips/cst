<?php
/**
 * admin/eleves.php - Gestion des élèves (CRUD)
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
requireAdmin();

$titrePage = 'Gestion des Élèves';
$pdo    = getDB();
$action = $_GET['action'] ?? 'liste';
$id     = (int)($_GET['id'] ?? 0);
$erreur = '';

/**
 * Génère un matricule automatique : AAAA + numéro à 3 chiffres
 */
function genererMatricule(PDO $pdo): string {
    $annee = date('Y');
    $stmt = $pdo->query("SELECT MAX(CAST(SUBSTRING(matricule, 5) AS UNSIGNED)) AS max_num FROM eleves WHERE matricule LIKE '{$annee}%'");
    $row  = $stmt->fetch();
    $num  = ($row['max_num'] ?? 0) + 1;
    return $annee . str_pad($num, 3, '0', STR_PAD_LEFT);
}

// ===== TRAITEMENT POST =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nom              = trim($_POST['nom']              ?? '');
    $prenom           = trim($_POST['prenom']           ?? '');
    $sexe             = $_POST['sexe']                  ?? 'M';
    $date_naissance   = $_POST['date_naissance']        ?? null;
    $lieu_naissance   = trim($_POST['lieu_naissance']   ?? '');
    $adresse          = trim($_POST['adresse']          ?? '');
    $telephone_parent = trim($_POST['telephone_parent'] ?? '');
    $classe_id        = (int)($_POST['classe_id']       ?? 0);
    $status           = $_POST['status']                ?? 'actif';
    $idPost           = (int)($_POST['id']              ?? 0);

    if (empty($nom) || empty($prenom)) {
        $erreur = 'Le nom et le prénom sont obligatoires.';
    } elseif ($classe_id < 1) {
        $erreur = 'Veuillez sélectionner une classe.';
    } else {
        try {
            if ($_POST['action_form'] === 'ajouter') {
                $matricule = genererMatricule($pdo);
                $stmt = $pdo->prepare("
                    INSERT INTO eleves (matricule,nom,prenom,sexe,date_naissance,lieu_naissance,adresse,telephone_parent,classe_id,annee_scolaire,status)
                    VALUES (:mat,:nom,:prenom,:sexe,:dn,:ln,:adr,:tel,:cls,:annee,'actif')
                ");
                $stmt->execute([
                    ':mat'  => $matricule, ':nom' => $nom, ':prenom' => $prenom,
                    ':sexe' => $sexe, ':dn'  => $date_naissance ?: null,
                    ':ln'   => $lieu_naissance, ':adr' => $adresse,
                    ':tel'  => $telephone_parent, ':cls' => $classe_id,
                    ':annee'=> ANNEE_SCOLAIRE,
                ]);
                logAction($pdo,'AJOUT ÉLÈVE','eleves',(int)$pdo->lastInsertId(),"$nom $prenom - Mat: $matricule");
                setMessage("Élève « {$prenom} {$nom} » ajouté(e) avec le matricule {$matricule}.");
                header('Location: eleves.php'); exit();
            } else {
                $stmt = $pdo->prepare("
                    UPDATE eleves SET nom=:nom,prenom=:prenom,sexe=:sexe,date_naissance=:dn,
                    lieu_naissance=:ln,adresse=:adr,telephone_parent=:tel,classe_id=:cls,status=:status
                    WHERE id=:id
                ");
                $stmt->execute([
                    ':nom'=>$nom,':prenom'=>$prenom,':sexe'=>$sexe,':dn'=>$date_naissance?:null,
                    ':ln'=>$lieu_naissance,':adr'=>$adresse,':tel'=>$telephone_parent,
                    ':cls'=>$classe_id,':status'=>$status,':id'=>$idPost
                ]);
                logAction($pdo,'MODIFICATION ÉLÈVE','eleves',$idPost,"$nom $prenom");
                setMessage("Élève « {$prenom} {$nom} » modifié(e).");
                header('Location: eleves.php'); exit();
            }
        } catch (PDOException $e) {
            $erreur = "Erreur : " . $e->getMessage();
        }
    }
    $action = ($_POST['action_form'] === 'ajouter') ? 'ajouter' : 'modifier';
}

// ===== SUPPRESSION =====
if ($action === 'supprimer' && $id > 0) {
    try {
        $el = $pdo->prepare("SELECT nom,prenom FROM eleves WHERE id=:id");
        $el->execute([':id'=>$id]);
        $elData = $el->fetch();
        // Supprimer les notes associées d'abord
        $pdo->prepare("DELETE FROM notes WHERE eleve_id=:id")->execute([':id'=>$id]);
        $pdo->prepare("DELETE FROM eleves WHERE id=:id")->execute([':id'=>$id]);
        logAction($pdo,'SUPPRESSION ÉLÈVE','eleves',$id,$elData['prenom'].' '.$elData['nom']);
        setMessage("Élève supprimé(e).");
    } catch (PDOException $e) {
        setMessage("Erreur : " . $e->getMessage(), 'erreur');
    }
    header('Location: eleves.php'); exit();
}

// Élève à modifier
$eleveEdit = null;
if ($action === 'modifier' && $id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM eleves WHERE id=:id");
    $stmt->execute([':id'=>$id]);
    $eleveEdit = $stmt->fetch();
    if (!$eleveEdit) { header('Location: eleves.php'); exit(); }
}

// Filtre par classe
$filtreClasse = (int)($_GET['classe'] ?? 0);

// Récupérer les classes avec id, nom et niveau
$classes = $pdo->query("SELECT id, nom, niveau FROM classes ORDER BY niveau, nom")->fetchAll();

// Liste des élèves avec le niveau de la classe
$params = [];
$where  = "WHERE e.status != 'diplome'";
if ($filtreClasse > 0) {
    $where .= " AND e.classe_id = :classe_id";
    $params[':classe_id'] = $filtreClasse;
}
$stmt = $pdo->prepare("
    SELECT e.*, c.nom AS classe_nom, c.niveau AS classe_niveau
    FROM eleves e
    LEFT JOIN classes c ON e.classe_id = c.id
    {$where}
    ORDER BY c.niveau, c.nom, e.nom, e.prenom
");
$stmt->execute($params);
$eleves = $stmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<h1 class="page-title">Gestion des Élèves</h1>
<?php afficherMessage(); ?>
<?php if (!empty($erreur)): ?>
    <div class="alert alert-danger"><i class="fas fa-exclamation-circle me-2"></i><?= h($erreur) ?></div>
<?php endif; ?>

<div class="row g-4">

    <!-- Formulaire -->
    <div class="col-lg-4">
        <div class="card shadow-sm">
            <div class="card-header d-flex align-items-center gap-2">
                <span><?= $action==='modifier' ? 'Modifier l\'élève' : 'Ajouter un élève' ?></span>
            </div>
            <div class="card-body">
                <form method="POST" action="eleves.php">
                    <input type="hidden" name="action_form" value="<?= $action==='modifier' ? 'modifier' : 'ajouter' ?>">
                    <?php if ($action==='modifier' && $eleveEdit): ?>
                        <input type="hidden" name="id" value="<?= h($eleveEdit['id']) ?>">
                    <?php endif; ?>

                    <div class="row g-2 mb-2">
                        <div class="col-6">
                            <label class="form-label">Nom <span class="text-danger"></span></label>
                            <input type="text" name="nom" class="form-control form-control-sm"
                                   value="<?= h($eleveEdit['nom'] ?? ($_POST['nom'] ?? '')) ?>" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label">Prénom <span class="text-danger"></span></label>
                            <input type="text" name="prenom" class="form-control form-control-sm"
                                   value="<?= h($eleveEdit['prenom'] ?? ($_POST['prenom'] ?? '')) ?>" required>
                        </div>
                    </div>

                    <div class="row g-2 mb-2">
                        <div class="col-6">
                            <label class="form-label">Sexe</label>
                            <select name="sexe" class="form-select form-select-sm">
                                <option value="M" <?= ($eleveEdit['sexe'] ?? 'M')==='M' ? 'selected':'' ?>>Masculin</option>
                                <option value="F" <?= ($eleveEdit['sexe'] ?? '')==='F' ? 'selected':'' ?>>Féminin</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label">Date naissance</label>
                            <input type="date" name="date_naissance" class="form-control form-control-sm"
                                   value="<?= h($eleveEdit['date_naissance'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="mb-2">
                        <label class="form-label">Lieu de naissance</label>
                        <input type="text" name="lieu_naissance" class="form-control form-control-sm"
                               value="<?= h($eleveEdit['lieu_naissance'] ?? '') ?>">
                    </div>

                    <div class="mb-2">
                        <label class="form-label">Adresse</label>
                        <input type="text" name="adresse" class="form-control form-control-sm"
                               value="<?= h($eleveEdit['adresse'] ?? '') ?>">
                    </div>

                    <div class="mb-2">
                        <label class="form-label">Tél. parent</label>
                        <input type="text" name="telephone_parent" class="form-control form-control-sm"
                               value="<?= h($eleveEdit['telephone_parent'] ?? '') ?>">
                    </div>

                    <div class="mb-2">
                        <label class="form-label">Niveau <span class="text-danger"></span></label>
                        <select name="classe_id" class="form-select form-select-sm" required>
                            <option value="">Sélectionner un niveau</option>
                            <?php foreach ($classes as $cl): 
                                $niveau = $cl['niveau'];
                                $niveauxAF = ['7eme', '8eme', '9eme'];
                                if (in_array($niveau, $niveauxAF)) {
                                    $afficher = $niveau . ' AF';
                                } else {
                                    $afficher = $niveau;
                                }
                            ?>
                                <option value="<?= $cl['id'] ?>"
                                    <?= ($eleveEdit['classe_id'] ?? $_POST['classe_id'] ?? 0) == $cl['id'] ? 'selected':'' ?>>
                                    <?= h($afficher) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <?php if ($action === 'modifier'): ?>
                    <div class="mb-3">
                        <label class="form-label">Statut</label>
                        <select name="status" class="form-select form-select-sm">
                            <option value="actif"   <?= ($eleveEdit['status']??'')==='actif'  ?'selected':'' ?>>Actif</option>
                            <option value="inactif" <?= ($eleveEdit['status']??'')==='inactif'?'selected':'' ?>>Inactif</option>
                            <option value="diplome" <?= ($eleveEdit['status']??'')==='diplome'?'selected':'' ?>>Diplômé</option>
                        </select>
                    </div>
                    <?php endif; ?>

                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-comep btn-sm">
                            <i class="fas fa-save me-1"></i>
                            <?= $action==='modifier' ? 'Enregistrer' : 'Ajouter l\'élève' ?>
                        </button>
                        <?php if ($action==='modifier'): ?>
                            <a href="eleves.php" class="btn btn-outline-secondary btn-sm">
                                <i class="fas fa-times me-1"></i> Annuler
                            </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Liste + filtre -->
    <div class="col-lg-8">
        <div class="card shadow-sm">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span><i class="fas fa-list me-2 text-primary"></i>
                    Élèves (<?= count($eleves) ?>)
                </span>
                <!-- Filtre par niveau -->
                <form method="GET" action="eleves.php" class="d-flex gap-2 align-items-center">
                    <select name="classe" class="form-select form-select-sm" style="width:180px">
                        <option value="">Tous les niveaux</option>
                        <?php foreach ($classes as $cl): 
                            $niveau = $cl['niveau'];
                            $niveauxAF = ['7eme', '8eme', '9eme'];
                            if (in_array($niveau, $niveauxAF)) {
                                $afficher = $niveau . ' AF';
                            } else {
                                $afficher = $niveau;
                            }
                        ?>
                            <option value="<?= $cl['id'] ?>" <?= $filtreClasse===$cl['id']?'selected':'' ?>>
                                <?= h($afficher) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-outline-primary btn-sm">
                        <i class="fas fa-filter"></i>
                    </button>
                    <?php if ($filtreClasse): ?>
                        <a href="eleves.php" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-times"></i>
                        </a>
                    <?php endif; ?>
                </form>
            </div>
            <div class="card-body p-0">
                <?php if (empty($eleves)): ?>
                    <p class="text-muted text-center py-4">Aucun élève trouvé.</p>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-comep table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Matricule</th>
                                <th>Nom & Prénom</th>
                                <th class="text-center">Sexe</th>
                                <th>Niveau</th>
                                <th class="text-center">Statut</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($eleves as $e): ?>
                            <tr>
                                <td><code><?= h($e['matricule']) ?></code></td>
                                <td class="fw-500"><?= h($e['prenom']) ?> <?= h($e['nom']) ?></td>
                                <td class="text-center">
                                    <i class="fas <?= $e['sexe']==='F' ? 'fa-venus text-danger' : 'fa-mars text-primary' ?>"></i>
                                </td>
                                <td>
                                    <?php 
                                    $niveau = $e['classe_niveau'] ?? '';
                                    $niveauxAF = ['7eme', '8eme', '9eme'];
                                    if (in_array($niveau, $niveauxAF)) {
                                        echo h($niveau . ' AF');
                                    } else {
                                        echo h($niveau);
                                    }
                                    ?>
                                </td>
                                <td class="text-center">
                                    <span class="badge badge-<?= h($e['status']) ?> px-2 py-1">
                                        <?= ucfirst(h($e['status'])) ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <a href="eleves.php?action=modifier&id=<?= $e['id'] ?>"
                                       class="btn btn-warning btn-sm btn-action me-1" title="Modifier">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <a href="eleves.php?action=supprimer&id=<?= $e['id'] ?>"
                                       class="btn btn-danger btn-sm btn-action"
                                       onclick="return confirmerSuppression('l\'élève <?= h(addslashes($e['prenom'].' '.$e['nom'])) ?>')">
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