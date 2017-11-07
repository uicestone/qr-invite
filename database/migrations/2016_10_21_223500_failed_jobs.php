<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class FailedJobs extends Migration
{
	
	protected $table = 'posts';
	
	/**
	 * Run the migrations.
	 *
	 * @return void
	 */
	public function up()
	{
		Schema::table('failed_jobs', function (Blueprint $table) {
			$table->longText('exception')->after('payload');
		});
	}
	
	public function down()
	{
		Schema::table('failed_jobs', function (Blueprint $table) {
			$table->dropColumn('exception');
		});
	}
}
