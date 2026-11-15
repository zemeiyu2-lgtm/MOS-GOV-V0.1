-- ============================================================================
-- MOS-GOV 004_healthboard_remediation.sql — V1.0 整改闭环（GOV-DEV-07）
--
-- 最小增量（全部 nullable，向前兼容；遵循 001/002/003 风格：无外键，
-- 整数引用 + 应用层解析）。幂等：ADD COLUMN IF NOT EXISTS（MariaDB 10+）。
--
-- 1) gov_task.issue_id
--    整改行动直接挂问题。此前 task 只能经 decision 间接挂 issue，
--    强制"整改必先决策"会扭曲语义（整改行动不是决策）。
-- 2) gov_oversight.issue_id
--    复核记录直接挂问题，形成 问题→整改→复核 可追溯链。
-- 3) gov_issue.regulation_ref
--    外部规范依据引用（白皮书 §规范层只读机制：MOS 理论规范外置于
--    MOS 知识库，平台只读、只提供证据 —— 因此存引用文本，不建规范实体）。
--
-- 审计双写职责、result 白名单同 003 注释：gov_audit_log 只存治理证据，
-- 不记录敏感个人数据。
-- ============================================================================

ALTER TABLE gov_task
    ADD COLUMN IF NOT EXISTS issue_id INT UNSIGNED NULL AFTER decision_id,
    ADD INDEX IF NOT EXISTS idx_task_issue (issue_id);

ALTER TABLE gov_oversight
    ADD COLUMN IF NOT EXISTS issue_id INT UNSIGNED NULL AFTER decision_id,
    ADD INDEX IF NOT EXISTS idx_oversight_issue (issue_id);

ALTER TABLE gov_issue
    ADD COLUMN IF NOT EXISTS regulation_ref VARCHAR(190) NULL AFTER description;
