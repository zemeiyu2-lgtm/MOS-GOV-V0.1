<?php

namespace ChurchCRM\Plugins\MosGov\Security;

/**
 * Immutable result of a governance authorization decision (V0.2 design §17).
 *
 * Every DENY carries a machine-readable reason and, where relevant, the
 * permission / scope / information level that caused it, so denial pages can
 * explain the boundary without leaking protected content.
 *
 * @SuppressWarnings(PHPMD.TooManyFields)
 */
final class AuthorizationDecision
{
    /**
     * @param array<int, string>|null $requiredScopes scopes the resource lives
     *                                                in (when scope failed)
     */
    public function __construct(
        public readonly bool $allowed,
        public readonly string $reason,
        public readonly ?string $permission = null,
        public readonly ?string $scope = null,
        public readonly ?string $informationLevel = null,
        public readonly ?string $source = null,
        public readonly ?array $requiredScopes = null,
    ) {
    }

    public static function allow(string $reason, ?string $source = null): self
    {
        return new self(true, $reason, source: $source);
    }

    public static function deny(string $reason, ?string $permission = null, ?string $scope = null, ?string $informationLevel = null, ?string $source = null): self
    {
        return new self(false, $reason, $permission, $scope, $informationLevel, $source);
    }

    public function withScopeContext(?string $scope, ?array $requiredScopes): self
    {
        return new self(
            $this->allowed,
            $this->reason,
            $this->permission,
            $scope,
            $this->informationLevel,
            $this->source,
            $requiredScopes,
        );
    }
}
