<?php
/**
 * admin/bulletins.php - Bulletins scolaires
 * Impression optimisée pour feuille 8.5 x 11 pouces
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
requireAdmin();

$titrePage   = 'Bulletins Scolaires';
$pdo         = getDB();
$classe_id   = (int)($_GET['classe_id']   ?? 0);
$controle_id = (int)($_GET['controle_id'] ?? 0);
$eleve_id    = (int)($_GET['eleve_id']    ?? 0);

$classes = $pdo->query("SELECT id, nom, niveau FROM classes ORDER BY nom")->fetchAll();

$stmt = $pdo->prepare("SELECT id, nom, numero FROM controles WHERE annee_scolaire=:annee ORDER BY numero");
$stmt->execute([':annee' => ANNEE_SCOLAIRE]);
$controles = $stmt->fetchAll();

$eleves   = [];
$bulletin = null;

// Charger la liste des élèves de la classe
if ($classe_id > 0 && $controle_id > 0 && $eleve_id === 0) {
    $stmt = $pdo->prepare("
        SELECT id, CONCAT(prenom,' ',nom) AS nom_complet, matricule
        FROM eleves WHERE classe_id=:cid AND status='actif' ORDER BY nom
    ");
    $stmt->execute([':cid' => $classe_id]);
    $eleves = $stmt->fetchAll();
}

// Construire le bulletin
if ($eleve_id > 0 && $controle_id > 0) {

    $stmt = $pdo->prepare("
        SELECT e.*, c.nom AS classe_nom, c.niveau AS classe_niveau
        FROM eleves e LEFT JOIN classes c ON e.classe_id=c.id WHERE e.id=:id
    ");
    $stmt->execute([':id' => $eleve_id]);
    $eleveInfo = $stmt->fetch();

    $stmt = $pdo->prepare("SELECT * FROM controles WHERE id=:id");
    $stmt->execute([':id' => $controle_id]);
    $controleInfo = $stmt->fetch();

    // Notes avec barème
    $stmt = $pdo->prepare("
        SELECT n.note AS note_brute,
               m.nom  AS matiere,
               m.code,
               COALESCE(cm.bareme, 100) AS bareme
        FROM notes n
        JOIN matieres m ON n.matiere_id = m.id
        LEFT JOIN classe_matieres cm
            ON cm.matiere_id=m.id AND cm.classe_id=:cls AND cm.annee_scolaire=:annee
        WHERE n.eleve_id=:eid AND n.controle_id=:cid
        ORDER BY m.nom
    ");
    $stmt->execute([
        ':eid'   => $eleve_id,
        ':cid'   => $controle_id,
        ':cls'   => $eleveInfo['classe_id'],
        ':annee' => ANNEE_SCOLAIRE,
    ]);
    $notes = $stmt->fetchAll();

    // Total barème
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(bareme),0) FROM classe_matieres
        WHERE classe_id=:cid AND annee_scolaire=:annee
    ");
    $stmt->execute([':cid'=>$eleveInfo['classe_id'],':annee'=>ANNEE_SCOLAIRE]);
    $totalBaremeClasse  = (int)$stmt->fetchColumn();
    $totalPointsObtenus = array_sum(array_column($notes, 'note_brute'));
    $diviseur           = $totalBaremeClasse > 0 ? $totalBaremeClasse / 10 : 1;
    $moyenne            = round($totalPointsObtenus / $diviseur, 2);

    // Rang
    $stmt2 = $pdo->prepare("
        SELECT n.eleve_id, SUM(n.note) AS total_pts
        FROM notes n
        WHERE n.controle_id=:cid
        AND n.eleve_id IN (SELECT id FROM eleves WHERE classe_id=:cls AND status='actif')
        GROUP BY n.eleve_id ORDER BY total_pts DESC
    ");
    $stmt2->execute([':cid'=>$controle_id,':cls'=>$eleveInfo['classe_id']]);
    $classement = $stmt2->fetchAll();
    $rang = 1; $nbEleves = count($classement);
    foreach ($classement as $i => $row) {
        if ($row['eleve_id'] == $eleve_id) { $rang = $i + 1; break; }
    }

    $appreciation = match(true) {
        $moyenne >= 9 => 'Excellent',
        $moyenne >= 8 => 'Très Bien',
        $moyenne >= 7 => 'Bien',
        $moyenne >= 6 => 'Assez Bien',
        $moyenne >= 5 => 'Passable',
        $moyenne >= 4 => 'Insuffisant',
        default       => 'Très Mal',
    };

    // Décision finale K3
    $moyenneFinale = null; $decision = null;
    if ($controleInfo['numero'] == 3) {
        $stmt = $pdo->prepare("
            SELECT c.id, COALESCE(SUM(n.note),0) AS total_pts
            FROM controles c
            LEFT JOIN notes n ON n.controle_id=c.id AND n.eleve_id=:eid
            WHERE c.annee_scolaire=:annee GROUP BY c.id
        ");
        $stmt->execute([':eid'=>$eleve_id,':annee'=>ANNEE_SCOLAIRE]);
        $tousControles = $stmt->fetchAll();
        $somme = 0; $nb = 0;
        foreach ($tousControles as $tc) {
            $somme += $tc['total_pts'] / $diviseur;
            $nb++;
        }
        $moyenneFinale = $nb > 0 ? round($somme / $nb, 2) : 0;
        $decision      = $moyenneFinale >= 5 ? 'Admis' : 'Reprend Classe';

        $pdo->prepare("
            INSERT INTO moyennes_finales (eleve_id, annee_scolaire, moyenne_finale, decision)
            VALUES (:eid,:annee,:mf,:dec)
            ON DUPLICATE KEY UPDATE moyenne_finale=:mf2, decision=:dec2
        ")->execute([
            ':eid'=>$eleve_id,':annee'=>ANNEE_SCOLAIRE,
            ':mf'=>$moyenneFinale,':dec'=>$decision,
            ':mf2'=>$moyenneFinale,':dec2'=>$decision,
        ]);
    }

    $bulletin = compact(
        'eleveInfo','controleInfo','notes','totalPointsObtenus',
        'totalBaremeClasse','diviseur','moyenne','rang','nbEleves',
        'appreciation','moyenneFinale','decision'
    );
}

require_once __DIR__ . '/../includes/header.php';
?>

<!-- ===== STYLES IMPRESSION ===== -->
<style>
@media print {
    /* Cacher tout sauf le bulletin */
    .navbar, .footer, .main-content > .container-fluid > *:not(#zone-impression),
    .no-print, h1.page-title, .card.shadow-sm.mb-4 {
        display: none !important;
    }

    /* Format page lettre USA = 8.5 x 11 pouces */
    @page {
        size: letter portrait;
        margin: 1.2cm 1.5cm;
    }

    body {
        background: white !important;
        font-size: 11pt;
        color: #000;
    }

    #zone-impression {
        display: block !important;
    }

    .bulletin-print {
        width: 100%;
        box-shadow: none !important;
        border: none !important;
        padding: 0 !important;
        margin: 0 !important;
    }

    .badge-print {
        border: 1px solid #333 !important;
        color: #000 !important;
        background: none !important;
        padding: 2px 6px;
    }

    table { page-break-inside: avoid; }
}

