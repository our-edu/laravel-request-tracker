<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('request_trackers', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->uuid('user_uuid')->index();
            $table->uuid('role_uuid')->nullable()->index();
            $table->string('role_name')->nullable()->index()->comment('Role name for easier filtering');
            $table->date('date')->index();
            
            // Access tracking - ملخص النشاط اليومي
            $table->integer('access_count')->default(0)->comment('إجمالي عدد الطلبات في اليوم');
            $table->dateTime('first_access')->nullable()->comment('أول دخول في اليوم');
            $table->dateTime('last_access')->nullable()->comment('آخر دخول في اليوم');
            
            // Session tracking
            $table->uuid('user_session_uuid')->nullable()->index();
            
            $table->timestamps();            
            // Composite unique index - يوزر واحد + رول واحد + يوم واحد = سطر واحد
            $table->unique(['user_uuid', 'role_uuid', 'date'], 'unique_user_role_date');
            $table->index(['user_uuid', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('request_trackers');
    }
};
