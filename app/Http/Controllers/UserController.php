<?php namespace App\Http\Controllers;

use App\Http\Request;
use App\ActionLog, App\Config, App\Sms, App\User;
use Log, Hash, URL;

class UserController extends Controller
{
	/**
	 * Display a listing of the resource.
	 * 仅对用户管理员提供
	 *
	 * @param Request $request
	 * @return \Illuminate\Http\Response
	 */
	public function index(Request $request)
	{
		$query = User::select('users.*');

		if($request->query('following_user_id'))
		{
			$query->following($request->query('following_user_id'));
		}
		
		if($request->query('followed_user_id'))
		{
			$query->followed($request->query('followed_user_id'));
		}
		
		if($request->query('liked_post_id'))
		{
			$query->like($request->query('liked_post_id'));
		}
		
		if($request->query('favored_post_id'))
		{
			$query->favorite($request->query('favored_post_id'));
		}
		
		if($request->query('shared_post_id'))
		{
			$query->share($request->query('shared_post_id'));
		}
		
		if($request->query('attending_event_id'))
		{
			$query->attend($request->query('attending_event_id'));
		}

		if($request->query('keyword', null, false))
		{
			foreach(explode(' ', $request->query('keyword', null, false)) as $syntax)
			{
				$query->matchSyntax($syntax);
			}
		}

		if($request->query('profile_key'))
		{
			$query->profile($request->query('profile_key'), $request->query('profile_value'));
		}
		
		if($request->query('address'))
		{
			$query->where('address', 'like', $request->query('address') . '%');
		}
		
		// TODO 各控制器的排序、分页逻辑应当统一抽象
		// 分页（支持page+per_page，offset+limit两种方式）
		$pagination = [
			'offset' => $request->query('offset') ?: 0,
			'limit' =>min($request->query('per_page') ?: ($request->query('limit') ?: 20), 1E3)
		];

		if($request->query('page'))
		{
			$request->query('per_page') && $pagination['limit'] = $request->query('per_page');
			$pagination['offset'] = ($request->query('page') - 1) * $pagination['limit'];
		}

		// 根据分页，确定列表位置
		$list_total = $list_start = $list_end = 0;

		if($pagination['limit'])
		{
			// 分页前先计算总数
			$list_total = $query->getQuery()->groups === null ? $query->count() : $query->get()->count();

			$query->take($pagination['limit']);

			if($pagination['offset'])
			{
				$query->skip($pagination['offset']);
			}
		}

		$query->with('profiles');
		
		// 排序字段
		$order_by = $request->query('order_by') ? $request->query('order_by') : 'is_fake';
		
		// 排序顺序
		if($request->query('order'))
		{
			$order = $request->query('order');
		}
		elseif(in_array($order_by, ['points', 'created_at', 'updated_at', 'is_specialist', 'is_fake']))
		{
			$order = 'desc';
		}
		
		if($order_by)
		{
			$query->orderBy($order_by, isset($order) ? $order : 'asc');
		}
		
		$users = $query->get()->map(function($user)
		{
			$user->append('subscribed', 'badges', 'level', 'followed', 'host_event', 'followed');
			return $user;
		});

		if($pagination['limit'])
		{
			$list_start = $pagination['offset'] + 1;
			$list_end = $pagination['offset'] + $pagination['limit'];

			if($list_end > $list_total)
			{
				$list_end = $list_total;
			}
		}
		else
		{
			$list_total = $users->count();
			$list_start = 1; $list_end = $list_total;
		}

		ActionLog::create(['tag'=>isset($query_tag) ? $query_tag : null], '查看用户列表');

		$links = [];

		$query = $request->query();

		if($list_start > 1)
		{
			$links['prev'] = URL::current() . '?' . http_build_query(array_merge($query, ['offset' => $pagination['offset'] - $pagination['limit']]));
			$links['first'] = URL::current() . '?' . http_build_query(array_diff_key($query, ['offset' => null, 'page' => null]));
		}

		if($list_end < $list_total)
		{
			$links['next'] = URL::current() . '?' . http_build_query(array_merge($query, ['offset' =>  $pagination['offset'] + $pagination['limit']]));;
			$links['last'] = URL::current() . '?' . http_build_query(array_merge($query, ['offset' =>  $list_total - $list_total % $pagination['limit']]));;
		}

		$link_header = implode(', ', array_map(function($link, $rel)
		{
			return '<' . $link . '>; rel="' . $rel . '"';
		},
				$links, array_keys($links)));

		$response = response($users)
				->header('Items-Total', $list_total)
				->header('Items-Start', $list_start)
				->header('Items-End', $list_end);

		if($link_header)
		{
			$response->header('Link', $link_header);
		}

		return $response;
	}

