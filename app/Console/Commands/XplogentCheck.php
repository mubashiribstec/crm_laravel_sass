<?php

namespace App\Console\Commands;

use Horsefly\User;
use App\Services\Xplogent\XplogentClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * php artisan xplogent:check — can this CRM reach Xplogent? (The other direction is
 * Xplogent's own Test connection.) Nothing is dialled. It also says who can see the
 * "Agent Call (AI)" menu: only roles or users given xplogent-agent-call, which no role
 * gets automatically.
 */
class XplogentCheck extends Command
{
    protected $signature = 'xplogent:check';

    protected $description = 'Check that the CRM can reach Xplogent (address, firewall, token, clocks) without dialling';

    public function handle(XplogentClient $client): int
    {
        $this->checkPermission();

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

    /** Warn (without failing) when nobody can see the Agent Call (AI) menu. */
    private function checkPermission(): void
    {
        $name = 'xplogent-agent-call';
        if (!Schema::hasTable('permissions') || !Permission::where('name', $name)->where('guard_name', 'web')->exists()) {
            $this->warn("! The permission {$name} doesn't exist yet, so nobody sees the Agent Call (AI) menu.");
            $this->line('  Run: php artisan migrate --force, then php artisan permission:cache-reset');

            return;
        }
        $roles = Role::whereHas('permissions', fn ($q) => $q->where('name', $name))->pluck('name');
        $users = User::permission($name)->count();
        if ($users === 0) {
            $this->warn("! Nobody has {$name}, so nobody sees the Agent Call (AI) menu.");
            $this->line("  Fix: Roles & Permissions → edit a role → group \"xplogent\" → tick {$name} → Save;");
            $this->line('  then php artisan permission:cache-reset and sign in again.');

            return;
        }
        $this->info("✓ Agent Call (AI) menu: {$users} user(s)"
            . ($roles->isNotEmpty() ? ' (roles: ' . $roles->implode(', ') . ')' : ''));
    }
}
