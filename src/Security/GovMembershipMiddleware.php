<?php

namespace ChurchCRM\Plugins\MosGov\Security;

use ChurchCRM\dto\SystemURLs;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;

/**
 * 成员准入闸门（GOV-DEV-09-E）。
 *
 * 产品决定：MOS-GOV 治理系统不向普通成员开放 —— 只有两类人可以进入：
 *   1. ChurchCRM 系统管理员（负责授予治理身份、初始化结构与主体）；
 *   2. 持有治理身份记录的人（gov_identity 有任何一行）。
 *
 * 为什么要在路由层再加一道，而不是只靠菜单隐藏：
 *   隐藏菜单只解决"看不见"，知道 URL 的人仍可访问。V0.1 的读策略是
 *   "任何登录用户可读"，本闸门把默认从 ALLOW 改为 DENY（管理员/身份持有人除外），
 *   与产品的"不给普通成员看系统"一致。
 *
 * 边界：
 *   - 未登录不放行也不拦截，交给全局 AuthMiddleware 走正常登录跳转；
 *   - 判定只做一次 identity 存在性查询（请求内记忆），不加载完整治理上下文；
 *   - 这里是"能不能进这个系统"，进去之后每一页/每一行仍由
 *     GovernancePolicy 与 GovAuthorization 独立判定。
 */
final class GovMembershipMiddleware implements MiddlewareInterface
{
    public function process(Request $request, Handler $handler): Response
    {
        $user = GovAuthorization::currentUser();

        // 未登录：交给全局认证中间件处理（保持原有 302 跳登录页行为）。
        if ($user === null) {
            return $handler->handle($request);
        }

        if ($user->isAdmin() || GovAuthorization::subjectToGovernancePolicy($user)) {
            return $handler->handle($request);
        }

        return $this->forbidden();
    }

    private function forbidden(): Response
    {
        $root = SystemURLs::getRootPath();
        $home = htmlspecialchars($root . '/v2/dashboard', ENT_QUOTES, 'UTF-8');
        $css = htmlspecialchars($root . '/skin/v2/churchcrm.min.css', ENT_QUOTES, 'UTF-8');

        $response = new \Slim\Psr7\Response(403);
        $response->getBody()->write(
            '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>MOS-GOV — 未开放</title>'
            . '<link rel="stylesheet" href="' . $css . '"></head>'
            . '<body class="antialiased"><div class="page page-center"><div class="container-tight py-4">'
            . '<div class="card"><div class="card-body text-center">'
            . '<div class="display-6 mb-2">MOS-GOV</div>'
            . '<div class="text-secondary mb-3">教会治理平台 · 未向此账号开放（HTTP 403）</div>'
            . '<p>治理系统仅向治理身份持有人与系统管理员开放。你的账号当前没有治理身份；'
            . '如需参与治理，请联系治理管理员授予。</p>'
            . '<div class="btn-list justify-content-center mt-4">'
            . '<a class="btn btn-outline-secondary" href="javascript:history.back()">返回上一页</a>'
            . '<a class="btn btn-primary" href="' . $home . '">返回仪表板</a>'
            . '</div></div></div></div></div></body></html>'
        );

        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
