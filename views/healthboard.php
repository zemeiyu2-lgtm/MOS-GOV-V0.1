<?php

/**
 * MOS-GOV V1.0 — 统一治理健康盘.
 *
 * 五类指标：逾期任务 / 到期授权 / 决策积压 / 监督覆盖 / 审计活动。
 * 数字与明细行全部来自 HealthboardService（数据层），行级可见性已由路由层
 * 经 GovAuthorization::can(view) 过滤；本视图只做呈现，不含任何 SQL。
 *
 * Expected variables (provided by the route):
 * - $snapshot     array  HealthboardService::snapshot() 结果（各指标可能带 error）
 * - $hbError      string|null  取数失败时的安全错误信息
 * - $canSeeAudit  bool   当前用户是否可见审计活动卡片（permission.manage）
 * - $esc          HTML-escaping closure
 */

use ChurchCRM\dto\SystemURLs;

$mosGovRootPath = SystemURLs::getRootPath() . '/plugins/mos-gov';

require __DIR__ . '/_i18n.php';

$sPageTitle = 'MOS-GOV 健康盘';
$sPageSubtitle = '统一治理健康盘（V1.0）';
$aBreadcrumbs = [
    ['label' => '插件', 'url' => SystemURLs::getRootPath() . '/plugins/management'],
    ['label' => 'MOS-GOV', 'url' => $mosGovRootPath],
    ['label' => '健康盘', 'active' => true],
];

require __DIR__ . '/../../../../Include/Header.php';

$activeSlug = 'healthboard';
require __DIR__ . '/_tabs.php';
require __DIR__ . '/_style.php';

/** 分桶行数：过滤后行数即计数。 */
$hbCount = static function (array $rows): int {
    return count($rows);
};

/** 渲染一小块计数（含说明与可选链接）。 */
$hbMetric = static function (string $label, int $count, string $hint = '', string $href = '', string $tone = 'bg-secondary') use ($esc): void {
    ?>
    <div class="col-sm-6 col-lg-3">
        <div class="card card-sm mg-card">
            <div class="card-body">
                <div class="d-flex align-items-center gap-2">
                    <span class="badge <?= $esc($tone) ?>">&nbsp;</span>
                    <span class="text-secondary"><?= $esc($label) ?></span>
                </div>
                <div class="h2 mb-0"><?= $count ?></div>
                <?php if ($hint !== ''): ?>
                    <div class="small text-secondary"><?= $esc($hint) ?></div>
                <?php endif; ?>
                <?php if ($href !== ''): ?>
                    <a class="stretched-link small text-decoration-none" href="<?= $esc($href) ?>">查看列表</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php
};

/** 渲染一张小明细表（最多 $limit 行）。 */
$hbTable = static function (array $rows, array $columns, int $limit = 10) use ($esc, $mosGovValue): void {
    if ($rows === []) {
        echo '<p class="text-secondary small mb-0">暂无记录。</p>';

        return;
    }
    ?>
    <div class="table-responsive">
        <table class="table table-sm table-vcenter card-table mb-0">
            <thead>
            <tr><?php foreach ($columns as $col): ?><th><?= $esc($col[0]) ?></th><?php endforeach; ?></tr>
            </thead>
            <tbody>
            <?php foreach (array_slice($rows, 0, $limit) as $row): ?>
                <tr>
                    <?php foreach ($columns as $col): ?>
                        <td>
                            <?php
                            $val = $row[$col[1]] ?? '';
                            if ($val === null || $val === '') {
                                echo '<span class="text-secondary">—</span>';
                            } elseif (!empty($col[2]) && $col[2] === 'enum') {
                                echo $esc($mosGovValue((string) $val));
                            } else {
                                echo $esc((string) $val);
                            }
                            ?>
                        </td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
    if (count($rows) > $limit) {
        echo '<div class="small text-secondary mt-1">仅显示前 ' . (int) $limit . ' 行，共 ' . count($rows) . ' 行。</div>';
    }
};
?>

<div class="mos-gov">

<?php if (!empty($hbError)): ?>
    <div class="alert alert-danger" role="alert">
        健康盘数据暂不可用：<?= $esc($hbError) ?>
    </div>
<?php elseif (!empty($hbTruncated)): ?>
    <div class="alert alert-warning" role="alert">
        部分指标数据行数超过 1000 行取数上限，相关计数为下限值（≥N），不代表完整总数。请缩小统计范围或导出明细核对。
    </div>
<?php endif; ?>

