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
 * 本文件只负责呈现：所有英文内部值（scope_type / source_type / status /
 * permission key 等）经 views/_i18n.php 或本文件内的显示映射转为中文，
 * 数据库值、权限键与授权判断一律不变。
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
require __DIR__ . '/_style.php';

/**
 * 范围来源 → 中文显示（仅显示；底层 source_type 值不变）。
 * 角色带来的范围显示为「角色继承」，其余按授予途径直述。
 */
$mosGovScopeSourceLabels = [
    'role' => '角色继承',
    'inherited' => '角色继承',
    'inherit' => '角色继承',
    'self' => '本人',
    'appointment' => '随任命',
    'direct' => '直接',
    'manual_assignment' => '手工指派',
];
$mosGovScopeSource = static function ($source) use ($mosGovScopeSourceLabels, $mosGovValue): string {
    $source = (string) $source;

    return $mosGovScopeSourceLabels[$source] ?? $mosGovValue($source);
};

/** 任务状态 → [中文, 强调色]（仅显示；底层 status 值不变）。 */
$mosGovTaskStatus = static function ($status) use ($mosGovValue): array {
    return match ((string) $status) {
        'open' => ['待处理', 'orange'],
        'in_progress' => ['处理中', 'blue'],
        'done' => ['已完成', 'green'],
        default => [$mosGovValue($status), 'gray'],
    };
};

/** 资源类型键 → 中文分组名（先查实体表，再查短语表；permission key 不变）。 */
$mosGovResourceLabel = static function (string $resourceType) use ($mosGovEntityLabel, $mosGovT): string {
    $entityLabel = $mosGovEntityLabel($resourceType);

    return $entityLabel !== $resourceType ? $entityLabel : $mosGovT(ucfirst($resourceType));
};

// $data is null when the user has no governance identity — all summary
// values below must tolerate that (the summary itself only renders with one).
$mosGovData = is_array($data) ? $data : [];
$mosGovPersonName = $mosGovData['person']['fullName']
    ?? $mosGovData['identity']['display_name_override']
    ?? ('人员 #' . ($ctx !== null ? $ctx->personId() : 0));
$mosGovIdentityStatus = $mosGovValue($mosGovData['identity']['identity_status'] ?? 'active');
$mosGovOpenTaskCount = count($mosGovData['open_tasks'] ?? []);
?>

<div class="mos-gov">

<?php if ($ctx === null): ?>
    <div class="card mg-card">
        <div class="card-body">
            <h3 class="card-title mb-2">尚无治理身份</h3>
            <p class="text-secondary mb-0">
                你的账号当前没有生效的 MOS-GOV 治理身份。治理身份由教会授予，
                与你的 ChurchCRM 登录账号相互独立。如你认为这是错误，请联系治理管理员。
            </p>
        </div>
    </div>
