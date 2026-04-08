<?php
/**
 * professeur/bulletin.php - Bulletins pour le professeur
 * Version 3 : Moyenne = total points ÷ (total barèmes ÷ 10)
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
requireProfesseur();

$titrePage = 'Bulletins';
$pdo    = getDB();
$profId = $_SESSION['utilisateur_id'];

$classe_id   = (int)($_GET['classe_id']   ?? 0);
$controle_id = (int)($_GET['controle_id'] ?? 0);
$eleve_id    = (int)($_GET['eleve_id']    ?? 0);

$mesClasses = $pdo->prepare("
    SELECT DISTINCT c.id, c.nom
    FROM professeur_classes pc JOIN classes c ON pc.classe_id=c.id
    WHERE pc.professeur_id=:pid AND pc.annee_scolaire=:annee ORDER BY c.nom
");
$mesClasses->execute([':pid'=>$profId,':annee'=>ANNEE_SCOLAIRE]);
$mesClasses = $mesClasses->fetchAll();

$controles = $pdo->query("SELECT * FROM controles WHERE annee_scolaire='".ANNEE_SCOLAIRE."' ORDER BY numero")->fetchAll();

$eleves = []; $bulletin = null;

if ($classe_id > 0 && $controle_id > 0 && $eleve_id === 0) {
    $check = $pdo->prepare("SELECT id FROM professeur_classes WHERE professeur_id=:pid AND classe_id=:cid");
    $check->execute([':pid'=>$profId,':cid'=>$classe_id]);
    if ($check->fetch()) {
        $stmt = $pdo->prepare("SELECT id, CONCAT(prenom,' ',nom) AS nom_complet, matricule FROM eleves WHERE classe_id=:cid AND status='actif' ORDER BY nom");
        $stmt->execute([':cid'=>$classe_id]);
        $eleves = $stmt->fetchAll();
    }
}

if ($eleve_id > 0 && $controle_id > 0) {
    $stmt = $pdo->prepare("SELECT classe_id FROM eleves WHERE id=:id");
    $stmt->execute([':id'=>$eleve_id]);
    $eleveClasse = $stmt->fetchColumn();

    $check = $pdo->prepare("SELECT id FROM professeur_classes WHERE professeur_id=:pid AND classe_id=:cid");
    $check->execute([':pid'=>$profId,':cid'=>$eleveClasse]);
    if (!$check->fetch()) {
        setMessage("Accès non autorisé.", 'erreur');
        header('Location: bulletin.php'); exit();
    }

    $stmt = $pdo->prepare("SELECT e.*, c.nom AS classe_nom, c.niveau AS classe_niveau FROM eleves e LEFT JOIN classes c ON e.classe_id=c.id WHERE e.id=:id");
    $stmt->execute([':id'=>$eleve_id]);
    $eleveInfo = $stmt->fetch();

    $stmt = $pdo->prepare("SELECT * FROM controles WHERE id=:id");
    $stmt->execute([':id'=>$controle_id]);
    $controleInfo = $stmt->fetch();

    // Notes avec barème
    $stmt = $pdo->prepare("
        SELECT n.note AS note_brute, m.nom AS matiere, m.code,
               COALESCE(cm.bareme, 100) AS bareme
        FROM notes n
        JOIN matieres m ON n.matiere_id=m.id
        LEFT JOIN classe_matieres cm ON cm.matiere_id=m.id AND cm.classe_id=:cls AND cm.annee_scolaire=:annee
        WHERE n.eleve_id=:eid AND n.controle_id=:cid
        ORDER BY m.nom
    ");
    $stmt->execute([':eid'=>$eleve_id,':cid'=>$controle_id,':cls'=>$eleveClasse,':annee'=>ANNEE_SCOLAIRE]);
    $notes = $stmt->fetchAll();

    // Total barème classe
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(bareme),0) FROM classe_matieres WHERE classe_id=:cid AND annee_scolaire=:annee");
    $stmt->execute([':cid'=>$eleveClasse,':annee'=>ANNEE_SCOLAIRE]);
    $totalBaremeClasse  = (int)$stmt->fetchColumn();
    $totalPointsObtenus = array_sum(array_column($notes,'note_brute'));
    $diviseur           = $totalBaremeClasse > 0 ? $totalBaremeClasse / 10 : 1;
    $moyenne            = round($totalPointsObtenus / $diviseur, 2);

    // Rang
    $stmt2 = $pdo->prepare("
        SELECT n.eleve_id, SUM(n.note) AS total_pts FROM notes n
        WHERE n.controle_id=:cid
        AND n.eleve_id IN (SELECT id FROM eleves WHERE classe_id=:cls AND status='actif')
        GROUP BY n.eleve_id ORDER BY total_pts DESC
    ");
    $stmt2->execute([':cid'=>$controle_id,':cls'=>$eleveClasse]);
    $classement = $stmt2->fetchAll();
    $rang=1; $nbEleves=count($classement);
    foreach ($classement as $i=>$row) { if($row['eleve_id']==$eleve_id){$rang=$i+1;break;} }

    $appreciation = match(true) {
        $moyenne >= 9 => 'Excellent',
        $moyenne >= 8 => 'Très Bien',
        $moyenne >= 7 => 'Bien',
        $moyenne >= 6 => 'Assez Bien',
        $moyenne >= 5 => 'Passable',
        $moyenne >= 4 => 'Insuffisant',
        default       => 'Très Insuffisant',
    };

    $moyenneFinale=null; $decision=null;
    if ($controleInfo['numero']==3) {
        $stmt = $pdo->prepare("SELECT moyenne_finale,decision FROM moyennes_finales WHERE eleve_id=:eid AND annee_scolaire=:a");
        $stmt->execute([':eid'=>$eleve_id,':a'=>ANNEE_SCOLAIRE]);
        $fin = $stmt->fetch();
        if ($fin) { $moyenneFinale=$fin['moyenne_finale']; $decision=$fin['decision']; }
    }

    $bulletin = compact('eleveInfo','controleInfo','notes','totalPointsObtenus',
                        'totalBaremeClasse','diviseur','moyenne','rang','nbEleves',
                        'appreciation','moyenneFinale','decision');
}

require_once __DIR__ . '/../includes/header.php';
?>

<h1 class="page-title"><i class="fas fa-file-alt"></i> Bulletins de mes Classes</h1>
<?php afficherMessage(); ?>

<div class="card shadow-sm mb-4">
    <div class="card-header"><i class="fas fa-search me-2 text-primary"></i>Sélectionner un bulletin</div>
    <div class="card-body">
        <form method="GET" action="bulletin.php" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label">Classe</label>
                <select name="classe_id" class="form-select" onchange="this.form.submit()">
                    <option value="">-- Classe --</option>
                    <?php foreach ($mesClasses as $c): ?>
                        <option value="<?= $c['id'] ?>" <?= $classe_id===$c['id']?'selected':'' ?>><?= h($c['nom']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Contrôle</label>
                <select name="controle_id" class="form-select" <?= !$classe_id?'disabled':'' ?>>
                    <option value="">-- Contrôle --</option>
                    <?php foreach ($controles as $ctrl): ?>
                        <option value="<?= $ctrl['id'] ?>" <?= $controle_id===$ctrl['id']?'selected':'' ?>><?= h($ctrl['nom']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if (!empty($eleves)): ?>
            <div class="col-md-4">
                <label class="form-label">Élève</label>
                <select name="eleve_id" class="form-select">
                    <option value="">-- Sélectionner --</option>
                    <?php foreach ($eleves as $e): ?>
                        <option value="<?= $e['id'] ?>"><?= h($e['nom_complet']) ?> (<?= h($e['matricule']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-comep w-100"><i class="fas fa-eye me-1"></i> Voir</button>
            </div>
            <?php elseif ($classe_id): ?>
            <div class="col-md-2"><button type="submit" class="btn btn-comep w-100"><i class="fas fa-search"></i></button></div>
            <?php endif; ?>
        </form>
    </div>
</div>

<?php if ($bulletin): ?>
<div class="bulletin-wrapper shadow" id="bulletinPrint">
    <div class="bulletin-school-header">
        <h1>Collège Sainte Thérèse (CST)</h1>
        <p>Port-Margot, Haïti &nbsp;|&nbsp;
           <i class="fas fa-phone"></i> 509-XXXX-XXXX &nbsp;|&nbsp;
           <i class="fas fa-envelope"></i> cst@email.com
        </p>
        <p>Année scolaire : <strong><?= ANNEE_SCOLAIRE ?></strong></p>
        <h2 class="h4 mt-2 text-dark fw-bold">
            <?php
            $numControle = $bulletin['controleInfo']['numero'] ?? 0;
            echo h(match((int)$numControle) {
                1 => 'BULLETIN — PREMIER CONTRÔLE',
                2 => 'BULLETIN — DEUXIÈME CONTRÔLE',
                3 => 'BULLETIN — TROISIÈME CONTRÔLE',
                default => 'BULLETIN SCOLAIRE — ' . strtoupper($bulletin['controleInfo']['nom'] ?? ''),
            });
            ?>
        </h2>
    </div>
    <div class="bulletin-eleve-info">
        <span><strong>Nom :</strong> <?= h($bulletin['eleveInfo']['nom']) ?></span>
        <span><strong>Matricule :</strong> <?= h($bulletin['eleveInfo']['matricule']) ?></span>
        <span><strong>Prénom :</strong> <?= h($bulletin['eleveInfo']['prenom']) ?></span>
        <span>
            <strong>Classe :</strong>
            <?= h($bulletin['eleveInfo']['classe_nom']) ?>
            <?php if (!empty($bulletin['eleveInfo']['classe_niveau'])): ?>
                <small class="text-muted">(<?= h($bulletin['eleveInfo']['classe_niveau']) ?>)</small>
            <?php endif; ?>
        </span>
        <span><strong>Sexe :</strong> <?= $bulletin['eleveInfo']['sexe']==='F'?'Féminin':'Masculin' ?></span>
        <span><strong>Période :</strong> <?= h($bulletin['controleInfo']['periode']??'') ?></span>
    </div>

    <?php if (empty($bulletin['notes'])): ?>
        <div class="alert alert-info">Aucune note enregistrée.</div>
    <?php else: ?>
    <table class="table table-bordered bulletin-table">
        <thead>
            <tr>
                <th class="matiere-col text-start">Matière</th>
                <th>Code</th>
                <th>Barème</th>
                <th>Points obtenus</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($bulletin['notes'] as $n):
                $pct = $n['bareme']>0 ? $n['note_brute']/$n['bareme']*100 : 0;
                $couleur = $pct>=50?'text-success':'text-danger';
            ?>
            <tr>
                <td class="matiere-col text-start fw-500"><?= h($n['matiere']) ?></td>
                <td><span class="badge bg-secondary"><?= h($n['code']) ?></span></td>
                <td>/<?= h($n['bareme']) ?></td>
                <td class="fw-bold <?= $couleur ?>">
                    <?= number_format($n['note_brute'],2) ?>
                    <small class="text-muted fw-normal">/<?= h($n['bareme']) ?></small>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot class="table-light fw-bold">
            <tr>
                <td colspan="2" class="text-end">TOTAL</td>
                <td>/<?= h($bulletin['totalBaremeClasse']) ?></td>
                <td class="text-primary"><?= number_format($bulletin['totalPointsObtenus'],2) ?></td>
            </tr>
        </tfoot>
    </table>

    <div class="bulletin-moyenne">
        <div>
            <div class="fw-bold fs-4">
                <?= number_format($bulletin['totalPointsObtenus'],2) ?> / <?= h($bulletin['totalBaremeClasse']) ?> points
            </div>
            <div class="mt-1">
                Diviseur : <?= number_format($bulletin['diviseur'],1) ?>
                &nbsp;→&nbsp;
                <span class="fs-5 fw-bold">Moyenne : <?= number_format($bulletin['moyenne'],2) ?>/10</span>
            </div>
            <small>Rang : <?= h($bulletin['rang']) ?>/<?= h($bulletin['nbEleves']) ?></small>
        </div>
        <div class="text-end">
            <div><?= h($bulletin['appreciation']) ?></div>
        </div>
    </div>

    <?php if ($bulletin['decision']): ?>
    <div class="mt-3 p-3 text-center border rounded">
        <strong>Résultat final :</strong>
        Moyenne = <strong><?= number_format($bulletin['moyenneFinale'],2) ?>/10</strong>
        &mdash;
        <span class="<?= $bulletin['decision']==='Admis'?'bulletin-decision-admis':'bulletin-decision-reprend' ?>">
            <?= h($bulletin['decision']) ?>
        </span>
    </div>
    <?php endif; ?>

    <div class="mt-3 p-2 bg-light rounded border" style="font-size:0.8rem;color:#6b7280">
        <i class="fas fa-calculator me-1"></i>
        <?= number_format($bulletin['totalPointsObtenus'],2) ?> ÷ <?= number_format($bulletin['diviseur'],1) ?>
        = <?= number_format($bulletin['moyenne'],2) ?>/10
        &nbsp;|&nbsp; Seuil admission : 6.00/10
    </div>
    <?php endif; ?>
</div>

<div class="text-center mt-3 no-print">
    <button onclick="window.print()" class="btn btn-comep">
        <i class="fas fa-print me-2"></i>Imprimer
    </button>
</div>
<?php $scriptPage='<style>@media print{.navbar,.footer,form,.no-print,h1.page-title{display:none!important}.bulletin-wrapper{box-shadow:none!important;}}</style>'; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
