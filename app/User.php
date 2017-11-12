<?php namespace App;

use Illuminate\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Auth\Passwords\CanResetPassword;
use Illuminate\Foundation\Auth\Access\Authorizable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Contracts\Auth\Access\Authorizable as AuthorizableContract;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Support\Str;
use Illuminate\Http\File;
use Overtrue\Pinyin\Pinyin;
use itbdw\QiniuStorage\QiniuStorage;
use App\Jobs\SendMessage, App\Jobs\SendSms;
use Log, Cache, DB, RuntimeException;

class User extends Model implements AuthenticatableContract, AuthorizableContract, CanResetPasswordContract {

	use Authenticatable, Authorizable, CanResetPassword, DispatchesJobs;

	/**
	 * The database table used by the model.
	 *
	 * @var string
	 */
	protected $table = 'users';

	/**
	 * The attributes that are mass assignable.
	 *
	 * @var array
	 */
	protected $fillable = ['name', 'realname', 'gender', 'mobile', 'email', 'address', 'avatar', 'roles', 'is_specialist', 'is_fake', 'points', 'source', 'following_users_count', 'followed_users_count', 'last_ip', 'wx_unionid'];
	protected $casts = ['id'=>'string', 'roles'=>'array', 'is_specialist'=>'int', 'is_fake'=>'boolean', 'points'=>'int', 'following_users_count'=>'int', 'followed_users_count'=>'int', 'mobile'=>'string'];

	/**
	 * The attributes excluded from the model's JSON form.
	 *
	 * @var array
	 */
	protected $visible = [
					'id', 'name', 'gender', 'address', 'avatar', 'badges', 'host_event', 'roles', 'is_specialist',
					'is_fake', 'points', 'level', 'followed', 'following_users_count', 'followed_users_count',
					'created_at', 'created_at_human'
				];
	
	protected $dates = ['last_active_at', 'last_check_message_at'];
	
	// Model Relationships
	
	public function profiles()
	{
		return $this->hasMany(Profile::class);
	}
	
	public function posts()
	{
		return $this->hasMany(Post::class, 'author_id');
	}
	
	public function followingUsers()
	{
		return $this->belongsToMany(User::class, 'user_follow', 'follower_id', 'user_id')->withTimestamps();
	}
	
	public function followedUsers()
	{
		return $this->belongsToMany(User::class, 'user_follow', 'user_id', 'follower_id')->withTimestamps();
	}
	
	public function attendingEvents()
	{
		return $this->belongsToMany(Post::class, 'event_attend')->withPivot('info')->withTimestamps();
	}
	
	public function likedPosts()
	{
		return $this->belongsToMany(Post::class, 'post_like')->withTimestamps();
	}
	
	public function favoredPosts()
	{
		return $this->belongsToMany(Post::class, 'post_favorite')->withTimestamps();
	}
	
	public function sharedPosts()
	{
		return $this->belongsToMany(Post::class, 'post_share')->withTimestamps();
	}
	
	public function paidPosts()
	{
		return $this->belongsToMany(Post::class, 'post_pay')->withTimestamps();
	}
	
	// Query Scopes
	
	public function scopeMatchSyntax($query, $syntax)
	{
		if(preg_match('/^(.*?):(.*?)(@(.*?))?$/', $syntax, $matches))
		{
			$key = $matches[1];
			$value_syntax = $matches[2];
			$date_range_syntax = isset($matches[4]) ? $matches[4] : null;
			
			$query->select('users.*')->groupBy('users.id');
			
			if(in_array($key, ['attend', 'order', 'share', 'like', 'pay', 'post', 'following', 'followed_by']))
			{
				$query->$key($value_syntax, $date_range_syntax);
			}
			
			elseif(in_array($key, ['membership']))
			{
				$query->profile($key, $value_syntax, $date_range_syntax);
			}
		}
		else
		{
			$query->where(function($query) use($syntax)
			{
				$query->where('users.name', 'like', $syntax . '%')
					->orWhere('users.address', 'like', $syntax . '%');
			});
		}
	}
	
