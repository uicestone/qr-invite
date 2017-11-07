<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class AddPostAbbrIndex extends Migration
{
	
	protected $table = 'posts';
	
	/**
	 * Run the migrations.
	 *
	 * @return void
	 */
	public function up()
	{
		Schema::table($this->table, function(Blueprint $table)
		{
			$table->string('abbreviation_new', 191)->nullable()->after('abbreviation');
		});
		
		DB::table($this->table)->where('abbreviation', '!=', '')->update(['abbreviation_new'=>DB::raw('abbreviation')]);
		
		Schema::table($this->table, function(Blueprint $table)
		{
			$table->dropColumn('abbreviation');
		});
		
		Schema::table($this->table, function(Blueprint $table)
		{
			$table->string('abbreviation', 191)->nullable()->after('abbreviation_new');
		});
		
		DB::table($this->table)->whereNotNull('abbreviation_new')->update(['abbreviation'=>DB::raw('abbreviation_new')]);
		
		Schema::table($this->table, function(Blueprint $table)
		{
			$table->dropColumn('abbreviation_new');
			$table->unique('abbreviation', 'abbreviation');
		});
	}

	/**
	 * Reverse the migrations.
	 *
	 * @return void
	 */
	public function down()
	{
		
	}
}
