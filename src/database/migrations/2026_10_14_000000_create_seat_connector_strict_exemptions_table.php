<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seat_connector_strict_exemptions', function (Blueprint $table): void {
            $table->increments('id');
            // Match SeAT 5's unsigned INT users.id and signed BIGINT token character_id.
            $table->unsignedInteger('user_id');
            $table->bigInteger('character_id');
            $table->timestamps();

            $table->unique(['user_id', 'character_id'], 'sc_strict_exempt_user_char_unique');
            $table->foreign('user_id', 'sc_strict_exempt_user_fk')
                ->references('id')->on('users')->onDelete('cascade');
            $table->foreign('character_id', 'sc_strict_exempt_character_fk')
                ->references('character_id')->on('refresh_tokens')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seat_connector_strict_exemptions');
    }
};
