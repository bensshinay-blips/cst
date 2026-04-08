<?php
/**
 * admin/import.php - Import Excel pour élèves et professeurs
 *
 * INSTALLATION SimpleXLSX (une seule fois) :
 * 1. Allez sur : https://github.com/shuchkin/simplexlsx
 * 2. Téléchargez le fichier : src/SimpleXLSX.php
 * 3. Placez-le dans : includes/SimpleXLSX.php
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
requireAdmin();

$titrePage = 'Import Excel';
$pdo = getDB();

// Vérifier si SimpleXLSX est disponible
$simplexlsxPath = __DIR__ . '/../includes/SimpleXLSX.php';
$librairieDispo = file_exists($simplexlsxPath);
if ($librairieDispo) {
    require_once $simplexlsxPath;
}

$type      = $_GET['type'] ?? 'eleves';
$resultats = [];
$erreurs   = [];
$succes    = 0;
$echecs    = 0;

// ===== TRAITEMENT DE L'IMPORT =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['fichier_excel'])) {

    if (!$librairieDispo) {
        $erreurs[] = "La bibliothèque SimpleXLSX n'est pas installée. Voir les instructions ci-dessous.";
    } else {
        $fichier = $_FILES['fichier_excel'];
        $type    = $_POST['type'] ?? 'eleves';

        // Vérifier erreur upload
        if ($fichier['error'] !== UPLOAD_ERR_OK) {
            $erreurs[] = "Erreur lors de l'upload. Code : " . $fichier['error'];

        } elseif ($fichier['size'] > 5 * 1024 * 1024) {
            $erreurs[] = "Le fichier est trop grand (max 5 MB).";

        } else {
            $ext = strtolower(pathinfo($fichier['name'], PATHINFO_EXTENSION));

            if ($ext !== 'xlsx') {
                $erreurs[] = "Seuls les fichiers .xlsx sont acceptés. Vous avez envoyé un fichier .$ext";

            } else {
                // ===== LIRE LE FICHIER EXCEL =====
                $xlsx = null;

                if (class_exists('\Shuchkin\SimpleXLSX')) {
                    $xlsx = \Shuchkin\SimpleXLSX::parse($fichier['tmp_name']);
                } elseif (class_exists('SimpleXLSX')) {
                    $xlsx = SimpleXLSX::parse($fichier['tmp_name']);
                } else {
                    $erreurs[] = "Classe SimpleXLSX introuvable. Vérifiez que le fichier includes/SimpleXLSX.php est bien en place.";
                }

                if (!$xlsx && empty($erreurs)) {
                    $erreurs[] = "Impossible de lire le fichier Excel. Vérifiez qu'il n'est pas corrompu.";
                }

                if ($xlsx) {
                    $lignes = $xlsx->rows(0); // Première feuille

                    if (empty($lignes)) {
                        $erreurs[] = "Le fichier est vide ou illisible.";
                    } else {
                        // Ignorer les 2 premières lignes (ligne 1 = titre, ligne 2 = en-têtes)
                        $donneesImport = array_slice($lignes, 2);

                        // Supprimer les lignes vides
                        $donneesImport = array_filter($donneesImport, function($ligne) {
                            foreach ($ligne as $cellule) {
                                if (trim((string)$cellule) !== '') return true;
                            }
                            return false;
                        });

                        if (count($donneesImport) === 0) {
                            $erreurs[] = "Aucune donnée trouvée. Avez-vous supprimé la ligne d'exemple ?";

                        } elseif (count($donneesImport) > 500) {
                            $erreurs[] = "Maximum 500 lignes par import. Votre fichier en contient " . count($donneesImport) . ".";

                        } else {
                            try {
                                $pdo->beginTransaction();

                                foreach ($donneesImport as $numLigne => $ligne) {
                                    $numReel = $numLigne + 3;

                                    if ($type === 'eleves') {
                                        $resultat = importerEleve($pdo, array_values($ligne), $numReel);
                                    } else {
                                        $resultat = importerProfesseur($pdo, array_values($ligne), $numReel);
                                    }

                                    if ($resultat['ok']) {
                                        $succes++;
                                        $resultats[] = ['ok' => true,  'msg' => $resultat['msg']];
                                    } else {
                                        $echecs++;
                                        $resultats[] = ['ok' => false, 'msg' => $resultat['msg']];
                                    }
                                }

                                $pdo->commit();
                                logAction($pdo, 'IMPORT EXCEL',
                                          $type === 'eleves' ? 'eleves' : 'utilisateurs',
                                          0, "Import: $succes OK, $echecs erreurs");

                                if ($succes > 0) {
                                    setMessage("{$succes} enregistrement(s) importé(s). {$echecs} erreur(s).",
                                              $echecs > 0 ? 'avert' : 'succes');
                                } else {
                                    setMessage("Aucun enregistrement importé. {$echecs} erreur(s).", 'erreur');
                                }

                            } catch (PDOException $e) {
                                $pdo->rollBack();
                                $erreurs[] = "Erreur base de données : " . $e->getMessage();
                            }
                        }
                    }
                }
            }
        }
    }
}

// ===== FONCTION : IMPORTER UN ÉLÈVE =====
// Colonnes attendues :
// 0=Nom | 1=Prénom | 2=Sexe | 3=Date naissance | 4=Lieu | 5=Adresse | 6=Tél | 7=Niveau | 8=Année | 9=Statut
function importerEleve(PDO $pdo, array $ligne, int $numLigne): array {
    $nom       = trim((string)($ligne[0] ?? ''));
    $prenom    = trim((string)($ligne[1] ?? ''));
    $sexe      = strtoupper(trim((string)($ligne[2] ?? 'M')));
    $dateNais  = trim((string)($ligne[3] ?? ''));
    $lieu      = trim((string)($ligne[4] ?? ''));
    $adresse   = trim((string)($ligne[5] ?? ''));
    $tel       = trim((string)($ligne[6] ?? ''));
    $niveau    = trim((string)($ligne[7] ?? ''));  // C'est le NIVEAU maintenant
    $annee     = trim((string)($ligne[8] ?? ANNEE_SCOLAIRE));
    $statut    = strtolower(trim((string)($ligne[9] ?? 'actif')));

    // Validation
    if (empty($nom))       return ['ok'=>false, 'msg'=>"Ligne {$numLigne} : Nom manquant."];
    if (empty($prenom))    return ['ok'=>false, 'msg'=>"Ligne {$numLigne} : Prénom manquant."];
    if (empty($niveau))    return ['ok'=>false, 'msg'=>"Ligne {$numLigne} : Niveau manquant. Utilisez: 7eme, 8eme, 9eme, NS1, NS2, NS3, NS4"];

    if (!in_array($sexe, ['M','F']))                        $sexe   = 'M';
    if (!in_array($statut, ['actif','inactif','diplome']))  $statut = 'actif';
    if (empty($annee) || !preg_match('/^\d{4}-\d{4}$/', $annee)) {
        $annee = ANNEE_SCOLAIRE;
    }

    // Trouver la classe par NIVEAU (7eme, 8eme, NS1, etc.)
    $stmt = $pdo->prepare("SELECT id FROM classes WHERE niveau = :niveau LIMIT 1");
    $stmt->execute([':niveau' => $niveau]);
    $classe = $stmt->fetch();
    
    if (!$classe) {
        return ['ok'=>false, 'msg'=>"Ligne {$numLigne} : Niveau « {$niveau} » introuvable. Utilisez: 7eme, 8eme, 9eme, NS1, NS2, NS3, NS4"];
    }

    // Générer le matricule automatiquement
    $anneePrefix = substr($annee, 0, 4);
    $stmt = $pdo->prepare("SELECT MAX(CAST(SUBSTRING(matricule, 5) AS UNSIGNED)) FROM eleves WHERE matricule LIKE :prefix");
    $stmt->execute([':prefix' => $anneePrefix . '%']);
    $maxNum    = (int)$stmt->fetchColumn();
    $matricule = $anneePrefix . str_pad($maxNum + 1, 3, '0', STR_PAD_LEFT);

    // Nettoyer la date de naissance
    $dateValide = null;
    if (!empty($dateNais)) {
        $formats = ['Y-m-d', 'd/m/Y', 'm/d/Y', 'd-m-Y'];
        foreach ($formats as $fmt) {
            $d = DateTime::createFromFormat($fmt, $dateNais);
            if ($d && $d->format($fmt) === $dateNais) {
                $dateValide = $d->format('Y-m-d');
                break;
            }
        }
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO eleves
                (matricule, nom, prenom, sexe, date_naissance, lieu_naissance,
                 adresse, telephone_parent, classe_id, annee_scolaire, status)
            VALUES
                (:mat, :nom, :prenom, :sexe, :dn, :ln,
                 :adr, :tel, :cid, :annee, :statut)
        ");
        $stmt->execute([
            ':mat'    => $matricule,
            ':nom'    => $nom,
            ':prenom' => $prenom,
            ':sexe'   => $sexe,
            ':dn'     => $dateValide,
            ':ln'     => $lieu    ?: null,
            ':adr'    => $adresse ?: null,
            ':tel'    => $tel     ?: null,
            ':cid'    => $classe['id'],
            ':annee'  => $annee,
            ':statut' => $statut,
        ]);
        return ['ok'=>true, 'msg'=>"Ligne {$numLigne} :  {$prenom} {$nom} importé(e) — Matricule: {$matricule} — Niveau: {$niveau}"];

    } catch (PDOException $e) {
        return ['ok'=>false, 'msg'=>"Ligne {$numLigne} : Erreur BDD — " . $e->getMessage()];
    }
}

// ===== FONCTION : IMPORTER UN PROFESSEUR =====
// Colonnes attendues :
// 0=Nom | 1=Prénom | 2=Email | 3=Mot de passe | 4=Téléphone | 5=Adresse | 6=Statut
function importerProfesseur(PDO $pdo, array $ligne, int $numLigne): array {
    $nom      = trim((string)($ligne[0] ?? ''));
    $prenom   = trim((string)($ligne[1] ?? ''));
    $email    = strtolower(trim((string)($ligne[2] ?? '')));
    $password = trim((string)($ligne[3] ?? ''));
    $tel      = trim((string)($ligne[4] ?? ''));
    $adresse  = trim((string)($ligne[5] ?? ''));
    $statut   = strtolower(trim((string)($ligne[6] ?? 'actif')));

    // Validation
    if (empty($nom))      return ['ok'=>false, 'msg'=>"Ligne {$numLigne} : Nom manquant."];
    if (empty($prenom))   return ['ok'=>false, 'msg'=>"Ligne {$numLigne} : Prénom manquant."];
    if (empty($email))    return ['ok'=>false, 'msg'=>"Ligne {$numLigne} : Email manquant."];
    if (empty($password)) return ['ok'=>false, 'msg'=>"Ligne {$numLigne} : Mot de passe manquant."];

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok'=>false, 'msg'=>"Ligne {$numLigne} : Email « {$email} » invalide."];
    }
    if (!in_array($statut, ['actif','inactif'])) $statut = 'actif';

    // Vérifier que l'email n'existe pas déjà
    $check = $pdo->prepare("SELECT id FROM utilisateurs WHERE email = :email");
    $check->execute([':email' => $email]);
    if ($check->fetch()) {
        return ['ok'=>false, 'msg'=>"Ligne {$numLigne} : Email « {$email} » déjà utilisé."];
    }

    try {
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $pdo->prepare("
            INSERT INTO utilisateurs
                (nom, prenom, email, password, role, telephone, adresse, status)
            VALUES
                (:nom, :prenom, :email, :pwd, 'professeur', :tel, :adr, :statut)
        ");
        $stmt->execute([
            ':nom'    => $nom,
            ':prenom' => $prenom,
            ':email'  => $email,
            ':pwd'    => $hash,
            ':tel'    => $tel     ?: null,
            ':adr'    => $adresse ?: null,
            ':statut' => $statut,
        ]);
        return ['ok'=>true, 'msg'=>"Ligne {$numLigne} :  Prof. {$prenom} {$nom} ({$email}) importé(e)."];

    } catch (PDOException $e) {
        return ['ok'=>false, 'msg'=>"Ligne {$numLigne} : Erreur BDD — " . $e->getMessage()];
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<h1 class="page-title"> Importation Fichier Excel</h1>
<?php afficherMessage(); ?>

<!-- Onglets -->
<ul class="nav nav-tabs mb-4">
    <li class="nav-item">
        <a class="nav-link <?= $type === 'eleves' ? 'active' : '' ?>"
           href="import.php?type=eleves">
            <i class="fas fa-user-graduate me-1"></i> Élèves
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $type === 'professeurs' ? 'active' : '' ?>"
           href="import.php?type=professeurs">
            <i class="fas fa-chalkboard-teacher me-1"></i> Professeurs
        </a>
    </li>
</ul>

<!-- Alerte installation SimpleXLSX -->
<?php if (!$librairieDispo): ?>
<div class="alert alert-warning mb-4">
    <h5 class="fw-bold"><i class="fas fa-exclamation-triangle me-2"></i>Installation requise — SimpleXLSX</h5>
    <p>Pour lire les fichiers Excel, vous devez installer une petite bibliothèque PHP gratuite.</p>
    <ol class="mb-2">
        <li>Allez sur :
            <a href="https://github.com/shuchkin/simplexlsx/blob/master/src/SimpleXLSX.php"
               target="_blank">
                https://github.com/shuchkin/simplexlsx
            </a>
        </li>
        <li>Cliquez sur <strong>src/SimpleXLSX.php</strong></li>
        <li>Cliquez sur <strong>Raw</strong> puis faites <strong>Ctrl+S</strong> pour télécharger</li>
        <li>Placez le fichier dans : <code>comep_gestion/includes/SimpleXLSX.php</code></li>
        <li>Rechargez cette page</li>
    </ol>
    <div class="alert alert-success mb-0 py-2">
        <i class="fas fa-check me-1"></i>
        Une fois le fichier en place, cette alerte disparaîtra.
    </div>
</div>
<?php endif; ?>

<div class="row g-4">

    <!-- ===== FORMULAIRE ===== -->
    <div class="col-lg-5">
        <div class="card shadow-sm">
            <div class="card-header d-flex align-items-center gap-2">
                <i class="fas fa-upload text-primary"></i>
                <span>Importer un fichier Excel (.xlsx)</span>
            </div>
            <div class="card-body">

                <!-- Étape 1 : Télécharger le modèle -->
                <div class="p-3 rounded mb-4" style="background:#f0f9ff;border:1px solid #bae6fd">
                    <h6 class="fw-bold mb-2">
                        <span class="badge bg-primary me-1">Étape 1</span>
                        Télécharger le modèle
                    </h6>
                    <p class="small text-muted mb-2">
                        Utilisez ce modèle pour saisir vos données dans le bon format.
                    </p>
                    <?php if ($type === 'eleves'): ?>
                    <a href="../modele_import_eleves.xlsx" class="btn btn-outline-primary btn-sm" download>
                        <i class="fas fa-download me-1"></i> Modèle Import Élèves.xlsx
                    </a>
                    <?php else: ?>
                    <a href="../modele_import_professeurs.xlsx" class="btn btn-outline-success btn-sm" download>
                        <i class="fas fa-download me-1"></i> Modèle Import Professeurs.xlsx
                    </a>
                    <?php endif; ?>
                </div>

                <!-- Étape 2 : Importer -->
                <div class="p-3 rounded" style="background:#f0fdf4;border:1px solid #bbf7d0">
                    <h6 class="fw-bold mb-3">
                        <span class="badge bg-success me-1">Étape 2</span>
                        Importer votre fichier rempli
                    </h6>

                    <form method="POST"
                          action="import.php?type=<?= h($type) ?>"
                          enctype="multipart/form-data">
                        <input type="hidden" name="type" value="<?= h($type) ?>">

                        <div class="mb-3">
                            <label class="form-label fw-500">
                                Sélectionner votre fichier .xlsx
                            </label>
                            <input type="file"
                                   name="fichier_excel"
                                   class="form-control"
                                   accept=".xlsx"
                                   required
                                   <?= !$librairieDispo ? 'disabled' : '' ?>>
                            <div class="form-text">Format : .xlsx uniquement — Max : 5 MB</div>
                        </div>

                        <button type="submit"
                                class="btn btn-comep w-100"
                                <?= !$librairieDispo ? 'disabled' : '' ?>>
                            <i class="fas fa-file-import me-1"></i>
                            Lancer l'import
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Règles -->
        <div class="card shadow-sm mt-3">
            <div class="card-header">
                <i class="fas fa-info-circle me-2 text-warning"></i>Règles importantes
            </div>
            <div class="card-body p-0">
                <ul class="list-group list-group-flush">
                    <?php if ($type === 'eleves'): ?>
                    <li class="list-group-item small">
                        <i class="fas fa-check text-success me-2"></i>
                        <strong>Obligatoires :</strong> Nom, Prénom, Niveau, Année
                    </li>
                    <li class="list-group-item small">
                        <i class="fas fa-check text-success me-2"></i>
                        Niveaux acceptés : <code>7eme, 8eme, 9eme, NS1, NS2, NS3, NS4</code>
                    </li>
                    <li class="list-group-item small">
                        <i class="fas fa-check text-success me-2"></i>
                        Sexe : <code>M</code> ou <code>F</code>
                    </li>
                    <li class="list-group-item small">
                        <i class="fas fa-check text-success me-2"></i>
                        Date : format <code>AAAA-MM-JJ</code> (ex: 2010-05-15)
                    </li>
                    <li class="list-group-item small">
                        <i class="fas fa-check text-success me-2"></i>
                        Matricule généré automatiquement
                    </li>
                    <?php else: ?>
                    <li class="list-group-item small">
                        <i class="fas fa-check text-success me-2"></i>
                        <strong>Obligatoires :</strong> Nom, Prénom, Email, Mot de passe
                    </li>
                    <li class="list-group-item small">
                        <i class="fas fa-check text-success me-2"></i>
                        Email unique dans le système
                    </li>
                    <li class="list-group-item small">
                        <i class="fas fa-check text-success me-2"></i>
                        Mots de passe chiffrés automatiquement
                    </li>
                    <?php endif; ?>
                    <li class="list-group-item small">
                        <i class="fas fa-exclamation text-warning me-2"></i>
                        Maximum <strong>500 lignes</strong> par import
                    </li>
                    <li class="list-group-item small">
                        <i class="fas fa-exclamation text-warning me-2"></i>
                        <strong>Supprimer</strong> la ligne d'exemple avant d'importer
                    </li>
                </ul>
            </div>
        </div>
    </div>

    <!-- ===== RÉSULTATS ===== -->
    <div class="col-lg-7">

        <?php if (!empty($erreurs)): ?>
        <div class="alert alert-danger">
            <strong><i class="fas fa-times-circle me-2"></i>Erreurs :</strong>
            <ul class="mb-0 mt-2">
                <?php foreach ($erreurs as $err): ?>
                    <li><?= h($err) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <?php if (!empty($resultats)): ?>
        <!-- Résumé -->
        <div class="row g-3 mb-4">
            <div class="col-6">
                <div class="card border-success text-center p-3">
                    <div class="fs-2 fw-bold text-success"><?= $succes ?></div>
                    <div class="text-muted small">Importé(s) avec succès</div>
                </div>
            </div>
            <div class="col-6">
                <div class="card border-danger text-center p-3">
                    <div class="fs-2 fw-bold text-danger"><?= $echecs ?></div>
                    <div class="text-muted small">Erreur(s)</div>
                </div>
            </div>
        </div>

        <!-- Détail ligne par ligne -->
        <div class="card shadow-sm">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span><i class="fas fa-list me-2"></i>Détail de l'import</span>
                <div class="d-flex gap-2">
                    <button onclick="filtrer('tous')"
                            class="btn btn-outline-secondary btn-sm" id="btn-tous">
                        Tous (<?= count($resultats) ?>)
                    </button>
                    <button onclick="filtrer('ok')"
                            class="btn btn-outline-success btn-sm" id="btn-ok">
                         OK (<?= $succes ?>)
                    </button>
                    <button onclick="filtrer('erreur')"
                            class="btn btn-outline-danger btn-sm" id="btn-erreur">
                        Erreurs (<?= $echecs ?>)
                    </button>
                </div>
            </div>
            <div class="card-body p-0" style="max-height:450px;overflow-y:auto">
                <ul class="list-group list-group-flush" id="listeResultats">
                    <?php foreach ($resultats as $r): ?>
                    <li class="list-group-item py-2 px-3 item-resultat <?= $r['ok'] ? 'item-ok' : 'item-erreur' ?>"
                        style="font-size:0.85rem">
                        <?php if ($r['ok']): ?>
                            <i class="fas fa-check-circle text-success me-2"></i>
                        <?php else: ?>
                            <i class="fas fa-times-circle text-danger me-2"></i>
                        <?php endif; ?>
                        <?= h($r['msg']) ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>

        <?php elseif (empty($erreurs)): ?>
        <!-- État initial -->
        <div class="card shadow-sm">
            <div class="card-body text-center py-5 text-muted">
                <i class="fas fa-file-excel fa-4x mb-3" style="color:#217346;opacity:0.3"></i>
                <h5 class="mb-2">Prêt pour l'import</h5>
                <p class="small">
                    1. Téléchargez le modèle Excel<br>
                    2. Remplissez-le avec vos données<br>
                    3. Importez-le ici
                </p>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php
$scriptPage = <<<'JS'
<script>
function filtrer(type) {
    document.querySelectorAll('.item-resultat').forEach(el => {
        if (type === 'tous')    el.style.display = '';
        else if (type === 'ok') el.style.display = el.classList.contains('item-ok')     ? '' : 'none';
        else                    el.style.display = el.classList.contains('item-erreur') ? '' : 'none';
    });
    ['tous','ok','erreur'].forEach(t => {
        const btn = document.getElementById('btn-' + t);
        if (btn) btn.classList.toggle('active', t === type);
    });
}
// Activer le bouton "Tous" par défaut
document.addEventListener('DOMContentLoaded', () => filtrer('tous'));
</script>
JS;
?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>