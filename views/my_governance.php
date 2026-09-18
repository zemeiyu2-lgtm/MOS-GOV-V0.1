<?php

/**
 * 我的治理中心 — V0.2 核心页面 (design §26/§27).
 *
 * 依次回答：
 *   我是谁 → 我承担什么角色 → 我被托付什么 → 我负责什么范围
 *     → 我能看到什么 → 我能做什么 → 我现在要完成什么 → 我向谁负责
 *
 * 「相关文件 / 培训 / 问责」在 V0.2 为稳定容器（子系统后续版本接入）。
 *
 * Expected variables:
 * - $ctx   GovernanceContext|null — null when the user has no identity
 * - $data  MyGovernanceService::build() result, or null
 * - $esc   HTML-escaping closure
 */

use ChurchCRM\dto\SystemURLs;

$mosGovRootPath = SystemURLs::getRootPath() . '/plugins/mos-gov';

require __DIR__ . '/_i18n.php';

$sPageTitle = '我的治理中心';
$sPageSubtitle = '我的身份、我的托付、我的范围';
$aBreadcrumbs = [
    ['label' => '插件', 'url' => SystemURLs::getRootPath() . '/plugins/management'],
    ['label' => 'MOS-GOV', 'url' => $mosGovRootPath],
    ['label' => '我的治理中心', 'active' => true],
];

require __DIR__ . '/../../../../Include/Header.php';

$activeSlug = 'my-governance';
require __DIR__ . '/_tabs.php';
?>

<?php if ($ctx === null): ?>
    <div class="card">
        <div class="card-body">
            <h3 class="card-title">尚无治理身份</h3>
            <p class="text-secondary mb-0">
                你的账号当前没有生效的 MOS-GOV 治理身份。治理身份由教会授予，
                与你的 ChurchCRM 登录账号相互独立。如你认为这是错误，请联系治理管理员。
            </p>
        </div>
    </div>
