<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('email_templates', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('title')->unique(); // e.g. "welcome_email"
            $table->string('slug')->unique();
            $table->longText('template')->nullable();   // no default: MySQL 8 refuses one on TEXT columns
            $table->string('from_email', 255)->nullable()->default('Null');
            $table->string('subject', 255)->nullable()->default('Null');
            $table->boolean('is_active')->default(1);
             
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_templates');
    }
};
