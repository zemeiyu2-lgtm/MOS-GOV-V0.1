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
    'role_scope' => ['角色治理范围', '角色治理范围'],
    'scope' => ['治理范围', '治理范围'],
    'visibility_rule' => ['信息可见性规则', '信息可见性规则'],
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
    'Scope ID' => '治理范围 ID',
    'Scope mode' => '治理范围模式',
    'Scope type' => '治理范围类型',
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
    'approved' => '已批准',
    'rejected' => '已拒绝',
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
    // scope types
    'global' => '全局',
    'church' => '教会',
    'structure' => '结构',
    'body' => '治理主体',
    'ministry' => '事工',
    'group' => '小组',
    'activity' => '活动',
    'project' => '项目',
    'person' => '人员',
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

/**
 * 引用/装饰标签 → 中文显示（display only）。
 *
 * 路由层的装饰标签形如「名称 (#12)」或「角色 — 人员 (#12)」，名称来自数据库
 * 原值（可能是英文种子名，如 Governance Role Registry）。此助手在不改动
 * 数据与路由逻辑的前提下，仅对显示文本做映射：
 *   - 末尾的「(#n)」内部编号保持原样；
 *   - 名称部分先经枚举映射（committee → 委员会 等），再经短语映射
 *     （Governance Administrator → 治理管理员 等）；
 *   - 未知文本原样透出，绝不虚构翻译。
 */
$mosGovDecorLabel = static function ($label) use ($mosGovT, $mosGovValue): string {
    $label = (string) $label;

    // 尾部的「(#12)」或「[E01]」内部标识保持原样，仅映射名称部分。
    if (preg_match('/^(.*\S)\s*([(\[])([^)\]]*)([)\]])$/u', $label, $m)) {
        return $mosGovT($mosGovValue($m[1])) . ' ' . $m[2] . $m[3] . $m[4];
    }

    return $mosGovT($mosGovValue($label));
};

/**
 * 冻结层用户可见消息 → 中文（display only）。
 *
 * src/Data（GovRepository / GovDataException）、src/Security（GovernancePolicy
 * 拒绝理由）与 src/Governance（IdentityService）属于冻结边界，内部消息保持
 * 英文原值不变；本映射只在其文本到达用户眼前时转换为中文。
 *
 * 处理顺序：精确匹配 → 已知中文前缀递归 → 模板正则（其中标签部分
 * 复用 $mosGovT，枚举值复用 $mosGovValue）→ 原样透出（绝不虚构翻译）。
 *
 * Provided:
 * - $mosGovMsg(string $message): string
 */

$mosGovMessages = [
    // --- GovernancePolicy deny reasons（拒绝页「引擎结论」） ---
    'You must be signed in.' => '请先登录。',
    'No active governance identity is linked to your account.'
        => '你的账号尚未挂接生效的治理身份。',
    'Unsupported action.' => '不支持的操作。',
    'This action is not defined in the permission registry.'
        => '该操作不在权限注册表白名单内。',
    'An explicit authorization denial applies to this permission.'
        => '该权限存在一条显式拒绝授权。',
    'Your governance roles do not include this permission.'
        => '你的治理角色不包含此权限。',
    'None of your appointments is currently active.' => '你当前没有生效的任命。',
    'This record is outside your governance scope.' => '该记录不在你的治理范围内。',
    'This information is protected (level P5).' => '此信息受权限保护（P5 级）。',
    'Your information level does not allow this.' => '你的信息分级不允许查看此内容。',
    'Manage actions are reserved for the governance administrator role.'
        => '管理类操作仅限治理管理员角色使用。',

    // --- GovAuthorization::writeDeniedMessage() ---
    'Modifying MOS-GOV governance data requires ChurchCRM administrator rights.'
        => '修改 MOS-GOV 治理数据需要 ChurchCRM 管理员权限。',

    // --- GovRepository（src/Data，冻结层） ---
    'Unknown governance entity.' => '未知的治理数据类型。',
    'Governance data is currently unavailable.' => '治理数据当前不可用。',
    'Please correct the highlighted fields.' => '请修正标红的字段后重新提交。',

    // --- 校验消息（固定文案 + 跨字段语义约束） ---
    'End date cannot be before the start date.' => '结束日期不能早于开始日期。',
    'A responsibility must belong to a role or to an appointment.'
        => '职责必须归属于某个治理角色或任命。',
    'A decision must reference an issue or a meeting.'
        => '决策必须关联一个议题或会议。',
    'Selected ChurchCRM person does not exist.' => '所选 ChurchCRM 人员不存在。',

    // --- IdentityService（src/Governance，冻结层） ---
    'The ChurchCRM person does not exist.' => '该 ChurchCRM 人员不存在。',
    'The governance identity does not exist.' => '该治理身份不存在。',
    'The governance role does not exist.' => '该治理角色不存在。',
    'The appointment does not exist.' => '该任命不存在。',
    'This identity already holds this role.' => '该治理身份已挂接此治理角色。',
    'Grant mode must be grant or deny.' => '授予方式必须为「授予（grant）」或「拒绝（deny）」。',
    'Unknown permission.' => '未知权限。',
    'Critical permissions cannot be granted as a personal override.'
        => '紧急（critical）级别权限不能以个人覆盖方式授予。',
    'Unknown scope type.' => '未知治理范围类型。',
    'Unknown scope source type.' => '未知治理范围来源类型。',

    // --- IdentityService 逐字段校验 ---
    'Unknown person.' => '人员不存在。',
    'Unknown identity.' => '治理身份不存在。',
    'Unknown role.' => '治理角色不存在。',
    'Unknown appointment.' => '任命不存在。',
    'Role already attached.' => '该治理角色已挂接。',
    'Risk level critical.' => '风险等级为紧急（critical）。',
];

