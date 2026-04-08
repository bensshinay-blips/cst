<?php
/**
 * econmat/acces.php - Gestion des accès bulletins
 */

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';

requireEconmat();

$titrePage = 'Gestion des Accès Bulletins';
$pdo       = getDB();
$econmatId = $_SESSION['utilisateur_id'];

$classeId = (int)($_GET['classe_id'] ?? 0);
$action   = $_GET['action']          ?? '';
$eleveId  = (int)($_GET['eleve_id']  ?? 0);

// ===== AUTORISER OU BLOQUER UN SEUL ÉLÈVE =====
if (in_array($action, ['autoriser', 'bloquer']) && $eleveId > 0) {
    try {
        // Créer la ligne si elle n'existe pas encore
        $pdo->prepare("
            INSERT IGNORE INTO acces_bulletins (eleve_id, annee_scolaire, acces)
            VALUES (:eid, :annee, 'bloque')
        ")->execute([':eid' => $eleveId, ':annee' => ANNEE_SCOLAIRE]);

        if ($action === 'autoriser') {
            $pdo->prepare("
                UPDATE acces_bulletins
                SET acces        = 'autorise',
                    autorise_par = :uid,
                    autorise_le  = NOW(),
                    bloque_par   = NULL,
                    bloque_le    = NULL
                WHERE eleve_id      = :eid
                  AND annee_scolaire = :annee
            ")->execute([
                ':uid'   => $econmatId,
                ':eid'   => $eleveId,
                ':annee' => ANNEE_SCOLAIRE,
            ]);
            setMessage(' Accès autorisé pour cet élève.');
        } else {
            $pdo->prepare("
                UPDATE acces_bulletins
                SET acces        = 'bloque',
                    bloque_par   = :uid,
                    bloque_le    = NOW(),
                    autorise_par = NULL,
                    autorise_le  = NULL
                WHERE eleve_id      = :eid
                  AND annee_scolaire = :annee
            ")->execute([
                ':uid'   => $econmatId,
                ':eid'   => $eleveId,
                ':annee' => ANNEE_SCOLAIRE,
            ]);
            setMessage('Accès bloqué pour cet élève.');
        }

        logAction($pdo, strtoupper($action) . ' ACCES', 'acces_bulletins', $eleveId, '');

    } catch (PDOException $e) {
        setMessage('Erreur : ' . $e->getMessage(), 'erreur');
    }

    header('Location: acces.php?classe_id=' . $classeId);
    exit();
}

// ===== AUTORISER OU BLOQUER TOUTE UNE CLASSE =====
if (in_array($action, ['autoriser_classe', 'bloquer_classe']) && $classeId > 0) {
    try {
        // Récupérer tous les élèves actifs de la classe
        $stmt = $pdo->prepare(
            "SELECT id FROM eleves WHERE classe_id = :cid AND status = 'actif'"
        );
        $stmt->execute([':cid' => $classeId]);
        $idsEleves = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($idsEleves as $eid) {
            $pdo->prepare("
                INSERT IGNORE INTO acces_bulletins (eleve_id, annee_scolaire, acces)
                VALUES (:eid, :annee, 'bloque')
            ")->execute([':eid' => $eid, ':annee' => ANNEE_SCOLAIRE]);
        }

        if (str_contains($action, 'autoriser')) {
            $pdo->prepare("
                UPDATE acces_bulletins ab
                JOIN eleves e ON ab.eleve_id = e.id
                SET ab.acces        = 'autorise',
                    ab.autorise_par = :uid,
                    ab.autorise_le  = NOW(),
                    ab.bloque_par   = NULL,
                    ab.bloque_le    = NULL
                WHERE e.classe_id       = :cid
                  AND e.status          = 'actif'
                  AND ab.annee_scolaire = :annee
            ")->execute([
                ':uid'   => $econmatId,
                ':cid'   => $classeId,
                ':annee' => ANNEE_SCOLAIRE,
            ]);
            setMessage('Accès autorisé pour les ' . count($idsEleves) . ' élèves.');
        } else {
            $pdo->prepare("
                UPDATE acces_bulletins ab
                JOIN eleves e ON ab.eleve_id = e.id
                SET ab.acces        = 'bloque',
                    ab.bloque_par   = :uid,
                    ab.bloque_le    = NOW(),
                    ab.autorise_par = NULL,
                    ab.autorise_le  = NULL
                WHERE e.classe_id       = :cid
                  AND e.status          = 'actif'
                  AND ab.annee_scolaire = :annee
            ")->execute([
                ':uid'   => $econmatId,
                ':cid'   => $classeId,
                ':annee' => ANNEE_SCOLAIRE,
            ]);
            setMessage('Accès bloqué pour les ' . count($idsEleves) . ' élèves.');
        }

        logAction($pdo, strtoupper($action), 'acces_bulletins', 0,
                  'Classe:' . $classeId);

    } catch (PDOException $e) {
        setMessage('Erreur : ' . $e->getMessage(), 'erreur');
    }

    header('Location: acces.php?classe_id=' . $classeId);
    exit();
}

