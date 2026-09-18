<?php

/**
 * MOS-GOV presentation-layer localization (简体中文).
 *
 * The user interface of MOS-GOV is Simplified Chinese. This file is the ONLY
 * place where English identifiers coming from the data layer are mapped to
 * Chinese display text, so:
 *
 *  - GovRepository (data layer), the authorization engine, ScopeResolver,
 *    IdentityService and LocalSecureMode stay untouched — they keep their
 *    English/internal vocabulary (entity keys, permission keys, scope types,
 *    enum values, decision reasons);
 *  - views render Chinese by passing registry labels through $mosGovT();
 *  - values submitted to the database are never translated (only the visible
 *    <option> text is).
 *
 * Unknown text is returned unchanged, so a new registry label degrades to
 * English instead of disappearing.
 *
 * Provided:
 * - $mosGovT(string): string                    label / phrase → 中文
 * - $mosGovEntityLabel(string $entity): string  entity key → 中文单数
 * - $mosGovEntityLabelPlural(string $entity): string
 * - $mosGovValue(string $value): string         enum 值 → 中文显示（显示用，不改库）
 * - $mosGovScopeLabel(string $label): string    scope label → 中文显示
 */

/** Entity key => [singular, plural] — covers the V0.1 entities and V0.2 tables. */
$mosGovEntityLabels = [
    'structure' => ['结构', '结构'],
    'body' => ['治理主体', '治理主体'],
    'role' => ['角色', '角色'],
    'appointment' => ['任命', '任命'],
    'responsibility' => ['职责', '职责'],
    'relationship' => ['关系', '关系'],
    'meeting' => ['会议', '会议'],
    'issue' => ['议题', '议题'],
    'decision' => ['决策', '决策'],
    'task' => ['任务', '任务'],
    'identity' => ['治理身份', '治理身份'],
    'identity_role' => ['身份角色', '身份角色'],
    'identity_scope' => ['身份范围', '身份范围'],
    'identity_permission' => ['身份权限', '身份权限'],
    'permission' => ['权限', '权限'],
    'role_permission' => ['角色权限', '角色权限'],
    'role_scope' => ['角色范围', '角色范围'],
    'scope' => ['范围', '范围'],
    'visibility_rule' => ['可见性规则', '可见性规则'],
];

/** English label / phrase => 中文. Keys are the registry's own label strings. */
$mosGovPhrases = [
    // entity labels / plurals
    'Structure' => '结构',
    'Structures' => '结构',
    'Body' => '治理主体',
    'Bodies' => '治理主体',
    'Role' => '角色',
    'Roles' => '角色',
    'Appointment' => '任命',
    'Appointments' => '任命',
    'Responsibility' => '职责',
    'Responsibilities' => '职责',
    'Relationship' => '关系',
    'Relationships' => '关系',
    'Meeting' => '会议',
    'Meetings' => '会议',
    'Issue' => '议题',
    'Issues' => '议题',
    'Decision' => '决策',
    'Decisions' => '决策',
    'Task' => '任务',
    'Tasks' => '任务',
    'Governance' => '教会治理',
    'GOVERNANCE' => '教会治理',
    'Governance Identity' => '治理身份',
    'Governance Identities' => '治理身份',
    'Identity' => '治理身份',
    'Identities' => '治理身份',
    'Identity Role' => '身份角色',
    'Identity Roles' => '身份角色',
    'Identity Scope' => '身份范围',
    'Identity Scopes' => '身份范围',
    'Identity Permission' => '身份权限',
    'Identity Permissions' => '身份权限',
    'Permission' => '权限',
    'Permissions' => '权限',
    'Role Permission' => '角色权限',
    'Role Permissions' => '角色权限',
    'Role Scope' => '角色范围',
    'Role Scopes' => '角色范围',
    'Scope' => '范围',
    'Scopes' => '范围',
    'Visibility Rule' => '可见性规则',
    'Visibility Rules' => '可见性规则',

    // visible seed / role names used by the V0.2 governance model
    'Governance Administrator' => '治理管理员',
    'Governance Role Registry' => '治理角色注册表',
    'Role Registry' => '角色注册表',
    'System' => '系统',
    'SYSTEM' => '系统',

    // field labels
    'Action' => '动作',
    'Appointed by' => '任命人',
    'Assignee' => '承办人',
    'Authorized by' => '授权人',
    'Child structures' => '下级结构',
    'ChurchCRM event ID' => 'ChurchCRM 活动 ID',
    'Closed at' => '关闭时间',
    'Code' => '编码',
    'Completed at' => '完成时间',
    'Created by user ID' => '创建用户 ID',
    'Decided at' => '决议时间',
    'Decided by' => '决议人',
    'Decision status' => '决策状态',
    'Decision text' => '决策内容',
    'Description' => '说明',
    'Display name override' => '显示名称覆盖',
    'Due date' => '截止日期',
    'End date' => '结束日期',
    'From ID' => '起点 ID',
    'From type' => '起点类型',
    'Grant mode' => '授予方式',
    'Identity status' => '身份状态',
    'Information level' => '信息分级',
    'Location' => '地点',
    'Meeting date' => '会议日期',
    'Member since' => '加入时间',
    'Minutes' => '会议记录',
    'Name' => '名称',
    'Notes' => '备注',
    'Opened at' => '开启时间',
    'Owner' => '负责人',
    'Parent structure' => '上级结构',
    'Permission key' => '权限键',
    'Person' => '人员',
    'Priority' => '优先级',
    'Reason' => '事由',
    'Relationship type' => '关系类型',
    'Resource type' => '资源类型',
    'Review date' => '复核日期',
    'Risk level' => '风险等级',
    'Rule type' => '规则类型',
    'Scope ID' => '范围 ID',
    'Scope mode' => '范围模式',
    'Scope type' => '范围类型',
    'Sort order' => '排序',
    'Source' => '来源',
    'Source ID' => '来源 ID',
    'Start date' => '开始日期',
    'Status' => '状态',
    'Title' => '标题',
    'To ID' => '终点 ID',
    'To type' => '终点类型',
    'Type' => '类型',

    // common scope labels (the rest are normalized by $mosGovScopeLabel)
    'Global (entire system)' => '全局（整个系统）',
    'Church (whole congregation)' => '教会（全体会众）',
];

