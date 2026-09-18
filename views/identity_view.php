<?php

/**
 * 治理身份详情 (V0.2)：角色、范围与显式权限覆盖.
 *
 * Expected variables:
 * - $row, $personLabel, $identityRoles, $identityScopes, $overrides
 * - $roleLabels, $appointmentLabels, $permissionLabels
 * - $scopeTypes, $grantModes
 * - $canEdit, $csrfField, $esc
 */

use ChurchCRM\dto\SystemURLs;

$mosGovRootPath = SystemURLs::getRootPath() . '/plugins/mos-gov';

require __DIR__ . '/_i18n.php';

$sPageTitle = '治理身份 #' . (int) $row['id'];
$sPageSubtitle = '角色、范围与显式权限';
$aBreadcrumbs = [
    ['label' => '插件', 'url' => SystemURLs::getRootPath() . '/plugins/management'],
    ['label' => 'MOS-GOV', 'url' => $mosGovRootPath],
    ['label' => '治理身份', 'url' => $mosGovRootPath . '/identity'],
    ['label' => '#' . (int) $row['id'], 'active' => true],
];

require __DIR__ . '/../../../../Include/Header.php';

$activeSlug = 'identity';
require __DIR__ . '/_tabs.php';
require __DIR__ . '/_style.php';

// P5 protection: identity notes never render inline without an explicit grant.
$notesVisible = false;
?>