	public function scopeAttend($query, $posts_syntax, $date_range_syntax = null)
	{
		$query->join('event_attend', 'users.id', '=', 'event_attend.user_id');
		
		if($date_range_syntax)
		{
			$query->whereBetween('event_attend.created_at', $this->parseDateRangeSyntax($date_range_syntax));
		}
		
		if($posts_syntax)
		{
			$this->applyPostSyntaxToQuery($query, 'event_attend.post_id', $posts_syntax);
		}
	}
	
	public function scopeOrder($query, $posts_syntax, $date_range_syntax = null)
	{
		$query->join('orders', 'users.id', '=', 'orders.user_id')
			->whereIn('orders.status', ['paid', 'in_group']);
		
		if($date_range_syntax)
		{
			$query->whereBetween('orders.created_at', $this->parseDateRangeSyntax($date_range_syntax));
		}
		
		if($posts_syntax)
		{
			$query->join('order_post', 'order_post.order_id', '=', 'orders.id');
			$this->applyPostSyntaxToQuery($query, 'order_post.post_id', $posts_syntax);
		}
	}
	
	public function scopeShare($query, $posts_syntax, $date_range_syntax = null)
	{
		$query->join('post_share', 'users.id', '=', 'post_share.user_id');
		
		if($date_range_syntax)
		{
			$query->whereBetween('post_share.created_at', $this->parseDateRangeSyntax($date_range_syntax));
		}
		
		if($posts_syntax)
		{
			$this->applyPostSyntaxToQuery($query, 'post_share.post_id', $posts_syntax);
		}
	}
	
	public function scopeLike($query, $posts_syntax, $date_range_syntax = null)
	{
		$query->join('post_like', 'users.id', '=', 'post_like.user_id');
		
		if($date_range_syntax)
		{
			$query->whereBetween('post_like.created_at', $this->parseDateRangeSyntax($date_range_syntax));
		}
		
		if($posts_syntax)
		{
			$this->applyPostSyntaxToQuery($query, 'post_like.post_id', $posts_syntax);
		}
	}
	
	public function scopeFavorite($query, $posts_syntax, $date_range_syntax = null)
	{
		$query->join('post_favorite', 'users.id', '=', 'post_favorite.user_id');
		
		if($date_range_syntax)
		{
			$query->whereBetween('post_favorite.created_at', $this->parseDateRangeSyntax($date_range_syntax));
		}
		
		if($posts_syntax)
		{
			$this->applyPostSyntaxToQuery($query, 'post_like.post_id', $posts_syntax);
		}
	}
	
	public function scopePay($query, $posts_syntax, $date_range_syntax = null)
	{
		$query->join('post_pay', 'users.id', '=', 'post_pay.user_id');
		
		if($date_range_syntax)
		{
			$query->whereBetween('post_pay.created_at', $this->parseDateRangeSyntax($date_range_syntax));
		}
		
		if($posts_syntax)
		{
			$this->applyPostSyntaxToQuery($query, 'post_pay.post_id', $posts_syntax);
		}
	}
	
	public function scopePost($query, $posts_syntax, $date_range_syntax = null)
	{
		$query->join('posts', 'users.id', '=', 'posts.author_id');
		
		if($date_range_syntax)
		{
			$query->whereBetween('posts.created_at', $this->parseDateRangeSyntax($date_range_syntax));
		}
		
		if($posts_syntax)
		{
			$this->applyPostSyntaxToQuery($query, 'posts.id', $posts_syntax);
		}
	}
	
	public function scopeProfile($query, $key, $value, $date_range_syntax = null)
	{
		$query->join('profiles', 'profiles.user_id', '=', 'users.id')->where('profiles.key', $key);
		
		if($value)
		{
			$query->where('profiles.value', $value);
		}
		
		if($date_range_syntax)
		{
			$query->whereBetween('posts.created_at', $this->parseDateRangeSyntax($date_range_syntax));
		}
	}
	
	public function scopeFollowing($query, $user_id, $date_range_syntax = null)
	{
		$query->join('user_follow', 'user_follow.follower_id', '=', 'users.id')
			->where('user_follow.user_id', $user_id)
			->orderBy('user_follow.created_at', 'desc');
		
		if($date_range_syntax)
		{
			$query->whereBetween('user_follow.created_at', $this->parseDateRangeSyntax($date_range_syntax));
		}
	}
	
