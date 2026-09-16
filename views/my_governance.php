<?php

/**
 * My Governance Center — the V0.2 core page (design §26/§27).
 *
 * Answers, in order:
 *   我是谁 → 我承担什么角色 → 我被托付什么 → 我负责什么范围
 *     → 我能看到什么 → 我能做什么 → 我现在要完成什么 → 我向谁负责
 *
 * "相关文件 / 培训 / 问责" are stable containers in V0.2 (no subsystem yet).
 *
 * Expected variables:
 * - $ctx   GovernanceContext|null — null when the user has no identity
 * - $data  MyGovernanceService::build() result, or null
 * - $esc   HTML-escaping closure
 */

use ChurchCRM\dto\SystemURLs;

$mosGovRootPath = SystemURLs::getRootPath() . '/plugins/mos-gov';

$sPageTitle = 'My Governance';
$sPageSubtitle = 'My identity, my responsibility, my scope';
$aBreadcrumbs = [
    ['label' => 'Plugins', 'url' => SystemURLs::getRootPath() . '/plugins/management'],
    ['label' => 'MOS-GOV', 'url' => $mosGovRootPath],
    ['label' => 'My Governance', 'active' => true],
];

require __DIR__ . '/../../../../Include/Header.php';

$activeSlug = 'my-governance';
require __DIR__ . '/_tabs.php';
?>

<?php if ($ctx === null): ?>
    <div class="card">
        <div class="card-body">
            <h3 class="card-title">No governance identity</h3>
            <p class="text-secondary">
                Your account does not currently have an active MOS-GOV governance
                identity. Governance identity is granted by the church — it is
                separate from your ChurchCRM login. Please contact your governance
                administrator if you believe this is an error.
            </p>
        </div>
    </div>
