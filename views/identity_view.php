<?php

/**
 * Governance identity detail (V0.2): roles, scopes, explicit overrides.
 *
 * Expected variables:
 * - $row, $personLabel, $identityRoles, $identityScopes, $overrides
 * - $roleLabels, $appointmentLabels, $permissionLabels
 * - $scopeTypes, $grantModes
 * - $canEdit, $csrfField, $esc
 */

use ChurchCRM\dto\SystemURLs;

$mosGovRootPath = SystemURLs::getRootPath() . '/plugins/mos-gov';

$sPageTitle = 'Governance identity #' . (int) $row['id'];
$sPageSubtitle = 'Roles, scope and explicit permissions';
$aBreadcrumbs = [
    ['label' => 'Plugins', 'url' => SystemURLs::getRootPath() . '/plugins/management'],
    ['label' => 'MOS-GOV', 'url' => $mosGovRootPath],
    ['label' => 'Identities', 'url' => $mosGovRootPath . '/identity'],
    ['label' => '#' . (int) $row['id'], 'active' => true],
];

require __DIR__ . '/../../../../Include/Header.php';

$activeSlug = 'identity';
require __DIR__ . '/_tabs.php';

// P5 protection: identity notes never render inline without an explicit grant.
$notesVisible = false;
?>

<div class="row g-3">
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header"><h3 class="card-title">Identity</h3></div>
            <div class="card-body">
                <div class="datagrid">
                    <div class="datagrid-item">
                        <div class="datagrid-title">Person</div>
                        <div class="datagrid-content"><?= $esc($personLabel) ?></div>
                    </div>
                    <div class="datagrid-item">
                        <div class="datagrid-title">Status</div>
                        <div class="datagrid-content"><?= $esc($row['identity_status']) ?></div>
                    </div>
                    <div class="datagrid-item">
                        <div class="datagrid-title">Member since</div>
                        <div class="datagrid-content"><?= $esc($row['member_since'] ?? '—') ?></div>
                    </div>
                    <div class="datagrid-item">
                        <div class="datagrid-title">Notes</div>
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
            <div class="card-header"><h3 class="card-title">Roles</h3></div>
            <div class="card-body">
                <?php if ($identityRoles === []): ?>
                    <p class="text-secondary mb-0">No roles attached.</p>
                <?php else: ?>
                    <table class="table table-sm table-vcenter">
                        <thead><tr><th>Role</th><th>Appointment</th><th>Status</th><th>Start</th><th>End</th></tr></thead>
                        <tbody>
                        <?php foreach ($identityRoles as $ir): ?>
                            <tr>
                                <td><?= $esc($roleLabels[(int) $ir['role_id']] ?? ('Role #' . (int) $ir['role_id'])) ?></td>
                                <td><?= $esc($ir['appointment_id'] !== null ? ($appointmentLabels[(int) $ir['appointment_id']] ?? ('#' . (int) $ir['appointment_id'])) : '—') ?></td>
                                <td><span class="badge <?= $ir['status'] === 'active' ? 'bg-success-lt' : 'bg-secondary-lt' ?>"><?= $esc($ir['status']) ?></span></td>
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
            <div class="card-header"><h3 class="card-title">Governance scope</h3></div>
            <div class="card-body">
                <?php if ($identityScopes === []): ?>
                    <p class="text-secondary mb-0">No explicit scope rows.</p>
                <?php else: ?>
                    <table class="table table-sm table-vcenter">
                        <thead><tr><th>Type</th><th>ID</th><th>Source</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php foreach ($identityScopes as $scope): ?>
                            <tr>
                                <td><code><?= $esc($scope['scope_type']) ?></code></td>
                                <td><?= $esc($scope['scope_id'] ?? '—') ?></td>
                                <td><?= $esc($scope['source_type']) ?></td>
                                <td><?= $esc($scope['status']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header"><h3 class="card-title">Explicit permission overrides</h3></div>
            <div class="card-body">
                <?php if ($overrides === []): ?>
                    <p class="text-secondary mb-0">No explicit overrides. Overrides cannot bypass the security boundary.</p>
                <?php else: ?>
                    <table class="table table-sm table-vcenter">
                        <thead><tr><th>Permission</th><th>Mode</th><th>Reason</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php foreach ($overrides as $ov): ?>
                            <tr>
                                <td><code><?= $esc($permissionLabels[(int) $ov['permission_id']] ?? ('#' . (int) $ov['permission_id'])) ?></code></td>
                                <td><span class="badge <?= $ov['grant_mode'] === 'deny' ? 'bg-danger-lt' : 'bg-success-lt' ?>"><?= $esc($ov['grant_mode']) ?></span></td>
                                <td><?= $esc($ov['reason'] ?? '') ?></td>
                                <td><?= $esc($ov['status']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!empty($canEdit)): ?>
            <div class="card">
                <div class="card-header"><h3 class="card-title">Manage</h3></div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <form method="post" action="<?= $esc($mosGovRootPath . '/identity/' . (int) $row['id'] . '/attach-role') ?>">
                                <?= $csrfField ?>
                                <label class="form-label">Attach role</label>
                                <select class="form-select mb-2" name="role_id" required>
                                    <?php foreach ($roleLabels as $rid => $label): ?>
                                        <option value="<?= (int) $rid ?>"><?= $esc($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <input class="form-control mb-2" type="text" name="appointment_id" placeholder="Appointment ID (optional)">
                                <button class="btn btn-sm btn-primary" type="submit">Attach</button>
                            </form>
                        </div>
                        <div class="col-md-4">
                            <form method="post" action="<?= $esc($mosGovRootPath . '/identity/' . (int) $row['id'] . '/assign-scope') ?>">
                                <?= $csrfField ?>
                                <label class="form-label">Assign scope</label>
                                <select class="form-select mb-2" name="scope_type" required>
                                    <?php foreach ($scopeTypes as $st): ?>
                                        <option value="<?= $esc($st) ?>"><?= $esc($st) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <input class="form-control mb-2" type="text" name="scope_id" placeholder="Scope ID (not for global/church)">
                                <button class="btn btn-sm btn-primary" type="submit">Assign</button>
                            </form>
                        </div>
                        <div class="col-md-4">
                            <form method="post" action="<?= $esc($mosGovRootPath . '/identity/' . (int) $row['id'] . '/override-permission') ?>">
                                <?= $csrfField ?>
                                <label class="form-label">Permission override</label>
                                <select class="form-select mb-2" name="permission_id" required>
                                    <?php foreach ($permissionLabels as $pid => $key): ?>
                                        <option value="<?= (int) $pid ?>"><?= $esc($key) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <select class="form-select mb-2" name="grant_mode">
                                    <?php foreach ($grantModes as $gm): ?>
                                        <option value="<?= $esc($gm) ?>"><?= $esc($gm) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <input class="form-control mb-2" type="text" name="reason" placeholder="Reason (required)" maxlength="255">
                                <button class="btn btn-sm btn-primary" type="submit">Apply</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/../../../../Include/Footer.php'; ?>
