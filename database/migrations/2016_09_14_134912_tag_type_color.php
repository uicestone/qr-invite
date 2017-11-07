<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class TagTypeColor extends Migration
{
	
	protected $table = 'tags';
	
	/**
	 * Run the migrations.
	 *
	 * @return void
	 */
	public function up()
	{
		Schema::table($this->table, function(Blueprint $table)
		{
			if(Schema::hasColumn($this->table, 'post_type') && Schema::hasColumn($this->table, 'priority') && Schema::hasColumn($this->table, 'event_date') && Schema::hasColumn($this->table, 'record_link'))
			{
				$table->dropColumn(['post_type', 'priority', 'event_date', 'record_link']);
			}
			
			if(!Schema::hasColumn($this->table, 'type') && !Schema::hasColumn($this->table, 'color'))
			{
				$table->string('type', 31)->after('name')->nullable();
				$table->string('color', 31)->after('type')->nullable();
			}
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
			if(Schema::hasColumn($this->table, 'type') && Schema::hasColumn($this->table, 'color'))
			{
				$table->removeColumn('type');
				$table->removeColumn('color');
			}
		});
	}
}