$mosGovMsg = null; // 占位，随后以自引用闭包赋值（支持「不允许导出：」前缀递归）。

$mosGovMsg = static function ($message) use ($mosGovT, $mosGovValue, $mosGovMessages, &$mosGovMsg): string {
    $message = (string) $message;

    if (isset($mosGovMessages[$message])) {
        return $mosGovMessages[$message];
    }

    // 已知中文前缀 + 英文原因（如导出拒绝页）。
    if (str_starts_with($message, '不允许导出：')) {
        return '不允许导出：' . $mosGovMsg(mb_substr($message, 6));
    }

    // --- GovRepository 顶层消息模板 ---
    if (preg_match('/^Unable to load (.+) records\.$/', $message, $m)) {
        return '无法加载「' . $mosGovT($m[1]) . '」记录列表。';
    }
    if (preg_match('/^Unable to load (.+) record\.$/', $message, $m)) {
        return '无法加载「' . $mosGovT($m[1]) . '」记录。';
    }
    if (preg_match('/^Unable to save the (.+) record\.$/', $message, $m)) {
        return '无法保存「' . $mosGovT($m[1]) . '」记录。';
    }
    if (preg_match('/^Unable to delete the (.+) record\.$/', $message, $m)) {
        return '无法删除「' . $mosGovT($m[1]) . '」记录。';
    }
    if (preg_match('/^Unsupported filter on (.+)\.$/', $message, $m)) {
        return '不支持对「' . $mosGovT($m[1]) . '」使用该筛选条件。';
    }
    if (preg_match('/^Unsupported sort on (.+)\.$/', $message, $m)) {
        return '不支持对「' . $mosGovT($m[1]) . '」按该字段排序。';
    }
    if (preg_match('/^Unsupported sort direction on (.+)\.$/', $message, $m)) {
        return '不支持对「' . $mosGovT($m[1]) . '」使用该排序方向。';
    }

    // --- 逐字段校验模板（{Label} is required. 等） ---
    if (preg_match('/^(.+?) is required\.$/', $message, $m)) {
        return '「' . $mosGovT($m[1]) . '」为必填项。';
    }
    if (preg_match('/^(.+?) is invalid\.$/', $message, $m)) {
        return '「' . $mosGovT($m[1]) . '」填写无效。';
    }
    if (preg_match('/^(.+?) is too long\.$/', $message, $m)) {
        return '「' . $mosGovT($m[1]) . '」内容过长。';
    }
    if (preg_match('/^(.+?) must be a whole number of (\d+) or greater\.$/', $message, $m)) {
        return '「' . $mosGovT($m[1]) . '」必须是不小于 ' . $m[2] . ' 的整数。';
    }
    if (preg_match('/^(.+?) must have the format YYYY-MM-DD\.$/', $message, $m)) {
        return '「' . $mosGovT($m[1]) . '」必须采用 YYYY-MM-DD 日期格式。';
    }
    if (preg_match('/^(.+?) is not a valid calendar date\.$/', $message, $m)) {
        return '「' . $mosGovT($m[1]) . '」不是有效的日历日期。';
    }
    if (preg_match('/^(.+?) must be a valid date and time\.$/', $message, $m)) {
        return '「' . $mosGovT($m[1]) . '」必须是有效的日期时间。';
    }
    if (preg_match('/^(.+?) cannot contain HTML tags\.$/', $message, $m)) {
        return '「' . $mosGovT($m[1]) . '」不能包含 HTML 标签。';
    }
    if (preg_match('/^(.+?) contains invalid characters\.$/', $message, $m)) {
        return '「' . $mosGovT($m[1]) . '」包含无效字符。';
    }
    if (preg_match('/^(.+?) must be a ChurchCRM person ID\.$/', $message, $m)) {
        return '「' . $mosGovT($m[1]) . '」必须填写 ChurchCRM 人员 ID（数字编号）。';
    }
    if (preg_match('/^(.+?) must be one of: (.+)\.$/', $message, $m)) {
        $options = array_map(
            static fn ($opt) => $mosGovValue(trim($opt)),
            explode(', ', $m[2])
        );

        return '「' . $mosGovT($m[1]) . '」必须是以下之一：' . implode('、', $options) . '。';
    }
    if (preg_match('/^Selected (.+) does not exist\.$/', $message, $m)) {
        return '所选' . $mosGovT(ucwords($m[1])) . '不存在。';
    }

    // --- 语义约束（scope / permission 白名单） ---
    if (preg_match('/^A (\w+) scope must not carry a numeric scope ID\.$/', $message, $m)) {
        return $mosGovValue($m[1]) . '范围的治理范围 ID 必须留空。';
    }
    if (preg_match('/^Scope type "([^"]+)" requires a numeric scope ID\.$/', $message, $m)) {
        return '治理范围类型「' . $mosGovValue($m[1]) . '」需要填写治理范围 ID（数字编号）。';
    }
    if (preg_match('/^Scope type "([^"]+)" must not carry a numeric scope ID\.$/', $message, $m)) {
        return '治理范围类型「' . $mosGovValue($m[1]) . '」不能填写治理范围 ID。';
    }
    if (preg_match('/^Resource type must be "([^"]+)" for this permission key\.$/', $message, $m)) {
        return '该权限键下，资源类型必须为「' . $mosGovT(ucwords($m[1])) . '」。';
    }
    if (preg_match('/^Action must be "([^"]+)" for this permission key\.$/', $message, $m)) {
        return '该权限键下，动作必须为「' . $mosGovValue($m[1]) . '」。';
    }
    if (preg_match('/^Risk level must be "([^"]+)" for this permission key\.$/', $message, $m)) {
        return '该权限键下，风险等级必须为「' . $mosGovValue($m[1]) . '」。';
    }

    return $message;
};