<div class="card mb-3 mg-card">
    <div class="card-body">
        <div class="d-flex flex-wrap align-items-center gap-2">
            <div>
                <div class="h1 mb-1">治理健康盘</div>
                <div class="text-secondary">
                    生成时间 <?= $esc($snapshot['generatedAt'] ?? '—') ?>
                    · 到期授权提醒窗口 <?= (int) ($snapshot['warningDays'] ?? 30) ?> 天
                </div>
            </div>
            <div class="ms-auto d-flex flex-wrap gap-2">
                <a class="btn btn-outline-primary" href="<?= $esc($mosGovRootPath . '/tasks') ?>">任务</a>
                <a class="btn btn-outline-primary" href="<?= $esc($mosGovRootPath . '/decisions') ?>">决策</a>
            </div>
        </div>
        <p class="text-secondary mb-0 mt-3 small">
            统计范围：治理数据（行级可见性按当前用户范围与信息分级过滤）；
            终态与无效记录已按各指标定义排除。所有数字可在下方明细中核对，不存在无来源的统计值。
        </p>
    </div>
</div>

<?php
$overdue = $snapshot['overdue'] ?? [];
$delegation = $snapshot['delegation'] ?? [];
$decision = $snapshot['decision'] ?? [];
$oversight = $snapshot['oversight'] ?? [];
$audit = $snapshot['audit'] ?? [];
?>

<!-- 1. 逾期任务 -->
<div class="card mb-3">
    <div class="card-header">
        <h3 class="card-title">1. 逾期任务</h3>
    </div>
    <div class="card-body">
        <div class="row g-3 mb-3">
            <?php $hbMetric('逾期未完成', $hbCount($overdue['rows'] ?? []), 'open/in_progress 且期限已过', '', 'bg-danger'); ?>
            <?php $hbMetric('无期限未完成任务', (int) ($overdue['noDueOpen'] ?? 0), 'due_date 为空，不计入逾期', $mosGovRootPath . '/tasks', 'bg-secondary'); ?>
        </div>
        <?php $hbTable($overdue['rows'] ?? [], [
            ['标题', 'title'],
            ['状态', 'status', 'enum'],
            ['优先级', 'priority', 'enum'],
            ['期限', 'due_date'],
        ]); ?>
    </div>
</div>

<!-- 2. 到期授权 -->
<div class="card mb-3">
    <div class="card-header">
        <h3 class="card-title">2. 到期授权</h3>
    </div>
    <div class="card-body">
        <div class="row g-3 mb-3">
            <?php $hbMetric('即将到期', $hbCount($delegation['expiringSoon'] ?? []), '在提醒窗口内到期，仍为 active', '', 'bg-warning'); ?>
            <?php $hbMetric('已过有效期（状态滞后）', $hbCount($delegation['staleActive'] ?? []), 'end_date 已过但状态仍为 active', $mosGovRootPath . '/delegations', 'bg-danger'); ?>
            <?php $hbMetric('已失效', $hbCount($delegation['expired'] ?? []), '状态 = expired', '', 'bg-secondary'); ?>
            <?php $hbMetric('已撤销', $hbCount($delegation['revoked'] ?? []), '状态 = revoked', '', 'bg-secondary'); ?>
        </div>
        <div class="small text-secondary mb-2">
            草稿（draft，未生效）<?= (int) ($delegation['draft'] ?? 0) ?> 件，不计入到期统计。
            四类互斥，不会重复计数。
        </div>
        <?php
        $hbRows = array_merge(
            $delegation['expiringSoon'] ?? [],
            $delegation['staleActive'] ?? [],
            $delegation['expired'] ?? [],
            $delegation['revoked'] ?? []
        );
        $hbTable($hbRows, [
            ['标题', 'title'],
            ['状态', 'status', 'enum'],
            ['到期日', 'end_date'],
            ['撤销时间', 'revoked_at'],
        ]);
        ?>
    </div>
</div>

