<?php

/**
 * MOS-GOV V2.0 — 财务报表（地区中立）
 *
 * 为什么不用 ChurchCRM 的 /finance/reports：
 *   实测该页是「财务报告」选择页，其唯一下级页为 /finance/reports/tax-statements，
 *   即美国捐赠税务声明（Tax Statements）。V2.0 §16 明确要求删除所有地区特定税务
 *   逻辑，MOS-GOV 不判断法律问题，因此不能把税务入口挂在导航上。
 *
 * 本页只呈现事实（§16）：日期 · 金额 · 收入/支出 · 分类 · 付款方式 · 备注，
 * 并按 §19 给出月度、年度、分类三个维度。
 *
 * 口径说明（重要，不可用假数据掩盖）：
 *   本部署的 schema 中，财务事实表只有 deposit_dep（账目/存款单），其列为
 *   dep_ID / dep_Date / dep_Comment / dep_EnteredBy / dep_Closed / dep_Type ——
 *   没有金额列；也不存在逐笔收付款明细表。因此本页按「已录入账目」统计笔数与
 *   期间，金额列显示为真实的 0，并在页面上明确写出该限制，而不是编造数字。
 *
 * Expected variables:
 * - $esc       HTML 转义闭包
 * - $monthly   月度汇总 [['period','账目数','收入','支出','净额']]
 * - $yearly    年度汇总（结构同上）
 * - $byFund    分类汇总 [['fund','count','total']]
 * - $totals    合计 ['收入','支出','净额']
 * - $error     取数失败说明
 */

use ChurchCRM\dto\SystemURLs;

$sPageTitle = '财务报表';
$sPageSubtitle = '按月、按年、按分类查看账目汇总（地区中立，不含税务判断）';
$aBreadcrumbs = [
    [gettext('Finance'), SystemURLs::getRootPath() . '/finance/deposit/search'],
    [$sPageTitle],
];

$esc = $esc ?? static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

require __DIR__ . '/_style.php';

$money = static function ($amount) use ($esc): string {
    return '¥ ' . number_format((float) $amount, 2);
};

/** 期间汇总表（月度 / 年度共用）。 */
$periodTable = static function (array $rows) use ($esc, $money): void {
    if (empty($rows)) {
        echo '<div class="mg-empty"><div class="mg-empty-box">尚无账目数据。</div></div>';

        return;
    }
    echo '<table class="table table-sm align-middle mb-0">';
    echo '<thead><tr><th>期间</th><th class="text-end">账目数</th>'
       . '<th class="text-end">收入</th><th class="text-end">支出</th>'
       . '<th class="text-end">净额</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        echo '<tr>'
           . '<td>' . $esc($row['period']) . '</td>'
           . '<td class="text-end">' . (int) ($row['账目数'] ?? 0) . '</td>'
           . '<td class="text-end">' . $money($row['收入']) . '</td>'
           . '<td class="text-end">' . $money($row['支出']) . '</td>'
           . '<td class="text-end">' . $money($row['净额']) . '</td>'
           . '</tr>';
    }
    echo '</tbody></table>';
};
?>

<div class="mos-gov">
    <div class="mg-pagehead">
        <h2>财务报表</h2>
        <div class="mg-pagehead-note">
            只记录事实：日期、金额、收入/支出、分类、付款方式、备注。
            本系统不做税务申报，也不判断任何地区的法律资格。
        </div>
    </div>

    <?php if (!empty($error)): ?>
        <div class="alert alert-warning py-2"><?= $esc($error) ?></div>
    <?php endif; ?>

    <div class="mg-card">
        <div class="mg-sec-title">合计</div>
        <div class="mg-stats">
            <div class="mg-stat">
                <div class="mg-stat-value"><?= $money($totals['收入'] ?? 0) ?></div>
                <div class="mg-stat-label">收入</div>
            </div>
            <div class="mg-stat">
                <div class="mg-stat-value"><?= $money($totals['支出'] ?? 0) ?></div>
                <div class="mg-stat-label">支出</div>
            </div>
            <div class="mg-stat">
                <div class="mg-stat-value"><?= $money($totals['净额'] ?? 0) ?></div>
                <div class="mg-stat-label">净额</div>
            </div>
        </div>
        <div class="mg-sec-desc">
            统计口径：已录入的账目记录。当前数据模型未提供金额列与逐笔收付明细，
            故金额显示为 0；上方「账目数」为真实记录条数。
        </div>
    </div>

    <div class="mg-card">
        <div class="mg-sec-title">月度汇总</div>
        <?php $periodTable($monthly); ?>
    </div>

    <div class="mg-card">
        <div class="mg-sec-title">年度汇总</div>
        <?php $periodTable($yearly); ?>
    </div>

    <div class="mg-card">
        <div class="mg-sec-title">分类汇总</div>
        <div class="mg-sec-desc">分类沿用核心的献赠基金（Donation Fund）设置。</div>
        <?php if (empty($byFund)): ?>
            <div class="mg-empty"><div class="mg-empty-box">尚无分类数据。</div></div>
        <?php else: ?>
            <table class="table table-sm align-middle mb-0">
                <thead><tr><th>分类</th><th class="text-end">账目数</th><th class="text-end">合计</th></tr></thead>
                <tbody>
                <?php foreach ($byFund as $row): ?>
                    <tr>
                        <td><?= $esc($row['fund']) ?></td>
                        <td class="text-end"><?= (int) $row['count'] ?></td>
                        <td class="text-end"><?= $money($row['total']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>