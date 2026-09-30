<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('backups', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('scope', 20);
            $table->string('filename', 255);
            $table->text('path');
            $table->unsignedBigInteger('file_size');
            $table->char('file_hash', 64);
            $table->unsignedSmallInteger('format_version');
            $table->unsignedInteger('vault_count');
            $table->unsignedInteger('note_count');
            $table->unsignedInteger('file_count');
            $table->json('contents');
            $table->timestamps();

            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('backups');
    }
};
