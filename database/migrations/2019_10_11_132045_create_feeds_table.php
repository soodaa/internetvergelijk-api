<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateFeedsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('feeds', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name')->nullable();
            $table->text('link')->nullable();
            $table->integer('supplier_id')->nullable();
            $table->integer('delay')->default(1);
            $table->string('package_name')->nullable();
            $table->string('package_link')->nullable();
            $table->string('supplier')->nullable();
            $table->string('download')->nullable();
            $table->string('upload')->nullable();
            $table->string('channels')->nullable();
            $table->string('channels_hd')->nullable();
            $table->string('call_costs')->nullable();
            $table->string('price')->nullable();
            $table->string('sale_months')->nullable();
            $table->string('price_per_month')->nullable();
            $table->string('price_per_year')->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('feeds');
    }
}
