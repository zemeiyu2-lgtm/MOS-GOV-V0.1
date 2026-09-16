<?php

/**
 * Governance identity list (V0.2 §13).
 *
 * Expected variables: $rows, $personLabels, $canEdit, $csrfField, $esc
 */

use ChurchCRM\dto\SystemURLs;

$mosGovRootPath = SystemURLs::getRootPath() . '/plugins/mos-gov';

$sPageTitle = 'Governance Identities';
$sPageSubtitle = 'Governance identity layer over ChurchCRM people';
$aBreadcrumbs = [
    ['label' => 'Plugins', 'url' => SystemURLs::getRootPath() . '/plugins/management'],
    ['label' => 'MOS-GOV', 'url' => $mosGovRootPath],
    ['label' => 'Identities', 'active' => true],
];

require __DIR__ . '/../../../../Include/Header.php';

$activeSlug = 'identity';
require __DIR__ . '/_tabs.php';
?>

<div class="card">
    <div class="card-header d-flex align-items-center">
        <h3 class="card-title">Governance identities</h3>
        <?php if (!empty($canEdit)): ?>
            <div class="ms-auto">
                <a class="btn btn-primary btn-sm" href="<?= $esc($mosGovRootPath . '/identity/new') ?>">New identity</a>
            </div>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <?php if ($rows === []): ?>
            <p class="text-secondary mb-0">
                No governance identities visible to you. Identities are granted
                per person by the governance administrator.
            </p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-vcenter card-table">
                    <thead>
                        <tr><th>#</th><th>Person</th><th>Status</th><th>Member since</th><th>Override name</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><a href="<?= $esc($mosGovRootPath . '/identity/' . (int) $row['id']) ?>">#<?= (int) $row['id'] ?></a></td>
                            <td><?= $esc($personLabels[(int) $row['id']] ?? ('Person #' . (int) $row['person_id'])) ?></td>
                            <td><?= $esc($row['identity_status']) ?></td>
                            <td><?= $esc($row['member_since'] ?? '—') ?></td>
                            <td><?= $esc($row['display_name_override'] ?? '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/../../../../Include/Footer.php'; ?>
