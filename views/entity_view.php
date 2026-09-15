<?php

/**
 * MOS-GOV shared entity detail view.
 *
 * Shows the record's own fields (with resolved reference and ChurchCRM person
 * labels) plus the child collections configured in the entity registry, so the
 * governance working loops are visible from either end:
 *   Structure → Body → Role → Appointment → Responsibility
 *   Meeting   → Issue → Decision → Task
 *
 * Expected variables (provided by the route):
 * - $cfg          entity config from GovRepository::ENTITIES
 * - $slug         URL slug (e.g. structures)
 * - $row          the record (assoc array) or null when not found
 * - $decorations  ['ref' => field => label, 'person' => field => label]
 * - $related      child collections: label, slug, fields, rows, decorations
 * - $error        optional safe error message
 * - $canWrite     whether the current user may edit
 * - $esc          HTML-escaping closure
 */

use ChurchCRM\dto\SystemURLs;

$mosGovRootPath = SystemURLs::getRootPath() . '/plugins/mos-gov';
$listUrl = $mosGovRootPath . '/' . $slug;

$sPageTitle = $cfg['label'] . ($row !== null ? ' #' . (int) $row['id'] : '');
$sPageSubtitle = 'MOS-GOV governance data';
$aBreadcrumbs = [
    ['label' => 'Plugins', 'url' => SystemURLs::getRootPath() . '/plugins/management'],
    ['label' => 'MOS-GOV', 'url' => $mosGovRootPath],
    ['label' => $cfg['labelPlural'], 'url' => $listUrl],
    ['label' => $row !== null ? '#' . (int) $row['id'] : 'Not found', 'active' => true],
];
if ($row !== null && !empty($canWrite)) {
    $sPageHeaderButtons = '<a class="btn btn-primary" href="'
        . $esc($listUrl . '/' . (int) $row['id'] . '/edit') . '">Edit</a>';
}

require __DIR__ . '/../../../../Include/Header.php';

$activeSlug = $slug;
require __DIR__ . '/_tabs.php';

/** Format one value of the record for display. */
$mosGovValue = static function (array $cfg, string $field, array $row, array $dec) use ($esc): string {
    $value = $row[$field] ?? null;
    $type = $cfg['fields'][$field]['type'] ?? 'text';

    if ($type === 'ref') {
        $label = $dec['ref'][$field] ?? null;

        return $label !== null ? $esc($label) : '<span class="text-secondary">&mdash;</span>';
    }
    if ($type === 'person') {
        $label = $dec['person'][$field] ?? null;

        return $label !== null ? $esc($label) : '<span class="text-secondary">&mdash;</span>';
    }
    if ($value === null || $value === '') {
        return '<span class="text-secondary">&mdash;</span>';
    }
    if ($type === 'textlong') {
        return nl2br($esc($value));
    }

    return $esc($value);
};

/** Format one cell of a related-collection row. */
$mosGovRelatedCell = static function (array $fields, array $row, array $dec) use ($esc): string {
    foreach ($fields as $field) {
        $value = $row[$field] ?? null;
        if ($value !== null && $value !== '') {
            return $esc($dec['ref'][$field] ?? $dec['person'][$field] ?? $value);
        }
    }

    return '<span class="text-secondary">&mdash;</span>';
};
?>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger" role="alert"><?= $esc($error) ?></div>
<?php elseif ($row === null): ?>
    <div class="alert alert-warning" role="alert">
        This <?= $esc(strtolower($cfg['label'])) ?> does not exist (it may have been removed).
    </div>
    <p><a href="<?= $esc($listUrl) ?>">&larr; Back to <?= $esc(strtolower($cfg['labelPlural'])) ?></a></p>
<?php else: ?>
    <div class="card">
        <div class="card-header d-flex align-items-center">
            <h3 class="card-title"><?= $esc($cfg['label']) ?></h3>
            <div class="ms-auto d-flex gap-2">
                <?php if (!empty($canWrite)): ?>
                    <a class="btn btn-sm btn-primary" href="<?= $esc($listUrl . '/' . (int) $row['id'] . '/edit') ?>">Edit</a>
                <?php endif; ?>
                <a class="btn btn-sm btn-outline-secondary" href="<?= $esc($listUrl) ?>">Back to list</a>
            </div>
        </div>
        <div class="card-body">
            <div class="datagrid">
                <?php foreach ($cfg['fields'] as $field => $spec): ?>
                    <div class="datagrid-item">
                        <div class="datagrid-title"><?= $esc($spec['label']) ?></div>
                        <div class="datagrid-content"><?= $mosGovValue($cfg, $field, $row, $decorations) ?></div>
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

    <?php foreach ($related as $group): ?>
        <div class="card mt-3">
            <div class="card-header d-flex align-items-center">
                <h3 class="card-title"><?= $esc($group['label']) ?></h3>
                <div class="ms-auto">
                    <a class="btn btn-sm btn-outline-primary"
                       href="<?= $esc($mosGovRootPath . '/' . $group['slug'] . '/new?' . $group['field'] . '=' . (int) $row['id']) ?>">
                        Add <?= $esc(\ChurchCRM\Plugins\MosGov\Data\GovRepository::labelPluralFor(\ChurchCRM\Plugins\MosGov\Data\GovRepository::entityForSlug($group['slug']))) ?>
                    </a>
                </div>
            </div>
            <div class="card-body">
                <?php if ($group['rows'] === []): ?>
                    <p class="text-secondary mb-0">None recorded yet.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-vcenter card-table">
                            <thead>
                                <tr><th class="w-1">ID</th><th>Summary</th><th class="w-1"></th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($group['rows'] as $childRow): ?>
                                    <?php $childDec = $group['decorations'][(int) $childRow['id']] ?? ['ref' => [], 'person' => []]; ?>
                                    <tr>
                                        <td class="text-secondary"><?= (int) $childRow['id'] ?></td>
                                        <td><?= $mosGovRelatedCell($group['fields'], $childRow, $childDec) ?></td>
                                        <td>
                                            <a class="btn btn-sm btn-outline-secondary"
                                               href="<?= $esc($mosGovRootPath . '/' . $group['slug'] . '/' . (int) $childRow['id']) ?>">View</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php require __DIR__ . '/../../../../Include/Footer.php'; ?>
