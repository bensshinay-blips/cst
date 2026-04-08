<?php
/**
 * eleve/bulletin.php - Portail élève : voir ses bulletins
 * Version responsive + impression optimisée
 */
session_start();
require_once __DIR__ . '/../config/database.php';

// Vérifier la session élève
if (empty($_SESSION['eleve_id'])) {
    header('Location: connexion.php'); exit();
}

$eleveId  = (int)$_SESSION['eleve_id'];
$pdo      = getDB();

// Vérifier que l'accès est toujours autorisé
$acces = $pdo->prepare("SELECT acces FROM acces_bulletins WHERE eleve_id=:eid AND annee_scolaire=:annee");
$acces->execute([':eid'=>$eleveId, ':annee'=>ANNEE_SCOLAIRE]);
$statutAcces = $acces->fetchColumn();
if ($statutAcces !== 'autorise') {
    session_destroy();
    header('Location: connexion.php?erreur=acces_revoque'); exit();
}

// Infos élève complètes
$stmt = $pdo->prepare("SELECT e.*, c.nom AS classe_nom, c.niveau AS classe_niveau FROM eleves e LEFT JOIN classes c ON e.classe_id=c.id WHERE e.id=:id");
$stmt->execute([':id' => $eleveId]);
$eleve = $stmt->fetch();

// Contrôle sélectionné
$controleId = (int)($_GET['controle_id'] ?? 0);

