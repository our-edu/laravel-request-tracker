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
            $table->uuid('user_uuid')->nullable()->index();
            $table->string('method')->nullable();
            $table->string('path')->nullable();
            $table->string('auth_guards')->nullable();
            $table->uuid('role_uuid')->nullable();
            $table->string('application')->nullable();
            $table->uuid('user_session_uuid')->nullable()->index();
            $table->dateTime('last_access')->nullable();
            $table->date('date')->nullable()->index(); // Store date only for daily tracking
            $table->timestamps();
            
            // Composite index for efficient daily unique user+role lookups
            $table->index(['user_uuid', 'role_uuid', 'date']);
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
