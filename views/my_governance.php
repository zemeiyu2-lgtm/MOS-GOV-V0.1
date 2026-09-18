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

<style>
    .mos-gov-center .gov-section-card {
        border: 1px solid rgba(98, 105, 118, .16);
        box-shadow: 0 1px 2px rgba(0, 0, 0, .04);
        border-radius: 12px;
        overflow: hidden;
        background: #fff;
    }
    .mos-gov-center .gov-section-card .card-header {
        background: #fafbfc;
        border-bottom: 1px solid rgba(98, 105, 118, .12);
        padding: .9rem 1.1rem;
    }
    .mos-gov-center .gov-section-card .card-body {
        padding: 1.1rem;
    }
    .mos-gov-center .gov-section-title {
        display: flex;
        align-items: center;
        gap: .65rem;
        margin: 0;
        font-size: 1rem;
        font-weight: 700;
    }
    .mos-gov-center .gov-section-no {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 2rem;
        height: 2rem;
        padding: 0 .45rem;
        border-radius: 999px;
        background: #e9f2ff;
        color: #206bc4;
        font-size: .82rem;
    }
    .mos-gov-center .gov-kicker {
        color: #667382;
        font-size: .78rem;
        letter-spacing: .04em;
        text-transform: uppercase;
        margin-bottom: .3rem;
    }
    .mos-gov-center .gov-value {
        font-size: 1.12rem;
        font-weight: 650;
    }
    .mos-gov-center .gov-list {
        display: grid;
        gap: .65rem;
    }
    .mos-gov-center .gov-item {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 1rem;
        padding: .75rem .85rem;
        border: 1px solid rgba(98, 105, 118, .12);
        border-radius: 10px;
        background: #fff;
    }
    .mos-gov-center .gov-item-main {
        min-width: 0;
    }
    .mos-gov-center .gov-item-title {
        font-weight: 600;
        line-height: 1.45;
    }
    .mos-gov-center .gov-item-meta {
        margin-top: .18rem;
        color: #667382;
        font-size: .82rem;
    }
    .mos-gov-center .gov-chip-row {
        display: flex;
        flex-wrap: wrap;
        gap: .35rem;
        align-items: center;
    }
    .mos-gov-center .gov-chip {
        display: inline-flex;
        align-items: center;
        gap: .25rem;
        padding: .28rem .55rem;
        border-radius: 999px;
        font-size: .78rem;
        line-height: 1;
    }
    .mos-gov-center .gov-summary {
        border: 1px solid rgba(32, 107, 196, .14);
        background: linear-gradient(180deg, #f6faff 0%, #fff 100%);
        border-radius: 12px;
        padding: 1rem 1.1rem;
        margin-bottom: 1rem;
    }
    .mos-gov-center .gov-summary-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: .8rem;
    }
    .mos-gov-center .gov-summary-box {
        padding: .8rem .9rem;
        border-radius: 10px;
        background: rgba(255,255,255,.86);
        border: 1px solid rgba(98, 105, 118, .10);
    }
    .mos-gov-center .gov-permission-group {
        border: 1px solid rgba(98, 105, 118, .12);
        border-radius: 10px;
        padding: .75rem .85rem;
        margin-bottom: .65rem;
    }
    .mos-gov-center .gov-permission-group:last-child {
        margin-bottom: 0;
    }
    .mos-gov-center .gov-permission-name {
        font-weight: 600;
        margin-bottom: .45rem;
    }
    .mos-gov-center .gov-empty {
        color: #667382;
        padding: .25rem 0;
    }
    @media (max-width: 767.98px) {
        .mos-gov-center .gov-summary-grid {
            grid-template-columns: 1fr;
        }
        .mos-gov-center .gov-item {
            flex-direction: column;
        }
    }
</style>

<?php if ($ctx === null): ?>
    <div class="card gov-section-card">
        <div class="card-body">
            <h3 class="card-title mb-2">尚无治理身份</h3>
            <p class="text-secondary mb-0">
                你的账号当前没有生效的 MOS-GOV 治理身份。治理身份由教会授予，
                与你的 ChurchCRM 登录账号相互独立。如你认为这是错误，请联系治理管理员。
            </p>
        </div>
    </div>
<?php else: ?>