	public function scopeFollowed($query, $user_id, $date_range_syntax = null)
	{
		$query->join('user_follow', 'user_follow.user_id', '=', 'users.id')
			->where('user_follow.follower_id', $user_id)
			->orderBy('user_follow.created_at', 'desc');
		
		if($date_range_syntax)
		{
			$query->whereBetween('user_follow.created_at', $this->parseDateRangeSyntax($date_range_syntax));
		}
	}
	
	/**
	 * 将内容表达式解析为一个Query
	 */
	protected function applyPostSyntaxToQuery($query, $post_id_field, $posts_syntax)
	{
		$syntaxes = explode(',', $posts_syntax);
		
		$query->where(function($query) use($syntaxes, $post_id_field)
		{
			foreach($syntaxes as $syntax)
			{
				if(in_array($syntax, array_keys((array)Post::$types)))
				{
					$query->orWhereIn($post_id_field, function($query) use($syntax)
					{
						$query->select('id')->from('posts')->where('type', $syntax);
					});
				}
				elseif(ctype_digit($syntax))
				{
					$query->orWhere($post_id_field, $syntax);
				}
				else
				{
					$match_posts = Post::where('title', 'like', $syntax . '%')->get();
					if($match_posts->count() === 1)
					{
						$query->orWhere($post_id_field, $match_posts->id);
					}
				}
			}
		});
	}
	
	/**
	 * 将日期范围表达式解析为一个2项数组
	 * ..分隔
	 * @param $date_range_syntax
	 * @return array
	 */
	protected function parseDateRangeSyntax($date_range_syntax)
	{
		$range = explode('~', $date_range_syntax);
		
		if(count($range) === 1)
		{
			$range[1] = date('Y-m-d', strtotime($range[0] . '+1 day'));
		}
		
		return $range;
	}
	
	// Mutators
	
	protected function mutateAttribute($key, $value)
	{
		if(in_array($key, []) && $cached = Cache::tags('user_' . $this->id)->get('user_attribute_' . $this->id . '_' . $key))
		{
			return $cached;
		}
		
		$result = $this->{'get'.Str::studly($key).'Attribute'}($value);
		
		if(in_array($key, []))
		{
//			Log::debug('Cache set ' . $key . ' for user ' . $this->id);
			Cache::tags('user_' . $this->id)->put('user_attribute_' . $this->id . '_' . $key, $result, rand(720, 1440));
		}
		
		return $result;
	}
	
	public function getAvatarAttribute($url)
	{
		if(!$url)
		{
			if(app()->from_admin)
			{
				return;
			}
			
			
			switch($this->gender)
			{
				case '男':
					$url = 'images/avatar-default-1-' . (crc32($this->wx_unionid) % 4) . '.jpg';
					break;
				default:
					$url = 'images/avatar-default-2-' . (crc32($this->wx_unionid) % 4) . '.jpg';
			}
		}
		
		if(preg_match('/^http:\/\/|^https:\/\//', $url))
		{
			if(app()->is_secure)
			{
				$url = str_replace('http://wx.qlogo.cn/', 'https://wx.qlogo.cn/', $url);
			}
			
			return $url;
		}
		
		if($url)
		{
			return (app()->is_secure ? env('CDN_PREFIX_SSL') : env('CDN_PREFIX')) . $url . '?imageView2/1/w/200/h/200';
		}

		return $url;
	}

	public function setAvatarAttribute($url)
	{
		// 将CDN地址移除
		$url = str_replace(env('CDN_PREFIX'), '', $url);
		$url = str_replace(env('CDN_PREFIX_SSL'), '', $url);

		$url = preg_replace('/\?imageView2.*$/', '', $url);
		
		$this->attributes['avatar'] = $url;
	}
	
	public function getRolesAttribute($roles)
	{
		$roles_decoded = json_decode($roles);
		
		if(is_null($roles_decoded))
		{
			//$roles = [];
			return [];
		}
		
		return $roles_decoded;
	}

	public function getPermissionsAttribute()
	{
		$roles_permissions = Config::get('user_roles_permissions') ?: (object)[];
		$permissions = [];
		foreach($this->roles as $role)
		{
			if(!isset($roles_permissions->$role))
			{
				continue;
			}

			$permissions = array_merge($permissions, $roles_permissions->$role);
		}

		return $permissions;
	}
	
