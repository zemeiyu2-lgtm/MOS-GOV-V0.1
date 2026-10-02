<?php

/**
 * MOS-GOV governance dashboard — 治理平台首页.
 *
 * 页面顺序体现治理平台定位：平台定位 → 我的治理中心 / 治理运行 → 治理数据统计
 * → 最近治理活动。统计数字来自数据层（GovRepository），视图不含任何 SQL。
 *
 * Expected variables (provided by the route):
 * - $stats                array<string,int> dashboard counters, or null on failure
 * - $statsError           safe error message when the counters could not be read
 * - $recentMeetings       latest governance meetings
 * - $recentDecisions      latest governance decisions
 * - $meetingDecorations   row id => ['ref' => ..., 'person' => ...] for meetings
 * - $decisionDecorations  row id => ['ref' => ..., 'person' => ...] for decisions
 * - $recentError          safe error message when the recent lists failed
 * - $canWrite             whether the current user may modify governance data
 * - $navigation           slug => plural label
 * - $esc                  HTML-escaping closure
 */

use ChurchCRM\dto\SystemURLs;

$mosGovRootPath = SystemURLs::getRootPath() . '/plugins/mos-gov';

require __DIR__ . '/_i18n.php';

$sPageTitle = 'MOS-GOV';
$sPageSubtitle = '教会治理平台（V0.2）';
$aBreadcrumbs = [
    ['label' => '插件', 'url' => SystemURLs::getRootPath() . '/plugins/management'],
    ['label' => 'MOS-GOV', 'active' => true],
];

require __DIR__ . '/../../../../Include/Header.php';

$activeSlug = null;
require __DIR__ . '/_tabs.php';
require __DIR__ . '/_style.php';

/** Render one counter tile. */
$mosGovTile = static function (string $label, $value, string $href = '') use ($esc): void {
    ?>
    <div class="col-sm-6 col-lg-3">
        <div class="card card-sm mg-card">
            <div class="card-body">
                <div class="text-secondary"><?= $esc($label) ?></div>
                <div class="h2 mb-0"><?= $value === null ? '&mdash;' : (int) $value ?></div>
                <?php if ($href !== ''): ?>
                    <a class="stretched-link small text-decoration-none" href="<?= $esc($href) ?>">查看列表</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php
};
?>

<div class="mos-gov">

<?php if (!empty($statsError)): ?>
    <div class="alert alert-danger" role="alert">
        治理数据暂不可用：<?= $esc($mosGovMsg($statsError)) ?>
    </div>
<?php endif; ?>

<?php if (empty($canWrite)): ?>
    <div class="alert alert-info" role="alert">
        你对治理数据仅有只读权限。修改 MOS-GOV 记录需要 ChurchCRM 管理员权限。
    </div>
<?php endif; ?>

