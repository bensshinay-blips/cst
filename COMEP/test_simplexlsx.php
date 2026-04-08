<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>Test SimpleXLSX</h2>";

// 1. Vérifier si le fichier existe
$chemin = __DIR__ . '/includes/SimpleXLSX.php';
echo "1. Fichier : " . $chemin . "<br>";

if (file_exists($chemin)) {
    echo "   ✅ Fichier trouvé (" . filesize($chemin) . " octets)<br>";
    
    // 2. Inclure le fichier
    try {
        require_once $chemin;
        echo "2. ✅ Fichier inclus<br>";
    } catch (Exception $e) {
        echo "2. ❌ Erreur inclusion : " . $e->getMessage() . "<br>";
    }
    
    // 3. Vérifier les classes disponibles
    echo "3. Classes disponibles contenant 'XLSX' :<br>";
    $classes = get_declared_classes();
    $trouve = false;
    foreach ($classes as $c) {
        if (stripos($c, 'xlsx') !== false) {
            echo "   - " . $c . "<br>";
            $trouve = true;
        }
    }
    
    if (!$trouve) {
        echo "   ❌ Aucune classe XLSX trouvée<br>";
    }
    
} else {
    echo "   ❌ Fichier NON trouvé !<br>";
    echo "   Le fichier doit être à : includes/SimpleXLSX.php<br>";
    
    // Lister le contenu du dossier includes
    echo "<br>Contenu du dossier includes :<br>";
    $includesDir = __DIR__ . '/includes';
    if (is_dir($includesDir)) {
        $fichiers = scandir($includesDir);
        foreach ($fichiers as $f) {
            if ($f != '.' && $f != '..') {
                echo "   - " . $f . "<br>";
            }
        }
    } else {
        echo "   Le dossier includes n'existe pas !<br>";
    }
}
?>