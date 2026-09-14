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
        Schema::create('checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->constrained('reviews')->cascadeOnDelete();
            $table->foreignId('server_id')->constrained('servers')->cascadeOnDelete();

            
            $table->string('powered_on')->default('pending');
            $table->string('mounts_status')->default('pending');
            $table->json('mounts_details')->nullable();
            $table->string('backup')->default('pending');
            $table->string('generated_at')->default('');
            $table->date('backup_date')->nullable();
            $table->string('size')->default('');
            $table->string('root_cause')->default('');
            $table->string('notified')->default('');
            $table->string('channel')->default('');
            $table->string('observations')->nullable();
            $table->string('review_result')->default('');
            $table->string('reviewed_by')->default('');


            $table->unique(['review_id', 'server_id']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('checks');
    }
};
