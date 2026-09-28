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
        Schema::table('checks', function (Blueprint $table) {
            $table->string('ssh_status')->default('idle')->after('reviewed_by');
            $table->uuid('ssh_job_id')->nullable()->unique()->after('ssh_status');
            $table->text('ssh_error')->nullable()->after('ssh_job_id');
            $table->timestamp('ssh_started_at')->nullable()->after('ssh_error');
            $table->timestamp('ssh_finished_at')->nullable()->after('ssh_started_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('checks', function (Blueprint $table) {
            $table->dropUnique(['ssh_job_id']);
            $table->dropColumn([
                'ssh_status',
                'ssh_job_id',
                'ssh_error',
                'ssh_started_at',
                'ssh_finished_at',
            ]);
        });
    }
};