<div class="card mb-3 mg-card">
    <div class="card-body">
        <div class="d-flex flex-wrap align-items-center gap-2">
            <div>
                <div class="h1 mb-1">MOS-GOV</div>
                <div class="text-secondary">教会治理平台</div>
            </div>
            <div class="ms-auto d-flex flex-wrap gap-2">
                <a class="btn btn-primary" href="<?= $esc($mosGovRootPath . '/my-governance') ?>">进入我的治理中心</a>
                <a class="btn btn-outline-primary" href="<?= $esc($mosGovRootPath . '/identity') ?>">治理身份</a>
                <a class="btn btn-outline-primary" href="<?= $esc($mosGovRootPath . '/search') ?>">治理搜索</a>
            </div>
        </div>
        <p class="text-secondary mb-0 mt-3">
            MOS-GOV 在 ChurchCRM 之上承载教会治理：治理身份、治理角色、任命、职责、治理范围、
            权限与信息分级。人员、家庭、小组与活动等事实数据仍由 ChurchCRM 保存，
            MOS-GOV 仅通过 ID 引用，不复制、不替代。
        </p>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header d-flex align-items-center">
                <h3 class="card-title">我的治理中心</h3>
                <div class="ms-auto">
                    <a class="btn btn-sm btn-primary" href="<?= $esc($mosGovRootPath . '/my-governance') ?>">打开</a>
                </div>
            </div>
            <div class="card-body">
                <p class="text-secondary mb-2">
                    我是谁 → 我承担什么角色 → 我被托付什么 → 我负责什么范围 →
                    我能看到什么、能做什么 → 我现在要完成什么 → 我向谁负责。
                </p>
                <p class="text-secondary mb-0 small">
                    这是我的治理身份入口；治理权限来自教会授予，与 ChurchCRM 登录账号相互独立。
                </p>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header d-flex align-items-center">
                <h3 class="card-title">治理运行</h3>
                <div class="ms-auto">
                    <a class="btn btn-sm btn-outline-primary" href="<?= $esc($mosGovRootPath . '/meetings') ?>">会议列表</a>
                </div>
            </div>
            <div class="card-body">
                <div class="d-flex flex-wrap gap-1">
                    <a class="btn btn-sm btn-outline-secondary" href="<?= $esc($mosGovRootPath . '/meetings') ?>">会议</a>
                    <a class="btn btn-sm btn-outline-secondary" href="<?= $esc($mosGovRootPath . '/issues') ?>">议题</a>
                    <a class="btn btn-sm btn-outline-secondary" href="<?= $esc($mosGovRootPath . '/decisions') ?>">决策</a>
                    <a class="btn btn-sm btn-outline-secondary" href="<?= $esc($mosGovRootPath . '/tasks') ?>">任务</a>
                    <a class="btn btn-sm btn-outline-secondary" href="<?= $esc($mosGovRootPath . '/relationships') ?>">关系</a>
                </div>
                <p class="text-secondary small mb-0 mt-3">
                    治理运行链路：会议 → 议题 → 决策 → 任务。所有读取都经过统一授权引擎按范围与信息分级过滤。
                </p>
            </div>
        </div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header">
        <h3 class="card-title">教会治理</h3>
    </div>
    <div class="card-body">
        <div class="d-flex flex-wrap gap-1 mb-3">
            <a class="btn btn-sm btn-outline-secondary" href="<?= $esc($mosGovRootPath . '/structures') ?>">结构</a>
            <a class="btn btn-sm btn-outline-secondary" href="<?= $esc($mosGovRootPath . '/bodies') ?>">治理主体</a>
            <a class="btn btn-sm btn-outline-secondary" href="<?= $esc($mosGovRootPath . '/roles') ?>">角色</a>
            <a class="btn btn-sm btn-outline-secondary" href="<?= $esc($mosGovRootPath . '/appointments') ?>">任命</a>
            <a class="btn btn-sm btn-outline-secondary" href="<?= $esc($mosGovRootPath . '/responsibilities') ?>">职责</a>
        </div>
        <div class="text-secondary small mb-2">治理数据统计</div>
        <div class="row g-3">
            <?php
            $mosGovTile('结构', $stats['structures'] ?? null, $mosGovRootPath . '/structures');
            $mosGovTile('治理主体', $stats['bodies'] ?? null, $mosGovRootPath . '/bodies');
            $mosGovTile('待处理议题', $stats['open_issues'] ?? null, $mosGovRootPath . '/issues');
            $mosGovTile('待办任务', $stats['open_tasks'] ?? null, $mosGovRootPath . '/tasks');
            ?>
        </div>
        <div class="row g-3 mt-0">
            <?php
            $mosGovTile('角色', $stats['roles'] ?? null, $mosGovRootPath . '/roles');
            $mosGovTile('任命', $stats['appointments'] ?? null, $mosGovRootPath . '/appointments');
            $mosGovTile('职责', $stats['responsibilities'] ?? null, $mosGovRootPath . '/responsibilities');
            $mosGovTile('关系', $stats['relationships'] ?? null, $mosGovRootPath . '/relationships');
            $mosGovTile('会议', $stats['meetings'] ?? null, $mosGovRootPath . '/meetings');
            $mosGovTile('议题（全部）', $stats['issues'] ?? null, $mosGovRootPath . '/issues');
            $mosGovTile('决策', $stats['decisions'] ?? null, $mosGovRootPath . '/decisions');
            $mosGovTile('任务（全部）', $stats['tasks'] ?? null, $mosGovRootPath . '/tasks');
            ?>
        </div>
    </div>
