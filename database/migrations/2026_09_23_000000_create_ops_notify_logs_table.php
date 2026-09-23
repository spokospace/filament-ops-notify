<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ops_notify_logs')) {
            return;
        }

        Schema::create('ops_notify_logs', function (Blueprint $table) {
            $table->id();
            $table->string('direction', 8)->default('out');
            $table->string('channel', 32);
            $table->string('topic', 32)->nullable();
            $table->string('event', 120)->index();
            $table->string('level', 16);
            $table->string('title')->nullable();
            $table->text('body')->nullable();
            $table->json('payload')->nullable();
            $table->string('status', 16)->index();
            $table->string('external_id', 64)->nullable();
            $table->text('error')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ops_notify_logs');
    }
};
