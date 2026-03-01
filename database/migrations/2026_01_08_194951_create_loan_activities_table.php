<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('loan_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained()->onDelete('cascade');
            $table->foreignUuid('user_id')->constrained(); // Who performed the action
            $table->string('action'); // created, approved, rejected, disbursed, payment_received
            $table->text('description');
            $table->json('metadata')->nullable(); // Additional data
            $table->timestamps();
            
            $table->index(['loan_id', 'created_at']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('loan_activities');
    }
};
