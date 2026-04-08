<?php
/**
 * professeur/index.php - Tableau de bord professeur
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
requireProfesseur();

$titrePage = 'Tableau de bord Professeur';
$pdo    = getDB();
$profId = $_SESSION['utilisateur_id'];

// Mes assignations (classes + matières que j'enseigne)
$assignations = $pdo->prepare("
    SELECT pc.id AS assign_id, pc.classe_id, pc.matiere_id,
           c.nom AS classe, c.niveau AS niveau, m.nom AS matiere, m.coefficient,
           pc.annee_scolaire
    FROM professeur_classes pc
    JOIN classes c  ON pc.classe_id   = c.id
    JOIN matieres m ON pc.matiere_id  = m.id
    WHERE pc.professeur_id = :pid
    AND pc.annee_scolaire  = :annee
    ORDER BY c.nom, m.nom
");
$assignations->execute([':pid'=>$profId, ':annee'=>ANNEE_SCOLAIRE]);
$assignations = $assignations->fetchAll();

// Nombre de notes que j'ai saisies ce mois
$nbNotes = $pdo->prepare("SELECT COUNT(*) FROM notes WHERE professeur_id=:pid AND MONTH(created_at)=MONTH(NOW())");
$nbNotes->execute([':pid'=>$profId]);
$nbNotes = $nbNotes->fetchColumn();

// Contrôles disponibles
$controles = $pdo->query("SELECT * FROM controles WHERE annee_scolaire='".ANNEE_SCOLAIRE."' ORDER BY numero")->fetchAll();

// Grouper par classe
$classesProfesseur = [];
foreach ($assignations as $a) {
    $classesProfesseur[$a['classe_id']]['nom'] = $a['classe'];
    $classesProfesseur[$a['classe_id']]['niveau'] = $a['niveau'];
    $classesProfesseur[$a['classe_id']]['matieres'][] = $a;
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="page-title mb-0">
        Tableau de bord
    </h1>
    <span class="text-muted small"><?= date('l d F Y') ?></span>
</div>

<?php afficherMessage(); ?>

<!-- Bienvenue -->
<div class="alert bg-comep-light border-0 mb-4 d-flex align-items-center gap-3">
    
    <div>
        <strong>Bienvenue, <?= h($_SESSION['prenom']) ?> <?= h($_SESSION['nom']) ?> !</strong><br>
        <small class="text-muted">Année scolaire : <?= ANNEE_SCOLAIRE ?> &nbsp;</small>
    </div>
</div>

<!-- Stats -->
<div class="row g-3 mb-4">
    <div class="col-sm-4">
        <div class="card shadow-sm text-center p-3">
            <div class="fs-2 text-primary fw-bold"><?= count($classesProfesseur) ?></div>
            <div class="text-muted small">Classes assignées</div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="card shadow-sm text-center p-3">
            <div class="fs-2 text-success fw-bold"><?= count($assignations) ?></div>
            <div class="text-muted small">Matières enseignées</div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="card shadow-sm text-center p-3">
            <div class="fs-2 text-warning fw-bold"><?= $nbNotes ?></div>
            <div class="text-muted small">Notes saisies ce trimestre</div>
        </div>
    </div>
</div>


<!-- Classes et actions rapides -->
<?php if (empty($classesProfesseur)): ?>
    <div class="alert alert-info">
        <i class="fas fa-info-circle me-2"></i>
        Aucune classe ne vous a été assignée pour l'année <?= ANNEE_SCOLAIRE ?>.
        Contactez l'administrateur.
    </div>
<?php else: ?>
<div class="row g-3">
    <?php foreach ($classesProfesseur as $classeId => $classeData): ?>
    <div class="col-md-6 col-lg-4">
        <div class="card shadow-sm h-100">
            <div class="card-header d-flex align-items-center gap-2"
                 style="background: linear-gradient(135deg, #1a3a5c, #0d6efd); color:white; border-radius: 14px 14px 0 0;">
                <span class="fw-bold">
                    <?php 
                    $niveau = $classeData['niveau'];
                    $niveauxAF = ['7eme', '8eme', '9eme'];
                    if (in_array($niveau, $niveauxAF)) {
                        echo 'Niveau ' . $niveau . ' AF';
                    } else {
                        echo 'Niveau ' . $niveau;
                    }
                    ?>
                </span>
                <small class="opacity-75">(<?= h($classeData['nom']) ?>)</small>
            </div>
            <div class="card-body">
                <h6 class="text-muted small mb-2">Matières :</h6>
                <ul class="list-unstyled mb-3">
                    <?php foreach ($classeData['matieres'] as $mat): ?>
                    <li class="mb-1">
                        <?= h($mat['matiere']) ?>
                        <span class="badge bg-light text-dark ms-1">/<?= h($mat['coefficient']) ?></span>
                    </li>
                    <?php endforeach; ?>
                </ul>
                

                <!-- Boutons par contrôle -->
                <div class="d-grid gap-1">
                    <?php foreach ($controles as $ctrl): ?>
                    <a href="notes.php?classe_id=<?= $classeId ?>&controle_id=<?= $ctrl['id'] ?>"
                       class="btn btn-sm btn-outline-primary">
                        Notes – <?= h($ctrl['nom']) ?>
                    </a>
                    <?php endforeach; ?>
                    <a href="notes.php?classe_id=<?= $classeId ?>"
                       class="btn btn-sm btn-outline-success mt-1">
                        Voir les notes
                    </a>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>