<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('application_version_history', function (Blueprint $table) {
            $table->id();
            $table->string('version', 50);
            $table->text('reason');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        $version = DB::table('settings')->where('key', 'app_version')->value('value');
        if ($version === null || $version === '') {
            $version = '2.1';
            DB::table('settings')->updateOrInsert(
                ['key' => 'app_version'],
                [
                    'value' => $version,
                    'type' => 'string',
                    'group' => 'app',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        DB::table('application_version_history')->insert([
            'version' => $version,
            'reason' => 'Initial version baseline',
            'updated_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('application_version_history');
    }
};