</div>

<?php if (!empty($recentError)): ?>
    <div class="alert alert-warning" role="alert">
        最近的治理活动无法加载：<?= $esc($recentError) ?>
    </div>
<?php endif; ?>

<div class="row">
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header d-flex align-items-center">
                <h3 class="card-title">最近的会议</h3>
                <div class="ms-auto">
                    <a class="btn btn-sm btn-outline-primary" href="<?= $esc($mosGovRootPath . '/meetings') ?>">全部会议</a>
                </div>
            </div>
            <div class="card-body">
                <?php if ($recentMeetings === []): ?>
                    <p class="text-secondary mb-0">暂无会议记录。</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-vcenter card-table">
                            <thead>
                                <tr><th>会议</th><th>治理主体</th><th>日期</th><th>状态</th></tr>
                            </thead>
                            <tbody>
                            <?php foreach ($recentMeetings as $row): ?>
                                <?php $dec = $meetingDecorations[(int) $row['id']] ?? ['ref' => [], 'person' => []]; ?>
                                <tr>
                                    <td>
                                        <a href="<?= $esc($mosGovRootPath . '/meetings/' . (int) $row['id']) ?>">
                                            <?= $esc($row['title'] ?? '') ?>
                                        </a>
                                    </td>
                                    <td><?= $esc($mosGovDecorLabel($dec['ref']['body_id'] ?? '')) ?></td>
                                    <td><?= $esc($row['meeting_date'] ?? '') ?></td>
                                    <td>
                                        <span class="badge bg-secondary-lt"><?= $esc($mosGovValue($row['status'] ?? '')) ?></span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card">
            <div class="card-header d-flex align-items-center">
                <h3 class="card-title">最近的决策</h3>
                <div class="ms-auto">
                    <a class="btn btn-sm btn-outline-primary" href="<?= $esc($mosGovRootPath . '/decisions') ?>">全部决策</a>
                </div>
            </div>
            <div class="card-body">
                <?php if ($recentDecisions === []): ?>
                    <p class="text-secondary mb-0">暂无决策记录。</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-vcenter card-table">
                            <thead>
                                <tr><th>决策</th><th>相关议题</th><th>状态</th><th>决议时间</th></tr>
                            </thead>
                            <tbody>
                            <?php foreach ($recentDecisions as $row): ?>
                                <?php $dec = $decisionDecorations[(int) $row['id']] ?? ['ref' => [], 'person' => []]; ?>
                                <tr>
                                    <td>
                                        <a href="<?= $esc($mosGovRootPath . '/decisions/' . (int) $row['id']) ?>">
                                            <?= $esc($row['title'] ?? '') ?>
                                        </a>
                                    </td>
                                    <td><?= $esc($mosGovDecorLabel($dec['ref']['issue_id'] ?? '')) ?></td>
                                    <td>
                                        <span class="badge bg-secondary-lt"><?= $esc($mosGovValue($row['decision_status'] ?? '')) ?></span>
                                    </td>
                                    <td><?= $esc($row['decided_at'] ?? '') ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="card mt-3">
    <div class="card-body">
        <p class="text-secondary mb-2">治理运行主链路：</p>
        <p class="mb-0">
            <code>结构 → 治理主体 → 治理角色 → 任命 → 职责</code><br>
            <code>会议 → 议题 → 决策 → 任务</code>
        </p>
        <p class="text-secondary small mb-0 mt-3">
            ChurchCRM 仍是底层事实系统（人员 / 家庭 / 小组 / 活动）；
            MOS-GOV 页面仅呈现治理层，不替代 CRM 导航。
        </p>
    </div>
</div>

</div>

<?php require __DIR__ . '/../../../../Include/Footer.php'; ?>