<?php else: ?>

    <div class="row g-3">
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header"><h3 class="card-title">1. Who I am</h3></div>
                <div class="card-body">
                    <div class="datagrid">
                        <div class="datagrid-item">
                            <div class="datagrid-title">Name</div>
                            <div class="datagrid-content">
                                <?= $esc($data['identity']['display_name_override'] ?? $data['person']['fullName'] ?? ('Person #' . $ctx->personId())) ?>
                            </div>
                        </div>
                        <div class="datagrid-item">
                            <div class="datagrid-title">Governance identity</div>
                            <div class="datagrid-content">#<?= (int) $ctx->identityId() ?> (<?= $esc($data['identity']['identity_status'] ?? 'active') ?>)</div>
                        </div>
                        <div class="datagrid-item">
                            <div class="datagrid-title">Member since</div>
                            <div class="datagrid-content"><?= $esc($data['identity']['member_since'] ?? '—') ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header"><h3 class="card-title">2. My roles &amp; appointments</h3></div>
                <div class="card-body">
                    <?php if ($data['roles'] === []): ?>
                        <p class="text-secondary mb-0">No governance roles attached.</p>
                    <?php else: ?>
                        <table class="table table-sm table-vcenter">
                            <thead><tr><th>Role</th><th>Code</th><th>Appointment</th><th>Status</th></tr></thead>
                            <tbody>
                            <?php foreach ($data['roles'] as $role): ?>
                                <tr>
                                    <td><?= $esc($role['role_name']) ?></td>
                                    <td><code><?= $esc($role['role_code']) ?></code></td>
                                    <td><?= $role['appointment_id'] !== null ? '#' . (int) $role['appointment_id'] : '—' ?></td>
                                    <td><?= $role['active'] ? '<span class="badge bg-success-lt">active</span>' : '<span class="badge bg-secondary-lt">inactive</span>' ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header"><h3 class="card-title">3. What I am entrusted with</h3></div>
                <div class="card-body">
                    <?php if ($data['responsibilities'] === []): ?>
                        <p class="text-secondary mb-0">No responsibilities recorded.</p>
                    <?php else: ?>
                        <ul class="list-unstyled mb-0">
                            <?php foreach (array_slice($data['responsibilities'], 0, 12) as $resp): ?>
                                <li class="mb-1">
                                    <strong><?= $esc($resp['title']) ?></strong>
                                    <span class="badge bg-info-lt ms-1"><?= $esc($resp['via']) ?></span>
                                    <span class="badge bg-secondary-lt"><?= $esc($resp['priority']) ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header"><h3 class="card-title">4. My governance scope</h3></div>
                <div class="card-body">
                    <?php if ($data['scopes'] === []): ?>
                        <p class="text-secondary mb-0">No explicit scope assigned.</p>
                    <?php else: ?>
                        <ul class="list-unstyled mb-0">
                            <?php foreach ($data['scopes'] as $scope): ?>
                                <li class="mb-1">
                                    <span class="badge bg-blue-lt"><?= $esc($scope['label']) ?></span>
                                    <span class="text-secondary small">via <?= $esc($scope['source_type']) ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header"><h3 class="card-title">5–6. What I can see &amp; do</h3></div>
                <div class="card-body">
                    <div class="datagrid">
                        <?php foreach ($data['permissions'] as $resourceType => $perms): ?>
                            <div class="datagrid-item">
                                <div class="datagrid-title"><?= $esc(ucfirst($resourceType)) ?></div>
                                <div class="datagrid-content">
                                    <?php foreach ($perms as $p): ?>
                                        <span class="badge bg-secondary-lt me-1"><?= $esc($p['action']) ?></span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <?php if ($data['permissions'] === []): ?>
                            <div class="datagrid-item">
                                <div class="datagrid-title">Permissions</div>
                                <div class="datagrid-content text-secondary">No active governance permissions.</div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header d-flex align-items-center">
                    <h3 class="card-title">7. What I must complete now</h3>
                    <div class="ms-auto">
                        <a class="btn btn-sm btn-outline-primary" href="<?= $esc($mosGovRootPath . '/tasks') ?>">All tasks</a>
                    </div>
                </div>
                <div class="card-body">
                    <?php if ($data['open_tasks'] === []): ?>
                        <p class="text-secondary mb-0">No open tasks assigned to you.</p>
                    <?php else: ?>
                        <table class="table table-sm table-vcenter">
                            <thead><tr><th>Task</th><th>Due</th><th>Status</th></tr></thead>
                            <tbody>
                            <?php foreach ($data['open_tasks'] as $task): ?>
                                <tr>
                                    <td><a href="<?= $esc($mosGovRootPath . '/tasks/' . (int) $task['id']) ?>"><?= $esc($task['title']) ?></a></td>
                                    <td><?= $esc($task['due_date'] ?? '—') ?></td>
                                    <td><span class="badge bg-warning-lt"><?= $esc($task['status']) ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header"><h3 class="card-title">8. Who I answer to</h3></div>
                <div class="card-body">
                    <?php if ($data['bodies'] === []): ?>
                        <p class="text-secondary mb-0">No governing body linked to your roles.</p>
                    <?php else: ?>
                        <ul class="list-unstyled mb-0">
                            <?php foreach ($data['bodies'] as $body): ?>
                                <li class="mb-1">
                                    <a href="<?= $esc($mosGovRootPath . '/bodies/' . (int) $body['id']) ?>"><?= $esc($body['name']) ?></a>
                                    <?php if (!empty($body['body_type'])): ?>
                                        <span class="badge bg-secondary-lt"><?= $esc($body['body_type']) ?></span>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header"><h3 class="card-title">9. Files · Training · Accountability</h3></div>
                <div class="card-body">
                    <p class="text-secondary small">
                        Stable containers reserved in V0.2 — the subsystems for
                        governance documents, training records and accountability
                        reviews attach here in later versions.
                    </p>
                    <div class="datagrid">
                        <div class="datagrid-item">
                            <div class="datagrid-title">Related files</div>
                            <div class="datagrid-content text-secondary">No documents yet.</div>
                        </div>
                        <div class="datagrid-item">
                            <div class="datagrid-title">Training</div>
                            <div class="datagrid-content text-secondary">No training records yet.</div>
                        </div>
                        <div class="datagrid-item">
                            <div class="datagrid-title">Accountability</div>
                            <div class="datagrid-content text-secondary">No accountability reviews yet.</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

<?php endif; ?>

<?php require __DIR__ . '/../../../../Include/Footer.php'; ?>
