<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['offices_documents' => 'office_id', 'units_documents' => 'unit_id'] as $name => $foreignKey) {
            if (!Schema::hasTable($name)) {
                Schema::create($name, function (Blueprint $table) use ($foreignKey) {
                    $table->id();
                    $table->unsignedBigInteger($foreignKey)->index();
                    $table->unsignedBigInteger('user_id')->nullable()->index();
                    $table->string('document_name');
                    $table->string('document_path');
                    $table->unsignedBigInteger('document_size')->nullable();
                    $table->string('document_extension', 10)->nullable();
                    $table->text('delete_reason')->nullable();
                    $table->unsignedBigInteger('deleted_by')->nullable();
                    $table->timestamps();
                    $table->softDeletes();
                });
            } else {
                Schema::table($name, function (Blueprint $table) use ($name) {
                    if (!Schema::hasColumn($name, 'delete_reason')) {
                        $table->text('delete_reason')->nullable();
                    }
                    if (!Schema::hasColumn($name, 'deleted_by')) {
                        $table->unsignedBigInteger('deleted_by')->nullable();
                    }
                    if (!Schema::hasColumn($name, 'deleted_at')) {
                        $table->softDeletes();
                    }
                });
            }
        }
    }

    public function down(): void
    {
        // These document tables may predate this migration and contain user data.
    }
};
