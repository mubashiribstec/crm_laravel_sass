<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'sip_extension')) {   // guard: a partial migrate or hand-made column (M-08)
                $table->string('sip_extension', 50)->nullable()->after('email')
                      ->comment('FreePBX SIP extension number for this agent');
            }
            if (!Schema::hasColumn('users', 'sip_password')) {   // guard: a partial migrate or hand-made column (M-08)
                $table->string('sip_password', 255)->nullable()->after('sip_extension')
                      ->comment('SIP/WebRTC password for this agent extension');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['sip_extension', 'sip_password']);
        });
    }
};