	public function getNameAttribute($name)
	{
		if($this->is_specialist > 0 && $this->realname && !app()->from_admin)
		{
			return $this->realname;
		}

		if(!$name && !app()->from_admin)
		{
			if(app()->user->id === $this->id)
			{
				return '用户' . $this->id;
			}
			else
			{
				$name = '网友';
			}
		}

		return $name;
	}
	
	public function getTempNameAttribute()
	{
		return '用户' . $this->id;
	}


	public function getGenderAttribute($gender)
	{
		switch($gender)
		{
			case 1:
				return '男';
			case 2:
				return '女';
			default:
				return '未知';
		}
	}
	
	public function getProvinceAttribute()
	{
		$profile = $this->profiles->where('key', '=', '省/市')->first();
		return $profile ? $profile->value : null;
	}
	
	public function getCityAttribute()
	{
		$profile = $this->profiles->where('key', '=', '市/区')->first();
		return $profile ? $profile->value : null;
	}
	
	/**
	 * @deprecated
	 */
	public function getHostEventAttribute()
	{
		return;
	}

	// 获得该用户关注的公众账号
	public function getSubscribeMpAccountsAttribute()
	{
		return collect($this->profiles->filter(function($profile)
		{
			return strpos($profile->key, 'wx_subscribed_') === 0 && $profile->value;
		})
		->map(function($profile)
		{
			return str_replace('wx_subscribed_', '', $profile->key);
		})
		->values());
	}

	public function getEntryMpAccountAttribute()
	{
		if($this->subscribe_mp_accounts->contains(app()->from_mp_account))
		{
			return app()->from_mp_account;
		}
		else
		{
			return $this->subscribe_mp_accounts->first();
		}
	}
	
	/**
	 * @deprecated
	 */
	public function getBadgesAttribute()
	{
		return;
	}

	public function getSubscribedTagsAttribute()
	{
		$profile = $this->profiles->where('key', 'subscribed_tags')->first();

		if(!$profile || !is_array($profile->value))
		{
			return [];
		}

		return $profile->value;
	}

	public function getProfessionalFieldAttribute()
	{
		$profile = $this->profiles->where('key', '专业领域')->first();

		if($profile)
		{
			return $profile->value;
		}
	}

	public function getBiographyAttribute()
	{
		$profile = $this->profiles->filter(function($profile)
		{
			return str_contains($profile->key, '介绍');
		})
		->first();

		if($profile)
		{
			return $profile->value;
		}
	}

	public function getFollowedAttribute()
	{
		if(!app()->user)
		{
			return false;
		}
		
		$following_users = app()->user->getProfile('following_users');
		
		if(is_array($following_users) && in_array($this->id, $following_users))
		{
			return true;
		}
		
		return false;
	}
	
	public function getHomeUrlAttribute()
	{
		if(!$this->id)
		{
			return;
		}
		
		return url('user') . '/' . $this->id;
		
	}
	
	/**
	 * @deprecated
	 * @return int
	 */
	public function updatePoints()
	{
		$points = 0;
		$points += $this->subscribed() ? 50 : 0; // 关注公众号
		$points += $this->posts->where('type', 'discussion')->count() * 20; // 提问
		$points += $this->posts->where('type', 'comment')->count() * 20; // 评论
		$points += $this->likedPosts->count() * 5; // 点赞
		$points += $this->sharedPosts->count() * 5; // 分享

		// 被分享
		$points += 5 * $this->posts->reduce(function($carry, $item)
		{
			$carry += $item->sharedUsers->count();
			return $carry;
		},
		0);

		// 被点赞
		$points += 5 * $this->posts->reduce(function($carry, $item)
		{
			$carry += $item->likedUsers->count();
			return $carry;
		},
		0);

		$points += 5 * $this->followingUsers->count(); // 关注用户
		$points += 5 * $this->followedUsers->count(); // 被用户关注

		$this->points = $points;
		$this->save();

		return $points;
	}
	
	public function getIsAdminAttribute()
	{
		return in_array('管理员', $this->roles);
	}
	