/** Enum value => 中文（仅用于显示；提交值始终为英文原值）。 */
$mosGovValues = [
    // shared status
    'active' => '有效',
    'inactive' => '停用',
    'archived' => '归档',
    'suspended' => '暂停',
    // priority
    'low' => '低',
    'normal' => '普通',
    'high' => '高',
    'critical' => '紧急',
    // meeting
    'planned' => '计划中',
    'held' => '已召开',
    'cancelled' => '已取消',
    // issue
    'open' => '待处理',
    'in_progress' => '处理中',
    'resolved' => '已解决',
    'closed' => '已关闭',
    // decision
    'proposed' => '提案中',
    'approved' => '已通过',
    'rejected' => '已否决',
    'superseded' => '已取代',
    // appointment / workflow
    'end' => '结束',
    // task
    'done' => '已完成',
    // risk
    'medium' => '中',
    // grant modes
    'grant' => '授予',
    'deny' => '拒绝',
    'allow' => '允许',
    // scope sources / misc engine display values
    'manual_assignment' => '手工指派',
    'appointment' => '随任命',
    'inherited' => '继承',
    'role' => '角色',
    'direct' => '直接',
    'inherit' => '继承',
    'self' => '本人',
    'system' => '系统',
    'committee' => '委员会',
    // actions
    'view' => '查看',
    'create' => '新建',
    'edit' => '编辑',
    'submit' => '提交',
    'approve' => '审批',
    'publish' => '发布',
    'close' => '关闭',
    'export' => '导出',
    'feedback' => '反馈',
    'manage' => '管理',
];

/** Label / phrase → 中文 (passthrough when unknown). */
$mosGovT = static function ($text) use ($mosGovPhrases): string {
    $text = (string) $text;

    return $mosGovPhrases[$text] ?? $text;
};

/** Enum value → 中文 (passthrough when unknown, e.g. permission keys). */
$mosGovValue = static function ($value) use ($mosGovValues): string {
    $value = (string) $value;

    return $mosGovValues[$value] ?? $value;
};

/** Scope label → 中文显示（不改变底层 scope 值或 ID）。 */
$mosGovScopeLabel = static function ($label) use ($mosGovPhrases): string {
    $label = (string) $label;

    if (isset($mosGovPhrases[$label])) {
        return $mosGovPhrases[$label];
    }

    $prefixMap = [
        'Structure: ' => '结构：',
        'Body: ' => '治理主体：',
        'Role: ' => '角色：',
        'Person: ' => '人员：',
        'Group #' => '小组 #',
        'Ministry #' => '事工 #',
        'Activity #' => '活动 #',
        'Project #' => '项目 #',
    ];

    foreach ($prefixMap as $prefix => $translatedPrefix) {
        if (str_starts_with($label, $prefix)) {
            return $translatedPrefix . substr($label, strlen($prefix));
        }
    }

    return $label;
};

/** Entity key → 中文单数标签. */
$mosGovEntityLabel = static function (string $entity) use ($mosGovEntityLabels): string {
    return $mosGovEntityLabels[$entity][0] ?? $entity;
};

/** Entity key → 中文复数标签（中文无单复数，返回同一标签）. */
$mosGovEntityLabelPlural = static function (string $entity) use ($mosGovEntityLabels): string {
    return $mosGovEntityLabels[$entity][1] ?? $entity;
};
