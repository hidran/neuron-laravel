<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     *
     * NeuronAI 4 identifies every stored message by the pair thread_id and
     * message_id: EloquentMessageStore appends with firstOrCreate on it.
     */
    public function up(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->string('message_id')->nullable()->after('thread_id');
            $table->unique(['thread_id', 'message_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropUnique(['thread_id', 'message_id']);
            $table->dropColumn('message_id');
        });
    }
};