<?php else: ?>

    <!-- ===================== 我的治理摘要 ===================== -->
    <div class="mg-summary" id="mg-summary">
        <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
            <span class="mg-chip mg-chip-blue">治理身份</span>
            <span class="mg-chip mg-chip-green"><?= $esc($mosGovIdentityStatus) ?></span>
            <span class="mg-internal">内部编号 #<?= (int) $ctx->identityId() ?></span>
        </div>
        <div class="mg-summary-name"><?= $esc($mosGovPersonName) ?></div>
        <?php if (!empty($data['identity']['display_name_override'])
            && $data['identity']['display_name_override'] !== $mosGovPersonName): ?>
            <div class="mg-meta-line">身份显示名：<?= $esc($data['identity']['display_name_override']) ?></div>
        <?php endif; ?>
        <div class="mg-stats">
            <a class="mg-stat" href="<?= $esc($mosGovRootPath . '/my-governance#mg-sec-roles') ?>">
                <div class="mg-stat-label">我的角色</div>
                <div class="mg-stat-value"><?= count($data['roles']) ?><small>个</small></div>
            </a>
            <a class="mg-stat" href="<?= $esc($mosGovRootPath . '/my-governance#mg-sec-scopes') ?>">
                <div class="mg-stat-label">我的范围</div>
                <div class="mg-stat-value"><?= count($data['scopes']) ?><small>个</small></div>
            </a>
            <a class="mg-stat mg-stat-todo" href="<?= $esc($mosGovRootPath . '/my-governance#mg-sec-tasks') ?>">
                <div class="mg-stat-label">我的待办</div>
                <div class="mg-stat-value"><?= $mosGovOpenTaskCount ?><small>项</small></div>
            </a>
        </div>
    </div>

    <div class="row g-3">
        <!-- ===================== 1 我是谁 ===================== -->
        <div class="col-lg-6">
            <div class="card h-100 mg-card">
                <div class="card-header">
                    <h3 class="mg-sec-title"><span class="mg-no">1</span>我是谁</h3>
                    <p class="mg-sec-desc">你在教会治理体系中的身份与基本信息。</p>
                </div>
                <div class="card-body">
                    <div class="mg-list">
                        <div class="mg-item">
                            <div class="mg-item-main">
                                <div class="mg-item-title"><?= $esc($mosGovPersonName) ?></div>
                                <div class="mg-meta-line">治理身份状态：<?= $esc($mosGovIdentityStatus) ?></div>
                                <div class="mg-meta-line">加入时间：<?= $esc($data['identity']['member_since'] ?? '—') ?></div>
                                <div class="mg-meta-line mg-internal">治理身份 #<?= (int) $ctx->identityId() ?> · 人员 #<?= (int) $ctx->personId() ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ===================== 2 我的角色与任命 ===================== -->
        <div class="col-lg-6">
            <div class="card h-100 mg-card" id="mg-sec-roles">
                <div class="card-header">
                    <h3 class="mg-sec-title"><span class="mg-no">2</span>我的角色与任命</h3>
                    <p class="mg-sec-desc">教会托付给你的治理角色，以及相应的任命记录。</p>
                </div>
                <div class="card-body">
                    <?php if ($data['roles'] === []): ?>
                        <div class="mg-empty">尚未挂接治理角色。</div>
                    <?php else: ?>
                        <div class="mg-list">
                            <?php foreach ($data['roles'] as $role): ?>
                                <div class="mg-item">
                                    <div class="mg-item-main">
                                        <div class="mg-item-title"><?= $esc($mosGovT($role['role_name'])) ?></div>
                                        <?php if ($role['appointment_id'] !== null): ?>
                                            <div class="mg-meta-line">来源：任命 #<?= (int) $role['appointment_id'] ?></div>
                                        <?php else: ?>
                                            <div class="mg-meta-line">来源：角色指派</div>
                                        <?php endif; ?>
                                        <div class="mg-meta-line mg-internal">角色编码 <code><?= $esc($role['role_code']) ?></code> · 角色 #<?= (int) $role['role_id'] ?></div>
                                    </div>
                                    <span class="mg-chip <?= $role['active'] ? 'mg-chip-green' : 'mg-chip-gray' ?>">
                                        <?= $role['active'] ? '有效' : '停用' ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ===================== 3 我被托付什么 ===================== -->
        <div class="col-lg-6">
            <div class="card h-100 mg-card">
                <div class="card-header">
                    <h3 class="mg-sec-title"><span class="mg-no">3</span>我被托付什么</h3>
                    <p class="mg-sec-desc">这些角色与任命带来的具体职责。</p>
                </div>
                <div class="card-body">
                    <?php if ($data['responsibilities'] === []): ?>
                        <div class="mg-empty">暂无职责记录。</div>
                    <?php else: ?>
                        <div class="mg-list">
                            <?php foreach (array_slice($data['responsibilities'], 0, 12) as $resp): ?>
                                <?php
                                $priorityValue = (string) $resp['priority'];
                                $priorityChip = match ($priorityValue) {
                                    'high' => 'mg-chip-orange',
                                    'critical' => 'mg-chip-red',
                                    default => 'mg-chip-gray',
                                };
                                ?>
                                <div class="mg-item">
                                    <div class="mg-item-main">
                                        <div class="mg-item-title"><?= $esc($resp['title']) ?></div>
                                        <div class="mg-meta-line">来源：<?= $esc($mosGovValue($resp['via'])) ?></div>
                                        <div class="mg-meta-line">优先级：<?= $esc($mosGovValue($priorityValue)) ?></div>
                                    </div>
                                    <?php if (in_array($priorityValue, ['high', 'critical'], true)): ?>
                                        <span class="mg-chip <?= $priorityChip ?>"><?= $esc($mosGovValue($priorityValue)) ?></span>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php if (count($data['responsibilities']) > 12): ?>
                            <div class="mg-meta-line mt-2">
                                其余 <?= count($data['responsibilities']) - 12 ?> 条职责可在
                                <a href="<?= $esc($mosGovRootPath . '/responsibilities') ?>">职责列表</a> 查看。
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ===================== 4 我的治理范围 ===================== -->
        <div class="col-lg-6">
            <div class="card h-100 mg-card" id="mg-sec-scopes">
                <div class="card-header">
                    <h3 class="mg-sec-title"><span class="mg-no">4</span>我的治理范围</h3>
                    <p class="mg-sec-desc">你被允许处理治理数据的具体边界。</p>
                </div>
                <div class="card-body">
                    <?php if ($data['scopes'] === []): ?>
                        <div class="mg-empty">未指派明确范围。</div>
                    <?php else: ?>
                        <div class="mg-list">
                            <?php foreach ($data['scopes'] as $scope): ?>
                                <div class="mg-item">
                                    <div class="mg-item-main">
                                        <div class="mg-item-title"><?= $esc($mosGovScopeLabel($scope['label'])) ?></div>
                                        <div class="mg-meta-line">来源：<?= $esc($mosGovScopeSource($scope['source_type'])) ?></div>
                                        <div class="mg-meta-line mg-internal">范围类型 <?= $esc($mosGovValue($scope['scope_type'])) ?><?= $scope['scope_id'] !== null ? ' · #' . (int) $scope['scope_id'] : '' ?></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ===================== 5–6 我能看到什么、能做什么 ===================== -->
        <div class="col-12">
            <div class="card mg-card">
                <div class="card-header">
                    <h3 class="mg-sec-title"><span class="mg-no">5–6</span>我能看到什么、能做什么</h3>
                    <p class="mg-sec-desc">按事项列出你当前拥有的治理能力（查看 / 导出 / 管理等）。</p>
                </div>
                <div class="card-body">
                    <?php if ($data['permissions'] === []): ?>
                        <div class="mg-empty">当前没有生效的治理权限。</div>
                    <?php else: ?>
                        <div class="mg-cap-grid">
                            <?php foreach ($data['permissions'] as $resourceType => $perms): ?>
                                <div class="mg-cap">
                                    <div class="mg-cap-name"><?= $esc($mosGovResourceLabel($resourceType)) ?></div>
                                    <div class="mg-chip-row">
                                        <?php foreach ($perms as $perm): ?>
                                            <span class="mg-chip <?= $perm['action'] === 'view' ? 'mg-chip-blue' : 'mg-chip-gray' ?>">
                                                可以<?= $esc($mosGovValue($perm['action'])) ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="mg-meta-line mt-2">完整权限清单见
                            <a href="<?= $esc($mosGovRootPath . '/permissions') ?>">权限注册表</a>。
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ===================== 7 我现在要完成什么 ===================== -->
        <div class="col-12">
            <div class="card mg-card" id="mg-sec-tasks">
                <div class="card-header d-flex flex-wrap align-items-center gap-2">
                    <h3 class="mg-sec-title"><span class="mg-no">7</span>我现在要完成什么</h3>
                    <div class="ms-auto">
                        <a class="btn btn-sm btn-outline-primary" href="<?= $esc($mosGovRootPath . '/tasks') ?>">全部任务</a>
                    </div>
                </div>
                <div class="card-body">
                    <?php if ($data['open_tasks'] === []): ?>
                        <div class="mg-empty">没有指派给你的待办任务。</div>
                    <?php else: ?>
                        <div class="mg-task-grid">
                            <?php
                            $mosGovToday = (new DateTimeImmutable('today'))->format('Y-m-d');
                            foreach ($data['open_tasks'] as $task):
                                [$statusLabel, $statusTone] = $mosGovTaskStatus($task['status']);
                                $taskClasses = ['mg-task'];
                                $taskClasses[] = match ($statusTone) {
                                    'orange' => 'mg-task--open',
                                    'blue' => 'mg-task--progress',
                                    'green' => 'mg-task--done',
                                    default => '',
                                };
                                $overdue = $task['due_date'] !== null
                                    && $task['due_date'] !== ''
                                    && $task['due_date'] < $mosGovToday;
                            ?>
                                <div class="<?= $esc(trim(implode(' ', $taskClasses))) ?>">
                                    <div class="d-flex align-items-start justify-content-between gap-2">
                                        <div class="mg-task-title">
                                            <a href="<?= $esc($mosGovRootPath . '/tasks/' . (int) $task['id']) ?>"><?= $esc($task['title']) ?></a>
                                        </div>
                                        <span class="mg-chip mg-chip-<?= $esc($statusTone) ?>"><?= $esc($statusLabel) ?></span>
                                    </div>
                                    <div class="mg-task-due<?= $overdue ? ' is-overdue' : '' ?>">
                                        <?php if ($overdue): ?>
                                            已过期 · 截止 <?= $esc($task['due_date']) ?>
                                        <?php else: ?>
                                            截止日期：<?= $esc($task['due_date'] ?? '—') ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ===================== 8 我向谁负责 ===================== -->
        <div class="col-lg-6">
            <div class="card h-100 mg-card">
                <div class="card-header">
                    <h3 class="mg-sec-title"><span class="mg-no">8</span>我向谁负责</h3>
                    <p class="mg-sec-desc">你所服务的治理主体与问责关系。</p>
                </div>
                <div class="card-body">
                    <?php if ($data['bodies'] === []): ?>
                        <div class="mg-empty">你的角色尚未关联治理主体。</div>
                    <?php else: ?>
                        <div class="mg-list">
                            <?php foreach ($data['bodies'] as $body): ?>
                                <div class="mg-item">
                                    <div class="mg-item-main">
                                        <div class="mg-item-title">
                                            <a href="<?= $esc($mosGovRootPath . '/bodies/' . (int) $body['id']) ?>"><?= $esc($mosGovT($body['name'])) ?></a>
                                        </div>
                                        <div class="mg-meta-line">我所属的责任与协作主体</div>
                                        <div class="mg-meta-line mg-internal">治理主体 #<?= (int) $body['id'] ?></div>
                                    </div>
                                    <?php if (!empty($body['body_type'])): ?>
                                        <span class="mg-chip mg-chip-gray"><?= $esc($mosGovValue($body['body_type'])) ?></span>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ===================== 9 相关文件 · 培训 · 问责 ===================== -->
        <div class="col-lg-6">
            <div class="card h-100 mg-card">
                <div class="card-header">
                    <h3 class="mg-sec-title"><span class="mg-no">9</span>相关文件 · 培训 · 问责</h3>
                    <p class="mg-sec-desc">V0.2 已预留稳定容器；后续版本接入治理文件、培训记录与问责复核。</p>
                </div>
                <div class="card-body">
                    <div class="row g-2">
                        <div class="col-md-4">
                            <div class="mg-empty-box">
                                <div class="mg-stat-label">相关文件</div>
                                <div class="text-secondary small mt-1">暂无文件</div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mg-empty-box">
                                <div class="mg-stat-label">培训</div>
                                <div class="text-secondary small mt-1">暂无培训记录</div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mg-empty-box">
                                <div class="mg-stat-label">问责</div>
                                <div class="text-secondary small mt-1">暂无问责复核记录</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

<?php endif; ?>

</div>

<?php require __DIR__ . '/../../../../Include/Footer.php'; ?>