<?php else: ?>

    <div class="row g-3">
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header"><h3 class="card-title">1. 我是谁</h3></div>
                <div class="card-body">
                    <div class="datagrid">
                        <div class="datagrid-item">
                            <div class="datagrid-title">姓名</div>
                            <div class="datagrid-content">
                                <?= $esc($data['identity']['display_name_override'] ?? $data['person']['fullName'] ?? ('人员 #' . $ctx->personId())) ?>
                            </div>
                        </div>
                        <div class="datagrid-item">
                            <div class="datagrid-title">治理身份</div>
                            <div class="datagrid-content">#<?= (int) $ctx->identityId() ?>（<?= $esc($mosGovValue($data['identity']['identity_status'] ?? 'active')) ?>）</div>
                        </div>
                        <div class="datagrid-item">
                            <div class="datagrid-title">加入时间</div>
                            <div class="datagrid-content"><?= $esc($data['identity']['member_since'] ?? '—') ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header"><h3 class="card-title">2. 我的角色与任命</h3></div>
                <div class="card-body">
                    <?php if ($data['roles'] === []): ?>
                        <p class="text-secondary mb-0">尚未挂接治理角色。</p>
                    <?php else: ?>
                        <table class="table table-sm table-vcenter">
                            <thead><tr><th>角色</th><th>角色编码</th><th>任命</th><th>状态</th></tr></thead>
                            <tbody>
                            <?php foreach ($data['roles'] as $role): ?>
                                <tr>
                                    <td><?= $esc($mosGovT($role['role_name'])) ?></td>
                                    <td><code><?= $esc($role['role_code']) ?></code></td>
                                    <td><?= $role['appointment_id'] !== null ? '#' . (int) $role['appointment_id'] : '—' ?></td>
                                    <td><?= $role['active'] ? '<span class="badge bg-success-lt">有效</span>' : '<span class="badge bg-secondary-lt">停用</span>' ?></td>
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
                <div class="card-header"><h3 class="card-title">3. 我被托付什么</h3></div>
                <div class="card-body">
                    <?php if ($data['responsibilities'] === []): ?>
                        <p class="text-secondary mb-0">暂无职责记录。</p>
                    <?php else: ?>
                        <ul class="list-unstyled mb-0">
                            <?php foreach (array_slice($data['responsibilities'], 0, 12) as $resp): ?>
                                <li class="mb-1">
                                    <strong><?= $esc($resp['title']) ?></strong>
                                    <span class="badge bg-info-lt ms-1"><?= $esc($mosGovValue($resp['via'])) ?></span>
                                    <span class="badge bg-secondary-lt"><?= $esc($mosGovValue($resp['priority'])) ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header"><h3 class="card-title">4. 我的治理范围</h3></div>
                <div class="card-body">
                    <?php if ($data['scopes'] === []): ?>
                        <p class="text-secondary mb-0">未指派明确范围。</p>
                    <?php else: ?>
                        <ul class="list-unstyled mb-0">
                            <?php foreach ($data['scopes'] as $scope): ?>
                                <li class="mb-1">
                                    <span class="badge bg-blue-lt"><?= $esc($mosGovScopeLabel($scope['label'])) ?></span>
                                    <span class="text-secondary small">来源：<?= $esc($mosGovValue($scope['source_type'])) ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header"><h3 class="card-title">5–6. 我能看到什么、能做什么</h3></div>
                <div class="card-body">
                    <div class="datagrid">
                        <?php foreach ($data['permissions'] as $resourceType => $perms): ?>
                            <div class="datagrid-item">
                                <div class="datagrid-title"><?= $esc($mosGovT(ucfirst($resourceType))) ?></div>
                                <div class="datagrid-content">
                                    <?php foreach ($perms as $p): ?>
                                        <span class="badge bg-secondary-lt me-1"><?= $esc($mosGovValue($p['action'])) ?></span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <?php if ($data['permissions'] === []): ?>
                            <div class="datagrid-item">
                                <div class="datagrid-title">权限</div>
                                <div class="datagrid-content text-secondary">当前没有生效的治理权限。</div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header d-flex align-items-center">
                    <h3 class="card-title">7. 我现在要完成什么</h3>
                    <div class="ms-auto">
                        <a class="btn btn-sm btn-outline-primary" href="<?= $esc($mosGovRootPath . '/tasks') ?>">全部任务</a>
                    </div>
                </div>
                <div class="card-body">
                    <?php if ($data['open_tasks'] === []): ?>
                        <p class="text-secondary mb-0">没有指派给你的待办任务。</p>
                    <?php else: ?>
                        <table class="table table-sm table-vcenter">
                            <thead><tr><th>任务</th><th>截止</th><th>状态</th></tr></thead>
                            <tbody>
                            <?php foreach ($data['open_tasks'] as $task): ?>
                                <tr>
                                    <td><a href="<?= $esc($mosGovRootPath . '/tasks/' . (int) $task['id']) ?>"><?= $esc($task['title']) ?></a></td>
                                    <td><?= $esc($task['due_date'] ?? '—') ?></td>
                                    <td><span class="badge bg-warning-lt"><?= $esc($mosGovValue($task['status'])) ?></span></td>
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
                <div class="card-header"><h3 class="card-title">8. 我向谁负责</h3></div>
                <div class="card-body">
                    <?php if ($data['bodies'] === []): ?>
                        <p class="text-secondary mb-0">你的角色尚未关联治理主体。</p>
                    <?php else: ?>
                        <ul class="list-unstyled mb-0">
                            <?php foreach ($data['bodies'] as $body): ?>
                                <li class="mb-1">
                                    <a href="<?= $esc($mosGovRootPath . '/bodies/' . (int) $body['id']) ?>"><?= $esc($mosGovT($body['name'])) ?></a>
                                    <?php if (!empty($body['body_type'])): ?>
                                        <span class="badge bg-secondary-lt"><?= $esc($mosGovValue($body['body_type'])) ?></span>
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
                <div class="card-header"><h3 class="card-title">9. 相关文件 · 培训 · 问责</h3></div>
                <div class="card-body">
                    <p class="text-secondary small">
                        V0.2 预留的稳定容器——治理文件、培训记录与问责复核子系统将在后续版本接入此处。
                    </p>
                    <div class="datagrid">
                        <div class="datagrid-item">
                            <div class="datagrid-title">相关文件</div>
                            <div class="datagrid-content text-secondary">暂无文件。</div>
                        </div>
                        <div class="datagrid-item">
                            <div class="datagrid-title">培训</div>
                            <div class="datagrid-content text-secondary">暂无培训记录。</div>
                        </div>
                        <div class="datagrid-item">
                            <div class="datagrid-title">问责</div>
                            <div class="datagrid-content text-secondary">暂无问责复核记录。</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

<?php endif; ?>

<?php require __DIR__ . '/../../../../Include/Footer.php'; ?>
