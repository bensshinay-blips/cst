<?php
/**
 * admin/index.php - Tableau de bord administrateur
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
requireAdmin();

$titrePage = 'Tableau de bord';
$pdo = getDB();

// ===== STATISTIQUES =====
$stats = [];
try {
    $stats['classes']    = $pdo->query("SELECT COUNT(*) FROM classes")->fetchColumn();
    $stats['eleves']     = $pdo->query("SELECT COUNT(*) FROM eleves WHERE status='actif'")->fetchColumn();
    $stats['profs']      = $pdo->query("SELECT COUNT(*) FROM utilisateurs WHERE role='professeur' AND status='actif'")->fetchColumn();
    $stats['matieres']   = $pdo->query("SELECT COUNT(*) FROM matieres")->fetchColumn();

    // Périodes de saisie ouvertes
    $stats['periodes_ouvertes'] = $pdo->query("
        SELECT COUNT(*) FROM periodes_saisie ps
        JOIN controles c ON ps.controle_id = c.id
        WHERE ps.statut = 'ouvert' AND c.annee_scolaire = '" . ANNEE_SCOLAIRE . "'
    ")->fetchColumn();

    // Élèves par classe (pour le graphique)
    $elevesParClasse = $pdo->query("
        SELECT c.nom AS classe, COUNT(e.id) AS total
        FROM classes c
        LEFT JOIN eleves e ON e.classe_id = c.id AND e.status = 'actif'
        GROUP BY c.id, c.nom
        ORDER BY c.nom
    ")->fetchAll();

    // Derniers logs (10 dernières activités)
    $dernierLogs = $pdo->query("
        SELECT l.*, u.nom, u.prenom, u.role
        FROM logs l
        LEFT JOIN utilisateurs u ON l.utilisateur_id = u.id
        ORDER BY l.created_at DESC
        LIMIT 10
    ")->fetchAll();

} catch (PDOException $e) {
    $erreurStats = "Erreur lors du chargement des statistiques.";
    error_log($e->getMessage());
}

require_once __DIR__ . '/../includes/header.php';
?>

<!-- ===== EN-TÊTE PAGE ===== -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="page-title mb-0">
        Tableau de bord
    </h1>
    <span class="text-muted small">
        <i class="fas fa-calendar-alt me-1"></i>
        <?= date('l d F Y') ?>
    </span>
</div>

<?php afficherMessage(); ?>

<!-- ===== CARTES STATISTIQUES ===== -->
<div class="row g-4 mb-4">

    <!-- Classes -->
    <div class="col-sm-6 col-xl-3 fade-in-up" style="animation-delay:0.05s">
        <div class="card stat-card shadow-sm h-100">
            <div class="card-body d-flex align-items-center gap-3 p-4"
                 style="background: linear-gradient(135deg,#1a3a5c,#2563eb);">
                <div class="text-white">
                    <div class="stat-number"><?= h($stats['classes'] ?? 0) ?></div>
                    <div class="stat-label">Classes</div>
                </div>
            </div>
            <div class="card-footer bg-white border-0 pt-0 pb-3 px-4">
                <a href="classes.php" class="text-primary text-decoration-none small fw-500">
                    Gérer les classes <i class="fas fa-arrow-right ms-1"></i>
                </a>
            </div>
        </div>
    </div>

    <!-- Élèves -->
    <div class="col-sm-6 col-xl-3 fade-in-up" style="animation-delay:0.1s">
        <div class="card stat-card shadow-sm h-100">
            <div class="card-body d-flex align-items-center gap-3 p-4"
                 style="background: linear-gradient(135deg,#065f46,#10b981);">
                
                <div class="text-white">
                    <div class="stat-number"><?= h($stats['eleves'] ?? 0) ?></div>
                    <div class="stat-label">Élèves actifs</div>
                </div>
            </div>
            <div class="card-footer bg-white border-0 pt-0 pb-3 px-4">
                <a href="eleves.php" class="text-success text-decoration-none small fw-500">
                    Gérer les élèves <i class="fas fa-arrow-right ms-1"></i>
                </a>
            </div>
        </div>
    </div>

    <!-- Professeurs -->
    <div class="col-sm-6 col-xl-3 fade-in-up" style="animation-delay:0.15s">
        <div class="card stat-card shadow-sm h-100">
            <div class="card-body d-flex align-items-center gap-3 p-4"
                 style="background: linear-gradient(135deg,#7c3aed,#a855f7);">
                
                <div class="text-white">
                    <div class="stat-number"><?= h($stats['profs'] ?? 0) ?></div>
                    <div class="stat-label">Professeurs</div>
                </div>
            </div>
            <div class="card-footer bg-white border-0 pt-0 pb-3 px-4">
                <a href="professeurs.php" class="text-purple text-decoration-none small fw-500" style="color:#7c3aed">
                    Gérer les profs <i class="fas fa-arrow-right ms-1"></i>
                </a>
            </div>
        </div>
    </div>

    <!-- Matières -->
    <div class="col-sm-6 col-xl-3 fade-in-up" style="animation-delay:0.2s">
        <div class="card stat-card shadow-sm h-100">
            <div class="card-body d-flex align-items-center gap-3 p-4"
                 style="background: linear-gradient(135deg,#b45309,#f59e0b);">
               
                <div class="text-white">
                    <div class="stat-number"><?= h($stats['matieres'] ?? 0) ?></div>
                    <div class="stat-label">Matières</div>
                </div>
            </div>
            <div class="card-footer bg-white border-0 pt-0 pb-3 px-4">
                <a href="matieres.php" class="text-warning text-decoration-none small fw-500">
                    Gérer les matières <i class="fas fa-arrow-right ms-1"></i>
                </a>
            </div>
        </div>
    </div>

    <!-- Périodes ouvertes -->
    <div class="col-sm-6 col-xl-3 fade-in-up" style="animation-delay:0.25s">
        <div class="card stat-card shadow-sm h-100">
            <div class="card-body d-flex align-items-center gap-3 p-4"
                 style="background: linear-gradient(135deg,<?= ($stats['periodes_ouvertes']??0) > 0 ? '#065f46,#10b981' : '#7f1d1d,#ef4444' ?>);">
                
                <div class="text-white">
                    <div class="stat-number"><?= h($stats['periodes_ouvertes'] ?? 0) ?></div>
                    <div class="stat-label">Période(s) ouverte(s)</div>
                </div>
            </div>
            <div class="card-footer bg-white border-0 pt-0 pb-3 px-4">
                <a href="periodes_saisie.php" class="text-decoration-none small fw-500"
                   style="color:<?= ($stats['periodes_ouvertes']??0) > 0 ? '#065f46' : '#dc2626' ?>">
                    Gérer les périodes <i class="fas fa-arrow-right ms-1"></i>
                </a>
            </div>
        </div>
    </div>
</div>

<!-- ===== GRAPHIQUE + LOGS ===== -->
<div class="row g-4">

    <!-- Graphique Élèves par Classe -->
    <div class="col-lg-7 fade-in-up" style="animation-delay:0.25s">
        <div class="card shadow-sm h-100">
            <div class="card-header d-flex align-items-center gap-2">
                <span>Répartition des élèves par classe</span>
            </div>
            <div class="card-body">
                <canvas id="graphEleves" height="220"></canvas>
            </div>
        </div>
    </div>

    <!-- Dernières activités -->
    <div class="col-lg-5 fade-in-up" style="animation-delay:0.3s">
        <div class="card shadow-sm h-100">
            <div class="card-header d-flex align-items-center gap-2">

                <span>Dernières activités</span>
            </div>
            <div class="card-body p-0">
                <?php if (!empty($dernierLogs)): ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($dernierLogs as $log): ?>
                    <li class="list-group-item px-3 py-2">
                        <div class="d-flex align-items-start gap-2">
                            <span class="badge <?= $log['role'] === 'admin' ? 'bg-warning text-dark' : 'bg-info' ?> mt-1" style="font-size:0.65rem">
                                <?= h(strtoupper($log['role'] ?? '?')) ?>
                            </span>
                            <div>
                                <div class="small fw-500"><?= h($log['action']) ?></div>
                                <div class="text-muted" style="font-size:0.75rem">
                                    <?= h($log['prenom'] ?? '') ?> <?= h($log['nom'] ?? '') ?>
                                    &mdash; <?= date('d/m H:i', strtotime($log['created_at'])) ?>
                                </div>
                            </div>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php else: ?>
                <p class="text-muted text-center py-4 mb-0">Aucune activité enregistrée.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ===== LIENS RAPIDES ===== -->
<div class="row g-3 mt-2">
    <div class="col-12">
        <div class="card shadow-sm">
            <div class="card-header">Accès rapides</div>
            <div class="card-body d-flex flex-wrap gap-2">
                <a href="periodes_saisie.php" class="btn <?= ($stats['periodes_ouvertes']??0)>0 ? 'btn-outline-success' : 'btn-outline-danger' ?> btn-sm">
                     Périodes de saisie
                </a>
                <a href="eleves.php?action=ajouter" class="btn btn-comep btn-sm">
                 </i> Nouvel élève
                </a>
                <a href="professeurs.php?action=ajouter" class="btn btn-outline-primary btn-sm">
                   Nouveau professeur
                </a>
                <a href="assignements.php" class="btn btn-outline-secondary btn-sm">
                   Assignations
                </a>
                <a href="bulletins.php" class="btn btn-outline-success btn-sm">
                  Bulletins
                </a>
                <a href="classes.php" class="btn btn-outline-info btn-sm">
                    Classes
                </a>
            </div>
        </div>
    </div>
</div>

<?php
// Données pour le graphique (encodées JSON)
$labelsGraph = json_encode(array_column($elevesParClasse ?? [], 'classe'));
$dataGraph    = json_encode(array_column($elevesParClasse ?? [], 'total'));

$scriptPage = <<<SCRIPT
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
const ctx = document.getElementById('graphEleves').getContext('2d');
new Chart(ctx, {
    type: 'bar',
    data: {
        labels: {$labelsGraph},
        datasets: [{
            label: 'Nombre d\'élèves',
            data: {$dataGraph},
            backgroundColor: [
                '#2563eb','#10b981','#f59e0b','#ef4444','#8b5cf6','#06b6d4','#ec4899'
            ],
            borderRadius: 8,
            borderSkipped: false,
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: { display: false },
            tooltip: {
                callbacks: {
                    label: ctx => ctx.parsed.y + ' élève(s)'
                }
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: { stepSize: 1 },
                grid: { color: '#f3f4f6' }
            },
            x: { grid: { display: false } }
        }
    }
});
</script>
SCRIPT;

require_once __DIR__ . '/../includes/footer.php';
?>