/* ---- Aperçu écran ---- */
.bulletin-print {
    background: white;
    border: 2px solid #e2e8f0;
    border-radius: 12px;
    padding: 28px 32px;
    max-width: 800px;
    margin: 0 auto;
    box-shadow: 0 4px 24px rgba(0,0,0,0.10);
}

/* En-tête école */
.bul-header {
    text-align: center;
    border-bottom: 3px double #1a3a5c;
    padding-bottom: 10px;
    margin-bottom: 14px;
}
.bul-header h1 {
    font-size: 1.25rem;
    font-weight: 900;
    color: #1a3a5c;
    margin: 0 0 2px;
    text-transform: uppercase;
    letter-spacing: 1px;
}
.bul-header .sub {
    font-size: 0.8rem;
    color: #555;
    margin: 2px 0;
}
.bul-titre {
    font-size: 1rem;
    font-weight: 700;
    color: #1a3a5c;
    margin-top: 6px;
    letter-spacing: 0.5px;
}

/* Infos élève — 2 colonnes */
.bul-eleve {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 4px 24px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 10px 14px;
    margin-bottom: 14px;
    font-size: 0.88rem;
}
.bul-eleve-item {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 3px 0;
    border-bottom: 1px dashed #e9ecef;
}
.bul-eleve-item:last-child,
.bul-eleve-item:nth-last-child(2):nth-child(odd) {
    border-bottom: none;
}
.bul-eleve-item strong {
    color: #1a3a5c;
    min-width: 75px;
    font-size: 0.8rem;
}

