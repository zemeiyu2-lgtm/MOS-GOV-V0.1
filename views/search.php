<?php

/**
 * Governance search results (V0.2 §23).
 *
 * Results have already passed: Authorization → Scope → Visibility.
 * P5 content arrives masked as '__P5_PROTECTED__' and is shown as a
 * protection notice, never as field name + content (§42).
 *
 * Expected variables: $query, $results (entity => rows), $error, $esc
 */

use ChurchCRM\dto\SystemURLs;

$mosGovRootPath = SystemURLs::getRootPath() . '/plugins/mos-gov';

$sPageTitle = 'Governance Search';
$sPageSubtitle = 'Scoped governance search (not the CRM global search)';
$aBreadcrumbs = [
    ['label' => 'Plugins', 'url' => SystemURLs::getRootPath() . '/plugins/management'],
    ['label' => 'MOS-GOV', 'url' => $mosGovRootPath],
    ['label' => 'Search', 'active' => true],
];

require __DIR__ . '/../../../../Include/Header.php';

$activeSlug = 'search';
require __DIR__ . '/_tabs.php';

$mosGovEntityRoute = ['meeting' => 'meetings', 'issue' => 'issues', 'decision' => 'decisions', 'task' => 'tasks'];
$mosGovLabels = ['meeting' => 'Meetings', 'issue' => 'Issues', 'decision' => 'Decisions', 'task' => 'Tasks'];
?>

<div class="card mb-3">
    <div class="card-body">
        <form method="get" action="<?= $esc($mosGovRootPath . '/search') ?>" class="d-flex gap-2">
            <input type="text" class="form-control" name="q" value="<?= $esc($query) ?>"
                   placeholder="Search governance meetings, issues, decisions, tasks…"
                   maxlength="100" autocomplete="off">
            <button class="btn btn-primary" type="submit">Search</button>
        </form>
        <p class="text-secondary small mb-0 mt-2">
            Results are limited to your governance scope and information level.
        </p>
    </div>
</div>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger"><?= $esc($error) ?></div>
<?php endif; ?>

<?php if ($query !== ''): ?>
    <?php $anyResults = false; ?>
    <?php foreach ($mosGovLabels as $entity => $label): ?>
        <?php if (empty($results[$entity])) { continue; } ?>
        <?php $anyResults = true; ?>
        <div class="card mb-3">
            <div class="card-header"><h3 class="card-title"><?= $esc($label) ?></h3></div>
            <div class="card-body">
                <table class="table table-sm table-vcenter">
                    <tbody>
                    <?php foreach ($results[$entity] as $row): ?>
                        <tr>
                            <td>
                                <a href="<?= $esc($mosGovRootPath . '/' . ($mosGovEntityRoute[$entity] ?? $entity) . '/' . (int) $row['id']) ?>">
                                    <?= $esc($row['title'] ?? ('#' . (int) $row['id'])) ?>
                                </a>
                            </td>
                            <td class="text-secondary">
                                <?php foreach ($row as $field => $value): ?>
                                    <?php if ($value === '__P5_PROTECTED__'): ?>
                                        <span class="badge bg-secondary-lt">此信息受权限保护</span>
                                        <?php break; ?>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </td>
                            <td class="text-end"><span class="badge bg-secondary-lt"><?= $esc($row['status'] ?? '') ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endforeach; ?>

    <?php if (!$anyResults): ?>
        <div class="card">
            <div class="card-body text-secondary">
                No results inside your governance scope for “<?= $esc($query) ?>”.
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/../../../../Include/Footer.php'; ?>
