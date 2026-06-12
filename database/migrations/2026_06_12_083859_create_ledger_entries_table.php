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
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();

            $table->uuid('transaction_id');

            $table->foreign('transaction_id')
                ->references('id')
                ->on('ledger_transactions');

            $table->foreignId('wallet_id')
                ->nullable()
                ->constrained();

            $table->enum('entry_type', ['debit', 'credit']);

            $table->bigInteger('amount');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};
