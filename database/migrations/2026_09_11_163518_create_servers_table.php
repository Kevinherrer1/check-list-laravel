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
        Schema::create('servers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('hostname')->default('');
            $table->string('ip')->default('');
            $table->string('ssh_command')->default('');
            $table->string('username')->default('');
            $table->string('system')->default('');
            $table->string('typical_time')->default('');
            $table->boolean('does_backup')->default(true);
            $table->text('observations')->nullable();
            $table->string('review_script')->default('');
            $table->boolean('active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->string('netapp_volume')->default('');
            $table->string('nfs_root')->default('');
            $table->string('nfs_slug')->default('');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('servers');
    }
};
