<?php

/**
 * Permission registry + role permission matrix (V0.2 §12).
 *
 * The registry is a closed whitelist — this page is read-only; overrides are
 * managed per identity. Unknown permission keys cannot exist in the system.
 *
 * Expected variables: $permissions, $roles, $rolePermissions, $canManage, $esc
 */

use ChurchCRM\dto\SystemURLs;

$mosGovRootPath = SystemURLs::getRootPath() . '/plugins/mos-gov';

$sPageTitle = 'Permission Registry';
$sPageSubtitle = 'System permission whitelist and role defaults';
$aBreadcrumbs = [
    ['label' => 'Plugins', 'url' => SystemURLs::getRootPath() . '/plugins/management'],
    ['label' => 'MOS-GOV', 'url' => $mosGovRootPath],
    ['label' => 'Permissions', 'active' => true],
];

require __DIR__ . '/../../../../Include/Header.php';

$activeSlug = 'permissions';
require __DIR__ . '/_tabs.php';

$riskClass = ['low' => 'bg-secondary-lt', 'medium' => 'bg-info-lt', 'high' => 'bg-warning-lt', 'critical' => 'bg-danger-lt'];
?>

<div class="card mb-3">
    <div class="card-header"><h3 class="card-title">Permission registry (<?= count($permissions) ?>)</h3></div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm table-vcenter">
                <thead><tr><th>Key</th><th>Resource</th><th>Action</th><th>Risk</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($permissions as $p): ?>
                    <tr>
                        <td><code><?= $esc($p['permission_key']) ?></code></td>
                        <td><?= $esc($p['resource_type']) ?></td>
                        <td><?= $esc($p['action']) ?></td>
                        <td><span class="badge <?= $riskClass[$p['risk_level']] ?? 'bg-secondary-lt' ?>"><?= $esc($p['risk_level']) ?></span></td>
                        <td><?= $esc($p['status']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="text-secondary small mb-0">
            Keys come from the closed system whitelist. Administrators cannot
            create unknown permission keys through any page or API.
        </p>
    </div>
</div>

<div class="card">
    <div class="card-header"><h3 class="card-title">Role default permissions</h3></div>
    <div class="card-body">
        <?php if ($roles === []): ?>
            <p class="text-secondary mb-0">No governance roles defined yet.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-vcenter">
                    <thead><tr><th>Role</th><th>Code</th><th>Default permissions</th></tr></thead>
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
                            <td><?= $esc($role['name']) ?></td>
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
