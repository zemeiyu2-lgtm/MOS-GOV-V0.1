# MOS-AI-HANDOFF V1.0

## 目的

建立 ChatGPT 与 WorkBuddy 在 MOS / MOS-GOV 项目中的统一交接协议，减少人工复制、重复解释和无效诊断。

## 角色分工

### ChatGPT
- MOS 架构与研究
- 技术方案与边界判断
- 任务拆解与验收标准
- 代码与架构审查
- GitHub 成果审查与最终决策

### WorkBuddy
- 本地源码检索
- PowerShell / Docker / PHP 执行
- 本地文件修改
- 测试、日志与回归验证
- 按任务要求形成执行报告

## 标准交接格式

### TASK
任务名称与唯一目标。

### CONTEXT
项目当前状态、相关文件、已知事实。

### CONSTRAINTS
允许修改范围、禁止修改范围、安全边界。

### ACTIONS
WorkBuddy 应执行的动作；可自主完成连续诊断，不要求逐步等待用户确认。

### RESULTS
实际执行结果、命令、关键输出、异常。

### CHANGES
修改或新增的文件，以及修改原因。

### TESTS
测试方式、结果、是否通过。

### BLOCKERS
当前阻塞、原因及需要 ChatGPT 判断的事项。

### NEXT
建议下一步动作，必须与当前任务边界一致。

## MOS-GOV 默认安全边界

1. 默认只允许修改 MOS-GOV、自有测试文件和临时诊断文件。
2. 不得擅自修改 ChurchCRM 核心代码。
3. 不得修改或删除现有 ChurchCRM 核心数据库表。
4. 不得删除项目文件，除非任务明确授权。
5. 不得执行破坏性数据库操作。
6. 发现必须修改 ChurchCRM 核心时，先停止并报告原因。
7. 所有代码修改必须有测试或明确说明为什么暂时无法测试。

## 工作闭环

ChatGPT 建任务 → WorkBuddy 连续执行 → WorkBuddy 写报告 / 提交 Git → ChatGPT 审查 → 确认后进入下一任务。

## 当前项目

- Repository: `zemeiyu2-lgtm/MOS-GOV-V0.1`
- Branch: `main`
- Plugin: `mos-gov`
- ChurchCRM 基础：本地 Docker 开发环境
