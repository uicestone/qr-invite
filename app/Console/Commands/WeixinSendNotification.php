<?php namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Config, App\Order, App\Post, App\Profile, App\Sms, App\User, App\Weixin;
use Log, ErrorException;

class WeixinSendNotification extends Command {

	/**
	 * The console command name.
	 *
	 * @var string
	 */
	protected $signature = 'weixin:send-notice {account? : 微信公众号代号}
			{--invitation-event= : 发送活动邀请通知，值为活动标ID}
			{--step= : 活动阶段(start, end)}
			{--skip= : 跳过开始N个用户}
			{--template= : 推送模板消息}
			{--message-data= : 客服消息内容或模板消息JSON数据}
			{--message-url= : 模板消息链接}
			{--t|test : 只发送给测试用户}
			{--c|count : 仅计算要发送的用户数量，不发送}
			{--d|days= : 对过去N天的回复进行推送消息，默认为1天}
			{--f|force : 即使用户在回复时间之后访问过网站，依然推送}
			{--r|repeat= : 重复发送的次数, 用于测试}
			{--u|user=* : 手动选择用户推送, 列出要推送的用户}
			{--type= : 客服消息类型image, text等}
			{--pending-order= : 未支付订单 }
			{--post= : 未支付订单的课程ID }
			';

	/**
	 * The console command description.
	 *
	 * @var string
	 */
	protected $description = '给微信用户发送通知';
	
	protected $should_invite = 3;

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
		$wx = new Weixin($this->argument('account'));
		
		app()->from_mp_account = $this->argument('account');
		app()->name = $wx->belongs_to_app;
		
		// 发送活动邀请提醒
		if($this->option('invitation-event'))
		{
			if($this->option('step') === 'attend')
			{
				$this->sendInvitationEventAttendNotice($wx);
			}
			else
			{
				$this->sendInvitationNotice($wx);
			}
		}
		