<!-- 3. 决策积压 -->
<div class="card mb-3">
    <div class="card-header">
        <h3 class="card-title">3. 决策积压</h3>
    </div>
    <div class="card-body">
        <div class="row g-3 mb-3">
            <?php $hbMetric('待审议（proposed）', $hbCount($decision['proposed'] ?? []), '尚未表决', $mosGovRootPath . '/decisions', 'bg-info'); ?>
            <?php $hbMetric('已批准待落实', $hbCount($decision['approved'] ?? []), '已批准，非终态', '', 'bg-info'); ?>
            <?php $hbMetric('复核逾期', $hbCount($decision['reviewOverdue'] ?? []), '已批准且复核日已过', '', 'bg-warning'); ?>
        </div>
        <div class="small text-secondary mb-2">
            终态不计入积压：已否决 <?= (int) ($decision['rejected'] ?? 0) ?> 件、
            已被取代 <?= (int) ($decision['superseded'] ?? 0) ?> 件。
            已批准但缺少决定时间的记录 <?= (int) ($decision['approvedNoDecidedAt'] ?? 0) ?> 件（数据缺口提示）。
        </div>
        <?php $hbTable(array_merge($decision['proposed'] ?? [], $decision['approved'] ?? []), [
            ['标题', 'title'],
            ['状态', 'decision_status', 'enum'],
            ['决定时间', 'decided_at'],
            ['复核日', 'review_date'],
        ]); ?>
    </div>
</div>

<!-- 4. 监督覆盖 -->
<div class="card mb-3">
    <div class="card-header">
        <h3 class="card-title">4. 监督覆盖</h3>
    </div>
    <div class="card-body">
        <div class="row g-3 mb-3">
            <?php $hbMetric('待监督', $hbCount($oversight['open'] ?? []), '状态 = open', $mosGovRootPath . '/oversight', 'bg-info'); ?>
            <?php $hbMetric('进行中', $hbCount($oversight['inProgress'] ?? []), '状态 = in_progress', '', 'bg-info'); ?>
            <?php $hbMetric('待补证据', $hbCount($oversight['missingEvidence'] ?? []), '证据引用与说明均为空', '', 'bg-warning'); ?>
            <?php $hbMetric('结果已出待归档', $hbCount($oversight['awaitingClosure'] ?? []), '结果已定但记录未完成', '', 'bg-warning'); ?>
        </div>
        <div class="small text-secondary mb-2">
            已完成 <?= $hbCount($oversight['completed'] ?? []) ?> 件
            （其中复核退回待重新整改 <?= $hbCount($oversight['failedReview'] ?? []) ?> 件）；
            已取消 <?= (int) ($oversight['cancelled'] ?? 0) ?> 件（不计入覆盖统计）。
        </div>
        <?php $hbTable(array_merge($oversight['open'] ?? [], $oversight['inProgress'] ?? []), [
            ['标题', 'title'],
            ['状态', 'oversight_status', 'enum'],
            ['结果', 'outcome', 'enum'],
            ['证据引用', 'evidence_ref'],
        ]); ?>
    </div>
</div>

<!-- 5. 审计活动 -->
<div class="card mb-3">
    <div class="card-header">
        <h3 class="card-title">5. 审计活动</h3>
    </div>
    <div class="card-body">
        <?php if (!$canSeeAudit): ?>
            <p class="text-secondary mb-0">
                审计活动仅对治理权限管理员可见（需要 permission.manage）。
            </p>
        <?php else: ?>
            <div class="row g-3 mb-3">
                <?php
                $hbAuditTile = static function (string $win, $w) use ($esc): void {
                    if ($w === null) {
                        echo '<div class="col-sm-6 col-lg-3"><div class="card card-sm mg-card"><div class="card-body">'
                            . '<div class="text-secondary">' . $esc($win) . '</div><div class="h2 mb-0">&mdash;</div></div></div></div>';

                        return;
                    }
                    ?>
                    <div class="col-sm-6 col-lg-3">
                        <div class="card card-sm mg-card">
                            <div class="card-body">
                                <div class="text-secondary"><?= $esc($win) ?></div>
                                <div class="h2 mb-0"><?= (int) $w['total'] ?></div>
                                <div class="small">
                                    <span class="badge bg-success">成功 <?= (int) $w['success'] ?></span>
                                    <span class="badge bg-warning">拒绝 <?= (int) $w['denied'] ?></span>
                                    <span class="badge bg-danger">错误 <?= (int) $w['error'] ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php
                };
                $hbAuditTile('近 7 天', $audit['w7'] ?? null);
                $hbAuditTile('近 30 天', $audit['w30'] ?? null);
                ?>
            </div>
            <p class="text-secondary small mb-0">
                审计记录由 AuditService 双写产生（关键治理操作：授权创建/变更/撤销、拒绝操作、监督结果变更等）。
                result 仅含 success / denied / error 三种；窗口外记录不计入。
            </p>
        <?php endif; ?>
    </div>
</div>

</div><!-- /.mos-gov -->

<?php require __DIR__ . '/../../../../Include/Footer.php'; ?>
