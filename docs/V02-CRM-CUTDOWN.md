# V0.2 — CRM Cutdown

原则(§28/§29):ChurchCRM core 不删除、不修改。裁剪通过"插件边界内的导航重组 +
安全模式 + 文档化配置建议"实现;底层能力(Person/Family/Group/Event/User/Finance)
完整保留,因为 MOS-GOV 依赖它们作为事实来源。

## MOS-GOV 插件内已实现的裁剪

- **治理导向导航** — `views/_tabs.php` 不再以 CRM 式平铺展示实体,而是按治理含义
  分组:治理首页 / 我的治理中心 / 教会治理 / 治理运行 / 治理身份 / 安全设置。
- **治理搜索替代 CRM 全局搜索** — `/mos-gov/search` 是唯一治理检索入口,
  经过 授权→Scope→可见性;普通会员无法用搜索越权(§23)。
- **导出入口默认关闭** — 列表页没有导出按钮;导出是独立权限且逐行过滤(§24)。
- **P5 信息在 UI 掩蔽** — 不显示字段名+内容,只显示保护提示(§42)。
- **MOS-GOV 页面零外链** — 不链接 Email/SMS/Maps/Fundraiser/Analytics 等入口。

## 建议在 ChurchCRM 管理端完成的裁剪(core 边界外,不修改代码)

| CRM 能力 | 建议 | 原因 |
| --- | --- | --- |
| google-analytics 插件 | 停用 | 外呼 + 追踪,违反 OUTBOUND_NETWORK=DENY |
| gravatar 插件 | 停用 | 外部资料服务 |
| mailchimp / vonage(SMS)插件 | 停用 | 默认无邮件/短信外发(§33) |
| external-backup 插件 | 按需停用 | 不允许默认云同步 |
| Email / Maps 菜单 | 通过 ChurchCRM 用户权限收敛 | 交给 core 自带权限系统 |

这些是 core 插件/权限配置,由管理员在 ChurchCRM 内操作;MOS-GOV 不代为修改
(边界规则 §43)。settings 页展示了当前安全模式与对应策略。

## 保留的底层能力(§29)

Person → Governance Identity;Group → Governance Scope;Event → 活动引用;
Finance → 只读受控接口(未来)。不创建第二套 CRM(§30)。
