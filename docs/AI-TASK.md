# MOS AI TASK

> 当前任务单。ChatGPT 建立任务，WorkBuddy 执行。完成后更新 `docs/WORKBUDDY-REPORT.md`。

## TASK
MOS-GOV V0.1 Plugin 集成测试与运行验证

## CONTEXT
- Repository: `zemeiyu2-lgtm/MOS-GOV-V0.1`
- Branch: `main`
- ChurchCRM 本地路径：`C:\churchcrm`
- MOS-GOV 本地路径：`C:\churchcrm\src\plugins\community\mos-gov`
- ChurchCRM 使用 Docker 开发环境。
- 插件发现、插件加载、PluginManager、完整 Bootstrap、SystemConfig 与 Propel CLI 初始化问题已完成诊断。
- 已确认此前的 Propel 报错属于不完整的临时 CLI 调试脚本，而非 MOS-GOV 插件代码问题。

## OBJECTIVE
验证 MOS-GOV V0.1 是否能够作为 ChurchCRM community plugin 完整运行，并找出当前真正阻塞 V0.1 的问题。

## CONSTRAINTS
- 默认只修改 MOS-GOV、自有测试文件和临时诊断文件。
- 禁止擅自修改 ChurchCRM 核心代码。
- 禁止修改或删除现有 ChurchCRM 核心数据库表。
- 禁止破坏性数据库操作。
- 如必须修改 ChurchCRM 核心，立即停止并报告原因，不自行实施。
- 不做无关重构或功能扩展。

## ACTIONS
1. 检查 Plugin Management 中 mos-gov 当前状态。
2. 使用 ChurchCRM 官方 PluginManager 流程尝试启用 mos-gov。
3. 验证 plugin boot 与 routes 注册。
4. 验证 MOS-GOV dashboard 可访问性。
5. 在不执行破坏性操作前，检查 `database/001_initial.sql` 与当前 MariaDB 环境兼容性。
6. 安全条件满足后，初始化 MOS-GOV 自有表。
7. 验证以下表：
   - gov_structure
   - gov_body
   - gov_role
   - gov_appointment
   - gov_responsibility
   - gov_relationship
   - gov_meeting
   - gov_issue
   - gov_decision
   - gov_task
8. 验证 dashboard 对 MOS-GOV 自有数据的读取。
9. 对发现的问题进行最小修改并回归测试。
10. 记录所有变更、测试与阻塞。

## ACCEPTANCE
- 插件可被 ChurchCRM 正常发现并启用。
- boot 无 fatal error。
- routes 正常注册。
- dashboard 可访问。
- MOS-GOV 自有数据库表可正常初始化并访问。
- 无 ChurchCRM 核心代码或核心表被修改。
- 所有必要修改均有测试记录。

## REPORT
完成后必须更新 `docs/WORKBUDDY-REPORT.md`，包括：
- RESULTS
- CHANGES
- TESTS
- BLOCKERS
- NEXT