	/**
	 * Store a newly created resource in storage.
	 *
	 * @param Request $request
	 * @return \Illuminate\Http\Response
	 */
	public function store(Request $request)
	{
		if(!$request->data('mobile') && !app()->from_admin)
		{
			abort(400, '手机号不能为空');
		}
		
		if($request->data('mobile'))
		{
			$mobile = $request->data('mobile');
			$user = User::where('mobile', $mobile)->first();
		}

		if(empty($user))
		{
			Log::info('Creating new user.');
			$user = new User();
			$result = $this->update($request, $user);
		}
		else
		{
			Log::info('Reseting password of user: ' . $user->id);
			$result = $this->resetPassword($user);
		}

		// 若result是code + message则直接返回
		if(is_array($result) && isset($result['code']))
		{
			return $result;
		}

		$token = Hash::make($user->name . $user->password . microtime(true));
		$user->token = $token;
		$user->save();

		$user->addVisible('token');

		return $this->show($user)->header('Token', $user->token);
	}

	/**
	 * Display the specified resource.
	 * 
	 * @param  User $user
	 * @return \Illuminate\Http\Response
	 */
	public function show(User $user)
	{
		$user->load('profiles');
		$user->append('followed', 'level', 'host_event', 'badges', 'points_rank', 'points_rank_position');
		$user->addVisible('profiles', 'points_rank', 'points_rank_position');

		if($user->host_event)
		{
			$user->append('professional_field', 'biography');
			$user->addVisible('professional_field', 'biography');
		}

		if($user->isWritable())
		{
			$user->append('subscribed_tags', 'permissions', 'membership', 'membership_label');
			$user->addVisible('realname', 'mobile', 'email', 'subscribed_tags', 'permissions', 'membership', 'membership_label');
			if(!app()->user->can('edit_user'))
			{
				$user->setRelation('profiles', $user->profiles->filter(function($profile)
				{
					return $profile->visibility !== 'system';
				})
				->values());
			}
		}
		else
		{
			$user->setRelation('profiles', $user->profiles->filter(function($profile)
			{
				return in_array($profile->visibility,  ['public', 'protected']);
			})
			->values());
		}

		return response($user);
	}
	
	/**
	 * Update the specified resource in storage.
	 *
	 * @param Request $request
	 * @param  User $user
	 * @return \Illuminate\Http\Response
	 */
	public function update(Request $request, User $user)
	{
		if($user->exists && !app()->user->exists)
		{
			abort(401, '用户没有登录');
		}
		
		// 如果只包含特定数据, 那么理解为patch请求
		if(!array_diff(array_keys($request->data()), ['followed']))
		{
			return $this->patch($request, $user);
		}

		// 用户可以更新自己的信息，管理员可以更新所有用户信息
		if(!$user->isWritable())
		{
			abort(403, '无权编辑此用户');
		}
		
		$input = $request->data();
		
		
		$user->fill($input);
		
		if($request->hasFile('avatar') && $request->data('avatar')->isValid())
		{
			$user->uploadFile($request->data('avatar'));
		}
		
		if($request->data('password') && $user->isWritable())
		{
			$user->password = Hash::make($request->data('password'));
		}
		
		$user->save();

		if($request->data('profiles'))
		{
			$user->updateProfiles($request->data('profiles'), 'private');
		}

		foreach(['children', 'subscribed_tags'] as $key)
		{
			if(!is_null($request->data($key)))
			{
				$user->setProfile($key, $request->data($key), 'private');
			}
		}

		if(!app()->user->exists && $user->wasRecentlyCreated)
		{
			app()->user = $user;
		}

		$action = $user->wasRecentlyCreated ? '创建用户' : '更新用户';
		
		$response = $this->show($user);

		ActionLog::create(['user'=>$user], $action);

		return $response;
	}
	
