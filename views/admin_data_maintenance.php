<?php

/**
 * MOS CHURCH — 数据格式化 / 清零（管理员 → 数据格式化）
 *
 * §4/§12 的要求集中体现在本页：
 *   - 明确警告：列出「将被清空」与「将被保留」两张清单；
 *   - 二次确认：必须逐字输入确认词才可能执行（后端再校验一次）；
 *   - 清零前备份：提供备份入口，并提示先备份；
 *   - 不删除管理员、不破坏数据库结构：只清业务数据，保留 user_usr、
 *     config_cfg 与 gov_* 治理表。
 *
 * 本页**不暴露任何绕过 UI 的清零接口**：真正执行在 POST 路由，
 * 且需管理员 + CSRF + 确认词三者同时满足（§12.6/§12.7）。
 *
 * 与上一版的差别：上一版只做「展示范围 + 跳转核心重置页」，但核心的
 * DELETE /api/database/reset 会 DROP 全部表且缺管理员校验（见
 * GovDataMaintenance 类注释），故本轮改为在插件内自带清零。
 *
 * Expected variables:
 * - $esc         HTML 转义闭包
 * - $scope       ['groups'=>[...], 'total'=>int]
 * - $operations  备份/恢复入口
 * - $csrfField   CSRF 输入字段 HTML
 * - $confirmWord 需要输入的确认词
 * - $error       错误提示（可选）
 * - $result      执行结果（可选）['cleared'=>[], 'skipped'=>[], 'error'=>?]
 */

use ChurchCRM\dto\SystemURLs;

$sPageTitle = '数据格式化';
$sPageSubtitle = '清空上一个教会的业务数据，保留系统与管理员，用于服务新的教会';
$aBreadcrumbs = [
    [gettext('Admin'), SystemURLs::getRootPath() . '/admin/'],
    [$sPageTitle],
];

$esc = $esc ?? static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

require __DIR__ . '/_style.php';

$scope       = $scope ?? ['groups' => [], 'total' => 0];
$operations  = $operations ?? [];
$confirmWord = $confirmWord ?? 'CLEAR CHURCH DATA';

/** 危险等级 → 徽标样式。 */
$riskChip = static function (string $risk) use ($esc): string {
    $map = [
        'danger' => ['mg-chip-red', '高风险'],
        'warn'   => ['mg-chip-orange', '需谨慎'],
        'safe'   => ['mg-chip-green', '不修改数据'],
    ];
    [$class, $text] = $map[$risk] ?? ['mg-chip-gray', '请确认'];

    return '<span class="mg-chip ' . $esc($class) . '">' . $esc($text) . '</span>';
};
?>

<div class="mos-gov">
    <div class="mg-pagehead">
        <h2>数据格式化</h2>
        <div class="mg-pagehead-note">
            用于「安装一次 → 清空旧教会数据 → 服务新教会」。
            本操作只清空<strong>业务数据</strong>，不会删除软件、管理员账户或数据库结构。
        </div>
    </div>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger py-2"><?= $esc($error) ?></div>
    <?php endif; ?>

    <?php if (!empty($result)): ?>
        <div class="alert alert-<?= empty($result['error']) ? 'success' : 'danger' ?> py-2">
            <?php if (!empty($result['error'])): ?>
                清零过程中出错：<?= $esc($result['error']) ?>
            <?php else: ?>
                已清空 <?= count($result['cleared']) ?> 张业务数据表。管理员账户与系统设置未受影响。
            <?php endif; ?>
        </div>
        <?php if (!empty($result['cleared'])): ?>
            <div class="mg-card">
                <div class="mg-sec-title">已清空的表</div>
                <div class="mg-sec-desc"><?= $esc(implode('、', $result['cleared'])) ?></div>
            </div>
        <?php endif; ?>
        <?php if (!empty($result['skipped'])): ?>
            <div class="mg-card">
                <div class="mg-sec-title">已跳过的项</div>
                <div class="mg-sec-desc"><?= $esc(implode('、', $result['skipped'])) ?></div>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if (!empty($operations)): ?>
        <div class="mg-card">
            <div class="mg-sec-title">备份与恢复</div>
            <div class="mg-sec-desc">清空前建议先备份。以下操作本身不删除数据。</div>
            <?php foreach ($operations as $op): ?>
                <div class="mg-item">
                    <div class="mg-item-main">
                        <div class="mg-item-title">
                            <?= $esc($op['title']) ?>
                            <?= $riskChip($op['risk']) ?>
                        </div>
                        <div class="mg-meta-line"><?= $esc($op['desc']) ?></div>
                        <div class="mg-meta-line">影响范围：<?= $esc($op['confirm']) ?></div>
                    </div>
                    <div class="mg-cap">
                        <a class="btn btn-sm btn-outline-secondary"
                           href="<?= $esc($op['href']) ?>">查看</a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="mg-card">
        <div class="mg-sec-title">将被清空的数据（当前共 <?= (int) $scope['total'] ?> 条）</div>
        <?php if (empty($scope['groups'])): ?>
            <div class="mg-empty"><div class="mg-empty-box">没有可清空的业务数据。</div></div>
        <?php else: ?>
            <table class="table table-sm align-middle mb-0">
                <thead><tr><th>类别</th><th>数据表</th><th class="text-end">当前条数</th></tr></thead>
                <tbody>
                <?php foreach ($scope['groups'] as $group): ?>
                    <?php foreach ($group['tables'] as $i => $table): ?>
                        <tr>
                            <?php if ($i === 0): ?>
                                <td rowspan="<?= count($group['tables']) ?>"><?= $esc($group['label']) ?></td>
                            <?php endif; ?>
                            <td><code><?= $esc($table['table']) ?></code></td>
                            <td class="text-end">
                                <?= $table['rows'] < 0 ? '—' : (int) $table['rows'] ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <div class="mg-card">
        <div class="mg-sec-title">将被保留（不会删除）</div>
        <div class="mg-sec-desc">
            <code>user_usr</code> 管理员账户 ·
            <code>config_cfg</code> 系统设置与教会信息 ·
            <code>gov_*</code> MOS 治理记录 ·
            全部数据库表结构
        </div>
    </div>

    <div class="mg-card">
        <div class="mg-sec-title">执行清零</div>
        <div class="alert alert-danger py-2">
            <strong>此操作会删除上表中的全部业务数据，且无法撤销。</strong>
            请先确认已备份。执行后系统将回到空白教会状态，管理员账户仍可登录。
        </div>

        <form method="post"
              action="<?= $esc(SystemURLs::getRootPath() . '/plugins/mos-gov/admin/data-maintenance') ?>"
              onsubmit="return confirm('确定要清空全部业务数据吗？此操作无法撤销。');">
            <?= $csrfField ?>
            <div class="mb-2">
                <label class="form-label" for="confirm_word">
                    请输入 <code><?= $esc($confirmWord) ?></code> 以启用清零按钮
                </label>
                <input type="text" class="form-control" id="confirm_word"
                       name="confirm_word" autocomplete="off"
                       placeholder="<?= $esc($confirmWord) ?>">
            </div>
            <button type="submit" class="btn btn-danger">确认清空业务数据</button>
        </form>
    </div>
</div>