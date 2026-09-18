<?php

/**
 * 新建治理身份 (V0.2).
 *
 * Expected variables: $isEdit, $row, $personCandidates, $errors, $csrfField, $esc
 */

use ChurchCRM\dto\SystemURLs;

$mosGovRootPath = SystemURLs::getRootPath() . '/plugins/mos-gov';

require __DIR__ . '/_i18n.php';

$sPageTitle = '新建治理身份';
$sPageSubtitle = '把 ChurchCRM 人员接入治理层';
$aBreadcrumbs = [
    ['label' => '插件', 'url' => SystemURLs::getRootPath() . '/plugins/management'],
    ['label' => 'MOS-GOV', 'url' => $mosGovRootPath],
    ['label' => '治理身份', 'url' => $mosGovRootPath . '/identity'],
    ['label' => '新建', 'active' => true],
];

require __DIR__ . '/../../../../Include/Header.php';

$activeSlug = 'identity';
require __DIR__ . '/_tabs.php';
require __DIR__ . '/_style.php';

$errors = $errors ?? [];
$row = $row ?? [];
?>

<?php if ($errors !== []): ?>
    <div class="alert alert-danger">请修正标红的字段后重新提交。</div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <form method="post" action="<?= $esc($mosGovRootPath . '/identity') ?>">
            <?= $csrfField ?>
            <div class="mb-3">
                <label class="form-label" for="person_id">人员（ChurchCRM）</label>
                <select class="form-select" id="person_id" name="person_id" required>
                    <option value="">— 选择人员 —</option>
                    <?php foreach ($personCandidates as $pid => $label): ?>
                        <option value="<?= (int) $pid ?>" <?= (string) ($row['person_id'] ?? '') === (string) $pid ? 'selected' : '' ?>>
                            <?= $esc($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (isset($errors['person_id'])): ?><div class="text-danger small"><?= $esc($errors['person_id']) ?></div><?php endif; ?>
            </div>
            <div class="mb-3">
                <label class="form-label" for="identity_status">身份状态</label>
                <select class="form-select" id="identity_status" name="identity_status">
                    <?php foreach (['active', 'inactive', 'suspended', 'archived'] as $status): ?>
                        <option value="<?= $esc($status) ?>" <?= ($row['identity_status'] ?? 'active') === $status ? 'selected' : '' ?>>
                            <?= $esc($mosGovValue($status)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label" for="member_since">加入时间</label>
                <input class="form-control" type="date" id="member_since" name="member_since"
                       value="<?= $esc($row['member_since'] ?? '') ?>">
            </div>
            <div class="mb-3">
                <label class="form-label" for="display_name_override">显示名称覆盖</label>
                <input class="form-control" type="text" id="display_name_override" name="display_name_override"
                       maxlength="190" value="<?= $esc($row['display_name_override'] ?? '') ?>">
                <div class="form-hint">可选的治理显示名称；ChurchCRM 的人员事实数据不会被复制。</div>
            </div>
            <button class="btn btn-primary" type="submit">创建治理身份</button>
            <a class="btn btn-outline-secondary" href="<?= $esc($mosGovRootPath . '/identity') ?>">取消</a>
        </form>
    </div>
</div>

<?php require __DIR__ . '/../../../../Include/Footer.php'; ?>
