<?php

/**
 * MOS-GOV shared entity detail view.
 *
 * Expected variables (provided by the route):
 * - $cfg        entity config from GovRepository::ENTITIES
 * - $slug       URL slug (structures|bodies|roles|appointments)
 * - $row        the record (assoc array) or null when not found
 * - $refLabels  field => display label for ref fields (resolved parent name)
 * - $error      optional safe error message
 * - $esc        HTML-escaping closure
 */
require_once __DIR__ . '/../../../../Include/Header.php';
$mosGovBase = \ChurchCRM\dto\SystemURLs::getRootPath() . '/plugins/mos-gov';
?>
<div class="container-xl">
    <div class="page-header d-print-none">
        <div class="row align-items-center">
            <div class="col">
                <h2 class="page-title"><?= $esc($cfg['label']) ?> #<?= isset($row['id']) ? (int) $row['id'] : 0 ?></h2>
                <div class="text-secondary">MOS-GOV governance data</div>
            </div>
            <div class="col-auto">
                <?php if ($row !== null): ?>
                    <a class="btn btn-primary"
                       href="<?= $esc('/plugins/mos-gov/' . $slug . '/' . (int) $row['id'] . '/edit') ?>">Edit</a>
                <?php endif; ?>
                <a class="btn btn-outline-secondary"
                   href="<?= $esc('/plugins/mos-gov/' . $slug) ?>">Back to list</a>
            </div>
        </div>
    </div>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger" role="alert"><?= $esc($error) ?></div>
    <?php elseif ($row === null): ?>
        <div class="alert alert-warning" role="alert">
            This record does not exist (it may have been removed).
        </div>
    <?php else: ?>
        <div class="card">
            <div class="card-body">
                <div class="datagrid">
                    <?php foreach ($cfg['fields'] as $field => $spec): ?>
                        <div class="datagrid-item">
                            <div class="datagrid-title"><?= $esc($spec['label']) ?></div>
                            <div class="datagrid-content">
                                <?php if ($spec['type'] === 'ref'): ?>
                                    <?= $esc($refLabels[$field] ?? ('#' . (string) $row[$field])) ?>
                                <?php else: ?>
                                    <?= $esc($row[$field] ?? '') === '' ? '<span class="text-secondary">&mdash;</span>' : $esc($row[$field]) ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <div class="datagrid-item">
                        <div class="datagrid-title">Created</div>
                        <div class="datagrid-content"><?= $esc($row['created_at'] ?? '') ?></div>
                    </div>
                    <div class="datagrid-item">
                        <div class="datagrid-title">Last updated</div>
                        <div class="datagrid-content"><?= $esc($row['updated_at'] ?? '') ?></div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../../../../Include/Footer.php'; ?>
