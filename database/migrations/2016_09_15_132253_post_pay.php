<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class PostPay extends Migration
{
	
	protected $table = 'post_pay';
	
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create($this->table, function(Blueprint $table)
		{
			$table->integer('id', true, true);
			$table->integer('post_id', false, true);
			$table->integer('user_id', false, true)->index('user_id');
			$table->timestamp('updated_at')->index('updated_at')->default(DB::raw('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'));
			$table->timestamp('created_at')->index('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));
			$table->unique(['post_id', 'user_id'], 'post_id');
			$table->foreign('user_id', 'post_pay_ibfk_1')->references('id')->on('users')->onUpdate('cascade')->onDelete('no action');
			$table->foreign('post_id', 'post_pay_ibfk_2')->references('id')->on('posts')->onUpdate('cascade')->onDelete('no action');;
		});
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::drop($this->table);
    }
}
