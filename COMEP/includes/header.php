<?php
/**
 * includes/header.php - En-tête et barre de navigation
 * Système de gestion scolaire CST
 */

if (!function_exists('estConnecte')) {
    require_once __DIR__ . '/auth.php';
}

$titrePage = $titrePage ?? 'CST - Gestion Scolaire';
$racine    = racine();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($titrePage) ?> | CST</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="<?= $racine ?>css/style.css" rel="stylesheet">
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark bg-comep sticky-top shadow">
    <div class="container-fluid px-4">

        <!-- Logo -->
        <a class="navbar-brand d-flex align-items-center gap-2" href="<?= $racine ?>index.php">
            <div class="brand-icon">
                <i class="fas fa-graduation-cap"></i>
            </div>
            <div>
                <span class="fw-bold fs-5">CST</span>
                <small class="d-block text-white-50" style="font-size:0.65rem;line-height:1;">Gestion Scolaire</small>
            </div>
        </a>

        <!-- Hamburger mobile -->
        <button class="navbar-toggler border-0" type="button"
                data-bs-toggle="collapse" data-bs-target="#navbarMenu">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="navbarMenu">

            <?php if (estConnecte()): ?>

                <?php
                // Détecter la page actuelle
                $pageCourante = $_SERVER['PHP_SELF'] ?? '';
                ?>

                <!-- ========== MENU ADMIN ========== -->
                <?php if (estAdmin()): ?>
                <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                    <li class="nav-item">
                        <a class="nav-link <?= str_contains($pageCourante,'admin/index')?'active':'' ?>"
                           href="<?= $racine ?>admin/index.php">
                           Tableau de bord
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= str_contains($pageCourante,'classes')?'active':'' ?>"
                           href="<?= $racine ?>admin/classes.php">
                          Classes
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= str_contains($pageCourante,'matieres')?'active':'' ?>"
                           href="<?= $racine ?>admin/matieres.php">
                   Matières
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= str_contains($pageCourante,'eleves')?'active':'' ?>"
                           href="<?= $racine ?>admin/eleves.php">
                           Élèves
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= str_contains($pageCourante,'professeurs')?'active':'' ?>"
                           href="<?= $racine ?>admin/professeurs.php">
                          Professeurs
                        </a>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#"
                           role="button" data-bs-toggle="dropdown">
                            Gestion
                        </a>
                        <ul class="dropdown-menu dropdown-menu-dark">
                            <li>
                                <a class="dropdown-item" href="<?= $racine ?>admin/assignements.php">
                                  Assignations Prof/Classe
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item" href="<?= $racine ?>admin/classe_matieres.php">
                                    Matières par Classe
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item" href="<?= $racine ?>admin/bulletins.php">
                                    Bulletins
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item" href="<?= $racine ?>admin/periodes_saisie.php">
                                    Contrôle des Périodes
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item" href="<?= $racine ?>admin/import.php">
                                   Import Excel
                                </a>
                            </li>
                            <li><hr class="dropdown-divider border-secondary"></li>
                            <li>
                                <a class="dropdown-item" href="<?= $racine ?>eleve/connexion.php" target="_blank">
                                     Portail Élève
                                </a>
                            </li>
                            <li><hr class="dropdown-divider border-secondary"></li>
                            <li>
                                <a class="dropdown-item text-warning fw-bold"
                                   href="<?= $racine ?>admin/nouvelle_annee.php">
                                  Nouvelle Année Scolaire
                                </a>
                            </li>
                        </ul>
                    </li>
                </ul>

                <!-- ========== MENU ÉCONOMAT ========== -->
                <?php elseif (estEconmat()): ?>
                <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                    <li class="nav-item">
                        <a class="nav-link <?= str_contains($pageCourante,'econmat/index')?'active':'' ?>"
                           href="<?= $racine ?>econmat/index.php">
                             Tableau de bord
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= str_contains($pageCourante,'econmat/acces')?'active':'' ?>"
                           href="<?= $racine ?>econmat/acces.php">
                           Accès Bulletins
                        </a>
                    </li>
                </ul>

                <!-- ========== MENU PROFESSEUR ========== -->
                <?php elseif (estProfesseur()): ?>
                <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                    <li class="nav-item">
                        <a class="nav-link <?= str_contains($pageCourante,'professeur/index')?'active':'' ?>"
                           href="<?= $racine ?>professeur/index.php">
                            Tableau de bord
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= str_contains($pageCourante,'notes')?'active':'' ?>"
                           href="<?= $racine ?>professeur/notes.php">
                           Saisir Notes
                        </a>
                    </li>
                </ul>
                <?php endif; ?>

                <!-- Utilisateur connecté + déconnexion (commun à tous les rôles) -->
                <ul class="navbar-nav ms-auto align-items-center">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle d-flex align-items-center gap-2"
                           href="#" role="button" data-bs-toggle="dropdown">
                            <div class="user-avatar">
                                <?= strtoupper(substr($_SESSION['prenom'] ?? 'U', 0, 1)) ?>
                            </div>
                            <span class="d-none d-md-inline">
                                <?= h($_SESSION['prenom']) ?> <?= h($_SESSION['nom']) ?>
                                <small class="badge ms-1
                                    <?= estAdmin()   ? 'bg-warning text-dark' :
                                       (estEconmat() ? 'bg-success'           : 'bg-info') ?>">
                                    <?= estAdmin()   ? 'Admin'     :
                                       (estEconmat() ? 'Économat'  : 'Prof') ?>
                                </small>
                            </span>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end dropdown-menu-dark">
                            <li>
                                <span class="dropdown-item-text text-white-50 small">
                                    <?= h($_SESSION['email'] ?? '') ?>
                                </span>
                            </li>
                            <li><hr class="dropdown-divider border-secondary"></li>
                            <?php if (estAdmin()): ?>
                            <li>
                                <a class="dropdown-item" href="<?= $racine ?>admin/profil.php">
                                    Mon Profil & Sécurité
                                </a>
                                 <a class="dropdown-item" href="<?= $racine ?>admin/backup.php">
                 Sauvegarde données
                  </a>
                     </li>
                            <li><hr class="dropdown-divider border-secondary"></li>
                            <?php endif; ?>
                            <li>
                                <a class="dropdown-item text-danger"
                                   href="<?= $racine ?>logout.php"
                                   onclick="return confirm('Voulez-vous vous déconnecter ?')">
                                    <i class="fas fa-sign-out-alt me-2"></i> Déconnexion
                                </a>
                            </li>
                        </ul>
                    </li>
                </ul>

            <?php else: ?>
                <!-- Non connecté -->
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="<?= $racine ?>login.php">
                            <i class="fas fa-sign-in-alt me-1"></i> Connexion
                        </a>
                    </li>
                </ul>
            <?php endif; ?>

        </div>
    </div>
</nav>

<!-- Contenu principal -->
<main class="main-content">
    <div class="container-fluid px-4 py-3">
