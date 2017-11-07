<?php namespace App\Http\Controllers;

use App\Post, App\Order, App\User, App\Config;
use Carbon\Carbon;
use Redis, DB, Request;

class DashboardController extends Controller
{
	/**
	 * @param $item
	 * @return \Illuminate\Http\Response
	 */
	public function index($item)
	{
		$method = camel_case($item);
		
		if(method_exists($this, $method))
		{
			return response($this->$method());
		}
		
		return abort(404, 'Invalid Dashboard Item');
	}
	
	/**
	 * 获得统计总数
	 */
	public function overview()
	{
		if(!app()->user->can('overview'))
		{
			abort(403);
		}
		
		// 用户总数
		$users_count = User::count();
		
		// PGC总数
		$pgc_count = Post::whereIn('type', ['article', 'insight', 'topic', 'event', 'course', 'audio', 'video', 'collage_theme', 'collage_template'])->count();
		
		// UGC总数
		$ugc_count = Post::whereIn('type', ['comment', 'discussion', 'question', 'answer', 'collage'])->count();
		
		// 成交订单金额
		$paid_amount = Order::whereIn('status', ['paid', 'in_group'])->sum('price');
		
		return collect(compact('users_count', 'pgc_count', 'ugc_count', 'paid_amount'));
	}
	
	/**
	 * 每日新用户数
	 */
	public function newUsers()
	{
		$users = collect(DB::table('users')->selectRaw('COUNT(*) `count`, DATE(`created_at`) `date`')
			->where('created_at', '>=', $this->getStartDate())
			->where('created_at', '<=', $this->getEndDate())
			->groupBy(DB::raw('DATE(`created_at`)'))->get());
		
		return compact('users');
	}
	
	/**
	 * 每日活跃用户数
	 */
	public function activeUsers()
	{
		// 获取最近每天日活, 以及其中上周回访和上月回访
		$active_users_daily = collect(Config::get('active_users_daily'))
			->filter(function($daily_data)
			{
				return $daily_data->date >= $this->getStartDate() && $daily_data->date <= $this->getEndDate();
			})->values();
		
//		$date = date('Y-m-d');
//		$last_week = date('Y_W', strtotime('-1 week', strtotime($date)));
//		$last_month = date('Y_m', strtotime('midnight first day of -1 month', strtotime($date)));
//
//		// 追加今天的活跃用户数据
//		$active_users_daily->prepend([
//			"date"=>$date,
//			"count"=>Redis::scard('active_users_daily' . date('Y-m-d')),
//			"previous_week"=>count(Redis::sinter('active_users_daily_' . $date, 'active_users_weekly_' . $last_week)),
//			"previous_month"=>count(Redis::sinter('active_users_daily_' . $date, 'active_users_monthly_' . $last_month)),
//		]);
		
		return $active_users_daily;
	}
	
	/**
	 * 每日微信公众号事件
	 */
	public function mpAccountEvents()
	{
		$events = collect(DB::table('messages')->whereNotNull('event')->selectRaw('`event`, COUNT(*) `count`, DATE(`created_at`) `date`')
			->where('event', '!=', '')
			->where('created_at', '>=', $this->getStartDate())
			->where('created_at', '<=', $this->getEndDate())
			->groupBy('event', DB::raw('DATE(`created_at`)'))->get())
			->groupBy('event');
		
		return $events;
	}
	
	/**
	 * 每日消息收发
	 */
	public function messages()
	{
		$events = collect(DB::table('messages')->whereNotNull('user_id')->selectRaw('`event`, COUNT(*) `count`, DATE(`created_at`) `date`')
			->where('created_at', '>=', $this->getStartDate())
			->where('created_at', '<=', $this->getEndDate())
			->groupBy(DB::raw('DATE(`created_at`)'), 'event')->get())
			->groupBy('event');
		
		return $events;
	}
	
