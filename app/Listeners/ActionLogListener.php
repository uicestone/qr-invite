<?php namespace App\Listeners;

use App\Config;
use App\Events\ActionLogCreated;
use App\Events\DashboardBroadcast;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Redis;

class ActionLogListener implements ShouldQueue
{
	/**
	 * Create the event listener.
	 *
	 * @return void
	 */
	public function __construct()
	{

	}

	/**
	 * Handle the event.
	 *
	 * @param  ActionLogCreated  $event
	 * @return void
	 */
	public function handle(ActionLogCreated $event)
	{
		$action_log = $event->action_log;
	
		$log = $action_log->attributesToArray();

		if($action_log->user_id)
		{
			$log['user_name'] = $action_log->user->name;
		}
	
		$log = array_merge($log, (array)$action_log->meta);

		// 记录活跃用户到Redis
		if($action_log->user_id)
		{
			// 日IP
			Redis::sadd('ip_daily_' . date('Y-m-d'), $action_log->user_id);
			Redis::expire('ip_daily_' . date('Y-m-d'), 86400 * 10);  //过期时间(秒)
			
			// 日活用户
			Redis::sadd('active_users_daily_' . date('Y-m-d'), $action_log->user_id);
			Redis::expire('active_users_daily_' . date('Y-m-d'), 86400 * 10);  //过期时间(秒)

			// 周活用户, 每周从星期一开始
			Redis::sadd('active_users_weekly_' . date('Y_W'), $action_log->user_id);
			Redis::expire('active_users_weekly_' . date('Y_W'), 86400 * 15);  //过期时间(秒)

			// 月活用户
			Redis::sadd('active_users_monthly_' . date('Y_m'), $action_log->user_id);
			Redis::expire('active_users_monthly_' . date('Y_m'), 86400 * 65);  //过期时间(秒)
		}

		event(new DashboardBroadcast($log));
	}
}
