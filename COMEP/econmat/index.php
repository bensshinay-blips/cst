<?php
/**
 * econmat/index.php - Tableau de bord Économat
 */

// Chemins absolus — évite tout problème de chemin relatif
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';

requireEconmat();

$titrePage = 'Économat — Tableau de bord';
$pdo = getDB();

$totalEleves   = 0;
$totalAutorise = 0;
$totalBloque   = 0;
$resumeClasses = [];

try {
    $totalEleves = $pdo->query(
        "SELECT COUNT(*) FROM eleves WHERE status='actif'"
    )->fetchColumn();

    $totalAutorise = $pdo->query(
        "SELECT COUNT(*) FROM acces_bulletins
         WHERE acces='autorise' AND annee_scolaire='" . ANNEE_SCOLAIRE . "'"
    )->fetchColumn();

    $totalBloque = $pdo->query(
        "SELECT COUNT(*) FROM acces_bulletins
         WHERE acces='bloque' AND annee_scolaire='" . ANNEE_SCOLAIRE . "'"
    )->fetchColumn();

    $resumeClasses = $pdo->query("
        SELECT c.id,
               c.nom    AS classe,
               c.niveau,
               COUNT(e.id) AS total,
               SUM(CASE WHEN ab.acces = 'autorise' THEN 1 ELSE 0 END) AS nb_autorise,
               SUM(CASE WHEN ab.acces = 'bloque' OR ab.acces IS NULL THEN 1 ELSE 0 END) AS nb_bloque
        FROM classes c
        JOIN eleves e
            ON e.classe_id = c.id AND e.status = 'actif'
        LEFT JOIN acces_bulletins ab
            ON ab.eleve_id = e.id AND ab.annee_scolaire = '" . ANNEE_SCOLAIRE . "'
        GROUP BY c.id, c.nom, c.niveau
        ORDER BY c.nom
    ")->fetchAll();

} catch (PDOException $e) {
    error_log("Econmat index error: " . $e->getMessage());
}

require_once dirname(__DIR__) . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="page-title mb-0">
        Économat
    </h1>
    <span class="badge bg-success px-3 py-2">
        <i class="fas fa-user me-1"></i>
        <?= h($_SESSION['prenom']) ?> <?= h($_SESSION['nom']) ?>
    </span>
</div>

<?php afficherMessage(); ?>

<!-- Statistiques -->
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card shadow-sm text-center p-4"
             style="border-left:5px solid #0d6efd">
            <div class="fs-2 fw-bold text-primary"><?= (int)$totalEleves ?></div>
            <div class="text-muted">Élèves actifs</div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card shadow-sm text-center p-4"
             style="border-left:5px solid #10b981">
            <div class="fs-2 fw-bold text-success"><?= (int)$totalAutorise ?></div>
            <div class="text-muted">
                Accès autorisé
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card shadow-sm text-center p-4"
             style="border-left:5px solid #ef4444">
            <div class="fs-2 fw-bold text-danger"><?= (int)$totalBloque ?></div>
            <div class="text-muted">
             Accès bloqué
            </div>
        </div>
    </div>
</div>

<!-- Tableau des classes -->
<div class="card shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>
            <i class="fas fa-list me-2 text-primary"></i>
            État par classe
        </span>
        <a href="acces.php" class="btn btn-comep btn-sm">
            Gérer les accès
        </a>
    </div>
    <div class="table-responsive">
        <?php if (empty($resumeClasses)): ?>
            <p class="text-muted text-center py-4">
                Aucun élève actif trouvé.
            </p>
        <?php else: ?>
        <table class="table table-comep table-hover mb-0 align-middle">
            <thead>
                <tr>
                    <th>Classe</th>
                    <th>Niveau</th>
                    <th class="text-center">Total</th>
                    <th class="text-center">Autorisés</th>
                    <th class="text-center">Bloqués</th>
                    <th class="text-center">Progression</th>
                    <th class="text-center">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($resumeClasses as $rc):
                    $total = (int)$rc['total'];
                    $nbOk  = (int)$rc['nb_autorise'];
                    $nbNok = (int)$rc['nb_bloque'];
                    $pct   = $total > 0 ? round($nbOk / $total * 100) : 0;
                ?>
                <tr>
                    <td class="fw-bold"><?= h($rc['classe']) ?></td>
                    <td><small class="text-muted"><?= h($rc['niveau']) ?></small></td>
                    <td class="text-center"><?= $total ?></td>
                    <td class="text-center">
                        <span class="badge bg-success"><?= $nbOk ?></span>
                    </td>
                    <td class="text-center">
                        <span class="badge bg-danger"><?= $nbNok ?></span>
                    </td>
                    <td style="min-width:140px">
                        <div class="d-flex align-items-center gap-2">
                            <div class="progress flex-grow-1" style="height:8px">
                                <div class="progress-bar bg-success"
                                     style="width:<?= $pct ?>%"></div>
                            </div>
                            <small class="text-muted"><?= $pct ?>%</small>
                        </div>
                    </td>
                    <td class="text-center">
                        <a href="acces.php?classe_id=<?= (int)$rc['id'] ?>"
                           class="btn btn-outline-primary btn-sm">
                           Gérer
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
