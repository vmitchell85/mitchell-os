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
        Schema::create('meals', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('category');
            $table->string('name');
            $table->text('description')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('skylight_id')->nullable();
            $table->date('skylight_date')->nullable();
            $table->boolean('repeats_in_skylight')->default(false);
            $table->json('skylight_snapshot')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('date');
            $table->unique(['skylight_id', 'skylight_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('meals');
    }
};
