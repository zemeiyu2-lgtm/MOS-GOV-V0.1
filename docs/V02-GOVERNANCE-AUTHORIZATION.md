# V0.2 — Governance Authorization

## Model

实际权限 = 治理身份 × 治理角色 × 受托责任 × 信息等级 × Governance Scope × Action × 当前状态

```
ChurchCRM User → Person
    ↓ (person_id, one row)
gov_identity                      治理身份
    ↓
gov_identity_role  ──► gov_role (A01…E01 + V0.1 治理角色)
    ↓ appointment_id
gov_appointment                   任命(active→权限生效;ended→全部失效)
    ↓
gov_identity_scope / gov_role_scope + gov_scope   治理范围
    ↓
gov_permission / gov_role_permission / gov_identity_permission   权限
    ↓
AuthorizationDecision (ALLOW / DENY + reason)
```

## Evaluation order (strict, §18)

1. 登录？ 2. 有治理身份？ 3. 身份 active？ 4. 有 active 角色？ 5. 角色有权限？
6. 任命 active？ 7. 资源在 Scope 内？ 8. 信息等级允许？ 9. Action 允许？
10. 存在 Explicit DENY？ → 11. ALLOW。

Explicit DENY 优先于任何 GRANT。P5 默认 DENY,任何角色不因职位高自动获得 P5;
唯一路径是显式授权(`gov_identity_permission` 显式 grant 或角色级 P5 allow 规则)。

## Scope containment

- 范围匹配是精确 (scope_type, scope_id) 对:**Group 12 永远不会因为 ID 运算变成 Group 13**。
- global ⊃ church ⊃ 其它类型;person 身份自带隐式 self-scope(本人的任务/身份永远可见)。
- scope_type 是封闭白名单(global/church/structure/body/ministry/group/activity/project/
  person);global/church 不允许携带数字 ID,其余必须携带。

## Permission registry

权限 key 来自封闭白名单(GovRepository::PERMISSIONS,35 个)。任何页面/API 都不能
创建白名单外的 key;critical 级权限(permission.manage)不允许作为个人 override 发放。

## Enforcement depth (§19)

- Route:所有新路由走 `GovAuthorization::can()`;写路由保留 R07 中间件。
- Repository/Query:列表页对携带治理身份的用户做逐行 scope 过滤(先授权,后展示)。
- Detail:URL/ID 猜测 → DENY 页(§21)。
- Search:先授权 → scope → visibility → 结果(§23)。
- Export:view ≠ export;逐行过滤 + P5 掩蔽 + 审计(§24)。
- P5 字段:视图层不显示"字段名+内容",只显示"此信息受权限保护。"(§42)。

## V0.1 compatibility

`GovAuthorization::canRead()` / `canWrite()` 语义保留(模块读取 = 登录用户;
治理 CRUD 写 = ChurchCRM 管理员)。携带治理身份的用户在其之上获得更严格的
scope/visibility 约束。导出没有管理员旁路。

---

## Appendix — legacy read surfaces and the monotonicity rule (final review)

The ten V0.1 entity pages predate the policy engine, so they cannot simply
"be" the engine. They instead ask two questions in order:

1. `GovAuthorization::subjectToGovernancePolicy()` — does this user hold a
   `gov_identity` row (any status)? If not, the V0.1 bootstrap read policy
   applies unchanged.
2. If yes, every row and every detail lookup goes through
   `GovAuthorization::can($user, 'view', $entity, $row)` — identity → role →
   permission → appointment → scope → information level → explicit deny.

The identity check in step 1 is deliberately *not* "is the identity active".
Gating on an active identity made this sequence fail open: an inactive
identity has no context, so step 2 never ran and the person saw the unscoped
list. The rule is now:

> Access is monotonic. Provisioning, activating or deactivating a governance
> identity may never increase what a person can read.

Consequences to keep in mind when extending this code:

- Never reintroduce `$ctx !== null` as a gate on a read surface.
- Detail denials must render through the deny page (it needs `decision` *and*
  `esc`); a missing closure capture or view variable turns a 403 into a 500
  while still leaking nothing — correct in direction, wrong in behaviour, and
  easy to miss because the path is only reachable for identity holders.
- Administrative `isAdmin()` bootstrap is applied to governance *writes* and
  identity administration, never to export (§24) and never as a scope bypass.
