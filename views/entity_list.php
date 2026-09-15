<?php

/**
 * MOS-GOV shared entity list view.
 *
 * Expected variables (provided by the route):
 * - $cfg         entity config from GovRepository::ENTITIES
 * - $slug        URL slug (structures|bodies|roles|appointments)
 * - $rows        list of rows (array of assoc arrays)
 * - $error       optional safe error message (data layer unavailable)
 * - $esc         HTML-escaping closure
 */
require_once __DIR__ . '/../../../../Include/Header.php';
$mosGovBase = \ChurchCRM\dto\SystemURLs::getRootPath() . '/plugins/mos-gov';
?>
<div class="container-xl">
    <div class="page-header d-print-none">
        <div class="row align-items-center">
            <div class="col">
                <h2 class="page-title"><?= $esc($cfg['label']) ?>s</h2>
                <div class="text-secondary">MOS-GOV governance data</div>
            </div>
            <div class="col-auto">
                <a class="btn btn-primary" href="<?= $esc('/plugins/mos-gov/' . $slug . '/new') ?>">
                    New <?= $esc($cfg['label']) ?>
                </a>
            </div>
        </div>
    </div>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger" role="alert"><?= $esc($error) ?></div>
    <?php endif; ?>

    <div class="card">
        <div class="card-body">
            <?php if ($rows === []): ?>
                <p class="text-secondary">No <?= $esc(strtolower($cfg['label'])) ?> records yet.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-vcenter card-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <?php foreach ($cfg['listFields'] as $field): ?>
                                    <th><?= $esc($cfg['fields'][$field]['label']) ?></th>
                                <?php endforeach; ?>
                                <th class="w-1"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $row): ?>
                                <tr>
                                    <td><?= (int) $row['id'] ?></td>
                                    <?php foreach ($cfg['listFields'] as $field): ?>
                                        <td><?= $esc($row[$field] ?? '') ?></td>
                                    <?php endforeach; ?>
                                    <td>
                                        <a class="btn btn-sm btn-outline-secondary"
                                           href="<?= $esc('/plugins/mos-gov/' . $slug . '/' . (int) $row['id']) ?>">View</a>
                                        <a class="btn btn-sm btn-outline-primary"
                                           href="<?= $esc('/plugins/mos-gov/' . $slug . '/' . (int) $row['id'] . '/edit') ?>">Edit</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <p class="mt-3">
        <a href="<?= $esc($mosGovBase) ?>">&larr; Back to MOS-GOV dashboard</a>
    </p>
</div>
<?php require_once __DIR__ . '/../../../../Include/Footer.php'; ?>
