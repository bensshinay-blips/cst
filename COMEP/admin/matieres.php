<?php
/**
 * admin/matieres.php - Gestion des matières (CRUD)
 * Version 1 : sans coefficient, sans barème global
 * Le barème est défini par classe dans admin/classe_matieres.php
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
requireAdmin();

$titrePage = 'Gestion des Matières';
$pdo    = getDB();
$action = $_GET['action'] ?? 'liste';
$id     = (int)($_GET['id'] ?? 0);
$erreur = '';

// ===== TRAITEMENTS POST =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nom    = trim($_POST['nom']  ?? '');
    $code   = strtoupper(trim($_POST['code'] ?? ''));
    $idPost = (int)($_POST['id'] ?? 0);

    if (empty($nom) || empty($code)) {
        $erreur = 'Le nom et le code sont obligatoires.';
    } else {
        try {
            if ($_POST['action_form'] === 'ajouter') {
                $check = $pdo->prepare("SELECT id FROM matieres WHERE code=:code");
                $check->execute([':code' => $code]);
                if ($check->fetch()) {
                    $erreur = "Le code « {$code} » est déjà utilisé.";
                } else {
                    $stmt = $pdo->prepare("INSERT INTO matieres (nom, code) VALUES (:nom, :code)");
                    $stmt->execute([':nom' => $nom, ':code' => $code]);
                    logAction($pdo, 'AJOUT MATIERE', 'matieres', (int)$pdo->lastInsertId(), "$nom ($code)");
                    setMessage("Matière « {$nom} » ajoutée. Assignez-lui un barème par classe dans 'Matières par Classe'.");
                    header('Location: matieres.php'); exit();
                }
            } else {
                $stmt = $pdo->prepare("UPDATE matieres SET nom=:nom, code=:code WHERE id=:id");
                $stmt->execute([':nom' => $nom, ':code' => $code, ':id' => $idPost]);
                logAction($pdo, 'MODIFICATION MATIERE', 'matieres', $idPost, "$nom ($code)");
                setMessage("Matière « {$nom} » modifiée.");
                header('Location: matieres.php'); exit();
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
        $nbNotes = $pdo->prepare("SELECT COUNT(*) FROM notes WHERE matiere_id=:id");
        $nbNotes->execute([':id' => $id]);
        if ($nbNotes->fetchColumn() > 0) {
            setMessage("Impossible de supprimer : des notes sont liées à cette matière.", 'erreur');
        } else {
            $mat = $pdo->prepare("SELECT nom FROM matieres WHERE id=:id");
            $mat->execute([':id' => $id]);
            $matData = $mat->fetch();
            // Supprimer aussi les assignations classe_matieres
            $pdo->prepare("DELETE FROM classe_matieres WHERE matiere_id=:id")->execute([':id' => $id]);
            $pdo->prepare("DELETE FROM matieres WHERE id=:id")->execute([':id' => $id]);
            logAction($pdo, 'SUPPRESSION MATIERE', 'matieres', $id, $matData['nom']);
            setMessage("Matière « {$matData['nom']} » supprimée.");
        }
    } catch (PDOException $e) {
        setMessage("Erreur : " . $e->getMessage(), 'erreur');
    }
    header('Location: matieres.php'); exit();
}

$matiereEdit = null;
if ($action === 'modifier' && $id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM matieres WHERE id=:id");
    $stmt->execute([':id' => $id]);
    $matiereEdit = $stmt->fetch();
    if (!$matiereEdit) { header('Location: matieres.php'); exit(); }
}

// Liste matières avec nombre de classes où elles sont assignées
$matieres = $pdo->query("
    SELECT m.*,
           COUNT(DISTINCT cm.classe_id) AS nb_classes,
           COUNT(DISTINCT n.id)         AS nb_notes
    FROM matieres m
    LEFT JOIN classe_matieres cm ON cm.matiere_id = m.id
    LEFT JOIN notes n            ON n.matiere_id  = m.id
    GROUP BY m.id
    ORDER BY m.nom
")->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<h1 class="page-title"> Gestion des Matières</h1>

<!-- Info importante -->
<div class="alert alert-info d-flex gap-2 align-items-center mb-4">
    <i class="fas fa-info-circle fa-lg"></i>
    <div>
        <strong>Message important :</strong>
        Les matières sont des références globales (nom + code).
        Le <strong>barème par classe</strong> se configure dans
        <a href="classe_matieres.php" class="alert-link">
            Matières par Classe
        </a>.
    </div>
</div>

<?php afficherMessage(); ?>
<?php if (!empty($erreur)): ?>
    <div class="alert alert-danger"><i class="fas fa-exclamation-circle me-2"></i><?= h($erreur) ?></div>
<?php endif; ?>

<div class="row g-4">

    <!-- Formulaire -->
    <div class="col-lg-4">
        <div class="card shadow-sm">
            <div class="card-header d-flex align-items-center gap-2">
                <i class="f<?= $action==='modifier' ? 'fa-edit' : 'fa-plus-circle' ?> text-primary"></i>
                <span><?= $action==='modifier' ? 'Modifier la matière' : 'Ajouter une matière' ?></span>
            </div>
            <div class="card-body">
                <form method="POST" action="matieres.php">
                    <input type="hidden" name="action_form"
                           value="<?= $action==='modifier' ? 'modifier' : 'ajouter' ?>">
                    <?php if ($action==='modifier' && $matiereEdit): ?>
                        <input type="hidden" name="id" value="<?= h($matiereEdit['id']) ?>">
                    <?php endif; ?>

                    <div class="mb-3">
                        <label class="form-label">Nom de la matière <span class="text-danger"></span></label>
                        <input type="text" name="nom" class="form-control"
                               placeholder="Ex: Algèbre"
                               value="<?= h($matiereEdit['nom'] ?? ($_POST['nom'] ?? '')) ?>"
                               required maxlength="100">
                    </div>

                    <div class="mb-4">
                        <label class="form-label">Code <span class="text-danger"></span></label>
                        <input type="text" name="code" class="form-control"
                               placeholder="Ex: ALG"
                               value="<?= h($matiereEdit['code'] ?? ($_POST['code'] ?? '')) ?>"
                               required maxlength="10" style="text-transform:uppercase">
                        <div class="form-text">Code unique, en majuscules. Ex: MATH, BIO, CHIM</div>
                    </div>
                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-comep">
                            <i class="fas fa-save me-1"></i>
                            <?= $action==='modifier' ? 'Enregistrer' : 'Ajouter' ?>
                        </button>
                        <?php if ($action==='modifier'): ?>
                            <a href="matieres.php" class="btn btn-outline-secondary">
                                <i class="fas fa-times me-1"></i> Annuler
                            </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <!-- Lien rapide -->
        <div class="card shadow-sm mt-3">
            <div class="card-body text-center">
                <i class="fas fa-cog fa-2x text-primary mb-2"></i>
                <p class="mb-2 small">Configurer les barèmes par classe</p>
                <a href="classe_matieres.php" class="btn btn-comep w-100">
                    <i class="fas fa-arrow-right me-1"></i> Matières par Classe
                </a>
            </div>
        </div>
    </div>

    <!-- Liste -->
    <div class="col-lg-8">
        <div class="card shadow-sm">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>
                    <i class="fas fa-list me-2 text-primary"></i>
                    Liste des matières (<?= count($matieres) ?>)
                </span>
            </div>
            <div class="card-body p-0">
                <?php if (empty($matieres)): ?>
                    <p class="text-muted text-center py-4">Aucune matière enregistrée.</p>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-comep table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Matière</th>
                                <th class="text-center">Code</th>
                                <th class="text-center">Assignée à</th>
                                <th class="text-center">Notes</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($matieres as $m): ?>
                            <tr>
                                <td class="fw-500"><?= h($m['nom']) ?></td>
                                <td class="text-center">
                                    <span class="badge bg-secondary"><?= h($m['code']) ?></span>
                                </td>
                                <td class="text-center">
                                    <?php if ($m['nb_classes'] > 0): ?>
                                        <span class="badge bg-success">
                                            <?= h($m['nb_classes']) ?> classe(s)
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-warning text-dark">
                                            Non assignée
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center text-muted"><?= h($m['nb_notes']) ?></td>
                                <td class="text-center">
                                    <a href="matieres.php?action=modifier&id=<?= $m['id'] ?>"
                                       class="btn btn-warning btn-sm btn-action me-1" title="Modifier">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <a href="matieres.php?action=supprimer&id=<?= $m['id'] ?>"
                                       class="btn btn-danger btn-sm btn-action"
                                       onclick="return confirmerSuppression('la matière « <?= h(addslashes($m['nom'])) ?> »')">
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
