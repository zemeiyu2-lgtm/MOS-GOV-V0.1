<?php

/**
 * MOS-GOV V1.0 — 统一治理健康盘数据服务.
 *
 * 五类指标的唯一定义与查询来源。视图不写 SQL，路由不做统计 —— 指标口径全部
 * 收敛在本类，且每条 SQL 只引用 003 迁移后真实存在的表和字段：
 *
 *   gov_task        status, due_date, completed_at, priority, assignee_person_id
 *   gov_decision    decision_status, decided_at, review_date
 *   gov_delegation  status, start_date, end_date, revoked_at
 *   gov_oversight   oversight_status, outcome, evidence_ref, evidence_notes, completed_at
 *   gov_audit_log   actor, action, resource, result, created_at
 *
 * 状态值一律引用 GovRepository 白名单常量（V1.0 healthboard 段），不出现字符串魔法值
 * 之外的新状态名。所有行级结果由路由层按 GovAuthorization::can(view) 过滤后再计数；
 * 本类只负责取数与分桶。
 *
 * V1.0 已记录限制（见 GOV-DEV-06 报告）：
 *  - 提醒窗口为类常量（30 天），库内无设置表可存配置；
 *  - 行级过滤采用「先取行（上限 1000）后过滤」，与 dashboard 的 recent 过滤一致，
 *    超大数据量时需改为 SQL 级 scope 过滤（留 V2）。
 */

namespace ChurchCRM\Plugins\MosGov\Data;

use Propel\Runtime\Propel;
use Propel\Runtime\Connection\ConnectionInterface;

final class HealthboardService
{
    /** 到期授权提醒窗口（天）。V1.0 常量，无 gov_settings 表可存。 */
    public const DELEGATION_WARNING_DAYS = 30;

    /** 单指标行数上限，防御性限制，与 dashboard recent 行为一致。 */
    private const ROW_LIMIT = 1000;

    /** 本次取数是否触达上限（>ROW_LIMIT 行时为 true，计数只反映前 ROW_LIMIT 行）。 */
    private bool $truncated = false;

    private ConnectionInterface $conn;

    public function __construct(?ConnectionInterface $conn = null)
    {
        $this->conn = $conn ?? Propel::getConnection();
    }

    /**
     * 五类指标快照。每个指标要么有 rows（可再经权限过滤），要么有 error。
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return [
            'generatedAt' => date('Y-m-d H:i'),
            'warningDays' => self::DELEGATION_WARNING_DAYS,
            'overdue' => $this->overdueTasks(),
            'delegation' => $this->delegations(),
            'decision' => $this->decisions(),
            'oversight' => $this->oversight(),
            'audit' => $this->audit(),
        ];
    }

    // ---------------------------------------------------------- 指标 1：逾期任务

    /**
     * 逾期任务：status ∈ {open, in_progress}（未完成、非终态）
     *          AND due_date IS NOT NULL AND due_date < CURDATE()。
     * 排除：done / cancelled（终态）；due_date 为空的未完成任务不计入逾期，
     * 单独以 noDueOpen 计数呈现（缺失数据处理，避免静默丢数）。
     */
    private function overdueTasks(): array
    {
        $rows = $this->rows(
            'SELECT id, title, status, priority, due_date, assignee_person_id
               FROM gov_task
              WHERE status IN (:s1, :s2)
                AND due_date IS NOT NULL
                AND due_date < CURDATE()
              ORDER BY due_date ASC',
            [':s1' => 'open', ':s2' => 'in_progress']
        );

        return ['rows' => $rows, 'noDueOpen' => $this->scalar(
            'SELECT COUNT(*) FROM gov_task WHERE status IN (:s1, :s2) AND due_date IS NULL',
            [':s1' => 'open', ':s2' => 'in_progress']
        )];
    }

    // ---------------------------------------------------------- 指标 2：到期授权

