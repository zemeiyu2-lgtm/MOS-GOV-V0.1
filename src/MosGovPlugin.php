<?php

namespace ChurchCRM\Plugins\MosGov;

use ChurchCRM\Plugin\AbstractPlugin;

/**
 * MOS-GOV V0.1
 *
 * Governance layer for ChurchCRM.
 *
 * Design boundary:
 * - ChurchCRM remains the source of church/person/group/event/user facts.
 * - MOS-GOV owns governance structures, appointments, meetings, decisions,
 *   responsibilities and tasks.
 * - Cross-system references use ChurchCRM IDs; MOS-GOV does not redefine
 *   ChurchCRM people/groups/events.
 */
class MosGovPlugin extends AbstractPlugin
{
    public function getId(): string
    {
        return 'mos-gov';
    }

    public function getName(): string
    {
        return 'MOS-GOV';
    }

    public function getDescription(): string
    {
        return 'MOS church governance layer for ChurchCRM.';
    }

    public function getVersion(): string
    {
        return '0.2.0';
    }

    public function boot(): void
    {
        // V0.1 intentionally keeps hooks empty until the real ChurchCRM
        // deployment has been verified. Governance actions are explicit.
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function activate(): void
    {
        // Database creation is intentionally performed by the SQL migration
        // in database/001_initial.sql during installation/verification.
    }

    public function deactivate(): void
    {
        // Preserve governance data when the plugin is disabled.
    }

    public function uninstall(): void
    {
        // V0.1 does not silently delete governance history.
        // Destructive uninstall must be a deliberate migration/admin action.
    }
}
