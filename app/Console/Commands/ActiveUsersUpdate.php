<?php namespace App\Console\Commands;

use App\ActionLog;
use Illuminate\Console\Command;
use App\Config;
use DB, Redis, Carbon\Carbon;

class ActiveUsersUpdate extends Command
{
	/**
	 * The name and signature of the console command.
	 *
	 * @var string
	 */
	protected $signature = 'active-user:update {--init : 初始化所有log到Redis, 并存历史日活数据到Config}';

	/**
	 * The console command description.
	 *
	 * @var string
	 */
	protected $description = '活跃用户更新任务';

	/**
	 * Create a new command instance.
	 *
	 * @return void
	 */
	public function __construct()
	{
		parent::__construct();
	}

	/**
	 * Execute the console command.
	 *
	 * @return mixed
	 */
	public function handle()
	{
		// 初始化活跃用户数据 logs -> Redis
		if($this->option('init'))
		{
			$this->init();
		}
		else
		{
			$this->cronDaily();
		}
	}

	/**
	 * 将所有log初始化到Redis, 计算历史日活并存入Config
	 */
	protected function init()
	{
		$bar = $this->output->createProgressBar(ActionLog::count());
		$bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s%');

		DB::table('logs')->chunk(1E4, function($logs) use (&$bar)
		{
			foreach ($logs as $index => $log)
			{
				if(env('APP_ENV') === 'local' && $index % 100 !== 0) continue;

				$date_string = date('Y-m-d', strtotime($log->created_at));
				$week_string = date('Y_W', strtotime($log->created_at));
				$month_string = date('Y_d', strtotime($log->created_at));

				Redis::sadd('ip_daily_' . $date_string, $log->ip);
				Redis::expire('ip_daily_' . $date_string, 86400 * 10);

				Redis::sadd('active_users_daily_' . $date_string, $log->user_id);
				Redis::expire('active_users_daily_' . $date_string, 86400 * 10);

				Redis::sadd('active_users_weekly_' . $week_string, $log->user_id);
				Redis::expire('active_users_weekly_' . $week_string, 86400 * 15);

				Redis::sadd('active_users_monthly_' . $month_string, $log->user_id);
				Redis::expire('active_users_monthly_' . $month_string, 86400 * 65);

			}

			$bar->advance(1E4);
		});
		
		$first_date = ActionLog::first()->created_at;
		
		$active_users_daily = $active_users_weekly = $active_users_monthly = [];
		
		for($date = $first_date; Redis::exists('ip_daily_' . $date->toDateString()); $date->addDay())
		{
			$active_users = $this->dumpCache($date);
			$active_users_daily[] = $active_users['daily'];
			
			if($active_users['weekly'])
			{
				$active_users_weekly[] = $active_users['weekly'];
			}
			
			if($active_users['monthly'])
			{
				$active_users_monthly[] = $active_users['monthly'];
			}
		}
		
		Config::set('active_users_daily', $active_users_daily);
		Config::set('active_users_weekly', $active_users_weekly);
		Config::set('active_users_monthly', $active_users_monthly);
	}
	
	/*
	 * 每日执行的脚本, 将已经不会再变化的Redis数据存入Config
	 * @todo 适应非连续每天运行时
	 */
	public function cronDaily()
	{
		$date = Carbon::yesterday();
		$active_users = $this->dumpCache($date);
		
		$active_users_daily = Config::get('active_users_daily');
		$active_users_weekly = Config::get('active_users_weekly');
		$active_users_monthly = Config::get('active_users_monthly');
		
		if(!$active_users_daily || $active_users_daily[count($active_users_daily) - 1]->date < $active_users['daily']->date)
		{
			$active_users_daily[] = $active_users['daily'];
			Config::set('active_users_daily', $active_users_daily);
		}
		
		if(isset($active_users['weekly']) && (!$active_users_weekly || $active_users_weekly[count($active_users_weekly) - 1]->week < $active_users['weekly']->week))
		{
			$active_users_weekly[] = $active_users['weekly'];
			Config::set('active_users_weekly', $active_users_weekly);
		}
		
		if(isset($active_users['monthly']) && (!$active_users_monthly || $active_users_monthly[count($active_users_monthly) - 1]->month < $active_users['monthly']->month))
		{
			$active_users_monthly[] = $active_users['monthly'];
			Config::set('active_users_monthly', $active_users_monthly);
		}
	}
	
	protected function dumpCache(Carbon $date)
	{
		$this->info('正在保存' . $date->toDateString() . '的数据');
		
		$previous_day = $date->copy()->subDay()->startOfDay();
		$previous_week = $date->copy()->previous()->startOfWeek();
		$previous_month = $date->copy()->subMonthNoOverFlow()->startOfMonth();
		
		$daily = (object)[
			'date' => $date->toDateString(),
			'ip' => Redis::scard('ip_daily_' . $date->toDateString()),
			'ac' => Redis::scard('active_users_daily_' . $date->toDateString()),
			'pd' => count(Redis::sinter('active_users_daily_' . $date->toDateString(), 'active_users_daily_' . $previous_day->toDateString())),
			'pw' => count(Redis::sinter('active_users_daily_' . $date->toDateString(), 'active_users_weekly_' . $previous_week->format('Y_W'))),
			'pm' => count(Redis::sinter('active_users_daily_' . $date->toDateString(), 'active_users_monthly_' . $previous_month->format('Y_m'))),
		];
		
		$weekly = $monthly = null;
		
		// 如果当天是星期一, 则保存上周周统计
		if($date->dayOfWeek === Carbon::MONDAY)
		{
			$week = $date->copy()->previous()->startOfWeek();
			$previous_week = $week->copy()->previous();
			$previous_month = $week->copy()->subMonthNoOverFlow()->startOfMonth();;
			
			$weekly = (object)[
				'week' => $week->format('Y_W'),
				'ac' => Redis::scard('active_users_weekly_' . $week->format('Y_W')),
				'pw' => count(Redis::sinter('active_users_weekly_' . $week->format('Y_W'), 'active_users_weekly_' . $previous_week->format('Y_W'))),
				'pm' => count(Redis::sinter('active_users_weekly_' . $week->format('Y_W'), 'active_users_monthly_' . $previous_month->format('Y_m'))),
			];
		}
		
		// 如果当天是月初, 则保存上月月统计
		if($date->day === 1)
		{
			$month = $date->copy()->subMonthNoOverFlow()->startOfMonth();
			$previous_month = $month->copy()->subMonthNoOverFlow()->startOfMonth();
			
			$monthly = (object)[
				'month' => $month->format('Y_m'),
				'ac' => Redis::scard('active_users_monthly_' . $month->format('Y_m')),
				'pm' => count(Redis::sinter('active_users_monthly_' . $month->format('Y_m'), 'active_users_monthly_' . $previous_month->format('Y_m'))),
			];
		}
		
		return compact('daily', 'weekly', 'monthly');
	}
}
