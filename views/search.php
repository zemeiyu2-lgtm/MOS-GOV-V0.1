<?php

/**
 * 治理搜索 — V0.2 §23.
 *
 * 结果已依次通过：授权 → 范围 → 可见性。P5 内容以 '__P5_PROTECTED__'
 * 占位返回，仅显示保护提示，绝不显示字段名 + 内容（§42）。
 *
 * Expected variables: $query, $results (entity => rows), $error, $esc
 */

use ChurchCRM\dto\SystemURLs;

$mosGovRootPath = SystemURLs::getRootPath() . '/plugins/mos-gov';

require __DIR__ . '/_i18n.php';

$sPageTitle = '治理搜索';
$sPageSubtitle = '限定范围的治理搜索（不是 CRM 全局搜索）';
$aBreadcrumbs = [
    ['label' => '插件', 'url' => SystemURLs::getRootPath() . '/plugins/management'],
    ['label' => 'MOS-GOV', 'url' => $mosGovRootPath],
    ['label' => '治理搜索', 'active' => true],
];

require __DIR__ . '/../../../../Include/Header.php';

$activeSlug = 'search';
require __DIR__ . '/_tabs.php';
require __DIR__ . '/_style.php';

$mosGovEntityRoute = ['meeting' => 'meetings', 'issue' => 'issues', 'decision' => 'decisions', 'task' => 'tasks'];
$mosGovLabels = ['meeting' => '会议', 'issue' => '议题', 'decision' => '决策', 'task' => '任务'];
?>

<div class="card mb-3">
    <div class="card-body">
        <form method="get" action="<?= $esc($mosGovRootPath . '/search') ?>" class="d-flex gap-2">
            <input type="text" class="form-control" name="q" value="<?= $esc($query) ?>"
                   placeholder="搜索治理会议、议题、决策、任务…"
                   maxlength="100" autocomplete="off">
            <button class="btn btn-primary" type="submit">搜索</button>
        </form>
        <p class="text-secondary small mb-0 mt-2">
            结果仅限你的治理范围与信息分级；搜索不会回退到 CRM 全局搜索。
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
                            <td class="text-end"><span class="badge bg-secondary-lt"><?= $esc($mosGovValue($row['status'] ?? '')) ?></span></td>
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
                在你的治理范围内没有找到与「<?= $esc($query) ?>」相符的记录。
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/../../../../Include/Footer.php'; ?>
