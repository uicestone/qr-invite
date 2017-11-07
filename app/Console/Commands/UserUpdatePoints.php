<?php namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;

use App\User;

class UserUpdatePoints extends Command {

	/**
	 * The console command name.
	 *
	 * @var string
	 */
	protected $signature = 'user:update-points';

	/**
	 * The console command description.
	 *
	 * @var string
	 */
	protected $description = 'Manually re-caculate points of all user.';

	/**
	 * Create a new command instance.
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
		User::with('profiles', 'posts', 'posts.likedUsers', 'posts.sharedUsers', 'likedPosts', 'sharedPosts', 'followingUsers', 'followedUsers')->chunk(1E3, function($users)
		{
			foreach($users as $user)
			{
				$user->updatePoints();
			}
		});
	}
}
