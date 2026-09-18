<?php

/**
 * 权限注册表 + 角色权限矩阵 (V0.2 §12).
 *
 * 注册表是封闭白名单——本页只读；覆盖项按身份逐个管理。
 * 系统内不可能存在白名单之外的权限键。
 *
 * Expected variables: $permissions, $roles, $rolePermissions, $canManage, $esc
 */

use ChurchCRM\dto\SystemURLs;

$mosGovRootPath = SystemURLs::getRootPath() . '/plugins/mos-gov';

require __DIR__ . '/_i18n.php';

$sPageTitle = '权限注册表';
$sPageSubtitle = '系统权限白名单与角色默认权限';
$aBreadcrumbs = [
    ['label' => '插件', 'url' => SystemURLs::getRootPath() . '/plugins/management'],
    ['label' => 'MOS-GOV', 'url' => $mosGovRootPath],
    ['label' => '权限注册表', 'active' => true],
];

require __DIR__ . '/../../../../Include/Header.php';

$activeSlug = 'permissions';
require __DIR__ . '/_tabs.php';
require __DIR__ . '/_style.php';

$riskClass = ['low' => 'bg-secondary-lt', 'medium' => 'bg-info-lt', 'high' => 'bg-warning-lt', 'critical' => 'bg-danger-lt'];
?>

<div class="card mb-3">
    <div class="card-header"><h3 class="card-title">权限注册表（<?= count($permissions) ?>）</h3></div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm table-vcenter">
                <thead><tr><th>权限键</th><th>资源</th><th>动作</th><th>风险</th><th>状态</th></tr></thead>
                <tbody>
                <?php foreach ($permissions as $p): ?>
                    <tr>
                        <td><code><?= $esc($p['permission_key']) ?></code></td>
                        <td><?= $esc($mosGovT(ucfirst((string) $p['resource_type']))) ?></td>
                        <td><?= $esc($mosGovValue($p['action'])) ?></td>
                        <td><span class="badge <?= $riskClass[$p['risk_level']] ?? 'bg-secondary-lt' ?>"><?= $esc($mosGovValue($p['risk_level'])) ?></span></td>
                        <td><?= $esc($mosGovValue($p['status'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="text-secondary small mb-0">
            权限键来自系统封闭白名单；任何页面或接口都无法创建白名单之外的权限键。
        </p>
    </div>
</div>

<div class="card">
    <div class="card-header"><h3 class="card-title">角色默认权限</h3></div>
    <div class="card-body">
        <?php if ($roles === []): ?>
            <p class="text-secondary mb-0">尚未定义治理角色。</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-vcenter">
                    <thead><tr><th>角色</th><th>角色编码</th><th>默认权限</th></tr></thead>
                    <tbody>
                    <?php foreach ($roles as $roleId => $role): ?>
                        <?php
                        $granted = $rolePermissions[$roleId] ?? [];
                        $grantedKeys = [];
                        foreach ($permissions as $p) {
                            if (in_array((int) $p['id'], $granted, true)) {
                                $grantedKeys[] = $p['permission_key'];
                            }
                        }
                        ?>
                        <tr>
                            <td><?= $esc($mosGovT($role['name'])) ?></td>
                            <td><code><?= $esc($role['role_code']) ?></code></td>
                            <td>
                                <?php if ($grantedKeys === []): ?>
                                    <span class="text-secondary">—</span>
                                <?php else: ?>
                                    <?php foreach ($grantedKeys as $key): ?>
                                        <span class="badge bg-secondary-lt me-1 mb-1"><?= $esc($key) ?></span>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/../../../../Include/Footer.php'; ?>
