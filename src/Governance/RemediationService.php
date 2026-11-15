<?php

/**
 * MOS-GOV V1.0 — 整改闭环服务（GOV-DEV-07）.
 *
 * 最小闭环映射（复用既有实体，不建第二套问题管理模块）：
 *
 *   发现问题     gov_issue（owner_person_id=问题负责人，status 生命周期）
 *   整改行动     gov_task（issue_id=问题，assignee=整改责任人，due_date=整改期限）
 *   证据与复核   gov_oversight（issue_id/task_id 挂问题与整改行动；
 *                evidence_ref/evidence_notes=证据引用与说明；
 *                outcome=pass/fail/partial=复核结论）
 *
 * 受控操作（通用实体路由之外的两个写入口）：
 *   submitEvidence()  整改责任人提交证据 —— 仅 open/in_progress 记录；
 *                     责任人本人或 permission.manage。
 *   review()          复核人给出结论 —— 仅 supervisor 本人或
 *                     permission.manage；**绝对禁止自批**
 *                     （actorPersonId === responsible_person_id 一律拒绝，
 *                     管理员也不例外 —— 白皮书未规定例外，故不设例外）；
 *                     pass/partial 必须已有证据（不得无证据通过）。
 *
 * 状态语义：
 *   - 复核结论产出 = oversight_status → completed（终态，经状态机校验，
 *     open 先显式走 in_progress，两步均留审计）。
 *   - fail 不删除、不改写原记录：问题、证据、复核意见全部保留；
 *     重新整改 = 用既有通用路由新建下一轮 task + oversight（同 issue_id）。
 *   - 提交证据绝不自动推导为通过 —— outcome 只能由 review() 写入。
 *
 * 审计：关键操作全部经审计器（默认 AuditService::audit：result 白名单、
 * reason 打码、失败返回 false —— 调用方路由将 false 转为 500，不假报成功）。
 *
 * 权限输入采用「路由层解析、服务层纯规则」：actorPersonId / hasManage 由
 * 调用方经授权引擎解析后传入，服务保持可脱离 ChurchCRM 会话测试。
 */

namespace ChurchCRM\Plugins\MosGov\Governance;

use ChurchCRM\Plugins\MosGov\Data\GovDataException;
use ChurchCRM\Plugins\MosGov\Data\GovRepository;
use ChurchCRM\Plugins\MosGov\Security\AuditService;

final class RemediationService
{
    private GovRepository $repo;

    /** @var callable(string,string,string,?int,string,string): bool */
    private $auditor;

    /**
     * @param ?callable(string,string,string,?int,string,string): bool $auditor
     *        审计器注入缝隙（与 DelegationService 的 connect 缝隙同理）：
     *        生产路由用默认 AuditService::audit；测试注入隔离连接。
     */
    public function __construct(?GovRepository $repo = null, ?callable $auditor = null)
    {
        $this->repo = $repo ?? new GovRepository();
        $this->auditor = $auditor ?? static fn (
            string $actor,
            string $action,
            string $resource,
            ?int $resourceId,
            string $result,
            string $reason
        ): bool => AuditService::audit($actor, $action, $resource, $resourceId, $result, $reason);
    }

    private function audit(string $actor, string $action, string $resource, ?int $resourceId, string $result, string $reason = ''): bool
    {
        return ($this->auditor)($actor, $action, $resource, $resourceId, $result, $reason);
    }

    /**
     * 提交整改证据.
     *
     * @return array{ok: bool, code: string, message: string}
     */
    public function submitEvidence(
        int $oversightId,
        string $actorName,
        ?int $actorPersonId,
        bool $hasManage,
        string $evidenceRef,
        string $evidenceNotes
    ): array {
        $row = $this->repo->find('oversight', $oversightId);
        if ($row === null) {
            return ['ok' => false, 'code' => 'not-found', 'message' => 'Oversight record not found.'];
        }

        $status = (string) ($row['oversight_status'] ?? '');
        if (!in_array($status, ['open', 'in_progress'], true)) {
            $this->deny($actorName, $oversightId, 'evidence-submit', 'oversight record is ' . $status . ' (terminal)');

            return ['ok' => false, 'code' => 'terminal', 'message' => 'This oversight record is already closed.'];
        }

        if (trim($evidenceRef) === '' && trim($evidenceNotes) === '') {
            return ['ok' => false, 'code' => 'empty-evidence', 'message' => 'Provide an evidence reference or evidence notes.'];
        }

        if (!$this->mayAct($actorPersonId, $hasManage, $row['responsible_person_id'] ?? null)) {
            $this->deny($actorName, $oversightId, 'evidence-submit', 'actor is not the responsible person');

            return ['ok' => false, 'code' => 'forbidden', 'message' => 'Only the responsible person may submit evidence.'];
        }

        try {
            $data = $row;
            $data['evidence_ref'] = mb_substr(trim($evidenceRef), 0, 190);
            $data['evidence_notes'] = trim($evidenceNotes);
            // 证据提交不改变结论；open → in_progress 体现「整改进行中」。
            if ($status === 'open') {
                $data['oversight_status'] = 'in_progress';
            }
            $this->repo->update('oversight', $oversightId, $data);
        } catch (GovDataException $e) {
            $this->audit($actorName, 'evidence-submit', 'oversight', $oversightId, 'error', $e->getMessage());

            return ['ok' => false, 'code' => 'invalid', 'message' => $e->getMessage()];
        }

        if (!$this->audit($actorName, 'evidence-submit', 'oversight', $oversightId, 'success', '')) {
            return ['ok' => false, 'code' => 'audit-failed', 'message' => 'Evidence saved but the audit record could not be written.'];
        }

        return ['ok' => true, 'code' => 'submitted', 'message' => 'Evidence submitted.'];
    }