/* Tableau des notes */
.bul-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.85rem;
    margin-bottom: 12px;
}
.bul-table thead th {
    background: #1a3a5c;
    color: white;
    padding: 7px 10px;
    text-align: center;
    font-weight: 600;
    font-size: 0.8rem;
    letter-spacing: 0.3px;
}
.bul-table thead th:first-child {
    text-align: left;
}
.bul-table tbody tr:nth-child(even) {
    background: #f8fafc;
}
.bul-table tbody tr:hover {
    background: #eef2ff;
}
.bul-table td {
    padding: 6px 10px;
    border-bottom: 1px solid #e9ecef;
    text-align: center;
    vertical-align: middle;
}
.bul-table td:first-child {
    text-align: left;
    font-weight: 500;
}
.bul-table tfoot td {
    padding: 7px 10px;
    font-weight: 700;
    background: #eef2ff;
    border-top: 2px solid #1a3a5c;
}
.note-ok   { color: #065f46; font-weight: 700; }
.note-nok  { color: #991b1b; font-weight: 700; }

/* Résumé moyenne */
.bul-resume {
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: linear-gradient(135deg, #1a3a5c, #0d6efd);
    color: white;
    border-radius: 8px;
    padding: 10px 16px;
    margin-bottom: 12px;
    font-size: 0.9rem;
}
.bul-resume .moyenne-val {
    font-size: 1.3rem;
    font-weight: 800;
}
.bul-resume .rang-txt {
    font-size: 0.78rem;
    opacity: 0.85;
}

/* Décision finale */
.bul-decision {
    text-align: center;
    border: 2px solid #1a3a5c;
    border-radius: 8px;
    padding: 8px 12px;
    margin-bottom: 12px;
    font-size: 0.88rem;
}
.decision-admis   { color: #065f46; font-weight: 800; font-size: 1rem; }
.decision-reprend { color: #991b1b; font-weight: 800; font-size: 1rem; }

/* Signatures */
.bul-signatures {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    gap: 16px;
    margin-top: 28px;
    text-align: center;
    font-size: 0.78rem;
    color: #555;
}
.bul-signatures .sig-line {
    border-top: 1px solid #999;
    padding-top: 6px;
    margin-top: 36px;
}
</style>

<h1 class="page-title no-print">
 Bulletins Scolaires
</h1>
<?php afficherMessage(); ?>

<!-- ===== FORMULAIRE DE SÉLECTION ===== -->
<div class="card shadow-sm mb-4 no-print">
    <div class="card-header">
        <i class="fas fa-search me-2 text-primary"></i>Sélectionner un bulletin
    </div>
    <div class="card-body">
        <form method="GET" action="bulletins.php" class="row g-3 align-items-end">

            <div class="col-md-3">
                <label class="form-label">Niveau</label>
                <select name="classe_id" class="form-select" onchange="this.form.submit()">
                    <option value="">Choisir</option>
                    <?php foreach ($classes as $c): ?>
                        <option value="<?= $c['id'] ?>"
                                <?= $classe_id===$c['id']?'selected':'' ?>>
                            <?= h($c['niveau']) ?>
                            <?php if ($c['niveau']): ?>
                            <?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-3">
                <label class="form-label">Trimestre</label>
                <select name="controle_id" class="form-select"
                        <?= !$classe_id ? 'disabled' : '' ?>>
                    <option value="">Choisir</option>
                    <?php foreach ($controles as $ctrl): ?>
                        <option value="<?= $ctrl['id'] ?>"
                                <?= $controle_id===$ctrl['id']?'selected':'' ?>>
                            <?= h($ctrl['nom']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <?php if (!empty($eleves)): ?>
            <div class="col-md-4">
                <label class="form-label">Élève</label>
                <select name="eleve_id" class="form-select">
                    <option value="">Sélectionner</option>
                    <?php foreach ($eleves as $e): ?>
                        <option value="<?= $e['id'] ?>">
                            <?= h($e['nom_complet']) ?> (<?= h($e['matricule']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-comep w-100">
                    <i class="fas fa-eye me-1"></i> Voir
                </button>
            </div>
            <?php elseif ($classe_id > 0 && $controle_id > 0): ?>
            <div class="col-12">
                <div class="alert alert-warning mb-0">Aucun élève trouvé dans cette classe.</div>
            </div>
            <?php elseif ($classe_id > 0): ?>
            <div class="col-md-2">
                <button type="submit" class="btn btn-comep w-100">
                    <i class="fas fa-search me-1"></i>
                </button>
            </div>
            <?php endif; ?>

        </form>
    </div>
</div>

<!-- ===== BULLETIN ===== -->
<?php if ($bulletin): ?>

<div id="zone-impression">
<div class="bulletin-print">

    <!-- EN-TÊTE ÉCOLE -->
    <div class="bul-header">
        <h1>Collège Sainte Thérèse</h1>
        <div class="sub">
            Port-Margot, Haïti &nbsp;|&nbsp;
            Phone : +509-XXXX-XXXX &nbsp;|&nbsp;
            Email : cst@gmail.com
        </div>
        <div class="sub">Année scolaire : <strong><?= ANNEE_SCOLAIRE ?></strong></div>
        <div class="bul-titre">
            <?php
            $num = (int)($bulletin['controleInfo']['numero'] ?? 0);
            echo h(match($num) {
                1 => ' BULLETIN DU PREMIER TRIMESTRE ',
                2 => ' BULLETIN DU DEUXIÈME TRIMESTRE ',
                3 => ' BULLETIN DU TROISIÈME TRIMESTRE ',
                default => 'BULLETIN SCOLAIRE',
            });
            ?>
        </div>
    </div>

    <!-- INFOS ÉLÈVE EN 2 COLONNES -->
    <div class="bul-eleve">
        <div class="bul-eleve-item">
            <strong>Nom :</strong>
            <?= h($bulletin['eleveInfo']['nom']) ?>
        </div>
        <div class="bul-eleve-item">
            <strong>Prénom :</strong>
            <?= h($bulletin['eleveInfo']['prenom']) ?>
        </div>
        <div class="bul-eleve-item">
            <strong>Matricule :</strong>
            <code><?= h($bulletin['eleveInfo']['matricule']) ?></code>
        </div>
        <div class="bul-eleve-item">
            <strong>Classe :</strong>
            <?= h($bulletin['eleveInfo']['classe_nom']) ?>
        </div>
        <div class="bul-eleve-item">
            <strong>Sexe :</strong>
            <?= $bulletin['eleveInfo']['sexe']==='F' ? 'Féminin' : 'Masculin' ?>
        </div>
        <div class="bul-eleve-item">
            <strong>Niveau :</strong>
            <?= h($bulletin['eleveInfo']['classe_niveau'] ?? '') ?>
        </div>
    </div>

    <!-- TABLEAU DES NOTES -->
    <?php if (empty($bulletin['notes'])): ?>
        <div style="text-align:center;padding:20px;color:#6b7280;border:1px dashed #ccc;border-radius:8px;margin-bottom:12px">
            Aucune note enregistrée pour ce trimestre.
        </div>
    <?php else: ?>

    <table class="bul-table">
        <thead>
            <tr>
                <th style="width:45%">Matière</th>
                <th style="width:12%">Code</th>
                <th style="width:15%">Barème</th>
                <th style="width:18%">Points obtenus</th>
                <th style="width:10%">%</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($bulletin['notes'] as $n):
                $pct     = $n['bareme'] > 0 ? round($n['note_brute'] / $n['bareme'] * 100) : 0;
                $classe  = $pct >= 50 ? 'note-ok' : 'note-nok';
            ?>
            <tr>
                <td><?= h($n['matiere']) ?></td>
                <td>
                    <span class="badge bg-secondary badge-print">
                        <?= h($n['code']) ?>
                    </span>
                </td>
                <td><?= h($n['bareme']) ?></td>
                <td class="<?= $classe ?>">
                    <?= (int)$n['note_brute'] ?>
                </td>
                <td class="<?= $classe ?>"><?= $pct ?>%</td>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="2" style="text-align:right">TOTAL GÉNÉRAL</td>
                <td><?= h($bulletin['totalBaremeClasse']) ?></td>
                <td style="color:#1a3a5c">
                    <?= (int)$bulletin['totalPointsObtenus'] ?>
                </td>
                <td style="color:#1a3a5c">
                    <?= $bulletin['totalBaremeClasse'] > 0
                        ? round($bulletin['totalPointsObtenus'] / $bulletin['totalBaremeClasse'] * 100)
                        : 0 ?>%
                </td>
            </tr>
        </tfoot>
    </table>

    <!-- RÉSUMÉ MOYENNE -->
    <div class="bul-resume">
        <div>
            <div class="moyenne-val">
                Moyenne : <?= number_format($bulletin['moyenne'], 2) ?>
            </div>
            <div class="rang-txt">
                Rang : <?= h($bulletin['rang']) ?> / <?= h($bulletin['nbEleves']) ?> élèves
            </div>
        </div>
        <div style="text-align:right">
            <div style="font-size:0.78rem;">Appréciation</div>
            <div style="font-size:1.1rem;font-weight:700">
                <?= h($bulletin['appreciation']) ?>
            </div>
        </div>
    </div>

    <!-- DÉCISION FINALE (K3 seulement) -->
<?php if ($bulletin['decision']): ?>
<div class="bul-decision">
    Résultat final <?= ANNEE_SCOLAIRE ?> &nbsp;|&nbsp;
    Moyenne finale : <strong style="color: #1a3a5c; font-size: 1.1rem;"><?= number_format($bulletin['moyenneFinale'], 2) ?></strong>
    &nbsp;&mdash;&nbsp;
    <span class="<?= $bulletin['decision']==='Admis' ? 'decision-admis' : 'decision-reprend' ?>">
        <?= h($bulletin['decision']) ?>
    </span>
    <div style="font-size:0.75rem;color:#6b7280;margin-top:2px">
        Seuil d'admission : 5.00 / 10
    </div>
</div>
<?php endif; ?>

    <!-- SIGNATURES -->
    <div class="bul-signatures">
        <div>
            <div class="sig-line">Signature des parents</div>
        </div>
        <div>
            CST</div>
        <div>
            <div class="sig-line">Cachet & Direction</div>
        </div>
    </div>

    <?php endif; // fin notes ?>

</div><!-- .bulletin-print -->
</div><!-- #zone-impression -->

<!-- Boutons (masqués à l'impression) -->
<div class="text-center mt-3 no-print">
    <button onclick="window.print()" class="btn btn-comep btn-lg">
        <i class="fas fa-print me-2"></i>Imprimer / Exporter PDF
    </button>
    <a href="bulletins.php?classe_id=<?= $classe_id ?>&controle_id=<?= $controle_id ?>"
       class="btn btn-outline-secondary btn-lg ms-2">
        <i class="fas fa-arrow-left me-1"></i> Autre élève
    </a>
</div>

<?php endif; // fin bulletin ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