// ===== CHARGEMENT DES DONNÉES =====

// Liste de toutes les classes
$classes = $pdo->query("
    SELECT id, nom, niveau
    FROM classes
    ORDER BY nom
")->fetchAll();

// Données de la classe sélectionnée
$eleves     = [];
$classeInfo = null;

if ($classeId > 0) {
    // Infos de la classe
    $stmt = $pdo->prepare("SELECT * FROM classes WHERE id = :id");
    $stmt->execute([':id' => $classeId]);
    $classeInfo = $stmt->fetch();

    if ($classeInfo) {
        // S'assurer que chaque élève a une ligne dans acces_bulletins
        $stmt = $pdo->prepare(
            "SELECT id FROM eleves WHERE classe_id = :cid AND status = 'actif'"
        );
        $stmt->execute([':cid' => $classeId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $eid) {
            $pdo->prepare("
                INSERT IGNORE INTO acces_bulletins (eleve_id, annee_scolaire, acces)
                VALUES (:eid, :annee, 'bloque')
            ")->execute([':eid' => $eid, ':annee' => ANNEE_SCOLAIRE]);
        }

        // Charger les élèves avec leur statut d'accès
        $stmt = $pdo->prepare("
            SELECT e.id,
                   e.nom,
                   e.prenom,
                   e.matricule,
                   COALESCE(ab.acces, 'bloque') AS acces,
                   ab.autorise_le,
                   ua.prenom AS autorise_par_prenom,
                   ua.nom    AS autorise_par_nom
            FROM eleves e
            LEFT JOIN acces_bulletins ab
                ON ab.eleve_id      = e.id
               AND ab.annee_scolaire = :annee
            LEFT JOIN utilisateurs ua ON ab.autorise_par = ua.id
            WHERE e.classe_id = :cid
              AND e.status    = 'actif'
            ORDER BY e.nom, e.prenom
        ");
        $stmt->execute([':cid' => $classeId, ':annee' => ANNEE_SCOLAIRE]);
        $eleves = $stmt->fetchAll();
    }
}

$nbAutorise = count(array_filter($eleves, fn($e) => $e['acces'] === 'autorise'));
$nbBloque   = count($eleves) - $nbAutorise;

require_once dirname(__DIR__) . '/includes/header.php';
?>

<h1 class="page-title">
Accès aux Bulletins
</h1>

<?php afficherMessage(); ?>

<div class="row g-4">

    <!-- Liste des classes (colonne gauche) -->
    <div class="col-lg-3">
        <div class="card shadow-sm">
            <div class="card-header">
                Classes
            </div>
            <div class="list-group list-group-flush">
                <?php foreach ($classes as $cl): ?>
                <?php
                // Statistiques rapides par classe
                $ms = $pdo->prepare("
                    SELECT COUNT(*)                                                  AS total,
                           SUM(CASE WHEN ab.acces='autorise' THEN 1 ELSE 0 END)    AS ok
                    FROM eleves e
                    LEFT JOIN acces_bulletins ab
                        ON ab.eleve_id      = e.id
                       AND ab.annee_scolaire = :annee
                    WHERE e.classe_id = :cid AND e.status = 'actif'
                ");
                $ms->execute([':cid' => $cl['id'], ':annee' => ANNEE_SCOLAIRE]);
                $msData = $ms->fetch();
                $tot    = (int)$msData['total'];
                $ok     = (int)$msData['ok'];

                if ($tot === 0) {
                    $badgeClass = 'bg-secondary';
                } elseif ($ok === $tot) {
                    $badgeClass = 'bg-success';
                } elseif ($ok > 0) {
                    $badgeClass = 'bg-warning text-dark';
                } else {
                    $badgeClass = 'bg-danger';
                }
                ?>
                <a href="acces.php?classe_id=<?= (int)$cl['id'] ?>"
                   class="list-group-item list-group-item-action
                          d-flex justify-content-between align-items-center
                          <?= $classeId === (int)$cl['id'] ? 'active' : '' ?>">
                    <div>
                        <div class="fw-500"><?= h($cl['nom']) ?></div>
                        <small class="opacity-75"><?= h($cl['niveau']) ?></small>
                    </div>
                    <span class="badge <?= $badgeClass ?>">
                        <?= $ok ?>/<?= $tot ?>
                    </span>
                </a>
                <?php endforeach; ?>

                <?php if (empty($classes)): ?>
                <div class="list-group-item text-muted small text-center py-3">
                    Aucune classe trouvée.
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Contenu principal (colonne droite) -->
    <div class="col-lg-9">

        <?php if ($classeId > 0 && $classeInfo): ?>

        <!-- Boutons globaux -->
        <div class="card shadow-sm mb-3">
            <div class="card-body d-flex align-items-center gap-3 flex-wrap">
                <div>
                    <span class="fw-bold fs-5"><?= h($classeInfo['nom']) ?></span>
                    <small class="text-muted ms-2"><?= h($classeInfo['niveau']) ?></small>
                    <span class="ms-3">
                        <span class="badge bg-success"><?= $nbAutorise ?> autorisé(s)</span>
                        <span class="badge bg-danger ms-1"><?= $nbBloque ?> bloqué(s)</span>
                    </span>
                </div>
                <div class="ms-auto d-flex gap-2 flex-wrap">
                    <a href="acces.php?action=autoriser_classe&classe_id=<?= $classeId ?>"
                       class="btn btn-success"
                       onclick="return confirm('Autoriser TOUS les élèves de <?= h(addslashes($classeInfo['nom'])) ?> ?')">
                        Autoriser tous
                    </a>
                    <a href="acces.php?action=bloquer_classe&classe_id=<?= $classeId ?>"
                       class="btn btn-danger"
                       onclick="return confirm('Bloquer TOUS les élèves de <?= h(addslashes($classeInfo['nom'])) ?> ?')">
                      Bloquer tous
                    </a>
                </div>
            </div>
        </div>

        <!-- Tableau des élèves -->
        <div class="card shadow-sm">
            <div class="card-header">
                <?= count($eleves) ?> élève(s) — <?= h($classeInfo['nom']) ?>
            </div>

            <?php if (empty($eleves)): ?>
            <div class="card-body text-center text-muted py-4">
                <i class="fas fa-info-circle me-1"></i>
                Aucun élève actif dans cette classe.
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-comep table-hover mb-0 align-middle">
                    <thead>
                        <tr>
                            <th>Matricule</th>
                            <th>Nom & Prénom</th>
                            <th class="text-center">Statut</th>
                            <th>Autorisé le</th>
                            <th class="text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($eleves as $e): ?>
                        <tr class="<?= $e['acces'] === 'autorise' ? 'table-success' : '' ?>">
                            <td><code><?= h($e['matricule']) ?></code></td>
                            <td class="fw-500">
                                <?= h($e['prenom']) ?> <?= h($e['nom']) ?>
                            </td>
                            <td class="text-center">
                                <?php if ($e['acces'] === 'autorise'): ?>
                                <span class="badge bg-success px-3 py-2">
                                    AUTORISÉ
                                </span>
                                <?php else: ?>
                                <span class="badge bg-danger px-3 py-2">
                                 BLOQUÉ
                                </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($e['autorise_le']): ?>
                                <small class="text-success">
                                    <i class="fas fa-check me-1"></i>
                                    <?= date('d/m/Y H:i', strtotime($e['autorise_le'])) ?>
                                    <?php if ($e['autorise_par_nom']): ?>
                                    <br>
                                    <span class="text-muted">
                                        par <?= h($e['autorise_par_prenom']) ?>
                                        <?= h($e['autorise_par_nom']) ?>
                                    </span>
                                    <?php endif; ?>
                                </small>
                                <?php else: ?>
                                <span class="text-muted small">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php if ($e['acces'] === 'bloque'): ?>
                                <a href="acces.php?action=autoriser&eleve_id=<?= (int)$e['id'] ?>&classe_id=<?= $classeId ?>"
                                   class="btn btn-success btn-sm">
                                     Autoriser
                                </a>
                                <?php else: ?>
                                <a href="acces.php?action=bloquer&eleve_id=<?= (int)$e['id'] ?>&classe_id=<?= $classeId ?>"
                                   class="btn btn-danger btn-sm"
                                   onclick="return confirm('Bloquer l\'accès de <?= h(addslashes($e['prenom'] . ' ' . $e['nom'])) ?> ?')">
                                   Bloquer
                                </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <?php else: ?>
        <!-- Aucune classe sélectionnée -->
        <div class="card shadow-sm">
            <div class="card-body text-center py-5 text-muted">
                <i class="fas fa-hand-point-left fa-3x mb-3 opacity-25"></i>
                <p class="fs-5">
                    Sélectionnez une classe dans la liste à gauche.
                </p>
            </div>
        </div>
        <?php endif; ?>

    </div><!-- col-lg-9 -->
</div><!-- row -->

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>






