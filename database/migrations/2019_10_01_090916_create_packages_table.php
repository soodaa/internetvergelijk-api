<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePackagesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('packages', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name')->nullable();
            $table->string('package_link')->nullable()->unique();
            $table->string('supplier_id')->nullable();
            $table->string('download')->default('0');
            $table->string('upload')->default('0');
            $table->string('channels')->default('0');
            $table->string('channels_hd')->default('0');
            $table->string('call_costs')->default('0');
            $table->string('price')->default('0');
            $table->string('sale_months')->default('0');
            $table->string('price_per_month')->default('0');
            $table->string('price_per_year')->default('0');
            $table->text('extra')->nullable();
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
        Schema::dropIfExists('packages');
    }
}
