<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;


return new class extends Migration
{
    public function up()
    {
        Schema::create('borrowers', function (Blueprint $table) {
            $table->id();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('phone')->unique();
            $table->string('alternate_phone')->nullable();
            $table->string('email')->nullable();
            $table->string('bvn')->unique()->nullable();
            $table->enum('gender', ['male', 'female'])->nullable();
            $table->date('date_of_birth')->nullable();
            $table->text('home_address');
            $table->text('business_address')->nullable();
            $table->string('lga')->nullable();
            $table->string('state')->nullable();
            $table->string('business_type')->nullable(); // e.g., Trader, Artisan, etc.
            $table->text('business_description')->nullable();
            $table->string('id_type')->nullable(); // NIN, Voter's Card, Driver's License
            $table->string('id_number')->nullable();
            $table->string('next_of_kin_name')->nullable();
            $table->string('next_of_kin_phone')->nullable();
            $table->string('next_of_kin_relationship')->nullable();
            $table->text('next_of_kin_address')->nullable();
            $table->foreignId('market_id')->nullable()->constrained();
            $table->string('shop_number')->nullable();
            $table->foreignId('registered_by')->nullable()->constrained('users'); // Agent who registered
            $table->string('photo_path')->nullable();
            $table->string('id_card_path')->nullable();
            $table->string('business_photo_path')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down()
    {
        Schema::dropIfExists('borrowers');
    }
};