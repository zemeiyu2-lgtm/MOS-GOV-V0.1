<?php

/**
 * MOS-GOV — 产品结构定义（唯一事实来源）
 *
 * 全系统唯一的模块结构声明：导航（MosGovMenu）、旧路由封锁
 * （MosGovRouteGuard）、插件内导航（views/_tabs.php）都应与本文件保持一致。
 *
 * 设计前提（V2.0 取证结论）：
 *   MOS-GOV 是 ChurchCRM 的插件层。ChurchCRM 核心导航定义在
 *   ChurchCRM/Config/Menu/Menu.php，且核心不受本仓库 Git 管理
 *   （installer/build.ps1 每次构建都从官方 ZIP 重新解压覆盖）。
 *   因此不修改核心，而是在插件 boot() 里通过 MENU_BUILDING 钩子
 *   重建整个导航 —— 改动留在 Git 内、可回退、且随安装包分发。
 *
 * 导航收口（GOV-DEV-09-C，2026-10-22）：
 *   启用插件后实测发现三处与已验收结构（2026-10-20）冲突，此处统一收口：
 *   1. 新增「治理」一级模块 —— 此前治理各页（含健康盘）在左侧没有任何入口，
 *      只能靠已知 URL 进入，V1.0 交付物实际上不可被发现；
 *   2. 恢复「档案管理」一级入口 —— 档案库已上线（archive/），插件接管导航后曾消失；
 *   3. 「活动」重新并回「日历」子项 —— 与 10-20 验收结论"活动不再是独立顶级入口"一致。
 *   同时把账务与会话子项补齐到不少于核心菜单，避免启用插件造成功能退化（§13）。
 *
 * @see \ChurchCRM\Plugins\MosGov\Integration\MosGovMenu
 * @see \ChurchCRM\Plugins\MosGov\Integration\MosGovRouteGuard
 */

namespace ChurchCRM\Plugins\MosGov\Integration;

/**
 * MOS-GOV 产品结构。
 *
 * 只读常量类：不在此写业务逻辑，只声明「最终产品长什么样」。
 * 一级标签一律显式指定中文，不依赖 gettext：语言包中 Finance 译作「财务」、
 * Admin 译作「管理员：」（带冒号），与产品基准「财务」「系统管理」不一致。
 */
final class MosGovProduct
{
    /** 产品版本标识，写入系统信息页与页脚，便于现场确认部署的是哪一版。 */
    public const PRODUCT_VERSION = '2.0';

    /**
     * 一级导航的稳定键（渲染与判定都用键，不用展示名）。
     *
     * 顺序即最终显示顺序，且「管理员」固定在最后。
     *
     * 8 项结构：仪表板 / 日历 / 人员 / 小组 / 财务 / 档案管理 / 治理 / 系统管理。
     * 「治理」放在「管理员」之前，因为它是 MOS-GOV 的核心业务面，
     * 而不是系统配置；放在末位会让日常使用者每次都要越过管理项才能到达。
     */
    public const MODULE_DASHBOARD  = 'dashboard';
    public const MODULE_CALENDAR   = 'calendar';
    public const MODULE_PEOPLE     = 'people';
    public const MODULE_GROUPS     = 'groups';
    public const MODULE_FINANCE    = 'finance';
    public const MODULE_ARCHIVE    = 'archive';
    public const MODULE_GOVERNANCE = 'governance';
    public const MODULE_ADMIN      = 'admin';

    /** 允许作为一级业务模块存在的全部键，顺序固定。 */
    public const MODULES = [
        self::MODULE_DASHBOARD,
        self::MODULE_CALENDAR,
        self::MODULE_PEOPLE,
        self::MODULE_GROUPS,
        self::MODULE_FINANCE,
        self::MODULE_ARCHIVE,
        self::MODULE_GOVERNANCE,
        self::MODULE_ADMIN,
    ];