	public function getSubscribedAttribute()
	{
		return $this->subscribed();
	}
	
	/**
	 * @deprecated
	 */
	public function getLevelAttribute()
	{
		return 0;
	}
	
	public function getPointsRankPositionAttribute()
	{
		$points_rank = User::where('points', '>', (int)$this->points)->count();
		return $points_rank;
	}
	
	public function getPointsRankAttribute()
	{
		$points_rank = $this->points_rank_position;

		$users_count = Cache::get('users_count_with_positive_points');

		if(!$users_count)
		{
			$users_count = User::where('points', '>', 0)->count();

			if (!$users_count) {
				return 0;
			}

			Cache::put('users_count_with_positive_points', $users_count, 1440);
		}

		return ($users_count - $points_rank) / $users_count;
	}

	public function setRolesAttribute($roles)
	{
		if(is_string($roles))
		{
			$roles = str_replace('，', ', ', $roles);
			$roles = str_replace('；', ', ', $roles);
			$roles = preg_split('/[;|,]\s*/', $roles);
		}
		
		
		$reserved = array_keys((array)Config::get('user_roles_permissions'));
		$roles = array_filter($roles, function($role) use($reserved)
		{
			return app()->user->is_admin || !in_array($role, $reserved);
		});
		
		if(!app()->from_admin)
		{
			$previous_reserved_roles = array_intersect($this->roles, $reserved);
			$roles = array_merge($roles, $previous_reserved_roles);
		}

		$roles = json_encode($roles, JSON_UNESCAPED_UNICODE);
		
		$this->attributes['roles'] = $roles;
	}
	
	public function setGenderAttribute($gender)
	{
		switch($gender)
		{
			case '男':
			case 1:
				$this->attributes['gender'] = 1;
				break;
			case '女':
			case 2:
				$this->attributes['gender'] = 2;
				break;
			default:
				$this->attributes['gender'] = null;
		}
	}
	
	public function getMembershipAttribute()
	{
		$membership = $this->getProfile('membership');
		return $membership ?: 0;
	}
	
	public function getMembershipLabelAttribute()
	{
		$memberships = collect(Config::get('memberships'));
		
		$membership = $memberships->where('level', $this->membership)->first();
		
		if(!$membership || !isset($membership->label))
		{
			Log::warning('未定义会员等级' . $this->membership);
			return null;
		}
		
		return $membership->label;
	}
	
	// Additional Methods
	
	public function getProfile($key)
	{
		$item = $this->profiles->where('key', $key)->first();
		
		if($item)
		{
			return $item->value;
		}
	}
	
	public function setProfile($key, $value, $visibility = null)
	{
		if(is_null($value))
		{
			$profile = Profile::where('user_id', $this->id)->where('key', $key)->first();
			$profile->delete();
		}
		
		$data = ['user_id'=>$this->id, 'key'=>$key, 'value'=>$value];
		
		if($visibility)
		{
			$data['visibility'] = $visibility;
		}
		
		try
		{
			$this->profiles()->updateOrCreate(['user_id'=>$this->id, 'key'=>$key], $data);
			$this->load('profiles');
			return $value;
		}
		catch(RuntimeException $e)
		{
			Log::warning('Caught RuntimeException: ' . $e->getMessage());
		}
	}
	
	public function updateProfiles($profiles, $visibility = null)
	{
		foreach($profiles as $index => $profile)
		{
			if(is_integer($index))
			{
				$key = $profile['key'];
				$value = $profile['value'];
				$visibility = isset($profile['visibility']) ? $profile['visibility'] : $visibility;
			}
			else
			{
				$key = $index;
				$value = $profile;
			}
			
			$this->setProfile($key, $value, $visibility);
		}
	}
	
	public function subscribed($mp_account = null)
	{
		if(is_null($mp_account))
		{
			$mp_account = app()->from_mp_account;
		}

		return (bool)$this->getProfile('wx_subscribed_' . $mp_account);
	}

	public function isWritable()
	{
		return !$this->exists || ($this->id === app()->user->id || app()->user->can('edit_user'));
	}

