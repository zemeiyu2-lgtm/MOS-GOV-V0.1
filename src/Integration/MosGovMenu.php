<?php

/**
 * MOS-GOV — 主导航构建器
 *
 * 通过 ChurchCRM 的 MENU_BUILDING 钩子（Hooks::MENU_BUILDING）接管整个左侧导航。
 *
 * 为什么用钩子而不是改核心：
 *   ChurchCRM 核心不受本仓库 Git 管理 —— installer/build.ps1 每次构建都从
 *   ChurchCRM 官方 ZIP 重新解压覆盖核心文件。直接改 Menu.php 属于「假性交付」：
 *   改动不进版本库、不可回退，且下一次构建即被覆盖。
 *   MENU_BUILDING 在 Include/Header.php 中于 MenuRenderer::renderMenu() 之前触发
 *   （PluginManager::init() 在 Header 第 29 行，renderMenu() 在第 287 行），
 *   因此插件可以安全地重建整棵导航树，而核心文件保持零改动。
 *
 * 当前结构（GOV-DEV-09-C 收口后）：
 *   仪表板 / 日历 / 人员 / 小组 / 财务 / 档案管理 / 治理 / 系统管理，共 8 个一级入口。
 *   沟通、筹款、数据/报告、主日学校 均不再出现（§3、§10、§14、§17、§20）。
 *   活动已并回日历；治理（含健康盘）为本轮新增，此前在左侧完全不可达。
 *
 * @see \ChurchCRM\Plugins\MosGov\Integration\MosGovProduct
 */

namespace ChurchCRM\Plugins\MosGov\Integration;

use ChurchCRM\Config\Menu\MenuItem;
use ChurchCRM\Plugins\MosGov\Security\GovAuthorization;

/**
 * 把 V2.0 产品结构翻译成 ChurchCRM 的 MenuItem 树。
 *
 * GOV-DEV-09-D（导航权限分叉）：本类此前对所有登录用户渲染同一棵树，
 * 与核心菜单的行为不一致 —— 核心的「系统管理」只在 isAdmin() 时挂载
 * （Menu.php:86-88），而插件把「管理员」暴露给了所有人；治理模块的
 * 七个业务页（结构/主体/会议/议题/决策/任务/健康盘）同样对没有治理身份的
 * 普通成员可见。此处在**菜单层**按用户能力裁剪，与页面层判定保持一致：
 *
 *   管理员模块       → 仅 ChurchCRM 管理员（与核心一致）
 *   治理模块         → 仅管理员 或 持有治理身份记录者；普通成员整块不渲染
 *                      （路由层由 GovMembershipMiddleware 落成 403）
 *   财务模块         → 受 bEnabledFinance 等既有开关约束（User::isFinanceEnabled）
 *   日历模块         → canViewEvents()
 *   人员「添加人员/添加家庭」→ isAddRecordsEnabled()
 *
 * 重要边界：这是**可见性**裁剪，不是权限校验。页面层仍逐页调用
 * GovernancePolicy / GovAuthorization 判定，隐藏菜单不等于授予或剥夺访问权。
 */
final class MosGovMenu
{
    /**
     * 每请求一次的能力快照（菜单在每次页面渲染时构建，重复查库没有意义）。
     *
     * @var array{admin:bool,governed:bool,addRecords:bool,finance:bool,events:bool}|null
     */
    private static ?array $access = null;

    /**
     * MENU_BUILDING 过滤器：丢弃 ChurchCRM 原始导航，返回 V2.0 八模块导航
     * （按当前用户能力裁剪后的结果）。
     *
     * 签名必须与 HookManager::applyFilters 的调用方式兼容：
     *   HookManager::applyFilters(Hooks::MENU_BUILDING, $menus)
     * 因此第一个参数是原菜单数组，返回值直接替换它。
     *
     * @param array<string, MenuItem> $menus ChurchCRM 原始导航（被整体丢弃）
     *
     * @return array<string, MenuItem> V2.0 导航
     */
    public static function build(array $menus): array
    {
        $v2 = [];

        foreach (MosGovProduct::MODULES as $module) {
            if (!self::moduleVisible($module)) {
                continue;
            }
            $item = self::moduleMenuItem($module);
            if ($item !== null) {
                // 用稳定键做数组键，避免依赖中文标签做查找。
                $v2[$module] = $item;
            }
        }

        return $v2;
    }

