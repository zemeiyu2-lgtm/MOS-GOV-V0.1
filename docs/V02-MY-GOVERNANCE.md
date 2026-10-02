# V0.2 — My Governance Center

`/plugins/mos-gov/my-governance`

V0.2 的核心页面。它不是 dashboard,而是回答八条问题:

```
我是谁?            → 身份卡(person 通过 PersonLookup 只读解析;P5 notes 掩蔽)
我承担什么角色?     → gov_identity_role + 角色 code(A01…E01)+ 任命状态徽章
我被托付什么?       → 角色职责模板 + 任命级职责(responsibility)
我负责什么范围?     → 有效 Scope(含来源:appointment / manual / inherited / self)
我能看到什么?       → 信息等级
我能做什么?         → 有效权限(按资源分组,view ≠ edit ≠ export ≠ manage)
我现在要完成什么?   → 分配给我的 open 任务(直接链到任务详情)
我向谁负责?         → 角色所属治理主体(body)
```

另有稳定容器(§26):相关文件 / 培训 / 问责 — V0.2 只建立数据容器与 UI 区域,
不为它们构建子系统。

## 数据路径

- `MyGovernanceService::build(GovernanceContext)` — 一次上下文 + 少量 prepared
  查询聚合全部区块;person 事实经 PersonLookup 只读解析,不复制进 gov_*。
- 无治理身份的用户看到明确说明("身份由教会授予,与 ChurchCRM 登录分离"),
  不显示任何他人数据。
- 页面数据全部来自授权后的上下文 — 它本身就是授权结果的可视化。
