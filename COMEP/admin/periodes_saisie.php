<?php
/**
 * admin/periodes_saisie.php - Contrôle des périodes de saisie des notes
 *
 * L'admin décide quand les professeurs peuvent entrer des notes,
 * classe par classe et contrôle par contrôle.
 *
 * Par défaut tout est FERMÉ. L'admin ouvre/ferme manuellement.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
requireAdmin();

$titrePage = 'Contrôle des Périodes de Saisie';
$pdo = getDB();

$controle_id = (int)($_GET['controle_id'] ?? 0);

// ===== ACTIONS =====
$action    = $_GET['action']   ?? '';
$classe_id = (int)($_GET['classe_id'] ?? 0);
$note      = trim($_GET['note'] ?? '');

// Ouvrir ou fermer UNE classe
if (in_array($action, ['ouvrir','fermer']) && $classe_id > 0 && $controle_id > 0) {
    $statut   = $action === 'ouvrir' ? 'ouvert' : 'ferme';
    $champ    = $action === 'ouvrir' ? 'ouvert_le=NOW(), ouvert_par=:uid, ferme_le=NULL' : 'ferme_le=NOW(), ferme_par=:uid';
    try {
        // S'assurer que la ligne existe
        $pdo->prepare("
            INSERT IGNORE INTO periodes_saisie (classe_id, controle_id, statut)
            VALUES (:cid, :ctrl, 'ferme')
        ")->execute([':cid'=>$classe_id,':ctrl'=>$controle_id]);

        $pdo->prepare("
            UPDATE periodes_saisie
            SET statut=:statut, {$champ}, note_admin=:note
            WHERE classe_id=:cid AND controle_id=:ctrl
        ")->execute([
            ':statut' => $statut,
            ':uid'    => $_SESSION['utilisateur_id'],
            ':note'   => $note ?: null,
            ':cid'    => $classe_id,
            ':ctrl'   => $controle_id,
        ]);

        $msg = $action === 'ouvrir'
            ? "Saisie OUVERTE pour cette classe."
            : "Saisie FERMÉE pour cette classe.";
        setMessage($msg);
        logAction($pdo, strtoupper($action).' PERIODE SAISIE', 'periodes_saisie', 0,
                  "Classe:$classe_id Controle:$controle_id -> $statut");
    } catch (PDOException $e) {
        setMessage("Erreur : " . $e->getMessage(), 'erreur');
    }
    header("Location: periodes_saisie.php?controle_id={$controle_id}"); exit();
}

// Ouvrir ou fermer TOUTES les classes
if (in_array($action, ['ouvrir_tout','fermer_tout']) && $controle_id > 0) {
    $statut = str_contains($action, 'ouvrir') ? 'ouvert' : 'ferme';
    $champ  = str_contains($action, 'ouvrir')
        ? 'ouvert_le=NOW(), ouvert_par=:uid, ferme_le=NULL'
        : 'ferme_le=NOW(), ferme_par=:uid';

    try {
        // S'assurer que toutes les lignes existent
        $classes = $pdo->query("SELECT id FROM classes")->fetchAll();
        foreach ($classes as $cl) {
            $pdo->prepare("
                INSERT IGNORE INTO periodes_saisie (classe_id, controle_id, statut)
                VALUES (:cid, :ctrl, 'ferme')
            ")->execute([':cid'=>$cl['id'],':ctrl'=>$controle_id]);
        }

        $pdo->prepare("
            UPDATE periodes_saisie
            SET statut=:statut, {$champ}
            WHERE controle_id=:ctrl
        ")->execute([
            ':statut' => $statut,
            ':uid'    => $_SESSION['utilisateur_id'],
            ':ctrl'   => $controle_id,
        ]);

        $nb  = count($classes);
        $msg = str_contains($action,'ouvrir')
            ? "Saisie OUVERTE pour toutes les {$nb} classes."
            : "Saisie FERMÉE pour toutes les {$nb} classes.";
        setMessage($msg);
        logAction($pdo, strtoupper($action).' PERIODES', 'periodes_saisie', 0,
                  "Controle:$controle_id -> $statut (toutes classes)");
    } catch (PDOException $e) {
        setMessage("Erreur : " . $e->getMessage(), 'erreur');
    }
    header("Location: periodes_saisie.php?controle_id={$controle_id}"); exit();
}

// ===== DONNÉES =====
$controles = $pdo->query("
    SELECT * FROM controles
    WHERE annee_scolaire = '" . ANNEE_SCOLAIRE . "'
    ORDER BY numero
")->fetchAll();

// Si aucun contrôle sélectionné, prendre le premier
if (!$controle_id && !empty($controles)) {
    $controle_id = $controles[0]['id'];
}

// Contrôle actif
$controleActif = null;
foreach ($controles as $c) {
    if ($c['id'] === $controle_id) { $controleActif = $c; break; }
}

// État de toutes les classes pour ce contrôle
$etatClasses = [];
if ($controle_id > 0) {
    // S'assurer que toutes les combinaisons existent
    $classes = $pdo->query("SELECT id FROM classes")->fetchAll();
    foreach ($classes as $cl) {
        $pdo->prepare("
            INSERT IGNORE INTO periodes_saisie (classe_id, controle_id, statut)
            VALUES (:cid, :ctrl, 'ferme')
        ")->execute([':cid'=>$cl['id'],':ctrl'=>$controle_id]);
    }

    $stmt = $pdo->prepare("
        SELECT
            ps.*,
            c.nom           AS classe_nom,
            c.niveau        AS classe_niveau,
            ua.nom          AS ouvert_par_nom,
            ua.prenom       AS ouvert_par_prenom,
            ub.nom          AS ferme_par_nom,
            ub.prenom       AS ferme_par_prenom,
            -- Compter les profs assignés à cette classe
            (SELECT COUNT(DISTINCT pc.professeur_id)
             FROM professeur_classes pc
             WHERE pc.classe_id = c.id
             AND pc.annee_scolaire = :annee) AS nb_profs,
            -- Compter les notes déjà saisies
            (SELECT COUNT(DISTINCT n.id)
             FROM notes n
             JOIN eleves e ON n.eleve_id = e.id
             WHERE e.classe_id = c.id
             AND n.controle_id = ps.controle_id) AS nb_notes,
            -- Compter les élèves actifs
            (SELECT COUNT(*) FROM eleves e
             WHERE e.classe_id = c.id AND e.status='actif') AS nb_eleves
        FROM periodes_saisie ps
        JOIN classes c ON ps.classe_id = c.id
        LEFT JOIN utilisateurs ua ON ps.ouvert_par = ua.id
        LEFT JOIN utilisateurs ub ON ps.ferme_par  = ub.id
        WHERE ps.controle_id = :ctrl
        ORDER BY FIELD(c.niveau,'7èm','8èm','9èm','10èm','NS1','NS2','NS3','NS4'), c.nom
    ");
    $stmt->execute([':ctrl'=>$controle_id, ':annee'=>ANNEE_SCOLAIRE]);
    $etatClasses = $stmt->fetchAll();
}

// Stats résumé
$nbOuvert = count(array_filter($etatClasses, fn($e) => $e['statut'] === 'ouvert'));
$nbFerme  = count($etatClasses) - $nbOuvert;

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
    <h1 class="page-title mb-0">
         Contrôle des Périodes de Saisie
    </h1>
    <div class="text-muted small text-end">
        <i class="fas fa-shield-alt me-1 text-primary"></i>
        Seul l'admin contrôle l'accès à la saisie des notes
    </div>
</div>

<?php afficherMessage(); ?>

<!-- Info générale -->
<div class="alert alert-info d-flex gap-3 align-items-start mb-4">
  
    <div>
        <strong>Comment ça fonctionne :</strong>
        Par défaut, toutes les périodes sont <strong>fermées</strong>.
        Les professeurs ne peuvent pas saisir de notes tant que l'admin n'a pas
        <strong>ouvert</strong> la période. Vous pouvez ouvrir/fermer classe par classe
        ou toutes les classes en un clic.
    </div>
</div>

<!-- Sélection du contrôle -->
<div class="card shadow-sm mb-4">
    <div class="card-body py-3">
        <div class="d-flex align-items-center gap-3 flex-wrap">
            <span class="fw-bold text-muted">Trimestre :</span>
<?php foreach ($controles as $ctrl): ?>
<a href="periodes_saisie.php?controle_id=<?= $ctrl['id'] ?>"
   class="btn <?= $controle_id===$ctrl['id']?'btn-comep':'btn-outline-secondary' ?> btn-sm">
    <?= h($ctrl['nom']) ?>
</a>
<?php endforeach; ?>
        </div>
    </div>
</div>

<?php if ($controle_id > 0 && !empty($etatClasses)): ?>

<!-- Stats rapides -->
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card shadow-sm text-center p-3" style="border-left:4px solid #10b981">
            <div class="fs-2 fw-bold text-success"><?= $nbOuvert ?></div>
            <div class="text-muted small">Classe(s) ouvertes
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card shadow-sm text-center p-3" style="border-left:4px solid #ef4444">
            <div class="fs-2 fw-bold text-danger"><?= $nbFerme ?></div>
            <div class="text-muted small">
                Classe(s) fermées
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card shadow-sm text-center p-3" style="border-left:4px solid #0d6efd">
            <div class="fs-2 fw-bold text-primary">
                <?= array_sum(array_column($etatClasses,'nb_notes')) ?>
            </div>
            <div class="text-muted small">
              Notes saisies au total
            </div>
        </div>
    </div>
</div>

<!-- Boutons globaux -->
<div class="card shadow-sm mb-4">
    <div class="card-body d-flex align-items-center gap-3 flex-wrap">
        <span class="fw-bold">Actions globales pour
            <strong><?= h($controleActif['nom'] ?? '') ?></strong> :
        </span>

        <a href="periodes_saisie.php?action=ouvrir_tout&controle_id=<?= $controle_id ?>"
           class="btn btn-success"
           onclick="return confirm('Ouvrir la saisie pour TOUTES les classes ?\nLes professeurs pourront entrer des notes.')">
            
            Ouvrir toutes les classes
        </a>

        <a href="periodes_saisie.php?action=fermer_tout&controle_id=<?= $controle_id ?>"
           class="btn btn-danger"
           onclick="return confirm('Fermer la saisie pour TOUTES les classes ?\nLes professeurs ne pourront plus modifier les notes.')">
            
            Fermer toutes les classes
        </a>
    </div>
</div>

<!-- Tableau des classes -->
<div class="card shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>
            <i class="fas fa-list me-2 text-primary"></i>
            État par classe — <?= h($controleActif['nom'] ?? '') ?>
        </span>
        <small class="text-muted">
            Année scolaire : <?= ANNEE_SCOLAIRE ?>
        </small>
    </div>
    <div class="table-responsive">
        <table class="table table-comep table-hover mb-0 align-middle">
            <thead>
                <tr>
                    <th>Classe</th>
                    <th class="text-center">Statut</th>
                    <th class="text-center">Élèves</th>
                    <th class="text-center">Notes saisies</th>
                    <th>Ouvert le</th>
                    <th>Fermé le</th>
                    <th>Note admin</th>
                    <th class="text-center">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($etatClasses as $ec): ?>
                <tr class="<?= $ec['statut']==='ouvert' ? 'table-success' : '' ?>">
                    <td>
                        <div class="fw-bold"><?= h($ec['classe_nom']) ?></div>
                        <small class="text-muted"><?= h($ec['nb_profs']) ?> prof(s)</small>
                    </td>
                    <td class="text-center">
                        <?php if ($ec['statut'] === 'ouvert'): ?>
                            <span class="badge bg-success px-3 py-2">
                             OUVERT
                            </span>
                        <?php else: ?>
                            <span class="badge bg-danger px-3 py-2">
                             FERMÉ
                            </span>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <span class="badge bg-info text-dark"><?= h($ec['nb_eleves']) ?></span>
                    </td>
                    <td class="text-center">
                        <?php
                        // Calculer le pourcentage de complétion
                        $matieres = $pdo->prepare("SELECT COUNT(*) FROM classe_matieres WHERE classe_id=:cid AND annee_scolaire=:a");
                        $matieres->execute([':cid'=>$ec['classe_id'],':a'=>ANNEE_SCOLAIRE]);
                        $nbMatieres = (int)$matieres->fetchColumn();
                        $notesAttendues = $ec['nb_eleves'] * $nbMatieres;
                        $pct = $notesAttendues > 0
                            ? round($ec['nb_notes'] / $notesAttendues * 100)
                            : 0;
                        ?>
                        <div><?= h($ec['nb_notes']) ?>
                            <?php if ($notesAttendues > 0): ?>
                                <small class="text-muted">/ <?= $notesAttendues ?></small>
                            <?php endif; ?>
                        </div>
                        <?php if ($notesAttendues > 0): ?>
                        <div class="progress mt-1" style="height:5px;width:80px;margin:0 auto">
                            <div class="progress-bar <?= $pct>=100?'bg-success':($pct>0?'bg-warning':'bg-secondary') ?>"
                                 style="width:<?= $pct ?>%"></div>
                        </div>
                        <small class="text-muted"><?= $pct ?>%</small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($ec['ouvert_le']): ?>
                            <div class="small">
                                <i class="fas fa-clock text-success me-1"></i>
                                <?= date('d/m/Y H:i', strtotime($ec['ouvert_le'])) ?>
                            </div>
                            <?php if ($ec['ouvert_par_nom']): ?>
                            <small class="text-muted">
                                par <?= h($ec['ouvert_par_prenom']) ?> <?= h($ec['ouvert_par_nom']) ?>
                            </small>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($ec['ferme_le']): ?>
                            <div class="small">
                                <i class="fas fa-clock text-danger me-1"></i>
                                <?= date('d/m/Y H:i', strtotime($ec['ferme_le'])) ?>
                            </div>
                            <?php if ($ec['ferme_par_nom']): ?>
                            <small class="text-muted">
                                par <?= h($ec['ferme_par_prenom']) ?> <?= h($ec['ferme_par_nom']) ?>
                            </small>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <!-- Note admin modifiable inline -->
                        <form method="GET" action="periodes_saisie.php" class="d-flex gap-1">
                            <input type="hidden" name="action"      value="<?= $ec['statut'] ?>">
                            <input type="hidden" name="classe_id"   value="<?= $ec['classe_id'] ?>">
                            <input type="hidden" name="controle_id" value="<?= $controle_id ?>">
                            <input type="text" name="note"
                                   class="form-control form-control-sm"
                                   style="min-width:120px;font-size:0.78rem"
                                   placeholder="Note interne..."
                                   value="<?= h($ec['note_admin'] ?? '') ?>"
                                   title="Note visible uniquement par l'admin">
                            <button type="submit" class="btn btn-outline-secondary btn-sm"
                                    title="Enregistrer la note">
                                <i class="fas fa-save"></i>
                            </button>
                        </form>
                    </td>
                    <td class="text-center">
                        <?php if ($ec['statut'] === 'ferme'): ?>
                        <a href="periodes_saisie.php?action=ouvrir&classe_id=<?= $ec['classe_id'] ?>&controle_id=<?= $controle_id ?>"
                           class="btn btn-success btn-sm"
                           onclick="return confirm('Ouvrir la saisie pour la classe <?= h(addslashes($ec['classe_nom'])) ?> ?')">
                           Ouvrir
                        </a>
                        <?php else: ?>
                        <a href="periodes_saisie.php?action=fermer&classe_id=<?= $ec['classe_id'] ?>&controle_id=<?= $controle_id ?>"
                           class="btn btn-danger btn-sm"
                           onclick="return confirm('Fermer la saisie pour la classe <?= h(addslashes($ec['classe_nom'])) ?> ?\nLes professeurs ne pourront plus modifier les notes.')">
                         Fermer
                        </a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Légende -->
    <div class="card-footer bg-light d-flex gap-4 flex-wrap">
        <small class="text-muted">
            <span class="badge bg-success me-1">OUVERT</span>
            Les professeurs peuvent saisir et modifier les notes
        </small>
        <small class="text-muted">
            <span class="badge bg-danger me-1">FERMÉ</span>
            Les professeurs voient les notes en lecture seule
        </small>
        <small class="text-muted">
            <i class="fas fa-chart-bar me-1 text-primary"></i>
            La barre de progression montre l'avancement de la saisie
        </small>
    </div>
</div>

<?php elseif ($controle_id > 0): ?>
    <div class="alert alert-warning">Aucune classe trouvée. Créez d'abord des classes.</div>
<?php else: ?>
    <div class="alert alert-info">Aucun contrôle trouvé pour l'année <?= ANNEE_SCOLAIRE ?>.</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
