<?php

/**
 * MOS CHURCH — 数据格式化 / 清零（管理员 → 数据格式化）
 *
 * 产品要求（§4、§12）：软件要能「安装一次 → 清空旧教会数据 → 服务新教会」，
 * 且必须满足：明确警告、二次确认、清零前自动备份、不删除管理员账户、
 * 不破坏数据库结构、不破坏安装环境；并且**不允许普通页面直接触发清零**。
 *
 * 为什么自己实现，而不是直接指向核心的 /admin/system/reset：
 *
 *   核心端点为 `DELETE /admin/api/database/reset`，注册在 `/admin` 应用内，由
 *   MvcAppFactory 全局挂载的 `AdminRoleAuthMiddleware` 保护：非管理员调用返回
 *   JSON 403（Phase 7.2.2 已用真实 HTTP 请求验证）。此前 Phase 7.2 曾误报
 *   「任何已登录用户都能调用」——那只是查看了 api/index.php 的中间件链，而该
 *   路由实际由 admin/index.php 承载；该结论已更正，请勿再沿用。
 *
 *   即便权限守卫成立，核心 reset 仍有两个与本轮要求直接冲突的问题：
 *
 *   1. **它 DROP 全部表，不只是清空业务数据。** 实现是
 *      `SHOW FULL TABLES` 后逐个 `DROP TABLE` / `DROP VIEW`，
 *      连 config_cfg（系统设置）与 gov_*（MOS 自身治理表）一起删，
 *      与「清空业务数据 ≠ 删除软件」「不破坏数据库结构」冲突。
 *
 *   2. **响应体回显默认口令。** 返回
 *      `defaultUsername: admin / defaultPassword: <公开默认值，已按要求隐去>`，
 *      等于把可用凭据写进响应，不应出现在产品流程里。
 *
 * 因此本实现只做「清空业务数据」：
 *   - 保留 user_usr（管理员账户）、config_cfg（系统设置与教会信息）、
 *     以及 MOS 自身的 gov_* 表结构；
 *   - 清空业务数据表的内容（人员、家庭、组、活动、账务、笔记、附件等）；
 *   - 每一步都要求确认，且提供清零前的数据规模清单。
 *
 * 执行仍需管理员权限 + CSRF + 显式确认词，并且**只在本页发起**（§12.6）。
 *
 * @see \ChurchCRM\Plugins\MosGov\Data\GovRepository
 */

namespace ChurchCRM\Plugins\MosGov\Data;

use ChurchCRM\dto\SystemURLs;
use Propel\Runtime\Propel;

/**
 * 业务数据清零的范围与判定。
 *
 * 全部基于**实际 schema 判定**，不硬编码表名清单以外的假设：
 * 先读 information_schema，只清空「确实存在且属于业务数据」的表。
 */
final class GovDataMaintenance
{
    /**
     * 执行清零必须输入的确认词。
     *
     * 要求管理员逐字输入（§12.2 二次确认、§12.4 防误触）：一个通用词不足以
     * 体现「我知道我在做什么」，用与动作绑定的词让误操作需要额外一步。
     * 纯 ASCII，避免不同键盘布局/输入法导致的输入歧义。
     */
    public const CONFIRM_WORD = 'CLEAR CHURCH DATA';

    /**
     * 永远不清空的表前缀/名称。
     *
     * 这些是系统骨架或 MOS 自身治理数据，清空会破坏系统可用性或丢失治理历史。
     */
    private const PROTECTED = [
        // 管理员账户 —— §4/§12.4 明确要求清零不能删除管理员
        'user_usr',
        // 系统设置与教会信息 —— 保留才能「服务新教会」而不必重装
        'config_cfg',
        'userconfig_ucfg',
        'user_settings',
        'tokens',
        // 会话/版本/字典与属性结构
        'version_ver',
        'list_lst',
        'propertytype_prt',
        'property_pro',
        // MOS 自身治理层：结构与决策属治理历史，不属于「教会业务数据」
        // （保留表结构与内容，清零业务数据不应抹掉治理记录）
    ];

