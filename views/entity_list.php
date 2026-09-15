<?php

/**
 * MOS-GOV shared entity list view.
 *
 * Expected variables (provided by the route):
 * - $cfg          entity config from GovRepository::ENTITIES
 * - $slug         URL slug (e.g. structures)
 * - $rows         list of rows (array of assoc arrays)
 * - $decorations  row id => ['ref' => field => label, 'person' => field => label]
 * - $error        optional safe error message (data layer unavailable)
 * - $canWrite     whether the current user may create/edit records
 * - $esc          HTML-escaping closure
 */

use ChurchCRM\dto\SystemURLs;

$mosGovRootPath = SystemURLs::getRootPath() . '/plugins/mos-gov';

$sPageTitle = $cfg['labelPlural'];
$sPageSubtitle = 'MOS-GOV governance data';
$aBreadcrumbs = [
    ['label' => 'Plugins', 'url' => SystemURLs::getRootPath() . '/plugins/management'],
    ['label' => 'MOS-GOV', 'url' => $mosGovRootPath],
    ['label' => $cfg['labelPlural'], 'active' => true],
];
if (!empty($canWrite)) {
    $sPageHeaderButtons = '<a class="btn btn-primary" href="'
        . $esc($mosGovRootPath . '/' . $slug . '/new') . '">New ' . $esc($cfg['label']) . '</a>';
}

require __DIR__ . '/../../../../Include/Header.php';

$activeSlug = $slug;
require __DIR__ . '/_tabs.php';

/** Format one list cell using the field spec and the resolved labels. */
$mosGovCell = static function (array $cfg, string $field, array $row, array $dec) use ($esc): string {
    $value = $row[$field] ?? null;
    $type = $cfg['fields'][$field]['type'] ?? 'text';

    if ($type === 'ref') {
        return $esc($dec['ref'][$field] ?? ($value === null ? '' : '#' . (int) $value));
    }
    if ($type === 'person') {
        return $esc($dec['person'][$field] ?? ($value === null ? '' : '#' . (int) $value));
    }
    if ($value === null || $value === '') {
        return '<span class="text-secondary">&mdash;</span>';
    }

    return $esc($value);
};
?>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger" role="alert"><?= $esc($error) ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <h3 class="card-title"><?= $esc($cfg['labelPlural']) ?></h3>
        <?php if (!empty($canWrite)): ?>
            <div class="ms-auto">
                <a class="btn btn-sm btn-primary" href="<?= $esc($mosGovRootPath . '/' . $slug . '/new') ?>">
                    New <?= $esc($cfg['label']) ?>
                </a>
            </div>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <?php if ($rows === []): ?>
            <p class="text-secondary mb-0">
                No <?= $esc(strtolower($cfg['labelPlural'])) ?> recorded yet.
                <?php if (!empty($canWrite)): ?>
                    <a href="<?= $esc($mosGovRootPath . '/' . $slug . '/new') ?>">Create the first one</a>.
                <?php endif; ?>
            </p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-vcenter card-table">
                    <thead>
                        <tr>
                            <th class="w-1">ID</th>
                            <?php foreach ($cfg['listFields'] as $field): ?>
                                <th><?= $esc($cfg['fields'][$field]['label'] ?? $field) ?></th>
                            <?php endforeach; ?>
                            <th class="w-1"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row): ?>
                            <?php $dec = $decorations[(int) $row['id']] ?? ['ref' => [], 'person' => []]; ?>
                            <tr>
                                <td class="text-secondary"><?= (int) $row['id'] ?></td>
                                <?php foreach ($cfg['listFields'] as $field): ?>
                                    <td><?= $mosGovCell($cfg, $field, $row, $dec) ?></td>
                                <?php endforeach; ?>
                                <td class="text-nowrap">
                                    <a class="btn btn-sm btn-outline-secondary"
                                       href="<?= $esc($mosGovRootPath . '/' . $slug . '/' . (int) $row['id']) ?>">View</a>
                                    <?php if (!empty($canWrite)): ?>
                                        <a class="btn btn-sm btn-outline-primary"
                                           href="<?= $esc($mosGovRootPath . '/' . $slug . '/' . (int) $row['id'] . '/edit') ?>">Edit</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="text-secondary small mb-0 mt-2">
                Showing <?= count($rows) ?> record<?= count($rows) === 1 ? '' : 's' ?> (most recent first, up to 500).
            </p>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/../../../../Include/Footer.php'; ?>