    /**
     * 一级菜单的显示名。
     *
     * 全部显式指定中文，不依赖 gettext：产品用词以最终基准为准。
     */
    public static function moduleLabel(string $module): string
    {
        switch ($module) {
            case self::MODULE_DASHBOARD:
                return '仪表板';
            case self::MODULE_CALENDAR:
                return '日历';
            case self::MODULE_PEOPLE:
                // GOV-VERSION-01（2026-10-22）：用词回归核心术语表
                // （Include/MosText.php：People→人员 / Groups→小组 / Finance→财务）。
                // 此前硬编码「人/组/账务」，插件启用后侧栏像是「人员、小组、财务不见了」，
                // 实为同一模块被改了名。界面用词必须与核心一致，内部键名/路由不变。
                return '人员';
            case self::MODULE_GROUPS:
                return '小组';
            case self::MODULE_FINANCE:
                // 产品用词统一为「财务」；路由（finance/*）与数据表均不变。
                return '财务';
            case self::MODULE_ARCHIVE:
                // 与 10-20 验收用词一致：全教会统一档案库。
                return '档案管理';
            case self::MODULE_GOVERNANCE:
                return '治理';
            case self::MODULE_ADMIN:
                // 与核心一致：核心 Admin 模块显示为「系统管理」。
                return '系统管理';
        }

        return '';
    }

