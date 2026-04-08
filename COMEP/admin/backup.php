<?php
/**
 * admin/backup.php - Gestion des sauvegardes
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
requireAdmin();

$titrePage = 'Sauvegarde - Système de gestion scolaire';
$pdo = getDB();
$message = '';
$erreur = '';

$backupDir = __DIR__ . '/../backups/';

if (!is_dir($backupDir)) {
    mkdir($backupDir, 0777, true);
}

// ===== CRÉER UN BACKUP =====
if (isset($_POST['action']) && $_POST['action'] === 'backup') {
    $date = date('Y-m-d_H-i-s');
    $sqlFile = $backupDir . 'sauvegarde_' . $date . '.sql';
    $zipFile = $backupDir . 'sauvegarde_' . $date . '.zip';
    
    // Votre chemin exact de mysqldump
    $mysqldump = "C:\\wamp\\bin\\mysql\\mysql8.0.31\\bin\\mysqldump.exe";
    
    // Vérifier que le fichier existe
    if (!file_exists($mysqldump)) {
        $erreur = "mysqldump introuvable à : " . $mysqldump;
    } else {
        // Construire la commande
        if (DB_PASS) {
            $command = "\"$mysqldump\" --host=" . DB_HOST . " --user=" . DB_USER . " --password=" . DB_PASS . " " . DB_NAME . " > \"" . $sqlFile . "\" 2>&1";
        } else {
            $command = "\"$mysqldump\" --host=" . DB_HOST . " --user=" . DB_USER . " " . DB_NAME . " > \"" . $sqlFile . "\" 2>&1";
        }
        
        // Exécuter
        exec($command, $output, $returnCode);
        
        if ($returnCode === 0 && file_exists($sqlFile) && filesize($sqlFile) > 0) {
            // Compresser en ZIP
            $zip = new ZipArchive();
            if ($zip->open($zipFile, ZipArchive::CREATE) === true) {
                $zip->addFile($sqlFile, 'sauvegarde.sql');
                $zip->close();
                unlink($sqlFile);
                $message = "Sauvegarde créée avec succès !";
                logAction($pdo, 'BACKUP', 'systeme', 0, 'Sauvegarde créée');
            } else {
                $erreur = "Impossible de compresser la sauvegarde";
            }
        } else {
            $erreur = "Erreur lors de la création de la sauvegarde. Code erreur : " . $returnCode;
        }
    }
}

// ===== TÉLÉCHARGER UN BACKUP =====
if (isset($_GET['download']) && isset($_GET['file'])) {
    $file = $backupDir . $_GET['file'];
    if (file_exists($file)) {
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . basename($file) . '"');
        header('Content-Length: ' . filesize($file));
        readfile($file);
        exit();
    }
}

// ===== SUPPRIMER UN BACKUP =====
if (isset($_GET['delete']) && isset($_GET['file'])) {
    $file = $backupDir . $_GET['file'];
    if (file_exists($file)) {
        unlink($file);
        $message = "Sauvegarde supprimée";
    }
}

// ===== LISTER LES BACKUPS =====
$backups = glob($backupDir . 'sauvegarde_*.zip');
usort($backups, function($a, $b) {
    return filemtime($b) - filemtime($a);
});

$lastBackup = !empty($backups) ? $backups[0] : null;
$lastBackupDate = $lastBackup ? date('d/m/Y H:i:s', filemtime($lastBackup)) : 'Aucune sauvegarde';
$lastBackupSize = $lastBackup ? round(filesize($lastBackup) / 1024 / 1024, 2) . ' Mo' : '-';

require_once __DIR__ . '/../includes/header.php';
?>

<h1 class="page-title">
 Sauvegarde les données CST
</h1>

<?php if ($message): ?>
    <div class="alert alert-success"><?= $message ?></div>
<?php endif; ?>

<?php if ($erreur): ?>
    <div class="alert alert-danger"><?= $erreur ?></div>
<?php endif; ?>

<div class="row g-4">

    <!-- Bouton principal -->
    <div class="col-md-12">
        <div class="card shadow-sm">
            <div class="card-body text-center py-5">
                <i class="fas fa-database fa-4x text-primary mb-3"></i>
                <h3>Gestion des sauvegardes</h3>
                <p class="text-muted">
                    Dernière sauvegarde : <strong><?= $lastBackupDate ?></strong><br>
                    Taille : <strong><?= $lastBackupSize ?></strong>
                </p>
                
                <form method="POST" style="display: inline-block;">
                    <input type="hidden" name="action" value="backup">
                    <button type="submit" class="btn btn-comep btn-lg">
                       Sauvegarder maintenant
                    </button>
                </form>
                
                <?php if ($lastBackup): ?>
                    <a href="?download=1&file=<?= urlencode(basename($lastBackup)) ?>" 
                       class="btn btn-outline-primary btn-lg ms-2">
                       Télécharger
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Historique -->
    <div class="col-md-12">
        <div class="card shadow-sm">
            <div class="card-header">
                <i class="fas fa-history me-2 text-primary"></i>
                Historique des sauvegardes
                <span class="badge bg-secondary ms-2"><?= count($backups) ?> sauvegarde(s)</span>
            </div>
            <div class="card-body p-0">
                <?php if (empty($backups)): ?>
                    <p class="text-muted text-center py-4">
                        Aucune sauvegarde. Cliquez sur "Sauvegarder maintenant".
                    </p>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Fichier</th>
                                <th>Date</th>
                                <th>Taille</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($backups as $backup): ?>
                            <?php 
                                $size = round(filesize($backup) / 1024 / 1024, 2);
                                $date = date('d/m/Y H:i:s', filemtime($backup));
                                $isLast = ($backup === $lastBackup);
                            ?>
                            <tr class="<?= $isLast ? 'table-success' : '' ?>">
                                <td>
                                    <code><?= basename($backup) ?></code>
                                    <?php if ($isLast): ?>
                                        <span class="badge bg-success ms-2">Dernière</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= $date ?></td>
                                <td><?= $size ?> Mo</td>
                                <td class="text-center">
                                    <a href="?download=1&file=<?= urlencode(basename($backup)) ?>" 
                                       class="btn btn-sm btn-success" title="Télécharger">
                                        <i class="fas fa-download"></i>
                                    </a>
                                    <a href="?delete=1&file=<?= urlencode(basename($backup)) ?>" 
                                       class="btn btn-sm btn-danger" 
                                       onclick="return confirm('Supprimer cette sauvegarde ?')"
                                       title="Supprimer">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Informations -->
    <div class="col-md-12">
        <div class="card shadow-sm">
            <div class="card-header">
                <i class="fas fa-info-circle me-2 text-info"></i>
                Informations
            </div>
            <div class="card-body">
                <ul class="mb-0">
                    <li>La sauvegarde contient TOUTES les données de l'école</li>
                    <li>Les 10 dernières sauvegardes sont conservées automatiquement</li>
                    <li>Téléchargez la sauvegarde sur votre ordinateur</li>
                    <li>Pour restaurer, utilisez phpMyAdmin</li>
                </ul>
            </div>
        </div>
    </div>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>