<div class="mos-gov-center">
    <div class="gov-summary">
        <div class="gov-chip-row mb-2">
            <span class="badge bg-blue-lt">治理身份</span>
            <span class="badge bg-success-lt"><?= $esc($mosGovValue($data['identity']['identity_status'] ?? 'active')) ?></span>
            <span class="text-secondary small">#<?= (int) $ctx->identityId() ?></span>
        </div>
        <div class="gov-value mb-3">
            <?= $esc($data['identity']['display_name_override'] ?? $data['person']['fullName'] ?? ('人员 #' . $ctx->personId())) ?>
        </div>
        <div class="gov-summary-grid">
            <div class="gov-summary-box">
                <div class="gov-kicker">我的角色</div>
                <div class="gov-value fs-3">
                    <?= count($data['roles']) ?>
                    <span class="fs-6 text-secondary">个角色</span>
                </div>
            </div>
            <div class="gov-summary-box">
                <div class="gov-kicker">我的范围</div>
                <div class="gov-value fs-3">
                    <?= count($data['scopes']) ?>
                    <span class="fs-6 text-secondary">个有效范围</span>
                </div>
            </div>
            <div class="gov-summary-box">
                <div class="gov-kicker">我的待办</div>
                <div class="gov-value fs-3">
                    <?= count($data['open_tasks']) ?>
                    <span class="fs-6 text-secondary">项待处理</span>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-6">
            <div class="card h-100 gov-section-card">
                <div class="card-header">
                    <h3 class="gov-section-title"><span class="gov-section-no">1</span>我是谁</h3>
                </div>
                <div class="card-body">
                    <div class="gov-list">
                        <div class="gov-item">
                            <div class="gov-item-main">
                                <div class="gov-kicker">姓名</div>
                                <div class="gov-item-title">
                                    <?= $esc($data['identity']['display_name_override'] ?? $data['person']['fullName'] ?? ('人员 #' . $ctx->personId())) ?>
                                </div>
                            </div>
                        </div>
                        <div class="gov-item">
                            <div class="gov-item-main">
                                <div class="gov-kicker">治理身份</div>
                                <div class="gov-item-title">#<?= (int) $ctx->identityId() ?></div>
                            </div>
                            <span class="badge bg-success-lt"><?= $esc($mosGovValue($data['identity']['identity_status'] ?? 'active')) ?></span>
                        </div>
                        <div class="gov-item">
                            <div class="gov-item-main">
                                <div class="gov-kicker">加入时间</div>
                                <div class="gov-item-title"><?= $esc($data['identity']['member_since'] ?? '—') ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card h-100 gov-section-card">
                <div class="card-header">
                    <h3 class="gov-section-title"><span class="gov-section-no">2</span>我的角色与任命</h3>
                </div>
                <div class="card-body">
                    <?php if ($data['roles'] === []): ?>
                        <div class="gov-empty">尚未挂接治理角色。</div>
                    <?php else: ?>
                        <div class="gov-list">
                            <?php foreach ($data['roles'] as $role): ?>
                                <div class="gov-item">
                                    <div class="gov-item-main">
                                        <div class="gov-item-title"><?= $esc($mosGovT($role['role_name'])) ?></div>
                                        <div class="gov-item-meta">
                                            角色编码：<code><?= $esc($role['role_code']) ?></code>
                                            <?php if ($role['appointment_id'] !== null): ?>
                                                · 任命 #<?= (int) $role['appointment_id'] ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <span class="badge <?= $role['active'] ? 'bg-success-lt' : 'bg-secondary-lt' ?>">
                                        <?= $role['active'] ? '有效' : '停用' ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card h-100 gov-section-card">
                <div class="card-header">
                    <h3 class="gov-section-title"><span class="gov-section-no">3</span>我被托付什么</h3>
                </div>
                <div class="card-body">
                    <?php if ($data['responsibilities'] === []): ?>
                        <div class="gov-empty">暂无职责记录。</div>
                    <?php else: ?>
                        <div class="gov-list">
                            <?php foreach (array_slice($data['responsibilities'], 0, 12) as $resp): ?>
                                <div class="gov-item">
                                    <div class="gov-item-main">
                                        <div class="gov-item-title"><?= $esc($resp['title']) ?></div>
                                        <div class="gov-item-meta">
                                            <?php
                                            $viaLabel = $mosGovValue($resp['via']);
                                            $priorityLabel = $mosGovValue($resp['priority']);
                                            ?>
                                            <span class="gov-chip bg-info-lt"><?= $esc($viaLabel) ?></span>
                                            <span class="gov-chip bg-secondary-lt">优先级：<?= $esc($priorityLabel) ?></span>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card h-100 gov-section-card">
                <div class="card-header">
                    <h3 class="gov-section-title"><span class="gov-section-no">4</span>我的治理范围</h3>
                </div>
                <div class="card-body">
                    <?php if ($data['scopes'] === []): ?>
                        <div class="gov-empty">未指派明确范围。</div>
                    <?php else: ?>
                        <div class="gov-list">
                            <?php foreach ($data['scopes'] as $scope): ?>
                                <div class="gov-item">
                                    <div class="gov-item-main">
                                        <div class="gov-item-title"><?= $esc($mosGovScopeLabel($scope['label'])) ?></div>
                                        <div class="gov-item-meta">来源：<?= $esc($mosGovValue($scope['source_type'])) ?></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card h-100 gov-section-card">
                <div class="card-header">
                    <h3 class="gov-section-title"><span class="gov-section-no">5–6</span>我能看到什么、能做什么</h3>
                </div>
                <div class="card-body">
                    <?php if ($data['permissions'] === []): ?>
                        <div class="gov-empty">当前没有生效的治理权限。</div>
                    <?php else: ?>
                        <?php foreach ($data['permissions'] as $resourceType => $perms): ?>
                            <?php
                            $viewActions = [];
                            $manageActions = [];
                            foreach ($perms as $p) {
                                if ($p['action'] === 'view') {
                                    $viewActions[] = $p['action'];
                                } else {
                                    $manageActions[] = $p['action'];
                                }
                            }
                            ?>
                            <div class="gov-permission-group">
                                <div class="gov-permission-name"><?= $esc($mosGovT(ucfirst($resourceType))) ?></div>
                                <div class="gov-chip-row">
                                    <?php if ($viewActions !== []): ?>
                                        <span class="gov-chip bg-blue-lt">可以查看</span>
                                    <?php endif; ?>
                                    <?php foreach ($manageActions as $action): ?>
                                        <span class="gov-chip bg-secondary-lt">可以<?= $esc($mosGovValue($action)) ?></span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card h-100 gov-section-card">
                <div class="card-header d-flex align-items-center">
                    <h3 class="gov-section-title"><span class="gov-section-no">7</span>我现在要完成什么</h3>
                    <div class="ms-auto">
                        <a class="btn btn-sm btn-outline-primary" href="<?= $esc($mosGovRootPath . '/tasks') ?>">全部任务</a>
                    </div>
                </div>
                <div class="card-body">
                    <?php if ($data['open_tasks'] === []): ?>
                        <div class="gov-empty">没有指派给你的待办任务。</div>
                    <?php else: ?>
                        <div class="gov-list">
                            <?php foreach ($data['open_tasks'] as $task): ?>
                                <div class="gov-item">
                                    <div class="gov-item-main">
                                        <div class="gov-item-title">
                                            <a href="<?= $esc($mosGovRootPath . '/tasks/' . (int) $task['id']) ?>">
                                                <?= $esc($task['title']) ?>
                                            </a>
                                        </div>
                                        <div class="gov-item-meta">
                                            截止：<?= $esc($task['due_date'] ?? '—') ?>
                                        </div>
                                    </div>
                                    <span class="badge bg-warning-lt"><?= $esc($mosGovValue($task['status'])) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card h-100 gov-section-card">
                <div class="card-header">
                    <h3 class="gov-section-title"><span class="gov-section-no">8</span>我向谁负责</h3>
                </div>
                <div class="card-body">
                    <?php if ($data['bodies'] === []): ?>
                        <div class="gov-empty">你的角色尚未关联治理主体。</div>
                    <?php else: ?>
                        <div class="gov-list">
                            <?php foreach ($data['bodies'] as $body): ?>
                                <div class="gov-item">
                                    <div class="gov-item-main">
                                        <div class="gov-item-title">
                                            <a href="<?= $esc($mosGovRootPath . '/bodies/' . (int) $body['id']) ?>">
                                                <?= $esc($mosGovT($body['name'])) ?>
                                            </a>
                                        </div>
                                        <div class="gov-item-meta">我所属的责任与协作主体</div>
                                    </div>
                                    <?php if (!empty($body['body_type'])): ?>
                                        <span class="badge bg-secondary-lt"><?= $esc($mosGovValue($body['body_type'])) ?></span>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card h-100 gov-section-card">
                <div class="card-header">
                    <h3 class="gov-section-title"><span class="gov-section-no">9</span>相关文件 · 培训 · 问责</h3>
                </div>
                <div class="card-body">
                    <p class="text-secondary small">
                        V0.2 已预留稳定容器；后续版本将在此接入治理文件、培训记录与问责复核。
                    </p>
                    <div class="row g-2">
                        <div class="col-md-4">
                            <div class="gov-summary-box h-100">
                                <div class="gov-kicker">相关文件</div>
                                <div class="text-secondary small">暂无文件</div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="gov-summary-box h-100">
                                <div class="gov-kicker">培训</div>
                                <div class="text-secondary small">暂无培训记录</div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="gov-summary-box h-100">
                                <div class="gov-kicker">问责</div>
                                <div class="text-secondary small">暂无问责复核记录</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php endif; ?>

<?php require __DIR__ . '/../../../../Include/Footer.php'; ?>
