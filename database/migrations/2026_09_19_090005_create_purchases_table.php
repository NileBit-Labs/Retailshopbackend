<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->unsignedBigInteger('purchase_counter')->default(0);
        });

        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->string('purchase_number')->nullable();
            $table->unsignedBigInteger('total')->default(0);
            $table->unsignedBigInteger('amount_paid')->default(0);
            $table->unsignedBigInteger('amount_due')->default(0);
            $table->string('status')->default('DRAFT');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
            $table->unique(['shop_id', 'purchase_number']);
            $table->index(['shop_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchases');
        Schema::table('shops', fn (Blueprint $table) => $table->dropColumn('purchase_counter'));
    }
};