<div class="row g-3">
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header"><h3 class="card-title">治理身份</h3></div>
            <div class="card-body">
                <div class="datagrid">
                    <div class="datagrid-item">
                        <div class="datagrid-title">人员</div>
                        <div class="datagrid-content"><?= $esc($personLabel) ?></div>
                    </div>
                    <div class="datagrid-item">
                        <div class="datagrid-title">状态</div>
                        <div class="datagrid-content"><?= $esc($mosGovValue($row['identity_status'])) ?></div>
                    </div>
                    <div class="datagrid-item">
                        <div class="datagrid-title">加入时间</div>
                        <div class="datagrid-content"><?= $esc($row['member_since'] ?? '—') ?></div>
                    </div>
                    <div class="datagrid-item">
                        <div class="datagrid-title">备注</div>
                        <div class="datagrid-content">
                            <?php if ($notesVisible): ?>
                                <?= $esc($row['notes'] ?? '') ?>
                            <?php else: ?>
                                <span class="text-secondary">此信息受权限保护。</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="card mb-3">
            <div class="card-header"><h3 class="card-title">角色</h3></div>
            <div class="card-body">
                <?php if ($identityRoles === []): ?>
                    <p class="text-secondary mb-0">尚未挂接角色。</p>
                <?php else: ?>
                    <table class="table table-sm table-vcenter">
                        <thead><tr><th>角色</th><th>任命</th><th>状态</th><th>开始</th><th>结束</th></tr></thead>
                        <tbody>
                        <?php foreach ($identityRoles as $ir): ?>
                            <tr>
                                <td><?= $esc($mosGovDecorLabel($roleLabels[(int) $ir['role_id']] ?? ('角色 #' . (int) $ir['role_id']))) ?></td>
                                <td><?= $esc($ir['appointment_id'] !== null ? ($appointmentLabels[(int) $ir['appointment_id']] ?? ('#' . (int) $ir['appointment_id'])) : '—') ?></td>
                                <td><span class="badge <?= $ir['status'] === 'active' ? 'bg-success-lt' : 'bg-secondary-lt' ?>"><?= $esc($mosGovValue($ir['status'])) ?></span></td>
                                <td><?= $esc($ir['start_date'] ?? '—') ?></td>
                                <td><?= $esc($ir['end_date'] ?? '—') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header"><h3 class="card-title">治理范围</h3></div>
            <div class="card-body">
                <?php if ($identityScopes === []): ?>
                    <p class="text-secondary mb-0">没有显式范围记录。</p>
                <?php else: ?>
                    <table class="table table-sm table-vcenter">
                        <thead><tr><th>范围类型</th><th>范围 ID</th><th>来源</th><th>状态</th></tr></thead>
                        <tbody>
                        <?php foreach ($identityScopes as $scope): ?>
                            <tr>
                                <td><span class="badge bg-secondary-lt"><?= $esc($mosGovValue($scope['scope_type'])) ?></span></td>
                                <td><?= $esc($scope['scope_id'] ?? '—') ?></td>
                                <td><?= $esc($mosGovValue($scope['source_type'])) ?></td>
                                <td><?= $esc($mosGovValue($scope['status'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header"><h3 class="card-title">显式权限覆盖</h3></div>
            <div class="card-body">
                <?php if ($overrides === []): ?>
                    <p class="text-secondary mb-0">没有显式覆盖。覆盖无法绕过安全边界。</p>
                <?php else: ?>
                    <table class="table table-sm table-vcenter">
                        <thead><tr><th>权限</th><th>方式</th><th>事由</th><th>状态</th></tr></thead>
                        <tbody>
                        <?php foreach ($overrides as $ov): ?>
                            <tr>
                                <td><code><?= $esc($permissionLabels[(int) $ov['permission_id']] ?? ('#' . (int) $ov['permission_id'])) ?></code></td>
                                <td><span class="badge <?= $ov['grant_mode'] === 'deny' ? 'bg-danger-lt' : 'bg-success-lt' ?>"><?= $esc($mosGovValue($ov['grant_mode'])) ?></span></td>
                                <td><?= $esc($ov['reason'] ?? '') ?></td>
                                <td><?= $esc($mosGovValue($ov['status'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!empty($canEdit)): ?>
            <div class="card">
                <div class="card-header"><h3 class="card-title">管理操作</h3></div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <form method="post" action="<?= $esc($mosGovRootPath . '/identity/' . (int) $row['id'] . '/attach-role') ?>">
                                <?= $csrfField ?>
                                <label class="form-label">挂接角色</label>
                                <select class="form-select mb-2" name="role_id" required>
                                    <?php foreach ($roleLabels as $rid => $label): ?>
                                        <option value="<?= (int) $rid ?>"><?= $esc($mosGovDecorLabel($label)) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <input class="form-control mb-2" type="text" name="appointment_id" placeholder="任命 ID（可选）">
                                <button class="btn btn-sm btn-primary" type="submit">挂接</button>
                            </form>
                        </div>
                        <div class="col-md-4">
                            <form method="post" action="<?= $esc($mosGovRootPath . '/identity/' . (int) $row['id'] . '/assign-scope') ?>">
                                <?= $csrfField ?>
                                <label class="form-label">指派范围</label>
                                <select class="form-select mb-2" name="scope_type" required>
                                    <?php foreach ($scopeTypes as $st): ?>
                                        <option value="<?= $esc($st) ?>"><?= $esc($mosGovValue($st)) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <input class="form-control mb-2" type="text" name="scope_id" placeholder="范围 ID（global/church 无需填写）">
                                <button class="btn btn-sm btn-primary" type="submit">指派</button>
                            </form>
                        </div>
                        <div class="col-md-4">
                            <form method="post" action="<?= $esc($mosGovRootPath . '/identity/' . (int) $row['id'] . '/override-permission') ?>">
                                <?= $csrfField ?>
                                <label class="form-label">权限覆盖</label>
                                <select class="form-select mb-2" name="permission_id" required>
                                    <?php foreach ($permissionLabels as $pid => $key): ?>
                                        <option value="<?= (int) $pid ?>"><?= $esc($key) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <select class="form-select mb-2" name="grant_mode">
                                    <?php foreach ($grantModes as $gm): ?>
                                        <option value="<?= $esc($gm) ?>"><?= $esc($mosGovValue($gm)) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <input class="form-control mb-2" type="text" name="reason" placeholder="事由（必填）" maxlength="255">
                                <button class="btn btn-sm btn-primary" type="submit">应用</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/../../../../Include/Footer.php'; ?>
