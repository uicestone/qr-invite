<?php namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use App\Profile, App\User;
use Hash;

class UserUpdate extends Command {

	/**
	 * The console command name.
	 *
	 * @var string
	 */
	protected $signature = 'user:update {--password : 加密用户的密码} {--like : 更新修正用户点赞数据} {--favorite : 更新修正用户收藏数据} {--share : 更新修正用户分享数据} {--attend : 更新修正用户参与活动数据} {--follow : 更新修正用户关注数据} {--pay : 更新修正用户支付数据}';

	/**
	 * The console command description.
	 *
	 * @var string
	 */
	protected $description = '更新修正用户数据';

	/**
	 * Create a new command instance.
	 */
	public function __construct()
	{
		parent::__construct();
	}

	/**
	 * Execute the console command.
	 */
	public function handle()
	{
		if($this->option('password'))
		{
			$bar = $this->output->createProgressBar(User::whereNotNull('password')->count());
			$bar->setFormat('%message%' . "\n" . ' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s%');
			
			User::whereNotNull('password')->chunk(1E3, function($users) use($bar)
			{
				$bar->setMessage('正在加密用户的密码');
				foreach($users as $user)
				{
					if($user->password && Hash::needsRehash($user->password))
					{
						$user->password = Hash::make($user->password);
						$user->save();
					}
				}
				
				$bar->advance(1E3);
			});
		}
		
		if($this->option('like'))
		{
			$bar = $this->output->createProgressBar(User::has('likedPosts')->count());
			$bar->setFormat('%message%' . "\n" . ' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s%');
			
			User::has('likedPosts')->chunk(1E3, function($users) use($bar)
			{
				$bar->setMessage('正在存储用户点赞的内容');
				$users->each(function(User $user)
				{
					$user->setProfile('liked_posts', $user->likedPosts->pluck('id')->toArray());
				});
				
				$bar->advance(1E3);
			});
		}
		
		if($this->option('favorite'))
		{
			$bar = $this->output->createProgressBar(User::has('favoredPosts')->count());
			$bar->setFormat('%message%' . "\n" . ' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s%');
			
			User::has('favoredPosts')->chunk(1E3, function($users) use($bar)
			{
				$bar->setMessage('正在存储用户收藏的内容');
				$users->each(function(User $user)
				{
					$user->setProfile('favored_posts', $user->favoredPosts->pluck('id')->toArray());
				});
				
				$bar->advance(1E3);
			});
		}
		
		if($this->option('share'))
		{
			$bar = $this->output->createProgressBar(User::has('sharedPosts')->count());
			$bar->setFormat('%message%' . "\n" . ' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s%');
			
			User::has('sharedPosts')->chunk(1E3, function($users) use($bar)
			{
				$bar->setMessage('正在存储用户分享的内容');
				$users->each(function(User $user)
				{
					$user->setProfile('shared_posts', $user->sharedPosts->pluck('id')->toArray());
				});
				
				$bar->advance(1E3);
			});
		}
		
		if($this->option('attend'))
		{
			$bar = $this->output->createProgressBar(User::has('attendingEvents')->count());
			$bar->setFormat('%message%' . "\n" . ' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s%');
			
			User::has('attendingEvents')->chunk(1E3, function($users) use($bar)
			{
				$bar->setMessage('正在存储用户参加的活动');
				$users->each(function(User $user) use($bar)
				{
					$user->setProfile('attending_events', $user->attendingEvents->pluck('id')->toArray());
				});
				
				$bar->advance(1E3);
			});
			}
		
		if($this->option('follow'))
		{
			$bar = $this->output->createProgressBar(User::has('followingUsers')->count());
			$bar->setFormat('%message%' . "\n" . ' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s%');
			
			User::has('followingUsers')->chunk(1E3, function($users) use($bar)
			{
				$bar->setMessage('正在存储用户关注的用户');
				$users->each(function(User $user)
				{
					$user->setProfile('following_users', $user->followingUsers->pluck('id')->toArray());
				});
				
				$bar->advance(1E3);
			});
		}
		
		if($this->option('pay'))
		{
			$bar = $this->output->createProgressBar(User::has('paidPosts')->count());
			$bar->setFormat('%message%' . "\n" . ' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s%');
			
			User::has('paidPosts')->chunk(1E3, function($users) use($bar)
			{
				$bar->setMessage('正在存储用户有权查看的付费内容');
				$users->each(function(User $user)
				{
					$user->setProfile('paid_posts', $user->paidPosts->pluck('id')->toArray());
				});
				
				$bar->advance(1E3);
			});
		}
	}
}
