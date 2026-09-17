<?php

/**
 * 治理身份列表 (V0.2 §13).
 *
 * Expected variables: $rows, $personLabels, $canEdit, $csrfField, $esc
 */

use ChurchCRM\dto\SystemURLs;

$mosGovRootPath = SystemURLs::getRootPath() . '/plugins/mos-gov';

require __DIR__ . '/_i18n.php';

$sPageTitle = '治理身份';
$sPageSubtitle = 'ChurchCRM 人员之上的治理身份层';
$aBreadcrumbs = [
    ['label' => '插件', 'url' => SystemURLs::getRootPath() . '/plugins/management'],
    ['label' => 'MOS-GOV', 'url' => $mosGovRootPath],
    ['label' => '治理身份', 'active' => true],
];

require __DIR__ . '/../../../../Include/Header.php';

$activeSlug = 'identity';
require __DIR__ . '/_tabs.php';
?>

<div class="card">
    <div class="card-header d-flex align-items-center">
        <h3 class="card-title">治理身份</h3>
        <?php if (!empty($canEdit)): ?>
            <div class="ms-auto">
                <a class="btn btn-primary btn-sm" href="<?= $esc($mosGovRootPath . '/identity/new') ?>">新建治理身份</a>
            </div>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <?php if ($rows === []): ?>
            <p class="text-secondary mb-0">
                当前没有你可见的治理身份。治理身份由治理管理员逐人授予。
            </p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-vcenter card-table">
                    <thead>
                        <tr><th>#</th><th>人员</th><th>状态</th><th>加入时间</th><th>显示名称覆盖</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><a href="<?= $esc($mosGovRootPath . '/identity/' . (int) $row['id']) ?>">#<?= (int) $row['id'] ?></a></td>
                            <td><?= $esc($personLabels[(int) $row['id']] ?? ('人员 #' . (int) $row['person_id'])) ?></td>
                            <td><span class="badge bg-secondary-lt"><?= $esc($mosGovValue($row['identity_status'])) ?></span></td>
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
