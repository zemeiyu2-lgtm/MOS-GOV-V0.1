<?php

declare(strict_types=1);

use ChurchCRM\Plugins\MosGov\MosGovPlugin;
use PHPUnit\Framework\TestCase;

final class MosGovPluginTest extends TestCase
{
    public function testMetadata(): void
    {
        $plugin = new MosGovPlugin();

        $this->assertSame('mos-gov', $plugin->getId());
        $this->assertSame('MOS-GOV', $plugin->getName());
        $this->assertSame('0.1.0', $plugin->getVersion());
        $this->assertTrue($plugin->isConfigured());
    }
}
