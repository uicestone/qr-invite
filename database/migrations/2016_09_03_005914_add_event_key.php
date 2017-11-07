<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddEventKey extends Migration
{
	
	protected $table = 'messages';
	
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table($this->table, function(Blueprint $table)
		{
			$table->index(['created_at', 'event'], 'created_at-event');
			$table->dropIndex('created_at');
		});
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table($this->table, function(Blueprint $table)
		{
			$table->index(['created_at'], 'created_at');
			$table->dropIndex('created_at-event');
		});
    }
}
