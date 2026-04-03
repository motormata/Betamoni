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
        Schema::table('loans', function (Blueprint $table) {
            $table->foreignUuid('loan_product_id')->nullable()->after('market_id')->constrained('loan_products');
            $table->integer('quantity')->default(1)->after('loan_product_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->dropForeign(['loan_product_id']);
            $table->dropColumn(['loan_product_id', 'quantity']);
        });
    }
};
