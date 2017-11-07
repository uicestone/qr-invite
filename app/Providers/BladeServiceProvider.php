<?php namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Blade;

class BladeServiceProvider extends ServiceProvider {

	public function boot() {
		
		Blade::directive('continue', function()
		{
			return '<?php continue; ?>';
		});
		
		Blade::directive('break', function()
		{
			return '<?php break; ?>';
		});
		
	}

	public function register() {
		//
	}

}
