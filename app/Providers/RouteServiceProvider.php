<?php

namespace App\Providers;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Illuminate\Support\Facades\Route;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use App\Post, App\Tag;

class RouteServiceProvider extends ServiceProvider
{
	/**
	 * This namespace is applied to your controller routes.
	 *
	 * In addition, it is set as the URL generator's root namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'App\Http\Controllers';

	/**
	 * Define your route model bindings, pattern filters, etc.
	 *
	 * @return void
	 */
	public function boot()
	{
		parent::boot();
		
		// 路由模型配置，使得控制器接受的参数直接是模型对象而非数字ID
		Route::model('post', Post::class, function($id)
		{
			if(is_numeric($id))
			{
				// 对"不存在"的模型拦截处理，因为有可能是软删除的
				// 软删除的模型权限由控制器控制
				$post = Post::withTrashed()->find($id);
			}
			else
			{
				$post = Post::where('abbreviation', $id)->first();
			}
			
			if(!$post)
			{
				throw new NotFoundHttpException('Post not found for id ' . $id);
			}
			
			// 将模型交给控制器处理
			return $post;
		});
		
		Route::model('tag', Tag::class, function($id_or_name)
		{
			if(is_numeric($id_or_name))
			{
				$tag = Tag::find($id_or_name);
			}
			else
			{
				$tag = Tag::where('name', $id_or_name)->first();
			}
			
			if(!$tag)
			{
				throw new NotFoundHttpException;
			}
			
			// 将模型交给控制器处理
			return $tag;
		});
	}
	
	/**
	 * Define the routes for the application.
	 *
	 * @return void
	 */
	public function map()
	{
		$this->mapApiRoutes();
		
		$this->mapWebRoutes();
		
		//
	}
	
	/**
	 * Define the "web" routes for the application.
	 *
	 * These routes all receive session state, CSRF protection, etc.
	 *
	 * @return void
	 */
	protected function mapWebRoutes()
	{
		Route::group([
			'middleware' => 'web',
			'namespace' => $this->namespace,
		], function ($router) {
			require base_path('routes/web.php');
		});
	}
	
	/**
	 * Define the "api" routes for the application.
	 *
	 * These routes are typically stateless.
	 *
	 * @return void
	 */
	protected function mapApiRoutes()
	{
		Route::group([
			'middleware' => ['api', 'cors'],
			'namespace' => $this->namespace,
			'prefix' => 'api'
		], function ($router) {
			require base_path('routes/api.php');
		});
		
		Route::group([
			'middleware' => ['api', 'cors'],
			'namespace' => $this->namespace,
		], function ($router) {
			require base_path('routes/api.php');
		});
	}
}
