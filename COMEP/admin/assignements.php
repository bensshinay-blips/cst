<?php
/**
 * admin/assignements.php - Assigner professeurs aux classes/matières
 * Version 2 : Affichage par Niveau et Barème
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
requireAdmin();

$titrePage = 'Assignations Professeurs';
$pdo    = getDB();
$erreur = '';

// ===== AJOUT =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action_form'] ?? '') === 'assigner') {
    $prof_id   = (int)($_POST['professeur_id'] ?? 0);
    $classe_id = (int)($_POST['classe_id']     ?? 0);
    $mat_id    = (int)($_POST['matiere_id']    ?? 0);

    if ($prof_id < 1 || $classe_id < 1 || $mat_id < 1) {
        $erreur = 'Veuillez sélectionner un professeur, un niveau et une matière.';
    } else {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO professeur_classes (professeur_id, classe_id, matiere_id, annee_scolaire)
                VALUES (:p, :c, :m, :a)
            ");
            $stmt->execute([':p'=>$prof_id,':c'=>$classe_id,':m'=>$mat_id,':a'=>ANNEE_SCOLAIRE]);
            logAction($pdo, 'ASSIGNATION', 'professeur_classes', (int)$pdo->lastInsertId(),
                      "Prof:$prof_id Classe:$classe_id Mat:$mat_id");
            setMessage("Assignation effectuée avec succès.");
            header('Location: assignements.php'); exit();
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                $erreur = 'Cette assignation existe déjà.';
            } else {
                $erreur = "Erreur : " . $e->getMessage();
            }
        }
    }
}

// ===== SUPPRESSION =====
if (isset($_GET['action']) && $_GET['action'] === 'supprimer' && isset($_GET['id'])) {
    $sid = (int)$_GET['id'];
    try {
        $pdo->prepare("DELETE FROM professeur_classes WHERE id=:id")->execute([':id'=>$sid]);
        logAction($pdo,'SUPPRESSION ASSIGNATION','professeur_classes',$sid,'');
        setMessage("Assignation supprimée.");
    } catch (PDOException $e) {
        setMessage("Erreur : " . $e->getMessage(), 'erreur');
    }
    header('Location: assignements.php'); exit();
}

// Données pour formulaire
$professeurs = $pdo->query("SELECT id, CONCAT(prenom,' ',nom) AS nom_complet FROM utilisateurs WHERE role='professeur' AND status='actif' ORDER BY nom")->fetchAll();

// Classes avec niveau pour l'affichage
$classes = $pdo->query("SELECT id, nom, niveau FROM classes ORDER BY FIELD(niveau,'7eme','8eme','9eme','NS1','NS2','NS3','NS4'), nom")->fetchAll();

// Matières avec coefficient (gardé pour info)
$matieres = $pdo->query("SELECT id, nom, coefficient FROM matieres ORDER BY nom")->fetchAll();

// Liste des assignations avec BARÈME (depuis classe_matieres)
$assignations = $pdo->query("
    SELECT pc.id,
           CONCAT(u.prenom,' ',u.nom) AS professeur,
           c.nom AS classe_nom,
           c.niveau AS classe_niveau,
           m.nom AS matiere,
           m.coefficient,
           COALESCE(cm.bareme, 100) AS bareme,
           pc.annee_scolaire
    FROM professeur_classes pc
    JOIN utilisateurs u ON pc.professeur_id = u.id
    JOIN classes c      ON pc.classe_id     = c.id
    JOIN matieres m     ON pc.matiere_id    = m.id
    LEFT JOIN classe_matieres cm ON cm.classe_id = c.id 
        AND cm.matiere_id = m.id 
        AND cm.annee_scolaire = pc.annee_scolaire
    ORDER BY FIELD(c.niveau,'7eme','8eme','9eme','NS1','NS2','NS3','NS4'), c.nom, u.nom, m.nom
")->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<h1 class="page-title">Assignations Professeurs</h1>
<?php afficherMessage(); ?>
<?php if (!empty($erreur)): ?>
    <div class="alert alert-danger"><i class="fas fa-exclamation-circle me-2"></i><?= h($erreur) ?></div>
<?php endif; ?>

<div class="row g-4">

    <!-- Formulaire -->
    <div class="col-lg-4">
        <div class="card shadow-sm">
            <div class="card-header d-flex align-items-center gap-2">
                <span>Nouvelle assignation</span>
            </div>
            <div class="card-body">
                <form method="POST" action="assignements.php">
                    <input type="hidden" name="action_form" value="assigner">

                    <div class="mb-3">
                        <label class="form-label">Professeur <span class="text-danger"></span></label>
                        <select name="professeur_id" class="form-select" required>
                            <option value="">Sélectionner un professeur</option>
                            <?php foreach ($professeurs as $p): ?>
                                <option value="<?= $p['id'] ?>"><?= h($p['nom_complet']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Niveau <span class="text-danger"></span></label>
                        <select name="classe_id" class="form-select" required>
                            <option value="">Sélectionner un niveau</option>
                            <?php foreach ($classes as $c): ?>
                                <option value="<?= $c['id'] ?>">
                                    <?php 
                                    $niveau = $c['niveau'];
                                    $niveauxAF = ['7eme', '8eme', '9eme'];
                                    if (in_array($niveau, $niveauxAF)) {
                                        echo h($niveau . ' AF - ' . $c['nom']);
                                    } else {
                                        echo h($niveau . ' - ' . $c['nom']);
                                    }
                                    ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-4">
                        <label class="form-label">Matière <span class="text-danger"></span></label>
                        <select name="matiere_id" class="form-select" required>
                            <option value="">Sélectionner une matière</option>
                            <?php foreach ($matieres as $m): ?>
                                <option value="<?= $m['id'] ?>">
                                    <?= h($m['nom']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text small text-muted">
                            Le barème est défini dans "Matières par Classe"
                        </div>
                    </div>

                    <p class="text-muted small mb-3">
                        <i class="fas fa-info-circle me-1"></i>
                        Année scolaire active : <strong><?= ANNEE_SCOLAIRE ?></strong>
                    </p>

                    <div class="d-grid">
                        <button type="submit" class="btn btn-comep">
                            <i class="fas fa-link me-1"></i> Assigner
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Liste des assignations -->
    <div class="col-lg-8">
        <div class="card shadow-sm">
            <div class="card-header">
                <i class="fas fa-list me-2 text-primary"></i>
                Assignations en cours (<?= count($assignations) ?>)
            </div>
            <div class="card-body p-0">
                <?php if (empty($assignations)): ?>
                    <p class="text-muted text-center py-4">Aucune assignation enregistrée.</p>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-comep table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Professeur</th>
                                <th>Niveau</th>
                                <th>Matière</th>
                                <th class="text-center">Barème</th>
                                <th>Année</th>
                                <th class="text-center">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($assignations as $a): ?>
                            <tr>
                                <td class="fw-500"><?= h($a['professeur']) ?></td>
                                <td>
                                    <span class="badge bg-primary">
                                        <?php 
                                        $niveau = $a['classe_niveau'];
                                        $niveauxAF = ['7eme', '8eme', '9eme'];
                                        if (in_array($niveau, $niveauxAF)) {
                                            echo h($niveau . ' AF');
                                        } else {
                                            echo h($niveau);
                                        }
                                        ?>
                                    </span>
                                    <small class="text-muted d-block"><?= h($a['classe_nom']) ?></small>
                                </td>
                                <td><?= h($a['matiere']) ?></td>
                                <td class="text-center">
                                    <span class="badge bg-success"> <?= h($a['bareme']) ?></span>
                                </td>
                                <td><small class="text-muted"><?= h($a['annee_scolaire']) ?></small></td>
                                <td class="text-center">
                                    <a href="assignements.php?action=supprimer&id=<?= $a['id'] ?>"
                                       class="btn btn-danger btn-sm btn-action"
                                       onclick="return confirmerSuppression('cette assignation')">
                                        <i class="fas fa-unlink"></i>
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