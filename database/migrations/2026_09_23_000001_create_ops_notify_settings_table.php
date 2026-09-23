<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ops_notify_settings')) {
            return;
        }

        Schema::create('ops_notify_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->text('value');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ops_notify_settings');
    }
};
