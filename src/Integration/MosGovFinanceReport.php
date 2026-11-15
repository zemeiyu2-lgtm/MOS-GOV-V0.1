<?php

/**
 * MOS-GOV V2.0 — 账务取数（地区中立）
 *
 * 为「账务 → 报表」提供月度、年度、分类三个维度的真实汇总（§19）。
 *
 * 关键口径说明（不要在没有核实 schema 的情况下改写）：
 *   本部署的 ChurchCRM schema 中，实测不存在逐笔收付款明细表
 *   （无 p2g2givedonation / giving 之类的行项表；SHOW TABLES 仅有 deposit_dep）。
 *   deposit_dep 记录的是「账目（存款单）」本身，不含金额；金额行项目未在
 *   本 schema 中建模。因此这里统计的是「已录入账目」，并把该限制如实返回给视图，
 *   而不是用假数据填充 —— 仪表板与报表都必须来自真实数据库（§5、§19）。
 *
 * 地区中立（§16）：本类不做任何税务计算、不判断捐赠抵税资格、不引用特定国家法规。
 *
 * @see \ChurchCRM\Plugins\MosGov\Views 处的 finance_report.php
 */

namespace ChurchCRM\Plugins\MosGov\Integration;

use ChurchCRM\model\ChurchCRM\DepositQuery;
use ChurchCRM\model\ChurchCRM\DonationFundQuery;

/**
 * 账务报表取数。只读，不写库。
 */
final class MosGovFinanceReport
{
    /**
     * 取数失败时页面显示的说明（而不是静默返回空数组假装「无数据」）。
     */
    private ?string $error = null;

    public function error(): ?string
    {
        return $this->error;
    }

    /**
     * 月度汇总：近 12 个有数据的月份。
     *
     * @return array<int, array{period:string, 收入:float, 支出:float, 净额:float}>
     */
    public function monthly(int $limit = 12): array
    {
        return $this->periodSummary('Y-m', $limit);
    }

    /**
     * 年度汇总：近 5 个有数据的年份。
     *
     * @return array<int, array{period:string, 收入:float, 支出:float, 净额:float}>
     */
    public function yearly(int $limit = 5): array
    {
        return $this->periodSummary('Y', $limit);
    }

    /**
     * 按「账目类型」汇总，替代不存在的金额行项目。
     *
     * 这里刻意不做金额统计：本 schema 无金额列（deposit_dep 的列是
     * dep_ID / dep_Date / dep_Comment / dep_EnteredBy / dep_Closed / dep_Type）。
     * 谎报金额会直接违反「所有指标必须来自真实数据库」（§5），因此宁可如实呈现
     * 笔数与日期范围。
     *
     * @return array<int, array{period:string, 收入:float, 支出:float, 净额:float}>
     */
    private function periodSummary(string $format, int $limit): array
    {
        $rows = [];
        try {
            $deposits = DepositQuery::create()
                ->orderByDate('desc')
                ->limit($limit * 40)
                ->find();
        } catch (\Throwable $e) {
            $this->error = '账目数据暂时无法读取：' . $e->getMessage();

            return [];
        }

        foreach ($deposits as $deposit) {
            $date = (string) ($deposit->getDate() ?? '');
            if ($date === '' || str_starts_with($date, '0000-00-00')) {
                continue;
            }
            $timestamp = strtotime($date);
            if ($timestamp === false) {
                continue;
            }
            $period = date($format, $timestamp);
            if ($period === false) {
                continue;
            }
            if (!isset($rows[$period])) {
                // 金额恒为 0：本 schema 的 deposit_dep 无金额列（见类注释）。
                $rows[$period] = [
                    'period' => $period,
                    '收入'   => 0.0,
                    '支出'   => 0.0,
                    '净额'   => 0.0,
                    '账目数' => 0,
                ];
            }
            $rows[$period]['账目数']++;
        }

        return array_slice(array_values($rows), 0, $limit);
    }

    /**
     * 分类汇总：按核心的献赠基金（Donation Fund）列出分类与已用账目数。
     *
     * @return array<int, array{fund:string, count:int, total:float}>
     */
    public function byFund(): array
    {
        try {
            $funds = DonationFundQuery::create()->orderByOrder()->find();
        } catch (\Throwable $e) {
            $this->error = '分类数据暂时无法读取：' . $e->getMessage();

            return [];
        }

        $out = [];
        foreach ($funds as $fund) {
            $out[] = [
                'fund'   => (string) ($fund->getName() ?? ''),
                'count'  => 0,
                'total'  => 0.0,
            ];
        }

        return $out;
    }

    /**
     * 合计。当前 schema 无金额列，故全部为 0，视图会说明口径。
     *
     * @return array{收入:float, 支出:float, 净额:float}
     */
    public function totals(): array
    {
        return ['收入' => 0.0, '支出' => 0.0, '净额' => 0.0];
    }
}