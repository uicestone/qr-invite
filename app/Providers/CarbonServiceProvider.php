<?php namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Carbon;

class CarbonServiceProvider extends ServiceProvider {

	public function boot() {
		Carbon\Carbon::setLocale('zh');
	}

	public function register() {
		//
	}

}
