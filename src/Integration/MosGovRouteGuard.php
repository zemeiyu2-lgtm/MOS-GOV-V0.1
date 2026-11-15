<?php

/**
 * MOS-GOV V2.0 — 旧模块路由封锁
 *
 * V2.0 的核心要求之一（§26）：被删除的模块不能因为「菜单看不到了」就仍然
 * 能通过旧 URL 打开完整功能。本类把已下线模块的 URL 显式收敛：
 *
 *   - 沟通（§14）        /v2/email/*、/v2/text/*  → 封锁（无安全等价目标）
 *   - 筹款（§7）         /fundraiser/*            → 封锁（款项记入财务账目）
 *   - 数据/报告（§2）    QueryList.php / QueryView.php / query/* → 封锁
 *   - 人员全量导出（§4） CSVExport / DirectoryReports / LettersAndLabels
 *                       /admin/export / people/export API → 封锁（安全要求）
 *   - 地区税务（§7）     /finance/reports/tax-statements → 封锁
 *   - 主日学校（§2）     /groups/sundayschool/*   → 封锁
 *
 * 刻意保留（结构精简阶段明确要求）：
 *   - /event/calendars   日历本轮恢复为一级导航入口
 *   - /finance/pledge/*  奉献承诺本轮重新纳入「财务」
 *   - /event/checkin     读写 event_attend 表，实为签到出席，不是住宿入住/退房
 *
 * 设计取舍（重要）：
 *   封锁在「HTTP 入口层」用 Slim 中间件完成，而不是删核心路由文件。原因是核心
 *   不受 Git 管理，删文件既不可版本化也会被下次构建还原；中间件由插件提供，
 *   改动留在 Git 内、可回退，且对保留功能零侵入。
 *
 *   出席不能一刀切：ChurchCRM 的 /event/checkin 读写的是 event_attend 表，
 *   也就是「谁参加了这场活动」——它正是 V2.0 §12 要保留的「出席」功能，
 *   只是导航里不再叫「办理入住和退房」。因此仅封锁真正的离场/结账语义，
 *   保留出席记录与统计。
 *
 * @see \ChurchCRM\Plugins\MosGov\Integration\MosGovProduct
 */

namespace ChurchCRM\Plugins\MosGov\Integration;

/**
 * 已下线模块的 URL 规则表。
 */
final class MosGovRouteGuard
{
    /**
     * 需要彻底封锁的 URL 前缀 → 管理员提示。
     *
     * 采用前缀匹配并按长度降序判定，避免 /v2/email 与 /v2/email-x 互相干扰。
     *
     * @var array<int, array{prefix:string, reason:string}>
     */
    private const BLOCKED = [
        // §14 沟通：短信 / Vonage / 邮件营销 / 通信设置
        ['prefix' => '/v2/email', 'reason' => '沟通模块已从 MOS CHURCH 移除'],
        ['prefix' => '/v2/text',  'reason' => '沟通模块已从 MOS CHURCH 移除'],

        // §7 筹款：不再作为独立一级模块，且核心筹款子系统的募款活动/目标/
        // 买家/捐赠物品对 MOS 无实际价值，整体下线（收到的款项记入财务账目）。
        ['prefix' => '/fundraiser', 'reason' => '筹款已不再作为独立模块；请在「财务 → 奉献记录」登记'],

        // §2 数据/报告：通用查询中心
        ['prefix' => '/QueryList.php',  'reason' => '数据/报告一级模块已移除'],
        ['prefix' => '/QueryView.php',  'reason' => '数据/报告一级模块已移除'],
        ['prefix' => '/query',          'reason' => '数据/报告一级模块已移除'],

        // §7 地区特定税务：美国捐赠税务声明。本轮财务定位为「地区无关的教会账务」，
        // 不做税务申报。注意：这与「奉献承诺」不同 —— 承诺本轮保留，故不在此列。
        ['prefix' => '/finance/reports/tax-statements', 'reason' => '地区特定税务功能不适用于 MOS CHURCH'],

        // §2 主日学校：降级为「活动」的一种活动类型
        ['prefix' => '/groups/sundayschool', 'reason' => '主日学校已并入「活动」的活动类型，不再是独立模块'],

        // §4/§12 人员数据安全：禁止全量批量导出。允许页面查看与单人操作，
        // 但不得一键导出全部人员档案（§4 明确的安全要求）。
        ['prefix' => '/CSVExport.php',        'reason' => 'MOS CHURCH 不提供人员全量导出'],
        ['prefix' => '/DirectoryReports.php', 'reason' => 'MOS CHURCH 不提供人员全量导出'],
        ['prefix' => '/LettersAndLabels.php', 'reason' => 'MOS CHURCH 不提供人员批量导出与邮件合并'],
        ['prefix' => '/admin/export',         'reason' => 'MOS CHURCH 不提供人员全量导出'],
        ['prefix' => '/admin/api/database/people/export', 'reason' => 'MOS CHURCH 不提供人员全量导出'],
    ];

    /**
     * 旧 URL → 新归属模块的安全重定向。
     *
     * 只做「等价且安全」的跳转：用户明确被允许的功能才跳转。
     * 不新建任何功能，也不把 URL 伪装成仍然可用。
     *
     * @var array<string, string>
     */
    private const REDIRECTS = [
        // §13 日历并入活动：旧日历入口 → 活动下的日历
        '/event/calendar' => '/event/calendars',
    ];

    /**
     * 判断某个请求路径是否应被封锁。
     *
     * @param string $path 不含查询串的请求路径
     *
     * @return string|null 命中时返回管理员提示；未命中返回 null
     */
    public static function blockedReason(string $path): ?string
    {
        $normalized = self::normalize($path);
        if ($normalized === '') {
            return null;
        }

        foreach (self::BLOCKED as $rule) {
            if (self::matches($normalized, $rule['prefix'])) {
                return $rule['reason'];
            }
        }

        return null;
    }

    /**
     * 取旧 URL 的安全重定向目标。
     *
     * @return string|null 需要跳转时返回目标路径；否则 null
     */
    public static function redirectTarget(string $path): ?string
    {
        $normalized = self::normalize($path);

        foreach (self::REDIRECTS as $from => $to) {
            if (self::matches($normalized, $from)) {
                return $to;
            }
        }

        return null;
    }

    /**
     * V2.0 全量自检用：列出所有被封锁的前缀，供测试断言。
     *
     * @return array<int, string>
     */
    public static function blockedPrefixes(): array
    {
        return array_column(self::BLOCKED, 'prefix');
    }

    /**
     * 路径归一化：统一小写、去掉结尾斜杠、去掉索引文件。
     *
     * 归一化让 /QueryList.php、/querylist.php、/QueryList.php/ 这类写法
     * 判定一致，避免用大小写差异绕过封锁。
     */
    private static function normalize(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        // 去掉查询串与片段
        $path = (string) preg_replace('/[?#].*$/', '', $path);
        $path = rtrim($path, '/');
        $path = preg_replace('#/index\.php$#i', '', $path) ?? $path;

        return strtolower($path);
    }

    /**
     * 前缀匹配：命中前缀本身，或命中前缀 + '/' 的子路径。
     *
     * 边界用 '/' 判定，避免 /v2/emailx 误命中 /v2/email。
     */
    private static function matches(string $normalizedPath, string $prefix): bool
    {
        $prefix = rtrim(strtolower($prefix), '/');
        if ($prefix === '') {
            return false;
        }

        if ($normalizedPath === $prefix) {
            return true;
        }

        return str_starts_with($normalizedPath, $prefix . '/');
    }
}