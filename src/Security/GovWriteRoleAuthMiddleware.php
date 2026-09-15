<?php

namespace ChurchCRM\Plugins\MosGov\Security;

use ChurchCRM\Slim\Middleware\Request\Auth\BaseAuthRoleMiddleware;

/**
 * Route middleware that enforces the MOS-GOV governance write permission.
 *
 * Reuses ChurchCRM's own role-middleware machinery (BaseAuthRoleMiddleware
 * handles authentication failures, browser-vs-API responses and the
 * access-denied redirect) while the actual policy decision is delegated to
 * the MOS-GOV authorization layer (R07).
 */
final class GovWriteRoleAuthMiddleware extends BaseAuthRoleMiddleware
{
    protected function hasRole(): bool
    {
        return GovAuthorization::canWrite($this->user);
    }

    protected function noRoleMessage(): string
    {
        return GovAuthorization::writeDeniedMessage();
    }

    protected function getRoleName(): string
    {
        return GovAuthorization::ROLE_NAME;
    }
}
