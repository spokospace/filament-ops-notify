<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('ops_notify_invites', 'chat_id')) {
            return;
        }

        Schema::table('ops_notify_invites', function (Blueprint $table) {
            // The chat the link was created in, so Revoke still targets it after the chat id
            // changes in Settings. Null for links created before this column existed.
            $table->string('chat_id', 64)->nullable()->after('channel');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('ops_notify_invites', 'chat_id')) {
            return;
        }

        Schema::table('ops_notify_invites', function (Blueprint $table) {
            $table->dropColumn('chat_id');
        });
    }
};