	public function can($ability, $arguments = [])
	{
		foreach($this->permissions as $permission)
		{
			if(preg_match('/' . $permission . '/', $ability))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * 根据操作改变用户积分, 并返回积分变动和下一等级提示
	 * @param string $action
	 * @param array $data
	 * @param bool $reverse
	 * @return array message,remark
	 */
	public function promote($action = 'post', $data = [], $reverse = false)
	{
		if(!$this->exists)
		{
			return;
		}

		$data = (object)$data;

		$action_points = Config::get('user_action_points');

		if($action === 'post' && isset($data->post))
		{
			$action = $data->post->type;
		}

		$points = 0;

		if(isset($action_points->$action))
		{
			$points = $action_points->$action;
		}
		else
		{
			Log::warning('Action: ' . $action . ' is not configured in user_actions.');
		}

		$level_before = $this->level;

		if($reverse)
		{
			if($this->points >= $points)
			{
				$this->decrement('points', $points);
			}
			else
			{
				Log::warning('Not enough points for user ' . $this->id . ' ' . $this->name . ', action: ' . $action . ', points / points to decrease: ' . $this->points . '/' . $points);
				$this->points = 0;
				$this->save();
			}
		}
		else
		{
			$this->increment('points', $points);
		}

		switch($action)
		{
			case 'comment':
				$message = '评论';
				break;
			case 'discussion':
				$message = '提问';
				break;
			case 'like':
				$message = '点赞';
				break;
			case 'favorite':
				$message = '收藏';
				break;
			case 'share':
				$message = '分享';
				break;
			case 'follow':
				$message = '关注';
				break;
			default:
				$message = '操作';
		}

		if($reverse)
		{
			$message = '取消' . $message;
		}

		$levels = Config::get('user_levels') ?: [];
		$action_points = Config::get('user_action_points');

		foreach($levels as $level)
		{
			if($level->level > $level_before)
			{
				$points_up = $level->points - $this->points;
				$level_up = $level;
				break;
			}
		}
		
		$message .= '成功';

//		if($this->level > $level_before)
//		{
//			$message .= '，<b>升到' . $this->level . '</b>级';
//			$this->sendMessage('level_up', url('my'), ['first'=>'恭喜您！成功升级啦～', 'keyword1'=>$this->name, 'keyword2'=>$this->level, 'keyword3'=>date('Y-m-d H:i:s'), 'remark'=>'欢迎继续在社区互动，我们将对活跃用户送出福利哦']);
//		}
//		elseif($points)
//		{
//			$message .= '，<b>' . ($reverse ? '-' : '+') . $points . '</b>积分';
//		}

		if(isset($points_up) && isset($level_up))
		{
			$remark = '再提问/评论' . ceil($points_up / $action_points->comment) . '次，或点赞/分享' . ceil($points_up / $action_points->like) . '次，即可升到' . $level_up->level . '级';
		}
		
		if(in_array($message, ['点赞成功', '取消点赞成功', '收藏成功', '取消收藏成功']))
		{
			$message = null;
		}

		return compact('message', 'remark');
	}

	/**
	 * 合并两个用户的信息
	 * 合并无wx_unionid的用户到有wx_unionid的用户
	 * 如果两个用户都有wx_unionid, b合并入a
	 * @param User $user_a
	 * @param User $user_b
	 */
	public static function merge(User $user_a, User $user_b)
	{
		if($user_a->id === $user_b->id)
		{
			return;
		}
		
		if($user_a->wx_unionid)
		{
			$user_base = $user_a; $user_source = $user_b;
		}
		else
		{
			$user_base = $user_b; $user_source = $user_a;
		}

		foreach(['name', 'realname', 'gender', 'mobile', 'address', 'avatar', 'roles', 'source'] as $key)
		{
			if(!$user_base->$key && $user_source->$key)
			{
				$user_base->$key = $user_source->$key;
			}
		}

		$user_base->points += $user_source->points;

		Post::withTrashed()->where('author_id', $user_source->id)->update(['author_id'=>$user_base->id]);
		Order::where('user_id', $user_source->id)->update(['user_id'=>$user_base->id]);
		
		// 转移profile, 遇到重复的key, 保留新的
		foreach($user_source->profiles as $profile)
		{
			$profile_base = $user_base->profiles->where('key', $profile->key)->first();

			try
			{
				if(!$profile_base)
				{
					$profile->user()->associate($user_base);
					$profile->save();
				}
				elseif(is_array($profile->value) && is_array($profile_base->value))
				{
					$profile->value = array_merge($profile->value, $profile_base->value);
				}
				elseif($profile->created_at > $profile_base->created_at)
				{
					$profile_base->delete();
					$profile->user()->associate($user_base);
					$profile->save();
				}
			}
			catch (RuntimeException $exception)
			{
				Log::error($exception->getMessage());
			}
		}

		// 转移logs, messages, post_favorite, post_like, post_share, event_attend, user_follow, 跳过重复
		$args = ['user_base_id'=>$user_base->id, 'user_source_id'=>$user_source->id];
		\DB::statement('UPDATE IGNORE `messages` SET `sender_id` = :user_base_id WHERE user_id = :user_source_id', $args);
		\DB::statement('UPDATE IGNORE `post_favorite` SET `user_id` = :user_base_id WHERE user_id = :user_source_id', $args);
		\DB::statement('UPDATE IGNORE `post_like` SET `user_id` = :user_base_id WHERE user_id = :user_source_id', $args);
		\DB::statement('UPDATE IGNORE `post_share` SET `user_id` = :user_base_id WHERE user_id = :user_source_id', $args);
		\DB::statement('UPDATE IGNORE `event_attend` SET `user_id` = :user_base_id WHERE user_id = :user_source_id', $args);
		\DB::statement('UPDATE IGNORE `user_follow` SET `user_id` = :user_base_id WHERE user_id = :user_source_id', $args);
		\DB::statement('UPDATE IGNORE `user_follow` SET `follower_id` = :user_base_id WHERE follower_id = :user_source_id', $args);

		// 删除$user_source
		$user_source->delete();

	}

	public function getHumanCodeAttribute()
	{
		$s = $this->name;

		$pinyins = explode(' ', Pinyin::letter($s));
		preg_match_all('/\w/', $s, $matches);

		$letters = $matches[0];
		$code = $pinyins[0] ? count($pinyins) : 0;
		$code .= count($letters);

		$code .= $pinyins[0] ? ord($pinyins[0]) - 96 : 0;
		if($matches[0])
		{
			$code .= ord(strtolower($letters[0])) - 96;
		}

		return $code;
	}

	public function sendMessage($slug, $url, $data, $cool_down_in = null)
	{
		if($this->getProfile('refuse_' . $slug . '_message_until') > time())
		{
			Log::warning('用户 ' . $this->id . ' ' . $this->name . ' 在' . date('Y-m-d H:i:s', $this->getProfile('refuse_' . $slug . '_message_until')) . '前不接收 ' . $slug . ' 消息');
			return;
		}
		
		if($cool_down_in)
		{
			$this->setProfile('refuse_' . $slug . '_message_until', time() + $cool_down_in);
		}
		
		if($slug === 'sms')
		{
			$job = new SendSms($this, $data);
		}
		else
		{
			$job = new SendMessage($this, $slug, $url, $data, $this->entry_mp_account);
		}

		if(date('H') >= 0 && date('H') < 7)
		{
			$this->dispatch($job->delay(strtotime('07:00') - time()));
		}
		elseif(date('H') >= 23)
		{
			$this->dispatch($job->delay(strtotime('midnight tomorrow') - time() + 7 * 3600));
		}
		else
		{
			$this->dispatch($job);
		}
	}
	
	/**
	 * @param File|string $file file or path
	 * @return User
	 */
	public function uploadFile($file)
	{
		if(is_string($file))
		{
			$file = new File($file);
		}
		
		$extension = $file->guessExtension();
		
		if(empty($extension))
		{
			throw new RuntimeException('file extension not resolved');
		}
		
		$file_store_name = md5_file($file->getPath()) . '.' . $extension;
		
		$this->avatar = 'uploads/' . $file_store_name;
		
		$file->move(storage_path('uploads'), $file_store_name);
		$disk = QiniuStorage::disk('qiniu');
		$disk->putFile('uploads/' . $file_store_name, storage_path('uploads/' . $file_store_name));
		
		return $this;
	}
}
