<?php namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use App\Console\Commands;

class Kernel extends ConsoleKernel {

	/**
	 * The Artisan commands provided by your application.
	 *
	 * @var array
	 */
	protected $commands = [
		Commands\Inspire::class,
		Commands\UserUpdatePoints::class,
		Commands\UserUpdate::class,
		Commands\PostUpdate::class,
		Commands\PostUpdateCount::class,
		Commands\ProfileJsonCheck::class,
		Commands\WeixinUpdateMenu::class,
		Commands\WeixinUpdateUserGroup::class,
		Commands\WeixinSendNotification::class,
		Commands\WeixinDownloadMedia::class,
		Commands\WeixinSyncUsers::class,
		Commands\WeixinGetCallbackIp::class,
		Commands\ActiveUsersUpdate::class,
		Commands\UserUpdateChildren::class,
	];

	/**
	 * Define the application's command schedule.
	 *
	 * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
	 * @return void
	 */
	protected function schedule(Schedule $schedule)
	{
		$schedule->command('inspire')
				 ->hourly();
	}

}