    /**
     * 构建单个一级模块及其二级项。
     *
     * 二级项分两类：
     *   - 有 path 的：直接可点的真实路由（全部经过存在性核对，无死链）；
     *   - path 为 null 的：V2.0 要求存在但核心未提供的入口（如「数据维护」），
     *     由 MOS-GOV 插件自己的路由补齐，此处指向插件页。
     */
    private static function moduleMenuItem(string $module): ?MenuItem
    {
        $label = MosGovProduct::moduleLabel($module);
        if ($label === '') {
            return null;
        }

        $item = new MenuItem($label, '', true, self::moduleIcon($module));

        foreach (self::visibleChildren($module) as $child) {
            $path = self::resolvePath($module, $child);
            if ($path === null) {
                // 没有可用路径就不渲染 —— 绝不用空链接充数（§27）。
                continue;
            }
            $item->addSubMenu(new MenuItem($child['label'], $path, true, self::childIcon($child['key'])));
        }

        // 一个二级项都没有的一级入口没有意义（避免空菜单，§27）。
        if (!$item->hasVisibleSubMenus()) {
            return null;
        }

        return $item;
    }

    /**
     * 当前用户的能力快照（每请求一次）。
     *
     * 取不到用户时（例如会话尚未建立、或在非认证上下文里构建导航）按「全部可见」
     * 处理，保持与改造前的行为一致 —— 菜单丢失比多显示一项更难排查，而真正的
     * 访问控制在所有页面路由里另有判定，不依赖这里。
     *
     * @return array{admin:bool,governed:bool,addRecords:bool,finance:bool,events:bool}
     */
    private static function access(): array
    {
        if (self::$access !== null) {
            return self::$access;
        }

        $user = GovAuthorization::currentUser();
        if ($user === null) {
            return self::$access = [
                'admin'      => true,
                'governed'   => true,
                'addRecords' => true,
                'finance'    => true,
                'events'     => true,
            ];
        }

        // 任一开关取值失败都不隐藏菜单（安全默认 = 维持原样，而不是静默减项）。
        $probe = static function (callable $fn): bool {
            try {
                return (bool) $fn();
            } catch (\Throwable $e) {
                return true;
            }
        };

        $isAdmin = $probe(static fn (): bool => $user->isAdmin());

        return self::$access = [
            'admin'      => $isAdmin,
            // 治理身份的存在性用 1 条查询判定（hasIdentityRecord，请求内记忆），
            // 不加载完整上下文 —— 导航在每一页渲染，不值得为菜单付 6 次查询。
            // 身份存在但已停用的人仍会看到入口，进去是「无权限/空列表」，
            // 由页面层裁决；这与列表页的判定门槛（identity-row 存在性）一致。
            'governed'   => $isAdmin || $probe(static fn (): bool => GovAuthorization::subjectToGovernancePolicy($user)),
            'addRecords' => $probe(static fn (): bool => $user->isAddRecordsEnabled()),
            'finance'    => $probe(static fn (): bool => $user->isFinanceEnabled()),
            'events'     => $probe(static fn (): bool => $user->canViewEvents()),
        ];
    }

    /**
     * 一级模块是否应出现在导航里。
     *
     * 与核心 Menu.php 的口径对齐：Administration 只在 isAdmin() 时挂载；
     * 财务受 bEnabledFinance 约束；日历受 canViewEvents() 约束。
     */
    private static function moduleVisible(string $module): bool
    {
        $access = self::access();

        switch ($module) {
            case MosGovProduct::MODULE_ADMIN:
                return $access['admin'];
            case MosGovProduct::MODULE_FINANCE:
                return $access['finance'];
            case MosGovProduct::MODULE_CALENDAR:
                return $access['events'];
        }

        return true;
    }

