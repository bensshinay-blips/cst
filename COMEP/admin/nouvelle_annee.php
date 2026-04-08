<?php
/**
 * admin/nouvelle_annee.php - Passage automatique à la nouvelle année scolaire
 * Système de gestion scolaire CST
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
requireAdmin();

$titrePage = 'Nouvelle Année Scolaire';
$pdo = getDB();

// =====================================================================
// ORDRE DE PROGRESSION DES CLASSES
// 7èm A et 7èm B sont parallèles → les deux montent en 8èm
// NS4 est la dernière classe → diplôme
// =====================================================================
// On récupère l'ordre depuis la base dynamiquement selon le niveau
$niveauxOrdre = ['7èm', '8èm', '9èm', '10èm', 'NS3', 'NS4'];
$derniereClasse = 'NS4'; // Niveau de la dernière classe → diplôme

$journal    = [];   // Journal des actions effectuées
$erreurs    = [];   // Erreurs rencontrées
$execution  = false;
$anneeNouvelle = '';

// =====================================================================
// STATISTIQUES POUR L'AFFICHAGE
// =====================================================================
$anneeActuelle = ANNEE_SCOLAIRE;

$statsAvant = [];
try {
    $statsAvant['nb_eleves_actifs'] = $pdo->query(
        "SELECT COUNT(*) FROM eleves WHERE status='actif'"
    )->fetchColumn();

    $statsAvant['nb_avec_decision'] = $pdo->query("
        SELECT COUNT(DISTINCT mf.eleve_id)
        FROM moyennes_finales mf
        JOIN eleves e ON e.id = mf.eleve_id
        WHERE mf.annee_scolaire = '" . ANNEE_SCOLAIRE . "' AND e.status='actif'
    ")->fetchColumn();

    $statsAvant['nb_sans_decision'] = $statsAvant['nb_eleves_actifs'] - $statsAvant['nb_avec_decision'];

    $statsAvant['nb_admis'] = $pdo->query("
        SELECT COUNT(*) FROM moyennes_finales
        WHERE annee_scolaire='" . ANNEE_SCOLAIRE . "' AND decision='Admis'
    ")->fetchColumn();

    $statsAvant['nb_reprend'] = $pdo->query("
        SELECT COUNT(*) FROM moyennes_finales
        WHERE annee_scolaire='" . ANNEE_SCOLAIRE . "' AND decision='Repran Klas'
        OR (annee_scolaire='" . ANNEE_SCOLAIRE . "' AND decision='Reprend Classe')
    ")->fetchColumn();

    $statsAvant['nb_assignations'] = $pdo->query("
        SELECT COUNT(*) FROM professeur_classes WHERE annee_scolaire='" . ANNEE_SCOLAIRE . "'
    ")->fetchColumn();

    // Progression des classes (pour affichage)
    $progressionClasses = $pdo->query("
        SELECT c.id, c.nom, c.niveau,
               COUNT(e.id) AS nb_eleves,
               (SELECT COUNT(*) FROM moyennes_finales mf
                JOIN eleves e2 ON e2.id=mf.eleve_id
                WHERE e2.classe_id=c.id AND mf.annee_scolaire='" . ANNEE_SCOLAIRE . "'
                AND mf.decision='Admis') AS nb_admis,
               (SELECT COUNT(*) FROM moyennes_finales mf
                JOIN eleves e2 ON e2.id=mf.eleve_id
                WHERE e2.classe_id=c.id AND mf.annee_scolaire='" . ANNEE_SCOLAIRE . "'
                AND (mf.decision='Reprend Classe' OR mf.decision='Repran Klas')) AS nb_reprend
        FROM classes c
        LEFT JOIN eleves e ON e.classe_id=c.id AND e.status='actif'
        GROUP BY c.id
        ORDER BY FIELD(c.niveau, '7èm','8èm','9èm','10èm','NS3','NS4'), c.nom
    ")->fetchAll();

} catch (PDOException $e) {
    $erreurs[] = "Erreur chargement stats : " . $e->getMessage();
}

// =====================================================================
// TRAITEMENT : LANCER LA NOUVELLE ANNÉE
// =====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['lancer'])) {

    $anneeNouvelle = trim($_POST['annee_nouvelle'] ?? '');

    // Dates des contrôles
    $dates = [
        1 => [
            'debut' => $_POST['k1_debut'] ?? '',
            'fin'   => $_POST['k1_fin']   ?? '',
        ],
        2 => [
            'debut' => $_POST['k2_debut'] ?? '',
            'fin'   => $_POST['k2_fin']   ?? '',
        ],
        3 => [
            'debut' => $_POST['k3_debut'] ?? '',
            'fin'   => $_POST['k3_fin']   ?? '',
        ],
    ];

    // --- Validation ---
    $formatOk = preg_match('/^\d{4}-\d{4}$/', $anneeNouvelle);
    if (!$formatOk) {
        $erreurs[] = "Format d'année invalide. Utilisez le format : 2025-2026";
    }
    if ($anneeNouvelle === $anneeActuelle) {
        $erreurs[] = "La nouvelle année doit être différente de l'année actuelle ({$anneeActuelle}).";
    }
    foreach ($dates as $num => $d) {
        if (empty($d['debut']) || empty($d['fin'])) {
            $erreurs[] = "Les dates du Contrôle {$num} sont obligatoires.";
        } elseif ($d['debut'] >= $d['fin']) {
            $erreurs[] = "La date de début du Contrôle {$num} doit être avant la date de fin.";
        }
    }

    // Vérifier que la nouvelle année n'existe pas déjà
    if (empty($erreurs)) {
        $checkAnnee = $pdo->prepare("SELECT COUNT(*) FROM controles WHERE annee_scolaire=:a");
        $checkAnnee->execute([':a' => $anneeNouvelle]);
        if ($checkAnnee->fetchColumn() > 0) {
            $erreurs[] = "L'année scolaire {$anneeNouvelle} existe déjà dans le système.";
        }
    }

    if (empty($erreurs)) {
        try {
            $pdo->beginTransaction();

            // ==========================================================
            // ÉTAPE 1 : Récupérer la liste des classes avec leur niveau
            // ==========================================================
            $toutesClasses = $pdo->query("
                SELECT id, nom, niveau FROM classes
                ORDER BY FIELD(niveau,'7èm','8èm','9èm','10èm','NS3','NS4'), nom
            ")->fetchAll();

            // Construire map : niveau → classe(s) suivante(s)
            // Pour chaque niveau, trouver le niveau suivant dans l'ordre
            $mapProgressionNiveau = [];
            for ($i = 0; $i < count($niveauxOrdre) - 1; $i++) {
                $niveauActuelLoop  = $niveauxOrdre[$i];
                $niveauSuivant     = $niveauxOrdre[$i + 1];
                $mapProgressionNiveau[$niveauActuelLoop] = $niveauSuivant;
            }
            // NS4 → diplôme
            $mapProgressionNiveau['NS4'] = 'DIPLOME';

            // Construire map niveau → première classe de ce niveau
            // (Si plusieurs classes d'un même niveau ex: 8èm A et 8èm B,
            //  on prend la première par ordre alphabétique)
            $classeParNiveau = [];
            foreach ($toutesClasses as $cl) {
                if (!isset($classeParNiveau[$cl['niveau']])) {
                    $classeParNiveau[$cl['niveau']] = $cl['id'];
                }
            }

            // ==========================================================
            // ÉTAPE 2 : Traiter chaque élève actif
            // ==========================================================
            $nbDiplomes  = 0;
            $nbMontes    = 0;
            $nbReprend   = 0;
            $nbSansDecision = 0;

            $eleves = $pdo->query("
                SELECT e.id, e.nom, e.prenom, e.classe_id, e.matricule,
                       c.niveau AS classe_niveau, c.nom AS classe_nom,
                       mf.decision
                FROM eleves e
                JOIN classes c ON e.classe_id = c.id
                LEFT JOIN moyennes_finales mf ON mf.eleve_id = e.id
                    AND mf.annee_scolaire = '" . ANNEE_SCOLAIRE . "'
                WHERE e.status = 'actif'
            ")->fetchAll();

            $updateEleve = $pdo->prepare("
                UPDATE eleves SET classe_id=:cid, annee_scolaire=:annee WHERE id=:id
            ");
            $diplomeEleve = $pdo->prepare("
                UPDATE eleves SET status='diplome', annee_scolaire=:annee WHERE id=:id
            ");
            $repriseEleve = $pdo->prepare("
                UPDATE eleves SET annee_scolaire=:annee WHERE id=:id
            ");

            foreach ($eleves as $eleve) {
                $niveauActuelEleve = $eleve['classe_niveau'];
                $decision          = $eleve['decision'] ?? null;

                if ($decision === 'Admis') {
                    $niveauSuivant = $mapProgressionNiveau[$niveauActuelEleve] ?? null;

                    if ($niveauSuivant === 'DIPLOME') {
                        // Diplômer l'élève
                        $diplomeEleve->execute([':annee' => $anneeActuelle, ':id' => $eleve['id']]);
                        $nbDiplomes++;
                    } elseif ($niveauSuivant && isset($classeParNiveau[$niveauSuivant])) {
                        // Monter à la classe supérieure
                        $updateEleve->execute([
                            ':cid'   => $classeParNiveau[$niveauSuivant],
                            ':annee' => $anneeNouvelle,
                            ':id'    => $eleve['id'],
                        ]);
                        $nbMontes++;
                    } else {
                        // Pas de classe suivante définie → garder même classe
                        $repriseEleve->execute([':annee' => $anneeNouvelle, ':id' => $eleve['id']]);
                        $journal[] = "⚠️ Pas de classe suivante trouvée pour {$eleve['prenom']} {$eleve['nom']} ({$eleve['classe_nom']}) — gardé dans la même classe.";
                    }

                } elseif ($decision === 'Reprend Classe' || $decision === 'Repran Klas') {
                    // Reste dans la même classe, nouvelle année
                    $repriseEleve->execute([':annee' => $anneeNouvelle, ':id' => $eleve['id']]);
                    $nbReprend++;

                } else {
                    // Pas de décision → garder dans la même classe (pas encore de résultat K3)
                    $repriseEleve->execute([':annee' => $anneeNouvelle, ':id' => $eleve['id']]);
                    $nbSansDecision++;
                }
            }

            $journal[] = "✅ {$nbDiplomes} élève(s) diplômé(s) (NS4 → Diplôme)";
            $journal[] = "✅ {$nbMontes} élève(s) passé(s) en classe supérieure";
            $journal[] = "✅ {$nbReprend} élève(s) redoublant(s) (restent dans leur classe)";
            if ($nbSansDecision > 0) {
                $journal[] = "⚠️  {$nbSansDecision} élève(s) sans décision finale — gardés dans leur classe";
            }

            // ==========================================================
            // ÉTAPE 3 : Créer les 3 nouveaux contrôles
            // ==========================================================
            $nomsControles = [
                1 => ['nom' => 'Kontwòl 1', 'periode' => 'Septanm - Oktòb'],
                2 => ['nom' => 'Kontwòl 2', 'periode' => 'Janvye - Mas'],
                3 => ['nom' => 'Kontwòl 3', 'periode' => 'Avril - Jen'],
            ];

            $insertControle = $pdo->prepare("
                INSERT INTO controles (nom, numero, periode, date_debut, date_fin, annee_scolaire)
                VALUES (:nom, :num, :periode, :debut, :fin, :annee)
            ");

            foreach ($dates as $num => $d) {
                $insertControle->execute([
                    ':nom'     => $nomsControles[$num]['nom'],
                    ':num'     => $num,
                    ':periode' => $nomsControles[$num]['periode'],
                    ':debut'   => $d['debut'],
                    ':fin'     => $d['fin'],
                    ':annee'   => $anneeNouvelle,
                ]);
            }
            $journal[] = "✅ 3 contrôles créés pour l'année {$anneeNouvelle}";

            // Créer les périodes de saisie (toutes FERMÉES par défaut) pour la nouvelle année
            $nouveauxControles = $pdo->prepare("SELECT id FROM controles WHERE annee_scolaire=:annee");
            $nouveauxControles->execute([':annee' => $anneeNouvelle]);
            $idsControles = $nouveauxControles->fetchAll(PDO::FETCH_COLUMN);
            $toutesClasses = $pdo->query("SELECT id FROM classes")->fetchAll(PDO::FETCH_COLUMN);
            $insertPeriode = $pdo->prepare("
                INSERT IGNORE INTO periodes_saisie (classe_id, controle_id, statut)
                VALUES (:cid, :ctrl, 'ferme')
            ");
            foreach ($idsControles as $ctrlId) {
                foreach ($toutesClasses as $clsId) {
                    $insertPeriode->execute([':cid'=>$clsId, ':ctrl'=>$ctrlId]);
                }
            }
            $journal[] = "✅ Périodes de saisie créées (toutes fermées — à ouvrir manuellement)";

            // ==========================================================
            // ÉTAPE 4 : Copier les assignations professeurs
            // ==========================================================
            $pdo->prepare("
                INSERT INTO professeur_classes (professeur_id, classe_id, matiere_id, annee_scolaire)
                SELECT professeur_id, classe_id, matiere_id, :nouvelle
                FROM professeur_classes
                WHERE annee_scolaire = :actuelle
            ")->execute([':nouvelle' => $anneeNouvelle, ':actuelle' => $anneeActuelle]);

            $nbAssign = $statsAvant['nb_assignations'];
            $journal[] = "✅ {$nbAssign} assignation(s) professeurs copiée(s)";

            // Copier aussi les barèmes des matières par classe
            $pdo->prepare("
                INSERT IGNORE INTO classe_matieres (classe_id, matiere_id, bareme, annee_scolaire)
                SELECT classe_id, matiere_id, bareme, :nouvelle
                FROM classe_matieres
                WHERE annee_scolaire = :actuelle
            ")->execute([':nouvelle' => $anneeNouvelle, ':actuelle' => $anneeActuelle]);

            $nbBaremes = $pdo->query("SELECT COUNT(*) FROM classe_matieres WHERE annee_scolaire='$anneeNouvelle'")->fetchColumn();
            $journal[] = "✅ {$nbBaremes} barème(s) de matières par classe copiés";

            // ==========================================================
            // ÉTAPE 5 : Mettre à jour ANNEE_SCOLAIRE dans database.php
            // ==========================================================
            $dbPath    = __DIR__ . '/../config/database.php';
            $contenu   = file_get_contents($dbPath);
            $nouveau   = preg_replace(
                "/define\('ANNEE_SCOLAIRE',\s*'[^']+'\)/",
                "define('ANNEE_SCOLAIRE', '{$anneeNouvelle}')",
                $contenu
            );

            if ($nouveau !== $contenu) {
                file_put_contents($dbPath, $nouveau);
                $journal[] = "✅ Fichier config/database.php mis à jour → ANNEE_SCOLAIRE = '{$anneeNouvelle}'";
            } else {
                $journal[] = "⚠️  Impossible de mettre à jour database.php automatiquement — faites-le manuellement.";
            }

            // ==========================================================
            // ÉTAPE 6 : Enregistrer dans les logs
            // ==========================================================
            logAction($pdo, 'NOUVELLE ANNÉE SCOLAIRE', 'systeme', 0,
                "Passage de {$anneeActuelle} vers {$anneeNouvelle} — " .
                "{$nbDiplomes} diplômés, {$nbMontes} montés, {$nbReprend} redoublants"
            );

            $pdo->commit();
            $execution = true;
            $journal[] = "🎉 Passage à l'année {$anneeNouvelle} terminé avec succès !";

        } catch (PDOException $e) {
            $pdo->rollBack();
            $erreurs[] = "Erreur critique : " . $e->getMessage();
            $erreurs[] = "Toutes les modifications ont été annulées.";
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<h1 class="page-title">
    <i class="fas fa-calendar-plus"></i> Nouvelle Année Scolaire
</h1>

<?php afficherMessage(); ?>

<!-- Avertissement important -->
<?php if (!$execution): ?>
<div class="alert alert-warning d-flex gap-3 align-items-start mb-4">
    <i class="fas fa-exclamation-triangle fa-lg mt-1"></i>
    <div>
        <strong>Action irréversible !</strong><br>
        Cette opération va modifier les classes de tous les élèves, créer les nouveaux contrôles
        et mettre à jour le système. Assurez-vous d'avoir <strong>généré tous les bulletins du
        3ème contrôle</strong> avant de continuer.
    </div>
</div>
<?php endif; ?>

<?php if (!empty($erreurs)): ?>
    <div class="alert alert-danger mb-4">
        <strong><i class="fas fa-times-circle me-2"></i>Erreurs détectées :</strong>
        <ul class="mb-0 mt-2">
            <?php foreach ($erreurs as $err): ?>
                <li><?= h($err) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if ($execution): ?>
<!-- ===== RÉSULTAT DE L'EXÉCUTION ===== -->
<div class="card shadow-sm mb-4 border-success">
    <div class="card-header bg-success text-white">
        <i class="fas fa-check-circle me-2"></i>
        Passage à l'année <strong><?= h($anneeNouvelle) ?></strong> effectué avec succès !
    </div>
    <div class="card-body">
        <h6 class="text-muted mb-3">Journal d'exécution :</h6>
        <ul class="list-unstyled mb-0">
            <?php foreach ($journal as $ligne): ?>
                <li class="py-1 border-bottom d-flex align-items-center gap-2">
                    <span style="font-size:1.1rem"><?= mb_substr($ligne, 0, 2) ?></span>
                    <span><?= h(mb_substr($ligne, 2)) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <div class="card-footer bg-light">
        <a href="index.php" class="btn btn-comep">
            <i class="fas fa-tachometer-alt me-1"></i> Retour au tableau de bord
        </a>
        <a href="eleves.php" class="btn btn-outline-primary ms-2">
            <i class="fas fa-user-graduate me-1"></i> Voir les élèves
        </a>
    </div>
</div>

<?php else: ?>

<div class="row g-4">

    <!-- ===== COLONNE GAUCHE : Stats + Progression ===== -->
    <div class="col-lg-5">

        <!-- Résumé année actuelle -->
        <div class="card shadow-sm mb-4">
            <div class="card-header">
                <i class="fas fa-chart-pie me-2 text-primary"></i>
                Bilan de l'année <strong><?= ANNEE_SCOLAIRE ?></strong>
            </div>
            <div class="card-body">
                <div class="row g-2 text-center mb-3">
                    <div class="col-6">
                        <div class="p-2 rounded" style="background:#e8f4fd">
                            <div class="fs-3 fw-bold text-primary"><?= h($statsAvant['nb_eleves_actifs']) ?></div>
                            <small class="text-muted">Élèves actifs</small>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="p-2 rounded" style="background:#e8fdf4">
                            <div class="fs-3 fw-bold text-success"><?= h($statsAvant['nb_admis']) ?></div>
                            <small class="text-muted">Admis</small>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="p-2 rounded" style="background:#fdf0e8">
                            <div class="fs-3 fw-bold text-warning"><?= h($statsAvant['nb_reprend']) ?></div>
                            <small class="text-muted">Redoublants</small>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="p-2 rounded" style="background:<?= $statsAvant['nb_sans_decision'] > 0 ? '#fde8e8' : '#e8fdf4' ?>">
                            <div class="fs-3 fw-bold <?= $statsAvant['nb_sans_decision'] > 0 ? 'text-danger' : 'text-success' ?>">
                                <?= h($statsAvant['nb_sans_decision']) ?>
                            </div>
                            <small class="text-muted">Sans décision K3</small>
                        </div>
                    </div>
                </div>

                <?php if ($statsAvant['nb_sans_decision'] > 0): ?>
                <div class="alert alert-warning py-2 mb-0">
                    <i class="fas fa-exclamation-triangle me-1"></i>
                    <small><strong><?= h($statsAvant['nb_sans_decision']) ?> élève(s)</strong> n'ont pas encore
                    de bulletin K3. Ils seront gardés dans leur classe actuelle.</small>
                </div>
                <?php else: ?>
                <div class="alert alert-success py-2 mb-0">
                    <i class="fas fa-check-circle me-1"></i>
                    <small>Tous les élèves ont une décision finale. Prêt pour le passage !</small>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Progression des classes -->
        <div class="card shadow-sm">
            <div class="card-header">
                <i class="fas fa-arrow-right me-2 text-primary"></i>
                Progression des classes
            </div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Classe actuelle</th>
                            <th class="text-center">Élèves</th>
                            <th class="text-center">Admis</th>
                            <th>Vers</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $niveauxVus = [];
                        foreach ($progressionClasses as $cl):
                            $niveauCl = $cl['niveau'];
                            $vers = '';
                            if (isset($mapProgressionNiveau[$niveauCl])) {
                                $vers = $mapProgressionNiveau[$niveauCl] === 'DIPLOME'
                                    ? '🎓 Diplôme'
                                    : $mapProgressionNiveau[$niveauCl];
                            }
                        ?>
                        <tr>
                            <td class="fw-500"><?= h($cl['nom']) ?></td>
                            <td class="text-center"><?= h($cl['nb_eleves']) ?></td>
                            <td class="text-center">
                                <span class="badge bg-success"><?= h($cl['nb_admis']) ?></span>
                            </td>
                            <td>
                                <span class="text-primary fw-500"><?= h($vers) ?></span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ===== COLONNE DROITE : Formulaire ===== -->
    <div class="col-lg-7">
        <div class="card shadow-sm">
            <div class="card-header d-flex align-items-center gap-2"
                 style="background:linear-gradient(135deg,#1a3a5c,#0d6efd);color:white;border-radius:14px 14px 0 0">
                <i class="fas fa-rocket fa-lg"></i>
                <span class="fw-bold fs-5">Lancer la nouvelle année scolaire</span>
            </div>
            <div class="card-body">
                <form method="POST" action="nouvelle_annee.php" id="formNouvelleAnnee">

                    <!-- Année -->
                    <div class="mb-4">
                        <label class="form-label fw-bold">
                            <i class="fas fa-calendar me-1 text-primary"></i>
                            Nouvelle année scolaire <span class="text-danger">*</span>
                        </label>
                        <input type="text" name="annee_nouvelle" class="form-control form-control-lg"
                               placeholder="Ex: 2025-2026"
                               pattern="\d{4}-\d{4}"
                               value="<?= h($anneeNouvelle) ?>"
                               required>
                        <div class="form-text">Année actuelle : <strong><?= ANNEE_SCOLAIRE ?></strong></div>
                    </div>

                    <hr class="my-4">

                    <!-- Dates des contrôles -->
                    <h6 class="fw-bold mb-3">
                        <i class="fas fa-calendar-alt me-1 text-primary"></i>
                        Dates des 3 contrôles
                    </h6>

                    <!-- Contrôle 1 -->
                    <div class="card bg-light border-0 mb-3 p-3">
                        <div class="fw-600 mb-2">
                            <span class="badge bg-primary me-1">K1</span>
                            Kontwòl 1 — Septanm / Oktòb
                        </div>
                        <div class="row g-2">
                            <div class="col-6">
                                <label class="form-label small">Date de début</label>
                                <input type="date" name="k1_debut" class="form-control form-control-sm" required
                                       value="<?= h($_POST['k1_debut'] ?? '') ?>">
                            </div>
                            <div class="col-6">
                                <label class="form-label small">Date de fin</label>
                                <input type="date" name="k1_fin" class="form-control form-control-sm" required
                                       value="<?= h($_POST['k1_fin'] ?? '') ?>">
                            </div>
                        </div>
                    </div>

                    <!-- Contrôle 2 -->
                    <div class="card bg-light border-0 mb-3 p-3">
                        <div class="fw-600 mb-2">
                            <span class="badge bg-info text-dark me-1">K2</span>
                            Kontwòl 2 — Janvye / Mas
                        </div>
                        <div class="row g-2">
                            <div class="col-6">
                                <label class="form-label small">Date de début</label>
                                <input type="date" name="k2_debut" class="form-control form-control-sm" required
                                       value="<?= h($_POST['k2_debut'] ?? '') ?>">
                            </div>
                            <div class="col-6">
                                <label class="form-label small">Date de fin</label>
                                <input type="date" name="k2_fin" class="form-control form-control-sm" required
                                       value="<?= h($_POST['k2_fin'] ?? '') ?>">
                            </div>
                        </div>
                    </div>

                    <!-- Contrôle 3 -->
                    <div class="card bg-light border-0 mb-4 p-3">
                        <div class="fw-600 mb-2">
                            <span class="badge bg-success me-1">K3</span>
                            Kontwòl 3 — Avril / Jen
                        </div>
                        <div class="row g-2">
                            <div class="col-6">
                                <label class="form-label small">Date de début</label>
                                <input type="date" name="k3_debut" class="form-control form-control-sm" required
                                       value="<?= h($_POST['k3_debut'] ?? '') ?>">
                            </div>
                            <div class="col-6">
                                <label class="form-label small">Date de fin</label>
                                <input type="date" name="k3_fin" class="form-control form-control-sm" required
                                       value="<?= h($_POST['k3_fin'] ?? '') ?>">
                            </div>
                        </div>
                    </div>

                    <hr class="mb-4">

                    <!-- Récapitulatif de ce qui va se passer -->
                    <div class="alert alert-info mb-4">
                        <strong><i class="fas fa-info-circle me-1"></i>Ce qui va se passer :</strong>
                        <ol class="mb-0 mt-2 small">
                            <li>Les élèves <strong>Admis</strong> passeront à la classe supérieure</li>
                            <li>Les élèves de <strong>NS4 Admis</strong> seront marqués comme diplômés</li>
                            <li>Les <strong>redoublants</strong> resteront dans leur classe</li>
                            <li>Les <strong>3 contrôles</strong> seront créés avec vos dates</li>
                            <li>Les <strong>assignations</strong> professeurs seront copiées</li>
                            <li>Le fichier <strong>database.php</strong> sera mis à jour</li>
                        </ol>
                    </div>

                    <!-- Bouton -->
                    <div class="d-grid">
                        <button type="submit" name="lancer" class="btn btn-lg btn-danger fw-bold"
                                onclick="return confirmerPassage()">
                            <i class="fas fa-rocket me-2"></i>
                            LANCER LA NOUVELLE ANNÉE SCOLAIRE
                        </button>
                    </div>

                </form>
            </div>
        </div>
    </div>
</div>

<?php endif; // fin !$execution ?>

<?php
$scriptPage = <<<'JS'
<script>
function confirmerPassage() {
    const annee = document.querySelector('[name="annee_nouvelle"]').value;
    if (!annee.match(/^\d{4}-\d{4}$/)) {
        alert('Format d\'année invalide. Utilisez : 2025-2026');
        return false;
    }
    return confirm(
        '⚠️ ATTENTION — Action irréversible !\n\n' +
        'Vous allez lancer le passage à l\'année scolaire ' + annee + '.\n\n' +
        '• Les élèves admis monteront en classe supérieure\n' +
        '• Les élèves de NS4 admis seront diplômés\n' +
        '• Les redoublants resteront dans leur classe\n' +
        '• Les 3 contrôles seront créés\n\n' +
        'Êtes-vous sûr(e) de vouloir continuer ?'
    );
}

// Auto-remplir les années suggérées
document.querySelector('[name="annee_nouvelle"]')?.addEventListener('input', function() {
    const val = this.value;
    if (val.match(/^\d{4}-\d{4}$/)) {
        const anneeDebut = parseInt(val.split('-')[0]);
        const anneeFin   = parseInt(val.split('-')[1]);

        // Pré-remplir les dates des contrôles
        const k1d = document.querySelector('[name="k1_debut"]');
        const k1f = document.querySelector('[name="k1_fin"]');
        const k2d = document.querySelector('[name="k2_debut"]');
        const k2f = document.querySelector('[name="k2_fin"]');
        const k3d = document.querySelector('[name="k3_debut"]');
        const k3f = document.querySelector('[name="k3_fin"]');

        if (!k1d.value) k1d.value = anneeDebut + '-09-01';
        if (!k1f.value) k1f.value = anneeDebut + '-10-31';
        if (!k2d.value) k2d.value = anneeFin   + '-01-01';
        if (!k2f.value) k2f.value = anneeFin   + '-03-31';
        if (!k3d.value) k3d.value = anneeFin   + '-04-01';
        if (!k3f.value) k3f.value = anneeFin   + '-06-30';
    }
});
</script>
JS;
?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
