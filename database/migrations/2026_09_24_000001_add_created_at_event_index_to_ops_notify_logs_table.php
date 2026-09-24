<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Settings counts the events of the last 30 days (SeenEvents). With this index that is a scan
 * of the index range alone, even when a burst logged many held-back messages.
 */
return new class extends Migration
{
    private const INDEX = 'ops_notify_logs_created_at_event_status_index';

    public function up(): void
    {
        if (! Schema::hasTable('ops_notify_logs') || Schema::hasIndex('ops_notify_logs', self::INDEX)) {
            return;
        }

        Schema::table('ops_notify_logs', function (Blueprint $table) {
            $table->index(['created_at', 'event', 'status'], self::INDEX);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ops_notify_logs') || ! Schema::hasIndex('ops_notify_logs', self::INDEX)) {
            return;
        }

        Schema::table('ops_notify_logs', function (Blueprint $table) {
            $table->dropIndex(self::INDEX);
        });
    }
};
