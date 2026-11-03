<?php

namespace App\Console\Commands;

use App\Services\Xplogent\XplogentClient;
use Illuminate\Console\Command;

/**
 * php artisan xplogent:check — can this CRM reach Xplogent? (The other direction is
 * Xplogent's own Test connection.) Nothing is dialled.
 */
class XplogentCheck extends Command
{
    protected $signature = 'xplogent:check';

    protected $description = 'Check that the CRM can reach Xplogent (address, firewall, token, clocks) without dialling';

    public function handle(XplogentClient $client): int
    {
        $r = $client->check('php artisan xplogent:check');
        $this->line('Xplogent address (XPLOGENT_BASE_URL): ' . ($r['url'] ?: '(not set)'));
        if ($r['ok']) {
            $this->info('✓ ' . $r['reason']);
            if ($r['warning']) {
                $this->warn('! ' . $r['warning']);
            }

            return self::SUCCESS;
        }
        $this->error('✗ ' . $r['reason']);
        $this->line('  ' . $r['fix']);

        return self::FAILURE;
    }
}