		// 发送订单未支付通知
		elseif($this->option('pending-order'))
		{
			$this->sendOrderPendingNotice($wx);
		}
		// 手动对指定用户发送消息
		elseif($this->option('user'))
		{
			$this->sendNotice($wx);
		}
		// 发送新消息提醒
		else
		{
			$this->sendMessageNotice($wx);
		}
	}
	
	/**
	 * 发送邀请提醒
	 * 对于昨天被邀请的用户, 其邀请不满 $this->should_invite 个的, 补发邀请函和邀请提醒
	 * @param Weixin $wx
	 */
	protected function sendInvitationNotice(Weixin $wx)
	{
		$event = Post::find($this->option('invitation-event'));

		$users_invited = Profile::where('key', 'invited_by_user_id_in_event_' . $event->id)->whereRaw('DATE(`created_at`) = ?', [date('Y-m-d', strtotime('-' . ($this->option('days') ?: 1) . ' day'))])->get()->map(function($profile)
		{
			return $profile->user;
		});

		$this->info('共' . count($users_invited) . '个用户');

		$total_users_invited = Profile::where('key', 'invited_by_user_id_in_event_' . $event->id)->count();

		$users_sent = 0;

		if($this->option('skip'))
		{
			$users_invited = $users_invited->slice((int)$this->option('skip'));
		}

		foreach($users_invited as $user)
		{
			try
			{
				$invitations = Profile::where('key', 'invited_by_user_id_in_event_' . $event->id)->where('value', $user->id)->count();

				if($invitations < $this->should_invite)
				{
					$media_id = $wx->getInvitationCardMediaId($event, $user);
					$message = Config::get('message_invitation_remind');
					$message = str_replace('{event_title}', $event->title, $message);
					$message = str_replace('{total_users_invited}', ($total_users_invited), $message);
					$message = str_replace('{invite_more}', $this->should_invite - $invitations, $message);

					$wx->sendServiceMessage($user, $message);

					$result = $wx->sendServiceMessage($user, $media_id, 'image');
					if(isset($result->errcode) && $result->errcode)
					{
						$this->error($result->errmsg);
					}
					else
					{
						$this->line('向 ' . $user->id . ' ' . $user->name . ' 发送了邀请提醒 (已邀请' . $invitations . ')');
					}

					$users_sent++;
				}
			}
			catch(ErrorException $e)
			{
				$this->error($e->getMessage() . '(' . $e->getFile() . ':' . $e->getLine() . ')');
			}
		}

		$this->info('向' . $users_sent . '位用户发送了邀请提醒');
	}

	/**
	 *
	 */
	protected function sendInvitationEventAttendNotice(Weixin $wx)
	{
		$event = Post::find($this->option('invitation-event'));
		
		if(!$event)
		{
			$this->error('Event ' . $this->$this->option('invitation-event') . ' not found.');
			return;
		}
		
		$users = Profile::where('key', 'invited_by_user_id_in_event_' . $this->option('invitation-event'))->get()->map(function($profile)
		{
			return $profile->user;
		})
		->filter(function($user) use($event)
		{
			$invited_users = Profile::where('key', 'invited_by_user_id_in_event_' . $event->id)->where('value', $user->id)->get()->map(function($profile){
				return $profile->user;
			});
			
			return $invited_users->count() < $this->should_invite;
		});

		$this->info('即将发送' . $users->count() . '个用户');

		if($this->option('count'))
		{
			return;
		}
		
		$media_id = Config::get('assistant_card_media_id_event_' . $event->id);

		if(!$media_id)
		{
			$media = $wx->uploadMedia($event->getMeta('assistant_card_path'));
			Config::set('assistant_card_media_id_event_' . $event->id, $media->media_id, $media->created_at + 86400 * 3);
		}

		$users->each(function($user) use($event, $media_id, $wx)
		{
			if($wx->supports('template_message'))
			{
				$user->sendMessage('event_attend', url($event->getMeta('assistant_card_path')), [
					'first'=>'你的好友【' . $user->name . '】已接受你的邀请。恭喜你已获得'
						. $event->getMeta('date')
						. '的【' . $event->title . '】免费参与资格',
					'keynote1'=>$event->title,
					'keynote2'=>$event->getMeta('date'),
					'keynote3'=>'本公众号和导师微信群',
					'remark'=>['value'=>"\n"
						. '点击本消息扫描二维码添加导师为好友，并将验证码【'
						. $user->human_code .
						'】发送给导师。', 'color'=>'#AA0000']]);
			}
			else
			{
				$message = Config::get('message_invitation_success');
				$message = str_replace('{user_name}', $user->name, $message);
				$message = str_replace('{event_title}', $event->title, $message);
				$message = str_replace('{inviter_human_code}', $user->human_code, $message);
				$wx->sendServiceMessage($user, $message);
			}
		});
		
	}

	public function sendNotice(Weixin $wx)
	{
		$user_ids = $this->option(('user'));

		if(!$user_ids)
		{
			$this->error('Fail to send Weixin notice, user ids not defined.');
			Log::error('Fail to send Weixin notice, user ids not defined.');
		}

		if(str_contains($user_ids[0], ','))
		{
			$user_ids = explode(',', $user_ids[0]);
		}

		$users = array_map(function($user_id)
		{
			$user = User::find($user_id);
			if(!$user)
			{
				$this->error('User id: ' . $user_id . ' not found.');
				Log::error('User id: ' . $user_id . ' not found.');
			}
			return $user;
		},
		$user_ids);

		$template = $this->option('template');

		if($template)
		{
			$data = json_decode($this->option('message-data'));

			if(!$data)
			{
				$data = (object)[];
			}

			$repeat = 1;

			if($this->option('test') && $this->option('repeat'))
			{
				$repeat = $this->option('repeat');
			}

			for($i = 0; $i < $repeat; $i++)
			{
				foreach($users as $user)
				{
					$url = null;

					if($this->option('message-url'))
					{
						$url = $this->option('message-url');
					}
					elseif(isset($data->url))
					{
						$url = $data->url;
					}

					$wx->sendTemplateMessage($user, $template, $url, $data);
				}
			}
		}
		else
		{
			$message = $this->option('message-data');

			if(!$message)
			{
				Log::error('Fail to send notice, message content not defined.');
				return;
			}

			foreach($users as $user)
			{
				$wx->sendServiceMessage($user, $message, $this->option('type') ?: 'text');
			}
		}
	}
	
	protected function sendOrderPendingNotice(Weixin $wx)
	{
		$query = Order::select('orders.*')->ofStatus(['pending', 'expired'])->join('order_post', 'order_post.order_id', '=', 'orders.id')->groupBy(['user_id', 'post_id', 'membership']);
		
		if($this->option('pending-order') === 'post')
		{
			$query->post();
			
			if($this->option('post'))
			{
				$query->whereIn('orders.id', function($query)
				{
					$query->select('order_id')->from('order_post')->where('post_id', $this->option('post'));
				});
			}
		}
		elseif($this->option('pending-order') === 'membership')
		{
			$query->member();
		}
		
		if($this->option('user'))
		{
			$query->whereIn('user_id', $this->option('user'));
		}
		
		$orders = $query->get();
		
		$this->output->progressStart($orders->count());
		
		foreach($orders as $order)
		{
			$user = $order->user;
			
			// 购买会员的订单
			if($order->membership)
			{
				if($user->membership)
				{
					continue;
				}

				// 发送模板消息
				$template_data = [
					'first'    => '您之前购买的会员未完成支付，请尽快支付避免名额售罄',
					'keyword1' => $user->name,
					'keyword2' => '未完成支付',
					'remark'   => '点击查看详情'
				];

				$template_url = 'http:///payment/wxpay?level=10';

				$result = $wx->sendTemplateMessage($user, 'membership_payment_failed', $template_url, $template_data );

				if(!$result)
				{
					// 发送短信
					$membership = collect(Config::get('memberships'))->where('level', $order->membership)->first();

					if(!$membership)
					{
						$this->error('未定义会员等级' . $order->membership);
					}

					Sms::send($user->mobile, '您之前购买的会员未完成支付，请尽快支付避免名额售罄');
				}
			}
			// 购买单个课程的订单
			else
			{
				$template_data = [
					'first'=>'点击本条信息支付，并锁定席位！',
					'keyword1'=>$order->name,
					'keyword2'=>1,
					'keyword3'=>'会员价 ¥',
					'keyword4'=>'体验会员价 ¥' . $order->price,
					'keyword5'=>'60分钟内',
					'remark'=>'点我查看详情！'
				];

				if($order->posts->diff($user->paidPosts)->count() === 0)
				{
					$this->warn('订单' . $order->id . ', 用户' . $user->id . '已经支付了课程' . $order->posts->implode('id', '-'));
					continue;
				}

				$template_url = 'http:///pay/post/' . $order->posts->first()->id;

				$result = $wx->sendTemplateMessage($user, 'payment_failed', $template_url, $template_data);

				if(!$result)
				{
					// 发送短信
				}
			}
			
			$this->output->progressAdvance();
		}
		
		$this->output->progressFinish();
	}
}