// Tous les contrôles de l'année
$controles = $pdo->query("
    SELECT * FROM controles
    WHERE annee_scolaire = '" . ANNEE_SCOLAIRE . "'
    ORDER BY numero
")->fetchAll();

// Si aucun contrôle sélectionné, prendre le premier qui a des notes
if (!$controleId) {
    foreach ($controles as $ctrl) {
        $hasNotes = $pdo->prepare("SELECT COUNT(*) FROM notes WHERE eleve_id=:eid AND controle_id=:cid");
        $hasNotes->execute([':eid'=>$eleveId, ':cid'=>$ctrl['id']]);
        if ($hasNotes->fetchColumn() > 0) {
            $controleId = $ctrl['id']; break;
        }
    }
    if (!$controleId && !empty($controles)) {
        $controleId = $controles[0]['id'];
    }
}

// Contrôle actif
$controleActif = null;
foreach ($controles as $c) {
    if ($c['id'] === $controleId) { $controleActif = $c; break; }
}

// Notes de l'élève pour ce contrôle
$notes = [];
$totalPointsObtenus = 0;
$totalBaremeClasse  = 0;
$moyenne            = 0;
$rang               = 0;
$nbEleves           = 0;
$appreciation       = '';

if ($controleId > 0) {
    $stmt = $pdo->prepare("
        SELECT n.note AS note_brute,
               m.nom AS matiere,
               m.code,
               COALESCE(cm.bareme, 100) AS bareme
        FROM notes n
        JOIN matieres m ON n.matiere_id = m.id
        LEFT JOIN classe_matieres cm
            ON cm.matiere_id = m.id
            AND cm.classe_id = :cls
            AND cm.annee_scolaire = :annee
        WHERE n.eleve_id = :eid AND n.controle_id = :cid
        ORDER BY m.nom
    ");
    $stmt->execute([
        ':eid'  => $eleveId,
        ':cid'  => $controleId,
        ':cls'  => $eleve['classe_id'],
        ':annee'=> ANNEE_SCOLAIRE,
    ]);
    $notes = $stmt->fetchAll();

    // Total barème de la classe
    $stmt2 = $pdo->prepare("SELECT COALESCE(SUM(bareme),0) FROM classe_matieres WHERE classe_id=:cid AND annee_scolaire=:annee");
    $stmt2->execute([':cid'=>$eleve['classe_id'], ':annee'=>ANNEE_SCOLAIRE]);
    $totalBaremeClasse  = (int)$stmt2->fetchColumn();
    $totalPointsObtenus = array_sum(array_column($notes, 'note_brute'));

    // Moyenne sur 10
    $diviseur = $totalBaremeClasse > 0 ? $totalBaremeClasse / 10 : 1;
    $moyenne  = $totalBaremeClasse > 0 ? round($totalPointsObtenus / $diviseur, 2) : 0;

    // Rang dans la classe
    $stmt3 = $pdo->prepare("
        SELECT n.eleve_id, SUM(n.note) AS total_pts
        FROM notes n
        WHERE n.controle_id = :cid
        AND n.eleve_id IN (SELECT id FROM eleves WHERE classe_id=:cls AND status='actif')
        GROUP BY n.eleve_id ORDER BY total_pts DESC
    ");
    $stmt3->execute([':cid'=>$controleId, ':cls'=>$eleve['classe_id']]);
    $classement = $stmt3->fetchAll();
    $nbEleves   = count($classement);
    $rang       = 1;
    foreach ($classement as $i => $row) {
        if ($row['eleve_id'] == $eleveId) { $rang = $i + 1; break; }
    }

    $appreciation = match(true) {
        $moyenne >= 9 => 'Excellent',
        $moyenne >= 8 => 'Très Bien',
        $moyenne >= 7 => 'Bien',
        $moyenne >= 6 => 'Assez Bien',
        $moyenne >= 5 => 'Passable',
        $moyenne >= 4 => 'Insuffisant',
        default       => 'Très Insuffisant',
    };
}

// Moyenne finale (si K3 disponible)
$moyenneFinale = null; $decision = null;
$stmtFin = $pdo->prepare("SELECT moyenne_finale, decision FROM moyennes_finales WHERE eleve_id=:eid AND annee_scolaire=:annee");
$stmtFin->execute([':eid'=>$eleveId, ':annee'=>ANNEE_SCOLAIRE]);
$fin = $stmtFin->fetch();
if ($fin) { $moyenneFinale = $fin['moyenne_finale']; $decision = $fin['decision']; }

// Résumé de tous les contrôles (pour le tableau récap)
$resumeControles = [];
foreach ($controles as $ctrl) {
    $stmtR = $pdo->prepare("SELECT SUM(n.note) AS total FROM notes n WHERE n.eleve_id=:eid AND n.controle_id=:cid");
    $stmtR->execute([':eid'=>$eleveId, ':cid'=>$ctrl['id']]);
    $totalPts = $stmtR->fetchColumn();
    $moyCtrl  = ($totalBaremeClasse > 0 && $totalPts !== null)
        ? round($totalPts / ($totalBaremeClasse / 10), 2)
        : null;
    $resumeControles[] = [
        'nom'     => $ctrl['nom'],
        'numero'  => $ctrl['numero'],
        'id'      => $ctrl['id'],
        'total'   => $totalPts,
        'moyenne' => $moyCtrl,
    ];
}

function h2($v) { return htmlspecialchars((string)($v??''), ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mon Bulletin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <style>
        /* ===== RESET & BASE ===== */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #f0f2f5; font-family: 'Inter', sans-serif; }

        /* ===== NAVBAR ===== */
        .navbar-eleve {
            background: linear-gradient(135deg, #065f46, #0d9488);
            padding: 0.5rem 1rem;
        }
        .navbar-eleve .navbar-brand { font-size: 1rem; }
        @media (max-width: 480px) {
            .navbar-eleve .navbar-brand span.brand-name { display: none; }
            .navbar-eleve .btn-outline-light { padding: 0.25rem 0.5rem; font-size: 0.75rem; }
        }

        /* ===== CARTE ÉLÈVE ===== */
        .eleve-card {
            background: linear-gradient(135deg, #065f46, #0d6efd);
            color: white;
            border-radius: 16px;
            padding: 1.25rem 1.5rem;
        }
        .eleve-card h2 { font-size: 1.15rem; font-weight: 700; margin-bottom: 0.25rem; }
        .eleve-card .meta { display: flex; flex-wrap: wrap; gap: 0.5rem 1rem; font-size: 0.82rem; opacity: 0.9; }
        .eleve-card .result-block { text-align: right; }
        .eleve-card .result-block .moy-finale { font-size: 1.6rem; font-weight: 800; line-height: 1; }
        @media (max-width: 575px) {
            .eleve-card { padding: 1rem; }
            .eleve-card h2 { font-size: 1rem; }
            /* Masquer le bloc résultat final sur mobile (visible dans les cartes contrôles) */
            .eleve-card .result-block { display: none; }
        }

        /* ===== GRILLE DES CONTRÔLES ===== */
        .controles-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 0.5rem;
        }
        .ctrl-card {
            border: 1.5px solid #e2e8f0;
            border-radius: 10px;
            background: white;
            padding: 0.6rem 0.5rem;
            text-align: center;
            text-decoration: none;
            color: inherit;
            transition: transform 0.15s, box-shadow 0.15s;
            display: block;
        }
        .ctrl-card:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.1); color: inherit; }
        .ctrl-card.active { border-color: #1a3a5c; border-width: 2px; }
        .ctrl-card .ctrl-label { font-size: 0.72rem; color: #6b7280; margin-bottom: 2px; }
        .ctrl-card .ctrl-moy { font-size: 1.1rem; font-weight: 700; }
        .ctrl-card .ctrl-pts { font-size: 0.7rem; color: #9ca3af; }
        @media (max-width: 400px) {
            .controles-grid { grid-template-columns: repeat(2, 1fr); }
            .ctrl-card .ctrl-moy { font-size: 0.95rem; }
        }

        /* ===== BULLETIN ===== */
        .bulletin-print {
            background: white;
            border-radius: 12px;
            padding: 22px;
            max-width: 100%;
            margin: 0 auto;
            box-shadow: 0 4px 24px rgba(0,0,0,0.10);
        }
        @media (max-width: 575px) {
            .bulletin-print { padding: 12px; border-radius: 8px; }
        }

        /* En-tête */
        .bul-header {
            text-align: center;
            border-bottom: 3px double #1a3a5c;
            padding-bottom: 10px;
            margin-bottom: 14px;
        }
        .bul-header h1 {
            font-size: 1.15rem;
            font-weight: 900;
            color: #1a3a5c;
            text-transform: uppercase;
            margin-bottom: 2px;
        }
        .bul-header .sub { font-size: 0.75rem; color: #6b7280; }
        .bul-titre { font-size: 0.9rem; font-weight: 700; color: #1a3a5c; margin-top: 4px; }
        @media (max-width: 575px) {
            .bul-header h1 { font-size: 0.95rem; }
            .bul-header .sub { font-size: 0.68rem; }
            .bul-titre { font-size: 0.78rem; }
        }

        /* Infos élève */
        .bul-eleve {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 3px 20px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 10px 14px;
            margin-bottom: 14px;
            font-size: 0.85rem;
        }
        @media (max-width: 480px) {
            .bul-eleve {
                grid-template-columns: 1fr;
                gap: 2px;
                padding: 8px 10px;
                font-size: 0.78rem;
            }
        }

        /* Tableau — défilement horizontal avec indicateur */
        .table-responsive-custom {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            margin-bottom: 12px;
            position: relative;
        }
        /* Indicateur de défilement visible uniquement sur mobile */
        @media (max-width: 600px) {
            .table-responsive-custom::before {
                content: '← Faire défiler le tableau →';
                display: block;
                text-align: center;
                font-size: 11px;
                color: #9ca3af;
                margin-bottom: 4px;
            }
        }
        .bul-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.83rem;
            min-width: 380px;
        }
        .bul-table thead th {
            background: #1a3a5c;
            color: white;
            padding: 7px 10px;
            text-align: center;
            font-weight: 600;
            font-size: 0.78rem;
        }
        .bul-table thead th:first-child { text-align: left; }
        .bul-table tbody tr:nth-child(even) { background: #f8fafc; }
        .bul-table td {
            padding: 6px 10px;
            border-bottom: 1px solid #e9ecef;
            text-align: center;
            vertical-align: middle;
        }
        .bul-table td:first-child { text-align: left; font-weight: 500; }
        .bul-table tfoot td {
            padding: 7px 10px;
            font-weight: 700;
            background: #eef2ff;
            border-top: 2px solid #1a3a5c;
        }
        @media (max-width: 575px) {
            .bul-table { font-size: 0.72rem; min-width: 340px; }
            .bul-table th, .bul-table td { padding: 5px 6px; }
        }

        .note-ok  { color: #065f46; font-weight: 700; }
        .note-nok { color: #991b1b; font-weight: 700; }

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
            gap: 8px;
            flex-wrap: wrap;
        }
        .bul-resume .moyenne-val { font-size: 1.2rem; font-weight: 800; }
        .bul-resume .rang-txt { font-size: 0.76rem; opacity: 0.85; }
        @media (max-width: 480px) {
            .bul-resume { flex-direction: column; text-align: center; }
            .bul-resume > div:last-child { text-align: center; }
            .bul-resume .moyenne-val { font-size: 1rem; }
        }

        /* Décision finale */
        .bul-decision {
            text-align: center;
            border: 2px solid #1a3a5c;
            border-radius: 8px;
            padding: 8px 12px;
            margin-bottom: 12px;
            font-size: 0.85rem;
        }
        .decision-admis   { color: #065f46; font-weight: 800; font-size: 1rem; }
        .decision-reprend { color: #991b1b; font-weight: 800; font-size: 1rem; }
        @media (max-width: 480px) {
            .bul-decision { font-size: 0.75rem; padding: 6px 8px; }
            .decision-admis, .decision-reprend { font-size: 0.88rem; }
        }

        /* Signatures */
        .bul-signatures {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 12px;
            margin-top: 28px;
            text-align: center;
            font-size: 0.76rem;
            color: #6b7280;
        }
        .bul-signatures .sig-line {
            border-top: 1px solid #9ca3af;
            padding-top: 6px;
            margin-top: 36px;
        }
        @media (max-width: 400px) {
            .bul-signatures { grid-template-columns: 1fr; gap: 6px; }
            .bul-signatures .sig-line { margin-top: 20px; }
        }

        /* Badge */
        .badge-print {
            border: 1px solid #333 !important;
            color: #000 !important;
            background: none !important;
            padding: 2px 6px;
        }

        /* Bouton imprimer */
        .btn-imprimer {
            display: block;
            width: 100%;
            max-width: 320px;
            margin: 1rem auto 0;
        }
        @media (min-width: 576px) {
            .btn-imprimer { width: auto; display: inline-block; }
        }

        /* ===== IMPRESSION ===== */
        @media print {
            .no-print { display: none !important; }
            body, .bulletin-print { background: white !important; margin: 0; padding: 0; }
            .bulletin-print {
                box-shadow: none !important;
                padding: 0 !important;
                max-width: 100%;
                overflow: visible;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            @page { size: letter portrait; margin: 1.2cm 1.5cm; }
            .badge-print { border: 1px solid #333 !important; background: none !important; color: #000 !important; }
            table { page-break-inside: avoid; }
            .bul-eleve, .bul-resume, .bul-decision { break-inside: avoid; }
            .table-responsive-custom { overflow: visible; }
            .table-responsive-custom::before { display: none !important; }
            .bul-table { min-width: unset; font-size: 0.8rem; }
        }
    </style>
</head>
<body>

<!-- ===== NAVBAR ===== -->
<nav class="navbar navbar-dark navbar-eleve no-print">
    <div class="container-fluid px-2 px-md-3">
        <a class="navbar-brand d-flex align-items-center gap-2" href="#">
            <i class="fas fa-graduation-cap"></i>
            <span class="fw-bold">CST</span>
            <span class="brand-name fw-normal opacity-75">— Mon Bulletin</span>
        </a>
        <div class="d-flex align-items-center gap-2">
            <span class="text-white-50 small d-none d-sm-inline">
                <?= h2($_SESSION['eleve_prenom'] ?? $eleve['prenom']) ?>
                <?= h2($_SESSION['eleve_nom'] ?? $eleve['nom']) ?>
            </span>
            <a href="deconnexion.php" class="btn btn-sm btn-outline-light">
                <i class="fas fa-sign-out-alt me-1"></i>Quitter
            </a>
        </div>
    </div>
</nav>

<div class="container py-3 py-md-4">

    <!-- ===== CARTE ÉLÈVE ===== -->
    <div class="eleve-card mb-3 no-print">
        <div class="d-flex justify-content-between align-items-start gap-3">
            <div>
                <h2>Bienvenue, <?= h2($eleve['prenom']) ?> <?= h2($eleve['nom']) ?> !</h2>
                <div class="meta">
                    <span>Matricule : <strong><?= h2($eleve['matricule']) ?></strong></span>
                    <span>Classe : <strong><?= h2($eleve['classe_niveau']) ?></strong></span>
                    <span>Année : <strong><?= ANNEE_SCOLAIRE ?></strong></span>
                </div>
            </div>
            <?php if ($moyenneFinale !== null): ?>
            <div class="result-block flex-shrink-0">
                <div style="font-size:0.72rem;opacity:.75">Résultat final</div>
                <div class="moy-finale"><?= number_format($moyenneFinale, 2) ?><small style="font-size:1rem">/10</small></div>
                <span class="badge <?= $decision==='Admis'?'bg-success':'bg-danger' ?> px-2 mt-1">
                    <?= h2($decision) ?>
                </span>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ===== GRILLE DES CONTRÔLES ===== -->
    <div class="controles-grid mb-3 no-print">
        <?php foreach ($resumeControles as $rc): ?>
        <a href="bulletin.php?controle_id=<?= $rc['id'] ?>"
           class="ctrl-card <?= $controleId===$rc['id']?'active':'' ?>">
            <div class="ctrl-label"><?= h2($rc['nom']) ?></div>
            <?php if ($rc['moyenne'] !== null): ?>
                <div class="ctrl-moy <?= $rc['moyenne']>=6?'text-success':'text-danger' ?>">
                    <?= number_format($rc['moyenne'], 2) ?>/10
                </div>
                <div class="ctrl-pts"><?= (int)$rc['total'] ?> pts</div>
            <?php else: ?>
                <div class="ctrl-moy text-secondary">—</div>
                <div class="ctrl-pts">Aucune note</div>
            <?php endif; ?>
        </a>
        <?php endforeach; ?>
    </div>

    <!-- ===== BULLETIN ===== -->
    <?php if (!empty($notes)): ?>
    <div class="bulletin-print" id="zone-impression">

        <!-- EN-TÊTE -->
        <div class="bul-header">
            <h1>Collège Sainte Thérèse</h1>
            <div class="sub">
                Port-Margot, Haïti &nbsp;|&nbsp;
                Tél : +509-XXXX-XXXX &nbsp;|&nbsp;
                cst@gmail.com
            </div>
            <div class="sub">Année scolaire : <strong><?= ANNEE_SCOLAIRE ?></strong></div>
            <div class="bul-titre">
                <?php
                $num = (int)($controleActif['numero'] ?? 0);
                echo h2(match($num) {
                    1 => 'BULLETIN DU PREMIER TRIMESTRE',
                    2 => 'BULLETIN DU DEUXIÈME TRIMESTRE',
                    3 => 'BULLETIN DU TROISIÈME TRIMESTRE',
                    default => 'BULLETIN SCOLAIRE',
                });
                ?>
            </div>
        </div>

        <!-- INFOS ÉLÈVE -->
        <div class="bul-eleve">
            <div><strong>Nom :</strong> <?= h2($eleve['nom']) ?></div>
            <div><strong>Prénom :</strong> <?= h2($eleve['prenom']) ?></div>
            <div><strong>Matricule :</strong> <?= h2($eleve['matricule']) ?></div>
            <div><strong>Classe :</strong> <?= h2($eleve['classe_nom']) ?></div>
            <div><strong>Sexe :</strong> <?= $eleve['sexe']==='F' ? 'Féminin' : 'Masculin' ?></div>
            <div><strong>Niveau :</strong> <?= h2($eleve['classe_niveau'] ?? '') ?></div>
        </div>

        <!-- TABLEAU DES NOTES -->
        <div class="table-responsive-custom">
            <table class="bul-table">
                <thead>
                    <tr>
                        <th>Matière</th>
                        <th>Code</th>
                        <th>Barème</th>
                        <th>Points</th>
                        <th>%</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($notes as $n):
                        $pct    = $n['bareme'] > 0 ? round($n['note_brute'] / $n['bareme'] * 100) : 0;
                        $classe = $pct >= 50 ? 'note-ok' : 'note-nok';
                    ?>
                    <tr>
                        <td><?= h2($n['matiere']) ?></td>
                        <td><span class="badge bg-secondary badge-print"><?= h2($n['code']) ?></span></td>
                        <td><?= h2($n['bareme']) ?></td>
                        <td class="<?= $classe ?>"><?= (int)$n['note_brute'] ?></td>
                        <td class="<?= $classe ?>"><?= $pct ?>%</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="2" style="text-align:right">TOTAL GÉNÉRAL</td>
                        <td><?= h2($totalBaremeClasse) ?></td>
                        <td style="color:#1a3a5c"><?= (int)$totalPointsObtenus ?></td>
                        <td style="color:#1a3a5c">
                            <?= $totalBaremeClasse > 0 ? round($totalPointsObtenus / $totalBaremeClasse * 100) : 0 ?>%
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <!-- RÉSUMÉ MOYENNE -->
        <div class="bul-resume">
            <div>
                <div class="moyenne-val">Moyenne : <?= number_format($moyenne, 2) ?>/10</div>
                <div class="rang-txt">Rang : <?= h2($rang) ?> / <?= h2($nbEleves) ?> élèves</div>
            </div>
            <div>
                <div style="font-size:0.74rem;opacity:.85">Appréciation</div>
                <div style="font-size:0.95rem;font-weight:700"><?= h2($appreciation) ?></div>
            </div>
        </div>

        <!-- DÉCISION FINALE -->
        <?php if ($decision): ?>
        <div class="bul-decision">
            Résultat final <?= ANNEE_SCOLAIRE ?> &nbsp;|&nbsp;
            Moyenne finale : <strong style="color:#1a3a5c"><?= number_format($moyenneFinale, 2) ?>/10</strong>
            &nbsp;&mdash;&nbsp;
            <span class="<?= $decision==='Admis' ? 'decision-admis' : 'decision-reprend' ?>">
                <?= h2($decision) ?>
            </span>
            <div style="font-size:0.72rem;color:#9ca3af;margin-top:3px">
                Seuil d'admission : 5.00 / 10
            </div>
        </div>
        <?php endif; ?>

        <!-- SIGNATURES -->
        <div class="bul-signatures">
            <div><div class="sig-line">Signature parents</div></div>
            <div>CST</div>
            <div><div class="sig-line">Cachet &amp; Direction</div></div>
        </div>

    </div>

    <!-- BOUTON IMPRIMER -->
    <div class="text-center mt-3 mt-md-4 no-print">
        <button onclick="window.print()" class="btn btn-success btn-imprimer">
            <i class="fas fa-print me-2"></i>Imprimer / Télécharger PDF
        </button>
    </div>

    <?php elseif ($controleId > 0): ?>
    <div class="alert alert-info text-center no-print">
        <i class="fas fa-info-circle me-2"></i>
        Aucune note enregistrée pour <?= h2($controleActif['nom'] ?? '') ?>.
    </div>
    <?php endif; ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
