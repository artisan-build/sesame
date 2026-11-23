<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Kept bodies: a send carrying `X-Sesame-Keep-Body: 1` keeps its whole raw
 * message (base64, as received) until sesame:prune removes the message. The
 * viewer key signs links to the message viewer; it is encrypted with APP_KEY.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sesame_messages', function (Blueprint $table): void {
            $table->text('raw')->nullable();
        });
        Schema::table('sesame_accounts', function (Blueprint $table): void {
            $table->text('viewer_key')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sesame_messages', function (Blueprint $table): void {
            $table->dropColumn('raw');
        });
        Schema::table('sesame_accounts', function (Blueprint $table): void {
            $table->dropColumn('viewer_key');
        });
    }
};