	/**
	 * 每日用户交互
	 */
	public function interactions()
	{
		$ugcs = collect(DB::table('posts')->selectRaw('COUNT(*) `count`, DATE(`created_at`) `date`')->whereIn('type', ['comment', 'discussion', 'question', 'answer', 'collage', 'image'])
			->where('created_at', '>=', $this->getStartDate())
			->where('created_at', '<=', $this->getEndDate())
			->groupBy(DB::raw('DATE(`created_at`)'))->get());
		
		$likes = collect(DB::table('post_like')->selectRaw('COUNT(*) `count`, DATE(`created_at`) `date`')
			->where('created_at', '>=', $this->getStartDate())
			->where('created_at', '<=', $this->getEndDate())
			->groupBy(DB::raw('DATE(`created_at`)'))->get());
		
		$shares = collect(DB::table('post_share')->selectRaw('COUNT(*) `count`, DATE(`created_at`) `date`')
			->where('created_at', '>=', $this->getStartDate())
			->where('created_at', '<=', $this->getEndDate())
			->groupBy(DB::raw('DATE(`created_at`)'))->get());
		
		$attends = collect(DB::table('event_attend')->selectRaw('COUNT(*) `count`, DATE(`created_at`) `date`')
			->where('created_at', '>=', $this->getStartDate())
			->where('created_at', '<=', $this->getEndDate())
			->groupBy(DB::raw('DATE(`created_at`)'))->get());
		
		$follows = collect(DB::table('user_follow')->selectRaw('COUNT(*) `count`, DATE(`created_at`) `date`')
			->where('created_at', '>=', $this->getStartDate())
			->where('created_at', '<=', $this->getEndDate())
			->groupBy(DB::raw('DATE(`created_at`)'))->get());
		
		return compact('likes', 'shares', 'attends', 'follows', 'ugcs');
	}
	
	// 每日UGC每种类型的数量
	public function ugcs()
	{
		$ugcs = collect(DB::table('posts')->selectRaw('`type`, COUNT(*) `count`, DATE(`created_at`) `date`')->whereIn('type', ['comment', 'discussion', 'question', 'answer', 'collage', 'image'])
			->where('created_at', '>=', $this->getStartDate())
			->where('created_at', '<=', $this->getEndDate())
			->groupBy(DB::raw('DATE(`created_at`)'), 'type')->get())
			->groupBy('type');
		
		return $ugcs;
	}
	
	// 新用户留存
	public function retention()
	{
		
		$new_users_daily = User::select(DB::raw('id, created_at'))->where('created_at', '>=', (new Carbon('-7 days'))->toDateString())
			->where('created_at', '<=', $this->getEndDate())->get()
			->groupBy(function($user)
			{
				return $user->created_at->toDateString();
			})
			->toArray();
		
		$daily_retention = collect();
		
		foreach($new_users_daily as $date_created => $new_users)
		{
			$date_created = new Carbon($date_created);
			
			$retention = (object)['date' => $date_created->toDateString(), 'new_users' => count($new_users), 'retention'=>collect()];
			
			for($date_retain = $date_created->copy()->addDay(); $date_retain->diffInDays(null, false) >= 1 && $date_created->diffInDays($date_retain, false) < 7; $date_retain->addDay())
			{
				$retention->retention->push((object)['date'=>$date_retain->toDateString(), 'count'=>count(array_intersect(array_column($new_users, 'id'), Redis::command('smembers', ['active_users_daily_' . $date_retain->toDateString()])))]);
			}
			
			$daily_retention->push($retention);
		}
		
		print_r($daily_retention->toJson());
	}
	
	/**
	 * 处理统计的开始时间
	 *
	 * @todo 支持输入变量
	 */
	protected function getStartDate()
	{
		return Request::query('start') ?: (new Carbon('-4 weeks'))->toDateString();
	}
	
	/**
	 * 处理统计的结束时间
	 *
	 * @todo 支持输入变量
	 */
	protected function getEndDate()
	{
		return Request::query('end') ?: (new Carbon())->toDateString();
	}
}
