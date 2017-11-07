<?php namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Config, App\Post, App\User, App\Weixin;
use Request, URL, Log, Route;

class AppServiceProvider extends ServiceProvider {

	/**
	 * Bootstrap any application services.
	 *
	 * @return void
	 */
	public function boot()
	{
		Config::autoLoad();
		$attributes_config = Config::get('post_attributes');
		Post::$sortable = $attributes_config->sortable;
		Post::$types = Config::get('post_types');
	}

	/**
	 * Register any application services.
	 *
	 * @return void
	 * @todo 除非需要全局使用 (Model, Console), 应当避免使用app()容器, 仅与请求相关的应当使用middleware处理, 并仅在控制器中使用
	 */
	public function register()
	{
		// app()->name
		$this->app->singleton('name', function()
		{
			// 普通请求, 从cip-request-from中获得
			if($request_from = Request::header('cip-request-from'))
			{
				$request_from = explode('-', $request_from);
				return $request_from[0];
			}
			
			// 来自微信的请求
			if(app()->from_mp_account)
			{
				$weixin = new Weixin();
				return $weixin->belongs_to_app;
			}
		});

		// app()->from_mp_account
		$this->app->singleton('from_mp_account', function()
		{
			if(Route::currentRouteName() === 'weixin_serve')
			{
				return Route::current()->getParameter('account');
			}
			
			if(Route::currentRouteName() === 'weixin_payment_confirm')
			{
				return Route::current()->getParameter('account');
			}
			
			if(Request::header('cip-from-mp-account'))
			{
				return Request::header('cip-from-mp-account');
			}
			
			$mp_account_of_hostname = collect(Config::get('wx_mp_accounts'))->where('hostname', parse_url(URL::previous(), PHP_URL_HOST))->first();
			
			if($mp_account_of_hostname)
			{
				return $mp_account_of_hostname->name;
			}
		});
		
		// app()->from_admin
		$this->app->singleton('from_admin', function()
		{
			return app()->name === 'admin';
		});
		
		// app()->user_agent
		$this->app->singleton('user_agent', function()
		{
			if(str_contains(Request::server('HTTP_USER_AGENT'), ' iOS '))
			{
				return 'iOS app';
			}
			elseif(str_contains(Request::server('HTTP_USER_AGENT'), ' Android '))
			{
				return 'Android app';
			}
			else
			{
				return 'browser';
			}
		});

		// app()->is_secure
		$this->app->singleton('is_secure', function()
		{
			return preg_match('/^https:\/\//', URL::previous());
		});

		// app()->user
		// TODO 全部移入$request->user, 使用middleware
		$this->app->singleton('user', function()
		{
			$user = new User();

			if(Request::header('authorization'))
			{
				$token = str_replace('"', '', Request::header('authorization'));

				if(env('APP_ENV') !== 'production' && preg_match('/TEST-(.*)/', $token, $matches))
				{
					$user = User::find($matches[1]);
				}
				else
				{
					$user = User::where('token', $token)->first();
				}

				if(!$user)
				{
					Log::error('Invalid authorization token: ' . $token);
					abort(401, '您的登陆已过期或已在别处登陆，请重新登录');
				}

				if(Request::ip() !== $user->last_ip)
				{
					$user->last_ip = Request::ip();
					$user->save();
				}
				
				$request_from = Request::header('cip-request-from');
				
				if($request_from && $request_from !== $user->getProfile('request_from'))
				{
					$user->setProfile('request_from', $request_from);
				}
			}

			return $user;
		});
	}
}