    /**
     * 到期授权，四种状态严格区分：
     *   expired      status = 'expired'（已失效，终态）
     *   revoked      status = 'revoked'（已撤销，终态；revoked_at 为佐证）
     *   staleActive  status = 'active' AND end_date < CURDATE()
     *                （已过有效期但状态未走完状态机 —— 数据一致性待办）
     *   expiringSoon status = 'active' AND end_date ∈ [CURDATE(), CURDATE()+30 天]
     *                （即将到期，提醒窗口内）
     * 排除：draft（未生效，与到期无关）单独计数；active 且无 end_date /
     *       未到窗口的计入 activeHealthy。
     */
    private function delegations(): array
    {
        $rows = $this->rows(
            'SELECT id, title, delegator_person_id, delegate_person_id,
                    supervisor_person_id, permission_id, responsibility_id,
                    scope_description, start_date, end_date, status, revoked_at
               FROM gov_delegation
              WHERE status IN (:s1, :s2, :s3)
              ORDER BY end_date ASC',
            [':s1' => 'active', ':s2' => 'expired', ':s3' => 'revoked']
        );

        $today = date('Y-m-d');
        $horizon = date('Y-m-d', strtotime('+' . self::DELEGATION_WARNING_DAYS . ' days'));

        $expired = $revoked = $staleActive = $expiringSoon = [];
        foreach ($rows as $row) {
            $end = $row['end_date'] ?? null;
            switch ($row['status']) {
                case 'expired':
                    $expired[] = $row;
                    break;
                case 'revoked':
                    $revoked[] = $row;
                    break;
                case 'active':
                    if ($end !== null && $end !== '' && $end < $today) {
                        $staleActive[] = $row;
                    } elseif ($end !== null && $end !== '' && $end <= $horizon) {
                        $expiringSoon[] = $row;
                    }
                    // active 且无 end_date / 窗口外 → 健康在期，不计入
                    break;
            }
        }

        return [
            'expired' => $expired,
            'revoked' => $revoked,
            'staleActive' => $staleActive,
            'expiringSoon' => $expiringSoon,
            'draft' => $this->scalar('SELECT COUNT(*) FROM gov_delegation WHERE status = :s', [':s' => 'draft']),
        ];
    }

    // ---------------------------------------------------------- 指标 3：决策积压

    /**
     * 决策积压：decision_status ∈ {proposed, approved}（非终态）为积压，
     *          按状态分列；rejected / superseded 为终态，排除出积压、仅计数。
     * 待复核：decision_status = 'approved' AND review_date < CURDATE()
     *         （已批准且复核日已过）。decided_at 为空的 approved 单独计数
     *         （缺失数据处理：批准但无决定时间）。
     */
    private function decisions(): array
    {
        $rows = $this->rows(
            'SELECT id, title, decision_status, decided_at, review_date, meeting_id, issue_id
               FROM gov_decision
              ORDER BY created_at DESC'
        );

        $today = date('Y-m-d');
        $proposed = $approved = $reviewOverdue = [];
        $rejected = $superseded = 0;
        $approvedNoDecidedAt = 0;

        foreach ($rows as $row) {
            switch ($row['decision_status']) {
                case 'proposed':
                    $proposed[] = $row;
                    break;
                case 'approved':
                    $approved[] = $row;
                    if (empty($row['decided_at'])) {
                        ++$approvedNoDecidedAt;
                    }
                    $review = $row['review_date'] ?? null;
                    if ($review !== null && $review !== '' && $review < $today) {
                        $reviewOverdue[] = $row;
                    }
                    break;
                case 'rejected':
                    ++$rejected;
                    break;
                case 'superseded':
                    ++$superseded;
                    break;
            }
        }

        return [
            'proposed' => $proposed,
            'approved' => $approved,
            'reviewOverdue' => $reviewOverdue,
            'rejected' => $rejected,
            'superseded' => $superseded,
            'approvedNoDecidedAt' => $approvedNoDecidedAt,
        ];
    }

    // ---------------------------------------------------------- 指标 4：监督覆盖

