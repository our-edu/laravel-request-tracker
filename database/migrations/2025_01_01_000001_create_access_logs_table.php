<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations - Detailed per-request access logs
     *
     * @return void
     */
    public function up()
    {
        Schema::create('user_access_details', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            
            // Link to daily summary (request_trackers)
            $table->uuid('tracker_uuid')->index()->comment('Foreign key to request_trackers.uuid');
            
            // User context (denormalized for faster queries)
            $table->uuid('user_uuid')->index();
            $table->uuid('role_uuid')->nullable()->index();
            $table->string('role_name')->nullable()->index()->comment('Role name for easier filtering');
            $table->date('date')->index();
            
            // Endpoint details - ده اللي المدرس دخل عليه
            $table->string('method', 10)->comment('GET, POST, PUT, DELETE');
            $table->text('endpoint')->comment('Full path: api/v1/users/123');
            $table->string('route_name')->nullable()->index()->comment('Laravel route name');
            $table->string('controller_action')->nullable()->comment('Controller@method');
            
            // Module organization - التنظيم بتاع الموديولات
            $table->string('module')->nullable()->index()->comment('Main module: users, orders, students');
            $table->string('submodule')->nullable()->index()->comment('Sub-module: profile, grades, attendance');
            $table->string('action')->nullable()->comment('Action description: "List", "Create", "Update", "Delete"');
            
            // Visit tracking - عدد مرات زيارة نفس الendpoint في نفس اليوم
            $table->integer('visit_count')->default(1)->comment('Number of times visited this endpoint today');
            
            // Timestamps - أول وآخر زيارة للendpoint ده
            $table->dateTime('first_visit')->nullable();
            $table->dateTime('last_visit')->nullable();
            
            $table->timestamps();
            
            // Indexes for efficient queries (removed unique constraint to allow multiple visits per day)
            $table->index(['user_uuid', 'role_uuid', 'endpoint', 'date'], 'idx_user_endpoint_date');
            $table->index(['tracker_uuid', 'module']);
            $table->index(['user_uuid', 'date', 'module']);
            $table->index(['module', 'submodule', 'date']);
            
            // Foreign key to request_trackers
            $table->foreign('tracker_uuid')
                  ->references('uuid')
                  ->on('request_trackers')
                  ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('user_access_details');
    }
};
