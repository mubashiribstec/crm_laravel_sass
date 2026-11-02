<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * users.sip_password was stored in plain text. The User model now casts it as
 * 'encrypted'; this encrypts existing plain values once (already-encrypted
 * values are left alone, so it is safe to run again).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('users') || !Schema::hasColumn('users', 'sip_password')) {
            return;
        }
        // Encrypted values are longer than 255 characters: widen the column first.
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE users MODIFY sip_password TEXT NULL');
        }

        DB::table('users')->whereNotNull('sip_password')->where('sip_password', '!=', '')
            ->orderBy('id')->select('id', 'sip_password')
            ->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    try {
                        Crypt::decryptString($row->sip_password);
                        continue;                     // already encrypted
                    } catch (DecryptException) {
                    }
                    DB::table('users')->where('id', $row->id)
                        ->update(['sip_password' => Crypt::encryptString($row->sip_password)]);
                }
            });
    }

    public function down(): void
    {
        // Not reversible on purpose: never write passwords back in plain text.
    }
};
