<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A non-secret mirror of the on-disk `mdvault-encryption.json` key file
     * (ADR `encrypted-vault-storage-layout`). It holds no passwords, keys or
     * content: the wrapped key is useless without the password.
     */
    public function up(): void
    {
        Schema::create('vault_encryption', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vault_id')->unique()->constrained('vaults')->cascadeOnDelete();
            $table->string('key_id', 36);
            $table->unsignedInteger('key_version')->default(1);
            $table->string('algorithm', 50);
            $table->string('kdf_algorithm', 30);
            $table->unsignedInteger('kdf_opslimit');
            $table->unsignedBigInteger('kdf_memlimit');
            $table->string('salt', 64);
            $table->string('nonce', 64);
            $table->text('encrypted_key');
            $table->unsignedSmallInteger('format_version');
            $table->char('header_hash', 64);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vault_encryption');
    }
};