	/**
	 * Update minor relational attributes of a resource
	 *
	 * @param User $user
	 * @param Request $request
	 * @return \Illuminate\Http\Response
	 */
	public function patch(Request $request, User $user)
	{
		$user->timestamps = false;
		$action = '';
		$message = [];
		
		if(!is_null($request->data('followed')))
		{
			// 关注
			if($request->data('followed') && !$user->followedUsers->contains(app()->user->id))
			{
				$user->promote('followed', ['user'=>$user]);
				$user->increment('followed_users_count');
				$user->followedUsers()->attach(app()->user);
				app()->user->setProfile('following_users', array_values(array_unique(array_merge(app()->user->getProfile('following_users') ?: [], [$user->id]))));
				app()->user->load('profiles');
				$message = app()->user->promote('follow', ['user'=>$user]);
				app()->user->increment('following_users_count');
				$user->sendMessage('new_follower', app_url('user/' . $user->id . '/following/'), ['first'=>'又有新粉丝关注您啦～', 'keyword1'=>'用户' . app()->user->name . '关注了您，目前您在社区共有' . $user->followed_users_count . '位粉丝', 'keyword2'=>date('Y-m-d H:i:s'), 'remark'=>'继续在社区参与互动，将有更多粉丝关注您，我们将对明星用户送出福利哦'], 600);
				$action = '关注用户';
			}
			// 取消关注
			elseif(!$request->data('followed') && $user->followedUsers->contains(app()->user->id))
			{
				$user->promote('followed', ['user'=>$user], true);
				$user->decrement('followed_users_count');
				$user->followedUsers()->detach(app()->user);
				app()->user->setProfile('following_users', array_values(array_diff(app()->user->getProfile('following_users') ?: [], [$user->id])));
				app()->user->load('profiles');
				$message = app()->user->promote('follow', ['user'=>$user], true);
				app()->user->decrement('following_users_count');
				$action = '取消关注用户';
			}
		}
		
		$user->timestamps = true;
		
		ActionLog::create(['user'=>$user], $action);
		
		$response =  array_merge(['code'=>200], $message);
		
		return $response;
	}

	/**
	 * Remove the specified resource from storage.
	 *
	 * @param  User $user
	 */
	public function destroy(User $user)
	{
		if(!app()->user->can('delete_user'))
		{
			abort(403, '不能删除用户');
		}
		
		$user->delete();
	}
	
	/**
	 * Authenticate a user and generate a token
	 *
	 * @param Request $request
	 * @return \Illuminate\Http\Response
	 */
	public function authenticate(Request $request)
	{
		if(!$request->data('username'))
		{
			abort(400, '请输入用户名');
		}
		
		if(!$request->data('password'))
		{
			abort(400, '请输入密码');
		}
		
		$users = User::where(function($query) use($request)
		{
			$query->where('name', $request->data('username'))->orWhere('mobile', $request->data('username'));
		})
		->get();

		if(!$users->count())
		{
			abort(401, '用户名或联系方式不存在');
		}

		foreach($users as $user_to_check)
		{
			if(Hash::check($request->data('password'), $user_to_check->password) || (string)$request->data('password') === $user_to_check->password)
			{
				$user = $user_to_check;
				break;
			}
		}

		if(!isset($user))
		{
			return abort(403, '密码错误');
		}

		if(!$user->token)
		{
			$token = Hash::make($user->name . $user->password . microtime(true));
			$user->token = $token;
			$user->save();
		}

		$user->addVisible('token');

		app()->user = $user;

		$response = $this->show($user);

		return $response->header('Token', $user->token);
	}
	
