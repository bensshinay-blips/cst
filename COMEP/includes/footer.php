<?php
/**
 * footer.php - Pied de page du système CST
 * À inclure en bas de chaque page, après tout le contenu.
 */
$anneeActuelle = date('Y');
?>
    </div><!-- /.container-fluid (ouvert dans header.php) -->
</main><!-- /.main-content -->

<!-- ===== PIED DE PAGE ===== -->
<footer class="footer mt-auto py-3">
    <div class="container-fluid px-4">
        <div class="row align-items-center">
            <div class="col-md-6 text-center text-md-start">
                <span class="text-muted small">
                    &copy; <?= $anneeActuelle ?> <strong>College Sainte Thérèse</strong> — Système de Gestion Scolaire.
                    Tous droits réservés.
                </span>
            </div>
            <div class="col-md-6 text-center text-md-end">
                <span class="text-muted small">
                    Développé par ING Roobens Estavien
                    &nbsp;|&nbsp;
                    <i class="fas fa-code me-1"></i>Version 1.0
                </span>
            </div>
        </div>
    </div>
</footer>

<!-- Bootstrap 5 JS Bundle (inclut Popper) -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<!-- Script global : confirmations de suppression, etc. -->
<script>
/**
 * Confirmation avant suppression (à appeler via onclick)
 * Exemple : onclick="return confirmerSuppression('cet élève')"
 */
function confirmerSuppression(element) {
    return confirm(`Êtes-vous sûr de vouloir supprimer ${element} ?\nCette action est irréversible.`);
}

/**
 * Masque automatiquement les alertes après 5 secondes
 */
document.addEventListener('DOMContentLoaded', function () {
    const alertes = document.querySelectorAll('.alert.alert-dismissible');
    alertes.forEach(function (alerte) {
        setTimeout(function () {
            const bsAlert = bootstrap.Alert.getOrCreateInstance(alerte);
            if (bsAlert) bsAlert.close();
        }, 5000);
    });
});
</script>

<?php
// Emplacement pour les scripts spécifiques à chaque page
// Utiliser : $scriptPage = '<script>...</script>';
if (!empty($scriptPage)) {
    echo $scriptPage;
}
?>

</body>
</html>
