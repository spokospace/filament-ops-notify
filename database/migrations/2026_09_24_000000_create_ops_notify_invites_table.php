<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ops_notify_invites')) {
            return;
        }

        Schema::create('ops_notify_invites', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 60);
            $table->string('name', 64);
            // Encrypted: whoever has the link can join the group.
            $table->text('invite_link');
            $table->unsignedInteger('member_limit')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ops_notify_invites');
    }
};