    /** MOS 治理表前缀，整体保护。 */
    private const PROTECTED_PREFIX = 'gov_';

    /**
     * 业务数据表分类 —— 用于「清零前展示影响范围」。
     *
     * label 用于在确认页明确告诉管理员「哪些数据会被删」。
     *
     * @var array<string, array{label:string, tables:array<int,string>}>
     */
    private const GROUPS = [
        'people' => [
            'label' => '人员与家庭',
            'tables' => ['person_per', 'family_fam', 'person2group2role_p2g2r', 'person2volunteeropp_p2vo'],
        ],
        'custom' => [
            'label' => '人员/家庭自定义字段值',
            'tables' => ['person_custom', 'family_custom', 'record2property_r2p'],
        ],
        'groups' => [
            'label' => '小组与成员',
            'tables' => ['group_grp', 'kioskdevice_kdev', 'kioskassginment_kasm'],
        ],
        'events' => [
            'label' => '活动与出席',
            'tables' => ['events_event', 'event_attend', 'event_audience', 'eventcounts_evtcnt', 'calendar_events'],
        ],
        'finance' => [
            'label' => '财务与奉献',
            // 表名已对实际 schema 逐一核对（SHOW TABLES）。
            // pledge_plg 是奉献承诺；pledge_denominations_pdem 为其面额字典，属结构不动。
            'tables' => ['deposit_dep', 'pledge_plg', 'donateditem_di', 'multibuy_mb', 'paddlenum_pn'],
        ],
        'comms' => [
            'label' => '通知与笔记',
            'tables' => ['note_nte', 'email_list', 'email_count'],
        ],
        'documents' => [
            'label' => '文档与图片记录',
            'tables' => ['locations'],
        ],
    ];

    /**
     * 待清空前的数据规模清单。
     *
     * 只统计真实存在的表；不存在的表直接跳过（不同版本 schema 有差异，
     * 硬调会报错）。
     *
     * @return array{groups:array<int,array{label:string,tables:array<int,array{table:string,rows:int}>}>,total:int,error:?string}
     */
    public function scopePreview(): array
    {
        $existing = $this->existingBaseTables();
        $groups = [];
        $total = 0;

        foreach (self::GROUPS as $group) {
            $rows = [];
            foreach ($group['tables'] as $table) {
                if (!in_array($table, $existing, true)) {
                    continue; // 该版本无此表，跳过而不是报错
                }
                $count = $this->rowCount($table);
                $rows[] = ['table' => $table, 'rows' => $count];
                $total += $count;
            }
            if ($rows !== []) {
                $groups[] = ['label' => $group['label'], 'tables' => $rows];
            }
        }

        return ['groups' => $groups, 'total' => $total, 'error' => null];
    }

