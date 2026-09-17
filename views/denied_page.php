<?php

/**
 * 治理拒绝页 (V0.2 §42).
 *
 * 说明边界但不泄漏受保护内容：不显示字段名、记录标题或用户本不知道的范围 ID。
 *
 * Expected variables: $decision (AuthorizationDecision), $esc from parent scope
 */

use ChurchCRM\dto\SystemURLs;

$mosGovRootPath = SystemURLs::getRootPath() . '/plugins/mos-gov';

$sPageTitle = '访问被拒绝';
$sPageSubtitle = 'MOS-GOV 治理授权';
$aBreadcrumbs = [
    ['label' => '插件', 'url' => SystemURLs::getRootPath() . '/plugins/management'],
    ['label' => 'MOS-GOV', 'url' => $mosGovRootPath],
    ['label' => '访问被拒绝', 'active' => true],
];

require __DIR__ . '/../../../../Include/Header.php';
?>

<div class="card">
    <div class="card-body text-center py-5">
        <div class="display-6 mb-3">&#128274;</div>
        <h3 class="mb-2">此信息受权限保护</h3>
        <p class="text-secondary mb-2">
            统一授权引擎判定：当前治理身份不满足读取该信息所需的条件。
        </p>
        <p class="text-secondary small mb-0">
            引擎结论：<?= htmlspecialchars($decision->reason, ENT_QUOTES, 'UTF-8') ?>
        </p>
        <?php if (!empty($decision->informationLevel)): ?>
            <p class="text-secondary small mb-0">
                信息分级：<?= htmlspecialchars($decision->informationLevel, ENT_QUOTES, 'UTF-8') ?>
            </p>
        <?php endif; ?>
        <div class="mt-3">
            <a class="btn btn-outline-primary" href="<?= $esc($mosGovRootPath) ?>">返回治理首页</a>
            <a class="btn btn-outline-secondary" href="<?= $esc($mosGovRootPath . '/my-governance') ?>">我的治理中心</a>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../../../../Include/Footer.php'; ?>