    /**
     * 监督覆盖，按 oversight_status 白名单分列：
     *   待监督 open / 进行中 in_progress / 已完成 completed；
     *   cancelled 排除出覆盖统计、单独计数。
     * 待补证据：status ∈ {open, in_progress} AND evidence_ref 与 evidence_notes
     *           均为空（证据渠道缺失）。
     * 待收尾（结果已出未归档）：outcome ∈ {pass, fail, partial}
     *           AND oversight_status ∈ {open, in_progress}。
     *   —— 白名单中无「待复核」状态；「结果已出但记录未完成」是真实字段上
     *      最接近「待复核」的可验证口径，V1.0 采用之（已在报告说明）。
     */
    private function oversight(): array
    {
        $rows = $this->rows(
            'SELECT id, title, oversight_status, outcome, supervisor_person_id,
                    responsible_person_id, decision_id, task_id, responsibility_id,
                    evidence_ref, evidence_notes, due_date, completed_at
               FROM gov_oversight
              ORDER BY created_at DESC'
        );

        $open = $inProgress = $missingEvidence = $awaitingClosure = $completed = $failedReview = [];
        $cancelled = 0;

        foreach ($rows as $row) {
            switch ($row['oversight_status']) {
                case 'open':
                    $open[] = $row;
                    break;
                case 'in_progress':
                    $inProgress[] = $row;
                    break;
                case 'completed':
                    $completed[] = $row;
                    // 复核退回（V1.0 DEV-07）：结论为 fail 的已归档记录 ——
                    // 问题未解决、等待新一轮整改。规格书仪表盘要求呈现
                    // 「重复出现的问题」，该计数即其数据来源。
                    if (($row['outcome'] ?? '') === 'fail') {
                        $failedReview[] = $row;
                    }
                    break;
                case 'cancelled':
                    ++$cancelled;
                    continue 2;
            }

            // 证据缺口与待归档只对未完成记录（open/in_progress）统计：
            // completed 记录即使证据为空也是历史事实，不再补救。
            if ($row['oversight_status'] === 'open' || $row['oversight_status'] === 'in_progress') {
                $noEvidence = empty($row['evidence_ref']) && empty($row['evidence_notes']);
                if ($noEvidence) {
                    $missingEvidence[] = $row;
                }
                if (in_array($row['outcome'], ['pass', 'fail', 'partial'], true)) {
                    $awaitingClosure[] = $row;
                }
            }
        }

        return [
            'open' => $open,
            'inProgress' => $inProgress,
            'missingEvidence' => $missingEvidence,
            'awaitingClosure' => $awaitingClosure,
            'completed' => $completed,
            'failedReview' => $failedReview,
            'cancelled' => $cancelled,
        ];
    }

    // ---------------------------------------------------------- 指标 5：审计活动

    /**
     * 审计活动：gov_audit_log 按 result ∈ {success, denied, error}
     *          （AuditService::RESULTS 白名单）统计，双窗口 7 / 30 天，
     *          锚点为数据库 NOW()。窗口外不计入。无行级权限过滤 ——
     *          该卡片由路由层 gate 在 permission.manage 之后。
     */
    private function audit(): array
    {
        $windows = [];
        foreach ([7 => 'w7', 30 => 'w30'] as $days => $key) {
            try {
                $stmt = $this->conn->prepare(
                    'SELECT result, COUNT(*) AS c
                       FROM gov_audit_log
                      WHERE created_at >= DATE_SUB(NOW(), INTERVAL ' . (int) $days . ' DAY)
                      GROUP BY result'
                );
                $stmt->execute();
                $counts = ['success' => 0, 'denied' => 0, 'error' => 0, 'total' => 0];
                foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                    $c = (int) $r['c'];
                    if (isset($counts[$r['result']])) {
                        $counts[$r['result']] = $c;
                    }
                    $counts['total'] += $c;
                }
                $windows[$key] = $counts;
            } catch (\PDOException $e) {
                $windows[$key] = null;
            }
        }

        return ['w7' => $windows['w7'], 'w30' => $windows['w30']];
    }

    // --------------------------------------------------------------- helpers

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rows(string $sql, array $params = []): array
    {
        $stmt = $this->conn->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->execute();

        // DEV-08 风险修复：取上限 +1 行用于检测截断——触顶时计数是下限值，
        // 必须让视图明示「≥N」而不是静默把截断数当完整总数展示。
        $all = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        if (count($all) > self::ROW_LIMIT) {
            $this->truncated = true;

            return array_slice($all, 0, self::ROW_LIMIT);
        }

        return $all;
    }

    /** 本实例取数是否触达行上限（视图据此提示计数为下限值）。 */
    public function truncated(): bool
    {
        return $this->truncated;
    }

    private function scalar(string $sql, array $params = []): int
    {
        $stmt = $this->conn->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }
}
