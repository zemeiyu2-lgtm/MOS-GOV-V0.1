<?php

namespace ChurchCRM\Plugins\MosGov;

use ChurchCRM\Plugin\AbstractPlugin;
use ChurchCRM\Plugin\Hook\HookManager;
use ChurchCRM\Plugin\Hooks;
use ChurchCRM\Plugins\MosGov\Integration\MosGovMenu;

/**
 * MOS-GOV V0.1
 *
 * Governance layer for ChurchCRM.
 *
 * Design boundary:
 * - ChurchCRM remains the source of church/person/group/event/user facts.
 * - MOS-GOV owns governance structures, appointments, meetings, decisions,
 *   responsibilities and tasks.
 * - Cross-system references use ChurchCRM IDs; MOS-GOV does not redefine
 *   ChurchCRM people/groups/events.
 */
class MosGovPlugin extends AbstractPlugin
{
    public function getId(): string
    {
        return 'mos-gov';
    }

    public function getName(): string
    {
        return 'MOS-GOV';
    }

    public function getDescription(): string
    {
        return '轻量小而精的单管理员教会管理系统：人 / 组 / 活动 / 账务。';
    }

    public function getVersion(): string
    {
        return '2.0.0';
    }

    public function boot(): void
    {
        // V2.0 产品定型（§2/§35）：接管左侧导航，只保留六大一级模块。
        //
        // 用 MENU_BUILDING 钩子而非直接改 ChurchCRM 核心的 Menu.php，是因为
        // 核心不受本仓库 Git 管理（installer/build.ps1 每次构建都从官方 ZIP
        // 重新解压覆盖核心文件）。钩子让导航重建逻辑留在版本库内：可评审、
        // 可回退、随安装包分发，且核心文件保持零改动。
        //
        // 触发时序已核对：Include/Header.php 第 29 行 PluginManager::init()
        // （其中调用各插件 boot()）早于第 287 行 MenuRenderer::renderMenu()，
        // 而 Menu::buildMenuItems() 内部才应用本过滤器 —— 顺序成立。
        HookManager::addFilter(
            Hooks::MENU_BUILDING,
            static fn (array $menus): array => MosGovMenu::build($menus),
            10
        );
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function activate(): void
    {
        // Database creation is intentionally performed by the SQL migration
        // in database/001_initial.sql during installation/verification.
    }

    public function deactivate(): void
    {
        // Preserve governance data when the plugin is disabled.
    }

    public function uninstall(): void
    {
        // V0.1 does not silently delete governance history.
        // Destructive uninstall must be a deliberate migration/admin action.
    }
}
