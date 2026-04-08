<?php
/**
 * admin/classe_matieres.php - Gestion des matières et barèmes par classe
 * Version 2 : Affichage par NIVEAU (7eme AF, 8eme AF, NS1, etc.)
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
requireAdmin();

$titrePage  = 'Matières par Niveau';
$pdo        = getDB();
$erreur     = '';

$classeSelectId = (int)($_GET['classe_id'] ?? $_POST['classe_id'] ?? 0);

// ===== AJOUT D'UNE MATIÈRE À UNE CLASSE =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action_form'] ?? '') === 'ajouter') {
    $classe_id  = (int)($_POST['classe_id']  ?? 0);
    $matiere_id = (int)($_POST['matiere_id'] ?? 0);
    $bareme     = (int)($_POST['bareme']     ?? 0);
    $annee      = ANNEE_SCOLAIRE;

    if ($classe_id < 1 || $matiere_id < 1 || $bareme < 1) {
        $erreur = 'Veuillez remplir tous les champs. Le barème doit être supérieur à 0.';
    } else {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO classe_matieres (classe_id, matiere_id, bareme, annee_scolaire)
                VALUES (:cid, :mid, :bar, :annee)
            ");
            $stmt->execute([':cid'=>$classe_id,':mid'=>$matiere_id,':bar'=>$bareme,':annee'=>$annee]);
            logAction($pdo,'AJOUT MATIERE CLASSE','classe_matieres',(int)$pdo->lastInsertId(),
                      "Classe:$classe_id Mat:$matiere_id Barème:$bareme");
            setMessage("Matière ajoutée au niveau avec un barème de /{$bareme}.");
            header("Location: classe_matieres.php?classe_id={$classe_id}"); exit();
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                $erreur = "Cette matière est déjà assignée à ce niveau pour l'année " . ANNEE_SCOLAIRE . ".";
            } else {
                $erreur = "Erreur : " . $e->getMessage();
            }
        }
    }
}

// ===== MODIFICATION DU BARÈME =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action_form'] ?? '') === 'modifier_bareme') {
    $cm_id  = (int)($_POST['cm_id']  ?? 0);
    $bareme = (int)($_POST['bareme'] ?? 0);
    $cid    = (int)($_POST['classe_id'] ?? 0);

    if ($bareme < 1) {
        $erreur = 'Le barème doit être supérieur à 0.';
    } else {
        try {
            $pdo->prepare("UPDATE classe_matieres SET bareme=:bar WHERE id=:id")
                ->execute([':bar'=>$bareme,':id'=>$cm_id]);
            logAction($pdo,'MODIFICATION BAREME','classe_matieres',$cm_id,"Nouveau barème: $bareme");
            setMessage("Barème mis à jour : /{$bareme}");
            header("Location: classe_matieres.php?classe_id={$cid}"); exit();
        } catch (PDOException $e) {
            $erreur = "Erreur : " . $e->getMessage();
        }
    }
}

// ===== SUPPRESSION =====
if (isset($_GET['action']) && $_GET['action'] === 'supprimer' && isset($_GET['id'])) {
    $sid = (int)$_GET['id'];
    $cid = (int)($_GET['classe_id'] ?? 0);
    try {
        $cm = $pdo->prepare("SELECT classe_id, matiere_id FROM classe_matieres WHERE id=:id");
        $cm->execute([':id' => $sid]);
        $cmData = $cm->fetch();

        if ($cmData) {
            $nbNotes = $pdo->prepare("
                SELECT COUNT(*) FROM notes n
                JOIN eleves e ON n.eleve_id = e.id
                WHERE n.matiere_id = :mid AND e.classe_id = :cid
            ");
            $nbNotes->execute([':mid'=>$cmData['matiere_id'],':cid'=>$cmData['classe_id']]);
            if ($nbNotes->fetchColumn() > 0) {
                setMessage("Impossible de supprimer : des notes existent déjà pour cette matière dans ce niveau.", 'erreur');
            } else {
                $pdo->prepare("DELETE FROM classe_matieres WHERE id=:id")->execute([':id'=>$sid]);
                logAction($pdo,'SUPPRESSION MATIERE CLASSE','classe_matieres',$sid,'');
                setMessage("Matière retirée du niveau.");
            }
        }
    } catch (PDOException $e) {
        setMessage("Erreur : " . $e->getMessage(), 'erreur');
    }
    header("Location: classe_matieres.php?classe_id={$cid}"); exit();
}

// Fonction pour formater l'affichage du niveau
function afficherNiveau($niveau, $nom) {
    $niveauxAF = ['7eme', '8eme', '9eme'];
    if (in_array($niveau, $niveauxAF)) {
        return $niveau . ' AF - ' . $nom;
    }
    return $niveau . ' - ' . $nom;
}

// ===== DONNÉES =====
$classes  = $pdo->query("SELECT id, nom, niveau FROM classes ORDER BY FIELD(niveau,'7eme','8eme','9eme','NS1','NS2','NS3','NS4'), nom")->fetchAll();
$matieres = $pdo->query("SELECT id, nom, code FROM matieres ORDER BY nom")->fetchAll();

// Matières de la classe sélectionnée
$classesMatieres = [];
$totalBareme     = 0;
$classeInfo      = null;

if ($classeSelectId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM classes WHERE id=:id");
    $stmt->execute([':id' => $classeSelectId]);
    $classeInfo = $stmt->fetch();

    $stmt = $pdo->prepare("
        SELECT cm.id AS cm_id, cm.bareme, cm.annee_scolaire,
               m.id AS matiere_id, m.nom AS matiere_nom, m.code AS matiere_code,
               COUNT(DISTINCT n.id) AS nb_notes
        FROM classe_matieres cm
        JOIN matieres m ON cm.matiere_id = m.id
        LEFT JOIN notes n ON n.matiere_id = m.id
            AND n.eleve_id IN (SELECT id FROM eleves WHERE classe_id = :cid AND status='actif')
        WHERE cm.classe_id = :cid2 AND cm.annee_scolaire = :annee
        GROUP BY cm.id
        ORDER BY m.nom
    ");
    $stmt->execute([':cid'=>$classeSelectId,':cid2'=>$classeSelectId,':annee'=>ANNEE_SCOLAIRE]);
    $classesMatieres = $stmt->fetchAll();

    $totalBareme = array_sum(array_column($classesMatieres, 'bareme'));
}

// Matières non encore assignées à cette classe
$matieresDispo = [];
if ($classeSelectId > 0) {
    $assigneesIds = array_column($classesMatieres, 'matiere_id');
    foreach ($matieres as $m) {
        if (!in_array($m['id'], $assigneesIds)) {
            $matieresDispo[] = $m;
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<h1 class="page-title">
Matières & Barèmes par Niveau
</h1>

<?php afficherMessage(); ?>
<?php if (!empty($erreur)): ?>
    <div class="alert alert-danger"><i class="fas fa-exclamation-circle me-2"></i><?= h($erreur) ?></div>
<?php endif; ?>

<!-- Sélection du niveau -->
<div class="card shadow-sm mb-4">
    <div class="card-header">Sélectionner un niveau</div>
    <div class="card-body">
        <form method="GET" action="classe_matieres.php" class="row g-3 align-items-end">
            <div class="col-md-4">
                <label class="form-label">Niveau</label>
                <select name="classe_id" class="form-select" onchange="this.form.submit()">
                    <option value="">Choisir un niveau</option>
                    <?php foreach ($classes as $c): 
                        $niveau = $c['niveau'];
                        $niveauxAF = ['7eme', '8eme', '9eme'];
                        if (in_array($niveau, $niveauxAF)) {
                            $afficher = $niveau . ' AF - ' . $c['nom'];
                        } else {
                            $afficher = $niveau . ' - ' . $c['nom'];
                        }
                    ?>
                        <option value="<?= $c['id'] ?>"
                                <?= $classeSelectId===$c['id']?'selected':'' ?>>
                            <?= h($afficher) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($classeSelectId): ?>
            <div class="col-md-8">
                <div class="d-flex gap-2 flex-wrap">
                    <?php foreach ($classes as $c): 
                        $niveau = $c['niveau'];
                        $niveauxAF = ['7eme', '8eme', '9eme'];
                        if (in_array($niveau, $niveauxAF)) {
                            $afficher = $niveau . ' AF';
                        } else {
                            $afficher = $niveau;
                        }
                    ?>
                        <a href="classe_matieres.php?classe_id=<?= $c['id'] ?>"
                           class="btn btn-sm <?= $classeSelectId===$c['id']?'btn-comep':'btn-outline-secondary' ?>">
                            <?= h($afficher) ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </form>
    </div>
</div>

<?php if ($classeSelectId && $classeInfo): 

$niveauAffichage = $classeInfo['niveau'];
$niveauxAF = ['7eme', '8eme', '9eme'];
if (in_array($niveauAffichage, $niveauxAF)) {
    $titreNiveau = $niveauAffichage . ' AF';
} else {
    $titreNiveau = $niveauAffichage;
}
?>

<div class="row g-4">

    <!-- ===== MATIÈRES DE CE NIVEAU ===== -->
    <div class="col-lg-8">
        <div class="card shadow-sm">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>
                    <i class="fas fa-list me-2 text-primary"></i>
                    Niveau <strong><?= h($titreNiveau) ?></strong>
                    - <?= count($classesMatieres) ?> matière(s)
                     - Année : <?= ANNEE_SCOLAIRE ?>
                </span>
            </div>

            <?php if (empty($classesMatieres)): ?>
                <div class="card-body">
                    <div class="text-center py-4 text-muted">
                        <i class="fas fa-book fa-3x mb-3 opacity-25"></i>
                        <p>Aucune matière assignée à ce niveau.<br>
                        Utilisez le formulaire ci-dessous pour en ajouter.</p>
                    </div>
                </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-comep table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Matière</th>
                            <th class="text-center">Code</th>
                            <th class="text-center">Barème</th>
                            <th class="text-center">Notes</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($classesMatieres as $cm): ?>
                        <tr>
                            <td class="fw-500"><?= h($cm['matiere_nom']) ?></td>
                            <td class="text-center">
                                <span class="badge bg-secondary"><?= h($cm['matiere_code']) ?></span>
                            </td>
                            <td class="text-center">
                                <form method="POST" action="classe_matieres.php"
                                      class="d-flex align-items-center gap-1 justify-content-center">
                                    <input type="hidden" name="action_form" value="modifier_bareme">
                                    <input type="hidden" name="cm_id"      value="<?= $cm['cm_id'] ?>">
                                    <input type="hidden" name="classe_id"  value="<?= $classeSelectId ?>">
                                    <input type="number" name="bareme"
                                           class="form-control form-control-sm text-center fw-bold"
                                           style="width:80px"
                                           value="<?= h($cm['bareme']) ?>"
                                           min="1" max="9999"
                                           onchange="this.form.submit()"
                                           title="Changer le barème">
                                    <span class="text-muted"></span>
                                </form>
                            </td>
                            <td class="text-center text-muted"><?= h($cm['nb_notes']) ?></td>
                            <td class="text-center">
                                <a href="classe_matieres.php?action=supprimer&id=<?= $cm['cm_id'] ?>&classe_id=<?= $classeSelectId ?>"
                                   class="btn btn-danger btn-sm btn-action"
                                   onclick="return confirmerSuppression('la matière « <?= h(addslashes($cm['matiere_nom'])) ?> »')">
                                    <i class="fas fa-trash"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Résumé total -->
            <div class="p-3 border-top">
                <div class="row align-items-center">
                    <div class="col-md-6">
                        <div class="d-flex gap-3 align-items-center">
                            <div class="text-center p-3 rounded"
                                 style="background:linear-gradient(135deg,#1a3a5c,#0d6efd);color:white;min-width:120px">
                                <div class="fw-bold fs-4"><?= $totalBareme ?></div>
                                <small>Total des points</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ===== FORMULAIRE AJOUT ===== -->
    <div class="col-lg-4">
        <div class="card shadow-sm">
            <div class="card-header d-flex align-items-center gap-2">
                <span>Ajouter une matière</span>
            </div>
            <div class="card-body">
                <?php if (empty($matieresDispo)): ?>
                    <div class="text-center text-muted py-3">
                        <i class="fas fa-check-circle fa-2x text-success mb-2"></i>
                        <p class="mb-0">Toutes les matières sont déjà assignées à ce niveau.</p>
                        <a href="matieres.php" class="btn btn-outline-primary btn-sm mt-3">
                           Créer une nouvelle matière
                        </a>
                    </div>
                <?php else: ?>
                <form method="POST" action="classe_matieres.php">
                    <input type="hidden" name="action_form" value="ajouter">
                    <input type="hidden" name="classe_id"   value="<?= $classeSelectId ?>">

                    <div class="mb-3">
                        <label class="form-label">Matière <span class="text-danger"></span></label>
                        <select name="matiere_id" class="form-select" required>
                            <option value="">Sélectionner une matière</option>
                            <?php foreach ($matieresDispo as $m): ?>
                                <option value="<?= $m['id'] ?>">
                                    <?= h($m['nom']) ?> (<?= h($m['code']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-4">
                        <label class="form-label">
                            Barème (points max) <span class="text-danger"></span>
                        </label>
                        <div class="input-group">
                            <span class="input-group-text">/</span>
                            <input type="number" name="bareme" class="form-control"
                                   placeholder="Ex: 200" min="1" max="9999"
                                   required>
                        </div>
                        <div class="form-text">
                            Exemples : 100, 200, 300, 400 points
                        </div>
                    </div>

                    <div class="d-grid">
                        <button type="submit" class="btn btn-comep">
                            <i class="fas fa-plus me-1"></i> Ajouter au niveau
                        </button>
                    </div>
                </form>
                <?php endif; ?>
            </div>
        </div>

        <!-- Résumé rapide par niveau -->
        <div class="card shadow-sm mt-3">
            <div class="card-header">
                <i class="fas fa-chart-bar me-2 text-primary"></i>
                Résumé tous les niveaux
            </div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Niveau</th>
                            <th class="text-center">Matières</th>
                            <th class="text-center">Total pts</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $resumeClasses = $pdo->query("
                            SELECT c.id, c.nom, c.niveau,
                                   COUNT(cm.id) AS nb_matieres,
                                   COALESCE(SUM(cm.bareme), 0) AS total_bareme
                            FROM classes c
                            LEFT JOIN classe_matieres cm
                                ON cm.classe_id = c.id
                                AND cm.annee_scolaire = '" . ANNEE_SCOLAIRE . "'
                            GROUP BY c.id
                            ORDER BY FIELD(c.niveau,'7eme','8eme','9eme','NS1','NS2','NS3','NS4'), c.nom
                        ")->fetchAll();
                        foreach ($resumeClasses as $rc): 
                            $niveau = $rc['niveau'];
                            $niveauxAF = ['7eme', '8eme', '9eme'];
                            if (in_array($niveau, $niveauxAF)) {
                                $afficher = $niveau . ' AF';
                            } else {
                                $afficher = $niveau;
                            }
                        ?>
                        <tr class="<?= $rc['id']===$classeSelectId?'table-primary':'' ?>">
                            <td class="fw-500"><?= h($afficher) ?> <small class="text-muted"></small></td>
                            <td class="text-center"><?= h($rc['nb_matieres']) ?></td>
                            <td class="text-center">
                                <?php if ($rc['total_bareme'] > 0): ?>
                                    <strong><?= h($rc['total_bareme']) ?></strong>
                                <?php else: ?>
                                    <span class="text-danger">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php elseif (!$classeSelectId): ?>
<div class="text-center py-5 text-muted">
    <i class="fas fa-hand-point-up fa-3x mb-3 opacity-25"></i>
    <p class="fs-5">Sélectionnez un niveau pour gérer ses matières et barèmes.</p>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>