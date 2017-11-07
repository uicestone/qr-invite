<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddEmailToUsersTable extends Migration
{
    protected $table = 'users';
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        //
        Schema::table($this->table,function(Blueprint $table)
        {
            if(!Schema::hasColumn($this->table, 'email')) {
                $table->string('email', 191)->after('mobile')->nullable()->index('email');
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

        Schema::table($this->table,function(Blueprint $table)
        {
            if(Schema::hasColumn($this->table, 'email')) {
                $table->dropColumn('email');
            }
        });
    }
}
