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
        Schema::create('notes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('vault_id')->constrained()->cascadeOnDelete();
            $table->string('title', 255);
            $table->string('filename', 255);
            $table->text('relative_path');
            $table->string('extension', 20);
            $table->string('mime_type', 100)->default('text/markdown');
            $table->unsignedBigInteger('file_size')->default(0);
            $table->string('file_hash', 64);
            $table->unsignedBigInteger('file_mtime')->nullable();
            $table->boolean('is_encrypted')->default(false);
            $table->timestamps();

            $table->unique(['vault_id', 'relative_path']);
            $table->index(['vault_id', 'file_hash']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notes');
    }
};