    /**
     * 二级结构定义。
     *
     * 只保留明确要求的入口，并且每一条都必须指向真实存在、已验证可访问的路由
     * —— 宁可少给入口，也绝不留死链（§13）。
     *
     * @return array<int, array{key:string,label:string,path:?string,note:string}>
     *         path 为 null 表示该项由插件自有路由补齐（见 MosGovMenu::resolvePath）。
     */
    public static function moduleChildren(string $module): array
    {
        switch ($module) {
            case self::MODULE_DASHBOARD:
                // 全系统唯一仪表板：进入即总览。各模块不再重复建设 Dashboard。
                return [
                    ['key' => 'overview', 'label' => '总览', 'path' => 'v2/dashboard', 'note' => ''],
                ];

            case self::MODULE_CALENDAR:
                // 活动并入日历（10-20 验收结论）：活动的仪表板/新增/签到与类型设置
                // 全部挂在此处，活动数据与路由不变，只是不再单独占用一级入口。
                return [
                    ['key' => 'calendar', 'label' => '月视图',   'path' => 'event/month',     'note' => ''],
                    ['key' => 'list',     'label' => '管理活动', 'path' => 'event/dashboard', 'note' => ''],
                    ['key' => 'series',   'label' => '固定安排', 'path' => 'event/series',    'note' => ''],
                    ['key' => 'add',      'label' => '添加活动', 'path' => 'event/editor',    'note' => ''],
                    ['key' => 'checkin',  'label' => '签到',     'path' => 'event/checkin',   'note' => ''],
                    ['key' => 'types',    'label' => '活动类型', 'path' => 'event/types',     'note' => ''],
                ];

            case self::MODULE_PEOPLE:
                // 「添加人员」用核心既有的 PersonEditor.php。
                // 不提供任何全量导出入口（§4 安全要求）—— 导出已在 core-overlay 封锁。
                return [
                    ['key' => 'list',   'label' => '人员列表', 'path' => 'people/list',       'note' => ''],
                    ['key' => 'add',    'label' => '添加人员', 'path' => 'PersonEditor.php',  'note' => ''],
                    ['key' => 'family', 'label' => '家庭',     'path' => 'people/family',     'note' => ''],
                    ['key' => 'addfam', 'label' => '添加家庭', 'path' => 'FamilyEditor.php',  'note' => ''],
                    ['key' => 'import', 'label' => '批量导入', 'path' => 'admin/import/csv',  'note' => ''],
                ];

            case self::MODULE_GROUPS:
                // 实测核心没有独立的「新建组」页面 —— /groups/editor/{id} 对 id<1
                // 直接跳回列表；新建是列表页上的内嵌表单。故只给列表入口（§13）。
                return [
                    ['key' => 'list', 'label' => '小组列表', 'path' => 'groups', 'note' => ''],
                ];

            case self::MODULE_FINANCE:
                // 地区中立：不链接 /finance/reports 的税务声明页
                // （/finance/reports/tax-statements 已被 RouteGuard 封锁）。
                // 支出登记、资产台账、收支明细为 10-20 已验收入口，启用插件后
                // 曾整体消失，此处补回，避免功能退化。
                // 标签不得与一级项「财务」重名（DEV-09-D 修正）：首个子项是
                // 核心的「View All Deposits」，用「奉献记录」；认捐与分类同理区分。
                return [
                    ['key' => 'deposits', 'label' => '奉献记录', 'path' => 'finance/deposit/search',   'note' => ''],
                    ['key' => 'pledge',   'label' => '认捐',     'path' => 'finance/pledge/dashboard', 'note' => ''],
                    ['key' => 'funds',    'label' => '奉献分类', 'path' => 'finance/funds',            'note' => ''],
                    ['key' => 'expense',  'label' => '支出登记', 'path' => 'finance/expenses',         'note' => ''],
                    ['key' => 'assets',   'label' => '资产台账', 'path' => 'finance/assets',           'note' => ''],
                    ['key' => 'ledger',   'label' => '收支明细', 'path' => 'finance/reports?tab=data',  'note' => ''],
                    ['key' => 'reports',  'label' => '报表',     'path' => 'mos-gov/finance/report',   'note' => ''],
                ];

            case self::MODULE_ARCHIVE:
                // 档案库入口对所有登录用户可见，文件级可见性由 MosArchiveService
                // 按密级与角色判定（10-20 验收口径），此处不做权限分叉。
                return [
                    ['key' => 'library', 'label' => '档案库', 'path' => 'archive/', 'note' => ''],
                ];

            case self::MODULE_GOVERNANCE:
                // 治理模块的入口集合。完整页面清单见 views/_tabs.php，
                // 这里只放日常最常用的九个，避免侧栏过长。
                // 所有路径由 MosGovMenu::resolvePath 统一补 plugins/ 前缀。
                return [
                    ['key' => 'home',     'label' => '治理首页',   'path' => 'mos-gov/dashboard',      'note' => ''],
                    ['key' => 'mine',     'label' => '我的治理中心', 'path' => 'mos-gov/my-governance', 'note' => ''],
                    ['key' => 'health',   'label' => '健康盘',     'path' => 'mos-gov/healthboard',     'note' => ''],
                    ['key' => 'struct',   'label' => '结构',       'path' => 'mos-gov/structures',      'note' => ''],
                    ['key' => 'bodies',   'label' => '治理主体',   'path' => 'mos-gov/bodies',          'note' => ''],
                    ['key' => 'meetings', 'label' => '会议',       'path' => 'mos-gov/meetings',        'note' => ''],
                    ['key' => 'issues',   'label' => '议题',       'path' => 'mos-gov/issues',          'note' => ''],
                    ['key' => 'decisions','label' => '决策',       'path' => 'mos-gov/decisions',       'note' => ''],
                    ['key' => 'tasks',    'label' => '任务',       'path' => 'mos-gov/tasks',           'note' => ''],
                ];

            case self::MODULE_ADMIN:
                // 单管理员模型：只暴露一个管理员所需的入口，不做团队/角色矩阵。
                // 注意：/QueryList.php 已被 RouteGuard 封锁，故不提供「数据与报表」入口，
                // 以免产生死链（§13 优先于"能力不减"）。
                return [
                    ['key' => 'settings', 'label' => '系统设置', 'path' => 'SystemSettings.php',       'note' => ''],
                    ['key' => 'church',   'label' => '教会信息', 'path' => 'admin/system/church-info', 'note' => ''],
                    ['key' => 'users',    'label' => '账号管理', 'path' => 'admin/system/users',       'note' => ''],
                    ['key' => 'data',     'label' => '数据格式化', 'path' => null, 'note' => 'data_maintenance'],
                    ['key' => 'system',   'label' => '维护',     'path' => 'admin/system/logs',        'note' => ''],
                ];
        }

        return [];
    }

    /**
     * 已删除、且必须从产品表面消失的一级模块键。
     *
     * 用于自检：导航构建完成后，若这些键仍出现在菜单里即视为回归失败。
     * 注意：'Calendar' 不在其中 —— 日历是现行一级模块（活动已并入日历）。
     *
     * @return array<int, string>
     */
    public static function removedModules(): array
    {
        return [
            'SundaySchool',    // §10：降级为活动类型
            'Communication',   // §14：删除沟通
            'Fundraiser',      // §17：删除筹款
            'Reports',         // §20：删除数据/报告
            'Deposits',        // §15：由「财务」取代
        ];
    }
}
