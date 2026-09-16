<?php

/**
 * Governance DENY page (V0.2 §42).
 *
 * Explains the boundary WITHOUT leaking the protected content: no field
 * names, no record titles, no scope IDs beyond what the user already knew.
 *
 * Expected variables: $decision (AuthorizationDecision), $esc from parent scope
 */

use ChurchCRM\dto\SystemURLs;

$mosGovRootPath = SystemURLs::getRootPath() . '/plugins/mos-gov';

$sPageTitle = 'Access denied';
$sPageSubtitle = 'MOS-GOV governance authorization';
$aBreadcrumbs = [
    ['label' => 'Plugins', 'url' => SystemURLs::getRootPath() . '/plugins/management'],
    ['label' => 'MOS-GOV', 'url' => $mosGovRootPath],
    ['label' => 'Access denied', 'active' => true],
];

require __DIR__ . '/../../../../Include/Header.php';
?>

<div class="card">
    <div class="card-body text-center py-5">
        <div class="display-6 mb-3">&#128274;</div>
        <h3 class="mb-2">此信息受权限保护 / This information is protected</h3>
        <p class="text-secondary">
            <?= htmlspecialchars($decision->reason, ENT_QUOTES, 'UTF-8') ?>
        </p>
        <?php if (!empty($decision->informationLevel)): ?>
            <p class="text-secondary small">
                Information level: <?= htmlspecialchars($decision->informationLevel, ENT_QUOTES, 'UTF-8') ?>
            </p>
        <?php endif; ?>
        <div class="mt-3">
            <a class="btn btn-outline-primary" href="<?= $esc($mosGovRootPath) ?>">Back to governance home</a>
            <a class="btn btn-outline-secondary" href="<?= $esc($mosGovRootPath . '/my-governance') ?>">My Governance Center</a>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../../../../Include/Footer.php'; ?>
