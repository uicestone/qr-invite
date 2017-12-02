<?php namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Foundation\Bus\DispatchesJobs;
use App\Jobs\SyncWeixinUserSubscribeTime;
use App\Profile, App\User, App\Weixin;
use RuntimeException;

class WeixinSyncUsers extends Command
{
	use DispatchesJobs;
	
	/**
	 * The name and signature of the console command.
	 *
	 * @var string
	 */
	protected $signature = 'weixin:sync-user {account? : 微信公众号代号}';

	/**
	 * The console command description.
	 *
	 * @var string
	 */
	protected $description = '同步微信关注用户';

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
	 * @todo 已关注用户没有去除
	 * @return mixed
	 */
	public function handle()
	{
		$mp_account = $this->argument('account');
		$weixin = new Weixin($mp_account);

		$users_subscribed_count = User::whereHas('profiles', function($query) use($weixin)
		{
			$query->where('key', 'wx_subscribed' . $weixin->account)->where('value', 'true');
		})
		->count('id');

		$this->info('系统中有' . $users_subscribed_count . '个关注用户');

		$openids = $weixin->getUserOpenids();

		$this->info('实际共有' . count($openids) . '个关注用户');

		foreach($openids as $index => $openid)
		{
			try
			{
				$openid_profile_existsed = Profile::where('key', 'wx_openid' . $weixin->account)->where('value', $openid)->first();

				$user_existed = null;

				if($openid_profile_existsed)
				{
					$user_existed = $openid_profile_existsed->user;
				}
				elseif ($weixin->supports('unionid'))
				{
					$user_info = $weixin->getUserInfo($openid);
					$user_existed = User::where('wx_unionid', $user_info->unionid)->first();
				}

				if(empty($user_existed))
				{
					if(!isset($user_info))
					{
						$user_info = $weixin->getUserInfo($openid);
					}
					$this->info('正在创建用户' . $user_info->nickname . '(' . $weixin->name . ' ' . $user_info->openid . ')');
					$user = new User([
						'name' => $user_info->nickname,
						'address' => $user_info->province . ' ' . $user_info->city,
						'gender' => $user_info->sex,
						'avatar' => $user_info->headimgurl,
					]);

					if (isset($user_info->unionid))
					{
						$user->wx_unionid = $user_info->unionid;
					}

					$user->save();
				}
				else
				{
					$user = $user_existed;

					$user->load('profiles');

					if(!$user->gender || !$user->getOriginal('avatar') || ($weixin->supports('unionid') && !$user->wx_unionid))
					{
						if(!isset($user_info))
						{
							$user_info = $weixin->getUserInfo($openid);
						}
						$this->info('正在更新用户 ' . $user->id . ' ' . $user_info->nickname . '(' . $user_info->openid . (isset($user_info->unionid) ? ' ' . $user_info->unionid : '') . ')');
						$user->fill([
							'name' => $user->name ?: $user_info->nickname,
							'address' => $user_info->province . ' ' . $user_info->city,
							'gender' => $user_info->sex,
							'avatar' => $user_info->headimgurl,
						]);

						if (isset($user_info->unionid))
						{
							$user->wx_unionid = $user_info->unionid;
						}

						$user->save();
					}

					if ($user->created_at->diffInSeconds() < 86400)
					{
						$this->dispatch(new SyncWeixinUserSubscribeTime($user, substr($weixin->account, 1), $openid, isset($user_info) ? $user_info : null));
					}

				}

				if(!$user->getProfile('wx_openid' . $weixin->account) || !$user->getProfile('wx_subscribed' . $weixin->account))
				{
					if(!isset($user_info))
					{
						$user_info = $weixin->getUserInfo($openid);
					}
					$this->info('正在更新用户资料 ' . $user->id . ' ' . $user_info->nickname . '(' . $user_info->openid . (isset($user_info->unionid) ? ' ' . $user_info->unionid : '') . ')');
					$user->setProfile('wx_openid' . $weixin->account, $openid);
					$user->setProfile('wx_subscribed' . $weixin->account, true, 'private');
				}

				unset($user_info);
			}
			catch(RuntimeException $e)
			{
				if(str_contains($e->getMessage(), 'Duplicate entry') && str_contains($e->getMessage(), 'wx_unionid'))
				{
					preg_match('/where `id` \= (\d*)/', $e->getMessage(), $matches_userid);
					preg_match('/Duplicate entry \'(.*?)\' for key/', $e->getMessage(), $matches_unionid);

					if(!$matches_userid || !$matches_unionid)
					{
						$this->error($e->getMessage());
					}

					$user_a = User::where('wx_unionid', $matches_unionid[1])->first();
					$user_b = User::find($matches_userid[1]);
					$this->warn('合并用户 ' . $user_a->id . ' 和 ' . $user_b->id);
					User::merge($user_a, $user_b);
				}
				else{
					$this->error($e->getMessage() . ' ' . $e->getFile() . ': ' . $e->getLine());
				}
			}

			if(($index + 1) % 1000 === 0)
			{
				$this->info('同步了' . ($index + 1) . '个用户信息');
			}
		}
	}
}