	public function getAuthenticatedUser()
	{
		if(!app()->user->exists)
		{
			abort(401, '用户没有登录');
		}

		$user = app()->user;
		return $this->show($user);
	}

	/**
	 * @param Request $request
	 * @return \Illuminate\Http\Response
	 */
	public function updateAuthenticatedUser(Request $request)
	{
		if(!app()->user->exists)
		{
			abort(401, '用户没有登录');
		}

		$user = app()->user;
		return $this->update($request, $user);
	}
	
	/**
	 * 重置密码
	 *
	 * @param Request $request
	 * @param User $user
	 * @return \Illuminate\Http\Response
	 */
	public function resetPassword(Request $request, User $user = null)
	{
		//输入的电话号码
		$mobile = $request->data('mobile');

		//根据电话查找用户
		if(is_null($user))
		{
			$user = User::where('mobile', $mobile)->first();
		}

		if(!app()->user->exists)
		{
			app()->user = $user;
		}

		//没有查找相关数据，则提示错误信息
		if(!$user)
		{
			abort(403, '用户名不存在');
		}

		// TODO 验证手机号的逻辑应到抽象到User模型
		$code = $request->query('code');

		if(!$code)
		{
			return $this->verifyMobile($request, $mobile);
		}
		else
		{
			if( ($code !== Config::get('mobile_code_' . $mobile) && !(env('APP_ENV') !== 'production' && $code === 'TEST'))
				&& !((string)$mobile === Sms::IOS_TEST_MOBILE && (string)$code === Sms::IOS_TEST_CODE)
			)
			{
				abort(403, '短信验证码错误或已过期');
			}

			if($request->data('password'))
			{
				//保存重置的用户信息
				$user->password = Hash::make($request->get('password'));
			}

			Config::remove('mobile_code_' . $mobile);

			return $this->update($request, $user);
		}
	}

	/**
	 * @todo 需要增加安全性，对手机号和IP作出限制
	 * @todo 验证手机号的逻辑应到抽象到User模型
	 * @param Request $request
	 * @param string $mobile
	 * @return \Illuminate\Http\Response
	 */
	public function verifyMobile(Request $request, $mobile)
	{
		// 查找已经生成的有效验证码
		$code = Config::get('mobile_code_' . $mobile);

		// 用户提供了验证码, 验证之
		$user_provided_code = $request->data('code');

		if($user_provided_code)
		{
			if( $user_provided_code === $code
				|| (( env('APP_ENV') !== 'production' && $user_provided_code === 'TEST')
					|| ( $mobile === Sms::IOS_TEST_MOBILE && (string)$user_provided_code === Sms::IOS_TEST_CODE)
				)
			)
			{
				return response(['code'=>200, 'message'=>'短信验证码正确']);
			}
			else
			{
				return abort(403, '短信验证码错误或已过期');
			}
		}
		// 否则获得一个验证码
		else
		{
			// 生成新的验证码
			if(!$code)
			{
				$code = floor(rand(1E5, 1E6-1));
				Config::set('mobile_code_' . $mobile, $code, 600);
			}
			
			Sms::send($mobile, Sms::getCodeMessage(app()->name, $code));

			return response(['code'=>200, 'message'=>'短信验证码已发送']);
		}
	}

	/**
	 * @param Request $request
	 * @return \Illuminate\Http\Response
	 */
	public function ipLocation(Request $request)
	{
		$result = json_decode(file_get_contents('http://api.map.baidu.com/location/ip?ak=' . env('BAIDUMAP_APP_KEY') . '&ip=' . $request->ip()));
		
		if($result->status)
		{
			Log::error('Fail to get IP location. ' . json_encode($result));
			abort(503, 'IP定位服务不可用');
		}
		
		return response(collect($result->content->address_detail));
	}
}
