<?php
/**
 * professeur/notes.php - Saisie des notes
 * Version 4 : Notes entières uniquement, barème dynamique (200, 300, 400...)
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
requireProfesseur();

$titrePage = 'Saisie des Notes';
$pdo    = getDB();
$profId = $_SESSION['utilisateur_id'];

$classe_id   = (int)($_GET['classe_id']   ?? $_POST['classe_id']   ?? 0);
$matiere_id  = (int)($_GET['matiere_id']  ?? $_POST['matiere_id']  ?? 0);
$controle_id = (int)($_GET['controle_id'] ?? $_POST['controle_id'] ?? 0);
$erreur      = '';

// ===== SAUVEGARDE =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sauvegarder'])) {
    $notes_post  = $_POST['notes']        ?? [];
    $cls_post    = (int)($_POST['classe_id']   ?? 0);
    $mat_post    = (int)($_POST['matiere_id']  ?? 0);
    $ctrl_post   = (int)($_POST['controle_id'] ?? 0);
    $bareme_post = (float)($_POST['bareme_mat']  ?? 100);

    // Vérifier autorisation
    $check = $pdo->prepare("
        SELECT id FROM professeur_classes
        WHERE professeur_id=:pid AND classe_id=:cid AND matiere_id=:mid
    ");
    $check->execute([':pid'=>$profId,':cid'=>$cls_post,':mid'=>$mat_post]);
    if (!$check->fetch()) {
        setMessage("Accès non autorisé.", 'erreur');
        header('Location: notes.php'); exit();
    }

    // Vérifier si la période est OUVERTE par l'admin
    if (!estAdmin()) {
        $periode = $pdo->prepare("
            SELECT statut FROM periodes_saisie
            WHERE classe_id=:cid AND controle_id=:ctrl LIMIT 1
        ");
        $periode->execute([':cid'=>$cls_post,':ctrl'=>$ctrl_post]);
        $statutPeriode = $periode->fetchColumn();
        if ($statutPeriode !== 'ouvert') {
            setMessage("La saisie est fermée pour cette classe. Contactez l'administrateur.", 'erreur');
            header("Location: notes.php?classe_id={$cls_post}&matiere_id={$mat_post}&controle_id={$ctrl_post}");
            exit();
        }
    }

    $nbSauvegardes = 0;
    $erreurSaisie  = '';

    try {
        $pdo->beginTransaction();
        foreach ($notes_post as $eleve_id => $note_val) {
            $eleve_id = (int)$eleve_id;
            $note_val = trim(str_replace(',', '.', $note_val));
            if ($note_val === '') continue;

            // Note entière
            $note_int = (int)round((float)$note_val);

            if ($note_int < 0 || $note_int > $bareme_post) {
                $erreurSaisie = "Note invalide : doit être un entier entre 0 et {$bareme_post} points.";
                break;
            }

            // Stocker la note brute (entière)
            $stmt = $pdo->prepare("
                INSERT INTO notes (eleve_id, matiere_id, controle_id, note, professeur_id)
                VALUES (:eid, :mid, :cid, :note, :pid)
                ON DUPLICATE KEY UPDATE note=:note2, professeur_id=:pid2, updated_at=NOW()
            ");
            $stmt->execute([
                ':eid'  => $eleve_id, ':mid'   => $mat_post,
                ':cid'  => $ctrl_post, ':note'  => $note_int,
                ':pid'  => $profId,   ':note2' => $note_int, ':pid2' => $profId,
            ]);
            $nbSauvegardes++;
        }

        if ($erreurSaisie) {
            $pdo->rollBack();
            $erreur = $erreurSaisie;
        } else {
            $pdo->commit();
            logAction($pdo,'SAISIE NOTES','notes',0,
                "Classe:$cls_post Mat:$mat_post Ctrl:$ctrl_post — $nbSauvegardes notes (/$bareme_post)");
            setMessage("{$nbSauvegardes} note(s) sauvegardée(s). Barème : /{$bareme_post}");
            header("Location: notes.php?classe_id={$cls_post}&matiere_id={$mat_post}&controle_id={$ctrl_post}");
            exit();
        }
    } catch (PDOException $e) {
        $pdo->rollBack();
        $erreur = "Erreur : " . $e->getMessage();
    }
    $classe_id = $cls_post; $matiere_id = $mat_post; $controle_id = $ctrl_post;
}

// ===== DONNÉES MENUS =====
$mesClasses = $pdo->prepare("
    SELECT DISTINCT c.id, c.nom, c.niveau
    FROM professeur_classes pc JOIN classes c ON pc.classe_id=c.id
    WHERE pc.professeur_id=:pid AND pc.annee_scolaire=:annee ORDER BY c.niveau, c.nom
");
$mesClasses->execute([':pid'=>$profId,':annee'=>ANNEE_SCOLAIRE]);
$mesClasses = $mesClasses->fetchAll();

// Matières que le prof enseigne dans cette classe + barème depuis classe_matieres
$mesMatieres = [];
if ($classe_id > 0) {
    $stmt = $pdo->prepare("
        SELECT m.id, m.nom, m.code,
               COALESCE(cm.bareme, 100) AS bareme
        FROM professeur_classes pc
        JOIN matieres m ON pc.matiere_id = m.id
        LEFT JOIN classe_matieres cm
            ON cm.matiere_id = m.id
            AND cm.classe_id = pc.classe_id
            AND cm.annee_scolaire = :annee
        WHERE pc.professeur_id=:pid AND pc.classe_id=:cid
        AND pc.annee_scolaire=:annee2
        ORDER BY m.nom
    ");
    $stmt->execute([':pid'=>$profId,':cid'=>$classe_id,':annee'=>ANNEE_SCOLAIRE,':annee2'=>ANNEE_SCOLAIRE]);
    $mesMatieres = $stmt->fetchAll();
}

$controles = $pdo->query("SELECT * FROM controles WHERE annee_scolaire='".ANNEE_SCOLAIRE."' ORDER BY numero")->fetchAll();

// Barème matière sélectionnée
$baremeMatiere = 100;
$matiereInfo   = null;
$controleActif = null;
$eleves        = [];
$totalBaremeClasse = 0;

if ($classe_id > 0 && $matiere_id > 0 && $controle_id > 0) {
    $check = $pdo->prepare("SELECT id FROM professeur_classes WHERE professeur_id=:pid AND classe_id=:cid AND matiere_id=:mid");
    $check->execute([':pid'=>$profId,':cid'=>$classe_id,':mid'=>$matiere_id]);
    if (!$check->fetch()) {
        setMessage("Accès non autorisé.", 'erreur');
        header('Location: notes.php'); exit();
    }

    // Barème de cette matière dans cette classe
    $stmt = $pdo->prepare("
        SELECT m.id, m.nom, m.code,
               COALESCE(
                   (SELECT cm2.bareme FROM classe_matieres cm2
                    WHERE cm2.matiere_id = m.id
                    AND cm2.classe_id = :cid
                    AND cm2.annee_scolaire = :annee
                    LIMIT 1),
                   100
               ) AS bareme
        FROM matieres m
        WHERE m.id = :mid
    ");
    $stmt->execute([':cid'=>$classe_id, ':annee'=>ANNEE_SCOLAIRE, ':mid'=>$matiere_id]);
    $matiereInfo   = $stmt->fetch();
    $baremeMatiere = (float)($matiereInfo['bareme'] ?? 100);

    // Sécurité : barème minimum 1
    if ($baremeMatiere < 1) $baremeMatiere = 100;

    // Total barème de la classe (pour info)
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(bareme),0) FROM classe_matieres WHERE classe_id=:cid AND annee_scolaire=:annee");
    $stmt->execute([':cid'=>$classe_id,':annee'=>ANNEE_SCOLAIRE]);
    $totalBaremeClasse = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT * FROM controles WHERE id=:id");
    $stmt->execute([':id'=>$controle_id]);
    $controleActif = $stmt->fetch();

    // Élèves + notes existantes
    $stmt = $pdo->prepare("
        SELECT e.id, e.nom, e.prenom, e.matricule, n.note AS note_brute
        FROM eleves e
        LEFT JOIN notes n ON n.eleve_id=e.id AND n.matiere_id=:mid AND n.controle_id=:cid
        WHERE e.classe_id=:cls AND e.status='actif'
        ORDER BY e.nom, e.prenom
    ");
    $stmt->execute([':mid'=>$matiere_id,':cid'=>$controle_id,':cls'=>$classe_id]);
    $eleves = $stmt->fetchAll();
}

require_once __DIR__ . '/../includes/header.php';
?>

<h1 class="page-title">Saisie des Notes</h1>
<?php afficherMessage(); ?>
<?php if (!empty($erreur)): ?>
    <div class="alert alert-danger"><i class="fas fa-exclamation-circle me-2"></i><?= h($erreur) ?></div>
<?php endif; ?>

<!-- Sélection -->
<div class="card shadow-sm mb-4">
    <div class="card-header">Sélectionner</div>
    <div class="card-body">
        <form method="GET" action="notes.php" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label">Classe</label>
                <select name="classe_id" class="form-select" onchange="this.form.submit()">
                    <option value="">Classe</option>
                    <?php foreach ($mesClasses as $c): ?>
                        <option value="<?= $c['id'] ?>" <?= $classe_id===$c['id']?'selected':'' ?>>
                            <?= h($c['niveau']) ?> - <?= h($c['nom']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Matière</label>
                <select name="matiere_id" class="form-select" <?= !$classe_id?'disabled':'' ?>>
                    <option value="">Matière</option>
                    <?php foreach ($mesMatieres as $m): ?>
                        <option value="<?= $m['id'] ?>" <?= $matiere_id===$m['id']?'selected':'' ?>>
                            <?= h($m['nom']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Trimestre</label>
                <select name="controle_id" class="form-select" <?= !$classe_id?'disabled':'' ?>>
                    <option value="">Trimestre</option>
                    <?php foreach ($controles as $ctrl): ?>
                        <option value="<?= $ctrl['id'] ?>" <?= $controle_id===$ctrl['id']?'selected':'' ?>>
                            <?= h($ctrl['nom']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-comep w-100">
                    <i class="fas fa-search me-1"></i> Afficher
                </button>
            </div>
        </form>
    </div>
</div>

<?php if (!empty($eleves) && $matiereInfo): ?>
<?php
$periodeTerminee = false;
$classeNom = '';
foreach ($mesClasses as $c) { if ($c['id']===$classe_id) { $classeNom=$c['nom']; break; } }
$diviseur = $totalBaremeClasse > 0 ? $totalBaremeClasse / 10 : 0;

// Vérifier le statut de la période pour cette classe (pour l'affichage)
if (!estAdmin() && $classe_id > 0 && $controle_id > 0) {
    $stmtPer = $pdo->prepare("SELECT statut FROM periodes_saisie WHERE classe_id=:cid AND controle_id=:ctrl LIMIT 1");
    $stmtPer->execute([':cid'=>$classe_id,':ctrl'=>$controle_id]);
    $statutAffichage = $stmtPer->fetchColumn();
    $periodeTerminee = ($statutAffichage !== 'ouvert');
}
?>

<!-- Bandeau info matière -->
<?php
// Récupérer le niveau de la classe (à ajouter avant le bandeau)
$stmt = $pdo->prepare("SELECT niveau FROM classes WHERE id = ?");
$stmt->execute([$classe_id]);
$classeNiveau = $stmt->fetchColumn();
?>

<div class="alert mb-3 d-flex align-items-center gap-3"
     style="background:linear-gradient(135deg,#1a3a5c,#0d6efd);color:white;border:none;border-radius:12px">
    <div class="flex-grow-1">
        <strong><?= h($matiereInfo['nom']) ?></strong>
        &nbsp;-&nbsp; <strong>
            <?php 
            $niveauxAF = ['7eme', '8eme', '9eme'];
            echo in_array($classeNiveau, $niveauxAF) ? h($classeNiveau . ' AF') : h($classeNiveau);
            ?>
        </strong>
        &nbsp;-&nbsp; <?= h($controleActif['nom'] ?? '') ?>
        <br>
        <small>
            Barème de cette matière : <strong>/ <?= number_format($baremeMatiere, 0) ?></strong>
            &nbsp;&nbsp;
        </small>
    </div>
</div>

<?php if ($periodeTerminee): ?>
<div class="alert alert-danger d-flex gap-3 align-items-center">
    <div>
        <strong>Saisie des notes fermée</strong><br>
        L'administrateur a fermé la saisie des notes pour la classe
        <strong><?= h($classeNiveau) ?></strong> pour <?= h($controleActif['nom'] ?? '') ?>.<br>
        <span class="small">Vous pouvez consulter les notes déjà saisies mais vous ne pouvez plus
        les modifier. Contactez l'administrateur pour rouvrir la saisie.</span>
    </div>
</div>
<?php endif; ?>

<div class="card shadow-sm">
    <div class="card-header d-flex justify-content-between">
        <span><i class="fas fa-list me-2 text-primary"></i><?= count($eleves) ?> élève(s)</span>
    </div>
    <div class="card-body">
        <form method="POST" action="notes.php" id="formNotes">
            <input type="hidden" name="classe_id"   value="<?= $classe_id ?>">
            <input type="hidden" name="matiere_id"  value="<?= $matiere_id ?>">
            <input type="hidden" name="controle_id" value="<?= $controle_id ?>">
            <input type="hidden" name="bareme_mat"  value="<?= $baremeMatiere ?>">

            <div class="table-responsive">
                <table class="table table-comep table-hover align-middle">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Matricule</th>
                            <th>Nom & Prénom</th>
                            <th class="text-center">
                                Note <?= number_format($baremeMatiere, 0) ?>
                            </th>
                            <th class="text-center">Statut</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($eleves as $i => $e):
                            $barMax = (float)$baremeMatiere;
                        ?>
                        <tr>
                            <td class="text-muted"><?= $i + 1 ?></td>
                            <td><code><?= h($e['matricule']) ?></code></td>
                            <td class="fw-500"><?= h($e['prenom']) ?> <?= h($e['nom']) ?></td>
                            <td class="text-center">
                                <input type="number"
                                       name="notes[<?= $e['id'] ?>]"
                                       class="form-control note-input mx-auto"
                                       min="0"
                                       max="<?= $barMax ?>"
                                       step="1"
                                       placeholder="0 – <?= $barMax ?>"
                                       value="<?= $e['note_brute'] !== null ? (int)$e['note_brute'] : '' ?>"
                                       <?= ($periodeTerminee && !estAdmin()) ? 'readonly' : '' ?>
                                       oninput="validerNote(this, <?= $barMax ?>)">
                                
                            </td>
                            <td class="text-center">
                                <?php if ($e['note_brute'] !== null): ?>
                                    <span class="badge bg-success">
                                        <i class="fas fa-check me-1"></i>
                                        <?= (int)$e['note_brute'] ?>/<?= number_format($barMax, 0) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Non saisie</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if (!$periodeTerminee || estAdmin()): ?>
            <div class="d-flex gap-2 justify-content-between mt-3 flex-wrap">
                <div class="d-flex gap-2">
                    <button type="button" onclick="effacerTout()"
                            class="btn btn-outline-danger btn-sm">
                      Effacer toutes les notes
                    </button>
                </div>
                <button type="submit" name="sauvegarder" class="btn btn-comep">
                  Sauvegarder
                </button>
            </div>
            <?php endif; ?>
        </form>
    </div>
</div>

<?php
$scriptPage = <<<JSEOF
<script>
function validerNote(input, bareme) {
    input.classList.remove('note-valide','note-invalide');
    if (input.value === '') return;

    const valBrute = parseFloat(input.value);
    if (isNaN(valBrute)) {
        input.classList.add('note-invalide');
        return;
    }

    // Arrondir à l'entier le plus proche
    const valEntiere = Math.round(valBrute);
    if (valBrute !== valEntiere) {
        input.value = valEntiere;
    }

    if (valEntiere < 0 || valEntiere > bareme) {
        input.classList.add('note-invalide');
    } else {
        input.classList.add('note-valide');
    }
}

function remplirTout(bareme) {
    const val = prompt('Note entière pour tous les élèves (0–' + bareme + ') :');
    if (val === null) return;
    const valInt = parseInt(val);
    if (isNaN(valInt)) { alert('Entrez un nombre entier valide.'); return; }
    document.querySelectorAll('.note-input:not([readonly])').forEach(inp => {
        inp.value = valInt;
        validerNote(inp, bareme);
    });
}

function effacerTout() {
    if (!confirm('Effacer toutes les notes du formulaire ?')) return;
    document.querySelectorAll('.note-input:not([readonly])').forEach(inp => {
        inp.value = '';
        inp.classList.remove('note-valide','note-invalide');
    });
}

document.getElementById('formNotes')?.addEventListener('submit', function(e) {
    document.querySelectorAll('.note-input:not([readonly])').forEach(inp => {
        if (inp.value !== '') {
            let val = parseInt(inp.value);
            if (!isNaN(val)) {
                inp.value = val;
            }
        }
    });
    if (document.querySelectorAll('.note-invalide').length > 0) {
        e.preventDefault();
        alert('Certaines notes sont invalides (hors de la plage 0–{$baremeMatiere}). Corrigez-les avant de sauvegarder.');
    }
});

// Initialiser la validation au chargement
document.querySelectorAll('.note-input').forEach(inp => {
    if (inp.value) validerNote(inp, {$baremeMatiere});
});
</script>
<style>
.note-valide { border-color: #10b981 !important; background-color: #ecfdf5 !important; }
.note-invalide { border-color: #ef4444 !important; background-color: #fef2f2 !important; }
.note-input { width: 150px; transition: all 0.2s; }
</style>
JSEOF;
?>

<?php elseif ($classe_id > 0 && $matiere_id > 0 && $controle_id > 0): ?>
    <div class="alert alert-info">Aucun élève actif dans cette classe.</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>