    /**
     * 二级项的可视集合。
     *
     * @return array<int, array{key:string,label:string,path:?string,note:string}>
     */
    private static function visibleChildren(string $module): array
    {
        $children = MosGovProduct::moduleChildren($module);
        $access = self::access();

        if ($module === MosGovProduct::MODULE_GOVERNANCE && !$access['governed']) {
            // GOV-DEV-09-E 产品决定：普通成员看不到治理系统 —— 整个「治理」一级入口
            // 不渲染（连「我的治理中心」也不再显示）。路由层由
            // GovMembershipMiddleware 把同一判定落成 403，菜单与访问控制一致。
            return [];
        }

        if ($module === MosGovProduct::MODULE_PEOPLE && !$access['addRecords']) {
            // 核心按 isAddRecordsEnabled() 隐藏「添加人员 / 添加家庭」，此处同口径。
            return array_values(array_filter(
                $children,
                static fn (array $child): bool => !in_array($child['key'] ?? '', ['add', 'addfam'], true)
            ));
        }

        return $children;
    }

    /**
     * 把产品定义里的相对路径解析成插件可用的路径。
     *
     * 「数据维护」是 V2.0 新增能力，核心没有对应页面，指向插件自有路由。
     */
    private static function resolvePath(string $module, array $child): ?string
    {
        $path = $child['path'] ?? null;

        if (empty($path)) {
            if (($child['note'] ?? '') !== 'data_maintenance') {
                return null;
            }
            $path = 'mos-gov/admin/data-maintenance';
        }

        // 插件自有路由必须带 plugins/ 前缀。
        // MenuItem 渲染时拼的是 RootPath + '/' + uri，若不补前缀会得到 /mos-gov/xxx；
        // 该路径由根应用接管并 302 到 errors/not-found.php（DEV-09-B §5 实测）。
        if ($path === 'mos-gov' || str_starts_with($path, 'mos-gov/')) {
            $path = 'plugins/' . $path;
        }

        return $path;
    }

    private static function moduleIcon(string $module): string
    {
        switch ($module) {
            case MosGovProduct::MODULE_DASHBOARD:
                return 'fa-gauge';
            case MosGovProduct::MODULE_CALENDAR:
                return 'fa-calendar';
            case MosGovProduct::MODULE_PEOPLE:
                return 'fa-people-group';
            case MosGovProduct::MODULE_GROUPS:
                return 'fa-users';
            case MosGovProduct::MODULE_FINANCE:
                return 'fa-cash-register';
            case MosGovProduct::MODULE_ARCHIVE:
                return 'fa-folder-open';
            case MosGovProduct::MODULE_GOVERNANCE:
                return 'fa-scale-balanced';
            case MosGovProduct::MODULE_ADMIN:
                return 'fa-screwdriver-wrench';
        }

        return '';
    }

    private static function childIcon(string $key): string
    {
        $icons = [
            'overview'  => 'fa-gauge',
            'calendar'  => 'fa-calendar-days',
            'list'      => 'fa-list',
            'add'       => 'fa-circle-plus',
            'family'    => 'fa-people-roof',
            'addfam'    => 'fa-people-roof',
            'import'    => 'fa-file-import',
            'series'    => 'fa-repeat',
            'types'     => 'fa-tags',
            'checkin'   => 'fa-user-check',
            'deposits'  => 'fa-list',
            'pledge'    => 'fa-handshake',
            'funds'     => 'fa-piggy-bank',
            'expense'   => 'fa-receipt',
            'assets'    => 'fa-boxes-stacked',
            'ledger'    => 'fa-table-list',
            'reports'   => 'fa-chart-column',
            'library'   => 'fa-folder-open',
            'home'      => 'fa-scale-balanced',
            'mine'      => 'fa-user-shield',
            'health'    => 'fa-heart-pulse',
            'struct'    => 'fa-sitemap',
            'bodies'    => 'fa-building-columns',
            'meetings'  => 'fa-handshake-angle',
            'issues'    => 'fa-triangle-exclamation',
            'decisions' => 'fa-gavel',
            'tasks'     => 'fa-list-check',
            'users'     => 'fa-user-gear',
            'church'    => 'fa-church',
            'settings'  => 'fa-gear',
            'data'      => 'fa-database',
            'system'    => 'fa-screwdriver-wrench',
        ];

        return $icons[$key] ?? 'fa-angles-right';
    }
}