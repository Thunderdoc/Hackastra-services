<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // sku lives here, not only in the 000007 alter migration:
            // that migration sorts before this create, so a fresh
            // migrate would otherwise never get the column.
            $table->string('sku', 100)->nullable();
            $table->string('category');
            $table->string('unit');
            $table->unsignedInteger('low_stock_threshold')->default(0);
            $table->timestamps();
        });

        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_item_id')->constrained('inventory_items')->cascadeOnDelete();
            $table->string('type');
            $table->unsignedInteger('quantity');
            $table->string('note')->nullable();
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->date('movement_date');
            $table->timestamps();
            $table->index('inventory_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
        Schema::dropIfExists('inventory_items');
    }
};