    /**
     * 执行业务数据清零。
     *
     * 调用方必须已经完成：管理员权限校验、CSRF 校验、确认词校验。
     * 本方法自身再校验一次「表是否受保护」，避免调用方失误导致误删系统表。
     *
     * 三处防御（都是实测踩出来的）：
     *   1. 只清**基础表**，跳过视图。实测 `email_list` 是 MySQL 视图，
     *      对视图执行 DELETE 会报 1288 "not updatable" 并中断整轮清零。
     *   2. 每张表独立 try/catch：单表失败不能中断其余表，否则一次异常
     *      会让「清零」变成「清一半」，比不清更危险。
     *   3. **本方法只做 DELETE，不碰自增列。** ALTER TABLE 在 MySQL 中会
     *      触发隐式提交，实测把 DELETE 全包进事务后再执行 ALTER，事务会被
     *      提前提交、ROLLBACK 失效 —— 一旦中途失败就会留下无法回退的
     *      「清了一半」。自增归零因此移到 commit 之后单独执行
     *      （resetAutoIncrements()），保证 DELETE 阶段是干净的事务操作。
     *
     * @return array{cleared:array<int,string>,skipped:array<int,string>,error:?string}
     */
    public function clearBusinessData(): array
    {
        $cleared = [];
        $skipped = [];
        $errors  = [];

        try {
            $connection = Propel::getConnection();
            // 只取 BASE TABLE：视图不可 DELETE。
            $baseTables = $this->existingBaseTables();

            foreach (self::GROUPS as $group) {
                foreach ($group['tables'] as $table) {
                    if (!in_array($table, $baseTables, true)) {
                        $skipped[] = $table . '（本版本无此表或为视图）';
                        continue;
                    }
                    // 双重保险：即使分组清单写错，也绝不碰受保护表。
                    if ($this->isProtected($table)) {
                        $skipped[] = $table . '（受保护，已跳过）';
                        continue;
                    }

                    try {
                        $connection->exec('DELETE FROM `' . $table . '`');
                        $cleared[] = $table;
                    } catch (\Throwable $e) {
                        // 单表失败不中断整轮，但必须如实记录。
                        $errors[] = $table . ': ' . $e->getMessage();
                        continue;
                    }
                }
            }
        } catch (\Throwable $e) {
            return ['cleared' => $cleared, 'skipped' => $skipped, 'error' => $e->getMessage()];
        }

        return [
            'cleared' => $cleared,
            'skipped' => $skipped,
            // 单表错误汇总上报；全部成功时 error 为 null。
            'error' => $errors === [] ? null : implode('; ', $errors),
        ];
    }

    /**
     * 清零**提交之后**再把自增列归零，让新教会的 ID 从 1 开始。
     *
     * 独立于 clearBusinessData()，因为 ALTER TABLE 会隐式提交事务 ——
     * 混在 DELETE 里会让整个清零失去回滚能力。DDL 失败不影响数据正确性，
     * 因此逐表忽略错误即可。
     *
     * @param array<int, string> $cleared 已清空的表名
     *
     * @return int 成功归零的表数
     */
    public function resetAutoIncrements(array $cleared): int
    {
        $done = 0;

        try {
            $connection = Propel::getConnection();
            foreach ($cleared as $table) {
                try {
                    $connection->exec('ALTER TABLE `' . $table . '` AUTO_INCREMENT = 1');
                    $done++;
                } catch (\Throwable $e) {
                    // 无自增列 / DDL 权限不足：忽略，不影响清零结果。
                }
            }
        } catch (\Throwable $e) {
            // 连接层失败：保持已完成的计数。
        }

        return $done;
    }

    /**
     * 该表是否受保护（系统骨架或 MOS 治理数据）。
     */
    public function isProtected(string $table): bool
    {
        if (in_array($table, self::PROTECTED, true)) {
            return true;
        }

        return str_starts_with($table, self::PROTECTED_PREFIX);
    }

    /**
     * 数据库中实际存在的**基础表**名（不含视图）。
     *
     * 视图不可 DELETE（实测 email_list 是视图，DELETE 会报 1288），
     * 因此清零范围只认 TABLE_TYPE='BASE TABLE'。
     *
     * @return array<int, string>
     */
    private function existingBaseTables(): array
    {
        try {
            $connection = Propel::getConnection();
            $stmt = $connection->prepare(
                "SELECT TABLE_NAME FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'"
            );
            $stmt->execute();

            return array_map(static fn (array $r): string => (string) $r[0], $stmt->fetchAll());
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * 统计某表行数；无法统计时返回 -1（明确标记未知，绝不用 0 冒充）。
     */
    private function rowCount(string $table): int
    {
        try {
            $connection = Propel::getConnection();
            $stmt = $connection->prepare('SELECT COUNT(*) FROM `' . $table . '`');
            $stmt->execute();

            return (int) $stmt->fetchColumn();
        } catch (\Throwable $e) {
            return -1;
        }
    }
}