    /**
     * 复核：给出 pass / fail / partial 结论并归档（终态）.
     *
     * @return array{ok: bool, code: string, message: string}
     */
    public function review(
        int $oversightId,
        string $actorName,
        ?int $actorPersonId,
        bool $hasManage,
        string $outcome,
        string $outcomeNotes
    ): array {
        $row = $this->repo->find('oversight', $oversightId);
        if ($row === null) {
            return ['ok' => false, 'code' => 'not-found', 'message' => 'Oversight record not found.'];
        }

        $status = (string) ($row['oversight_status'] ?? '');
        if (!in_array($status, ['open', 'in_progress'], true)) {
            $this->deny($actorName, $oversightId, 'review', 'oversight record is ' . $status . ' (terminal)');

            return ['ok' => false, 'code' => 'terminal', 'message' => 'This oversight record is already closed.'];
        }

        if (!in_array($outcome, ['pass', 'fail', 'partial'], true)) {
            return ['ok' => false, 'code' => 'invalid-outcome', 'message' => 'Outcome must be pass, fail or partial.'];
        }

        // 复核人：supervisor 本人或 permission.manage。
        if (!$this->mayAct($actorPersonId, $hasManage, $row['supervisor_person_id'] ?? null)) {
            $this->deny($actorName, $oversightId, 'review', 'actor is not the supervisor');

            return ['ok' => false, 'code' => 'forbidden', 'message' => 'Only the supervisor may review this record.'];
        }

        // 绝对禁止自批：整改责任人不得复核自己的整改（管理员也不例外；
        // 白皮书未规定例外，故不设例外）。responsible 为空时无法构成
        // 自批，此时复核资格仅由 supervisor/manage 判定。
        $responsible = $row['responsible_person_id'] ?? null;
        if ($responsible !== null && (int) $responsible !== 0 && $actorPersonId !== null
            && $actorPersonId === (int) $responsible) {
            $this->deny($actorName, $oversightId, 'review', 'self-approval denied: actor is the responsible person');

            return ['ok' => false, 'code' => 'self-approval', 'message' => 'The responsible person may not review their own remediation.'];
        }

        // pass/partial 必须已有证据；fail 允许直接退回（无需等待证据）。
        $hasEvidence = trim((string) ($row['evidence_ref'] ?? '')) !== ''
            || trim((string) ($row['evidence_notes'] ?? '')) !== '';
        if ($outcome !== 'fail' && !$hasEvidence) {
            return ['ok' => false, 'code' => 'no-evidence', 'message' => 'Evidence must be submitted before a pass/partial review.'];
        }

        try {
            // open → in_progress（显式起步，状态机不允许 open 直跳 completed）
            if ($status === 'open') {
                $data = $row;
                $data['oversight_status'] = 'in_progress';
                $this->repo->update('oversight', $oversightId, $data);
                $this->audit($actorName, 'review-started', 'oversight', $oversightId, 'success', '');
            }

            $data = $this->repo->find('oversight', $oversightId);
            $data['oversight_status'] = 'completed';
            $data['outcome'] = $outcome;
            $data['outcome_notes'] = trim($outcomeNotes);
            $data['completed_at'] = date('Y-m-d H:i:s');
            $this->repo->update('oversight', $oversightId, $data);
        } catch (GovDataException $e) {
            $this->audit($actorName, 'review', 'oversight', $oversightId, 'error', $e->getMessage());

            return ['ok' => false, 'code' => 'invalid', 'message' => $e->getMessage()];
        }

        if (!$this->audit($actorName, 'review', 'oversight', $oversightId, 'success', 'outcome ' . $outcome)) {
            return ['ok' => false, 'code' => 'audit-failed', 'message' => 'Review saved but the audit record could not be written.'];
        }

        return ['ok' => true, 'code' => $outcome === 'fail' ? 'returned' : 'approved', 'message' => 'Review recorded: ' . $outcome . '.'];
    }

    /** 责任人 / 监督人 / manage 三选一的通用判定。 */
    private function mayAct(?int $actorPersonId, bool $hasManage, $requiredPersonId): bool
    {
        if ($hasManage) {
            return true;
        }
        if ($actorPersonId === null || $requiredPersonId === null || (int) $requiredPersonId === 0) {
            return false;
        }

        return $actorPersonId === (int) $requiredPersonId;
    }

    private function deny(string $actorName, int $oversightId, string $action, string $reason): void
    {
        $this->audit($actorName, $action . '-denied', 'oversight', $oversightId, 'denied', $reason);
    }
}
