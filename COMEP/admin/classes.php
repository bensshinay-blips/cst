<?php
/**
 * admin/classes.php - Gestion des classes (CRUD)
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
requireAdmin();

$titrePage = 'Gestion des Classes';
$pdo = getDB();

$action = $_GET['action'] ?? 'liste';
$id     = (int)($_GET['id'] ?? 0);
$erreur = '';

// ===== TRAITEMENTS POST =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nom       = trim($_POST['nom']       ?? '');
    $niveau    = trim($_POST['niveau']    ?? '');
    $capacite  = (int)($_POST['capacite'] ?? 40);
    $description = trim($_POST['description'] ?? '');
    $idPost    = (int)($_POST['id']       ?? 0);

    // Validation
    if (empty($nom) || empty($niveau)) {
        $erreur = 'Le nom et le niveau sont obligatoires.';
    } elseif ($capacite < 1 || $capacite > 100) {
        $erreur = 'La capacité doit être entre 1 et 100.';
    } else {
        try {
            if ($_POST['action_form'] === 'ajouter') {
                // Vérifier unicité du nom
                $check = $pdo->prepare("SELECT id FROM classes WHERE nom = :nom");
                $check->execute([':nom' => $nom]);
                if ($check->fetch()) {
                    $erreur = "Une classe nommée « {$nom} » existe déjà.";
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO classes (nom, niveau, capacite, description)
                        VALUES (:nom, :niveau, :capacite, :description)
                    ");
                    $stmt->execute([':nom'=>$nom,':niveau'=>$niveau,':capacite'=>$capacite,':description'=>$description]);
                    logAction($pdo, 'AJOUT CLASSE', 'classes', (int)$pdo->lastInsertId(), "Classe: $nom");
                    setMessage("Classe « {$nom} » ajoutée avec succès.");
                    header('Location: classes.php'); exit();
                }
            } elseif ($_POST['action_form'] === 'modifier') {
                $stmt = $pdo->prepare("
                    UPDATE classes SET nom=:nom, niveau=:niveau, capacite=:capacite, description=:description
                    WHERE id=:id
                ");
                $stmt->execute([':nom'=>$nom,':niveau'=>$niveau,':capacite'=>$capacite,':description'=>$description,':id'=>$idPost]);
                logAction($pdo, 'MODIFICATION CLASSE', 'classes', $idPost, "Classe: $nom");
                setMessage("Classe « {$nom} » modifiée avec succès.");
                header('Location: classes.php'); exit();
            }
        } catch (PDOException $e) {
            $erreur = "Erreur base de données : " . $e->getMessage();
        }
    }
    $action = ($_POST['action_form'] === 'ajouter') ? 'ajouter' : 'modifier';
}

// ===== SUPPRESSION =====
if ($action === 'supprimer' && $id > 0) {
    try {
        $classe = $pdo->prepare("SELECT nom FROM classes WHERE id=:id");
        $classe->execute([':id'=>$id]);
        $classeData = $classe->fetch();
        if ($classeData) {
            $pdo->prepare("DELETE FROM classes WHERE id=:id")->execute([':id'=>$id]);
            logAction($pdo, 'SUPPRESSION CLASSE', 'classes', $id, "Classe: ".$classeData['nom']);
            setMessage("Classe « {$classeData['nom']} » supprimée.");
        }
    } catch (PDOException $e) {
        // Le trigger MySQL empêche la suppression si des élèves existent
        setMessage("Impossible de supprimer : " . str_replace('SQLSTATE[45000]: <<Unknown error>>: 1644 ', '', $e->getMessage()), 'erreur');
    }
    header('Location: classes.php'); exit();
}

// ===== CHARGEMENT DONNÉES =====
$classeEdit = null;
if ($action === 'modifier' && $id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM classes WHERE id=:id");
    $stmt->execute([':id'=>$id]);
    $classeEdit = $stmt->fetch();
    if (!$classeEdit) { header('Location: classes.php'); exit(); }
}

// Liste des classes avec nombre d'élèves
$classes = $pdo->query("
    SELECT c.*, COUNT(e.id) AS nb_eleves
    FROM classes c
    LEFT JOIN eleves e ON e.classe_id = c.id AND e.status='actif'
    GROUP BY c.id
    ORDER BY c.niveau, c.nom
")->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<h1 class="page-title">Gestion des Classes</h1>

<?php afficherMessage(); ?>
<?php if (!empty($erreur)): ?>
    <div class="alert alert-danger"><i class="fas fa-exclamation-circle me-2"></i><?= h($erreur) ?></div>
<?php endif; ?>

<div class="row g-4">

    <!-- ===== FORMULAIRE AJOUT/MODIFICATION ===== -->
    <div class="col-lg-4">
        <div class="card shadow-sm">
            <div class="card-header d-flex align-items-center gap-2">
                <i class="fa<?= ($action === 'modifier') ? 'fa-edit' : 'fa-plus-circle' ?> text-primary"></i>
                <span><?= ($action === 'modifier') ? 'Modifier la classe' : 'Ajouter une classe' ?></span>
            </div>
            <div class="card-body">
                <form method="POST" action="classes.php">
                    <input type="hidden" name="action_form" value="<?= ($action === 'modifier') ? 'modifier' : 'ajouter' ?>">
                    <?php if ($action === 'modifier' && $classeEdit): ?>
                        <input type="hidden" name="id" value="<?= h($classeEdit['id']) ?>">
                    <?php endif; ?>

                    <div class="mb-3">
                        <label class="form-label">Nom de la classe <span class="text-danger"></span></label>
                        <input type="text" name="nom" class="form-control"
                               placeholder="Ex: Sainte Therese"
                               value="<?= h($classeEdit['nom'] ?? ($_POST['nom'] ?? '')) ?>"
                               required maxlength="50">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Niveau <span class="text-danger"></span></label>
                        <select name="niveau" class="form-select" required>
                            <option value="">Sélectionner</option>
                            <?php
                            $niveaux = ['7eme','8eme','9eme','NS1','NS2','NS3','NS4'];
                            $niveauActuel = $classeEdit['niveau'] ?? ($_POST['niveau'] ?? '');
                            foreach ($niveaux as $n): ?>
                                <option value="<?= h($n) ?>" <?= $niveauActuel === $n ? 'selected' : '' ?>>
                                    <?= h($n) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Capacité maximale</label>
                        <input type="number" name="capacite" class="form-control"
                               min="1" max="100"
                               value="<?= h($classeEdit['capacite'] ?? ($_POST['capacite'] ?? 40)) ?>">
                    </div>

                    <div class="mb-4">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="2"
                                  placeholder="Description optionnelle..."><?= h($classeEdit['description'] ?? ($_POST['description'] ?? '')) ?></textarea>
                    </div>

                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-comep">
                            <i class="fas fa-save me-1"></i>
                            <?= ($action === 'modifier') ? 'Enregistrer les modifications' : 'Ajouter la classe' ?>
                        </button>
                        <?php if ($action === 'modifier'): ?>
                            <a href="classes.php" class="btn btn-outline-secondary">
                                <i class="fas fa-times me-1"></i> Annuler
                            </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ===== LISTE DES CLASSES ===== -->
    <div class="col-lg-8">
        <div class="card shadow-sm">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="fas fa-list me-2 text-primary"></i>Liste des classes (<?= count($classes) ?>)</span>
            </div>
            <div class="card-body p-0">
                <?php if (empty($classes)): ?>
                    <p class="text-muted text-center py-4">Aucune classe enregistrée.</p>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-comep table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Classe</th>
                                <th>Niveau</th>
                                <th class="text-center">Élèves</th>
                                <th class="text-center">Capacité</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($classes as $c): ?>
                            <tr>
                                <td>
                                    <div class="fw-600"><?= h($c['nom']) ?></div>
                                    <?php if ($c['description']): ?>
                                        <small class="text-muted"><?= h($c['description']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge bg-primary"><?= h($c['niveau']) ?></span></td>
                                <td class="text-center">
                                    <span class="badge <?= $c['nb_eleves'] >= $c['capacite'] ? 'bg-danger' : 'bg-success' ?>">
                                        <?= h($c['nb_eleves']) ?>
                                    </span>
                                </td>
                                <td class="text-center text-muted"><?= h($c['capacite']) ?></td>
                                <td class="text-center">
                                    <a href="classes.php?action=modifier&id=<?= $c['id'] ?>"
                                       class="btn btn-warning btn-sm btn-action me-1" title="Modifier">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <a href="classes.php?action=supprimer&id=<?= $c['id'] ?>"
                                       class="btn btn-danger btn-sm btn-action"
                                       title="Supprimer"
                                       onclick="return confirmerSuppression('la classe « <?= h(addslashes($c['nom'])) ?> »')">
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
