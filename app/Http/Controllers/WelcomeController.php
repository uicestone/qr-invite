<?php namespace App\Http\Controllers;

use App\Post, App\Tag, App\Config, App\ActionLog;
use Response, Cache, Input, File;

class WelcomeController extends Controller
{
	/**
	 * 当前版本号
	 */
	public function version()
	{
		$composer = json_decode(File::get('../composer.json'));
		return $composer->version;
	}
	
	public function redirect($slug)
	{
		$target = Config::get($slug);
		ActionLog::create(['url'=>$target, 'slug'=>$slug], '动态链接跳转');
		return redirect($target);
	}
}
