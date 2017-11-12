<?php namespace App\Http\Controllers;

use App\ActionLog, App\Config, App\Meta, App\Order, App\Post, App\Profile, App\Sms, App\User, App\Weixin;
use App\Services\OrderService;
use App\Jobs\SendSms, App\Jobs\SendInvitationCard;
use App\Http\Request;
use Intervention\Image\ImageManagerStatic as Image;
use Log, Hash;

class WeixinController extends Controller
{
	/**
	 * 接受微信消息事件推送的页面
	 *
	 * @param Request $request
	 * @param string $account
	 * @return \Illuminate\Http\Response
	 */
	public function serve(Request $request, $account = null)
	{
		$wx = new Weixin($account);
		$wx->verify($request->query('timestamp'), $request->query('nonce'), $request->query('signature'), $request->query('echostr'))->initMessage();

		// 手动获得二维码邀请活动邀请卡
		$wx->onMessage('^\d+$', function($message) use($wx)
		{
			$user = $message->sender;
			$meta = Meta::where('key', 'mp_account_name')->where('value', $wx->name)->orderBy('created_at', 'desc')->skip($message->content - 1)->first();

			if(!$meta)
			{
				return;
			}

			$media_id = $wx->getInvitationCardMediaId($meta->post, $user);
			$wx->sendServiceMessage($user, $media_id, 'image');
		});
		
		// 二维码活动
		$wx->onMessage([['event'=>'scan'], ['event'=>'subscribe']], function($message) use($wx)
		{
			$user = $message->sender; // 被邀请人

			if(!$message->event_key)
			{
				return;
			}

			$qr_scene_id = str_replace('qrscene_', '', $message->event_key);

			$qrcode = Config::get('wx_qrscene_' . $qr_scene_id . $wx->account);

			if(isset($qrcode->scene_data) && isset($qrcode->scene_data->name))
			{
				if($qrcode->scene_data->name === 'invitation')
				{
					$this->dispatch(new SendInvitationCard($qrcode, $user, $wx->name));
				}
				elseif($qrcode->scene_data->name === 'collage')
				{
					$this->processCollage($qrcode, $user, $wx);
				}
			}
		});
		
		return $wx->replyMessage();
	}
	
	protected function processCollage($qrcode, User $user, Weixin $weixin)
	{
		$scene_data = $qrcode->scene_data;
		$collage = Post::find($scene_data->collage_id);

		$media_id = Config::get('collage_media_id_' . $collage->id);

		if(!$media_id)
		{
			$collage_template = $collage->parent;
			$args = $collage_template->getMeta('args');
			$image_cert = Image::make($collage_template->getOriginal('url'));

			if($collage_template->getMeta('require_image') && $avatar = $collage->images->first())
			{
				$avatar_path = $avatar->getOriginal('url');

				if(strpos($avatar_path, 'wx://') === 0)
				{
					$avatar_path = $weixin->downloadMedia(str_replace('wx://', '', $avatar_path));
					$avatar->uploadFile($avatar_path);
					$avatar->save();
				}

				$image_avatar = Image::make($avatar_path)->fit($args->image->width, $args->image->height);
				$image_cert->insert($image_avatar, 'top-left', $args->image->x, $args->image->y);
			}

			$input_fields = $collage_template->getMeta('input_fields');
			$input_field_values = collect($collage->getMeta('input_fields'));
			if($input_fields && is_array($input_fields))
			{
				foreach($input_fields as $input_field)
				{
					$name = $input_field->name;
					$value = $input_field_values->where('name', $name)->first()->value;

					if(isset($args->$name->line_width))
					{
						$value = mb_strwrap($value, $args->$name->line_width);
					}
					
					if(isset($args->$name->prefix))
					{
						$value = $args->$name->prefix . $value;
					}
					
					if(isset($args->$name->suffix))
					{
						$value = $value . ' ' . $args->$name->prefix;
					}
					
					$image_cert->text($value, $args->$name->x, $args->$name->y, function($font) use($args, $name)
					{
						$font->file(env('FONT_PATH') . (isset($args->$name->font) ? $args->$name->font : 'Yuanti.ttc'));
						$font->size($args->$name->size);
						
						if(isset($args->$name->rotate))
						{
							$font->angle($args->$name->rotate);
						}

						$align = ['',''];

						if(isset($args->$name->align))
						{
							$align = explode('-', $args->$name->align);
						}

						$font->valign($align[0] ?: 'top');
						$font->align($align[1] ?: 'left');

						if(isset($args->$name->color))
						{
							$font->color($args->$name->color);
						}
					});
				}
			}

			$image_cert_path = 'uploads/collage_' . $collage->id . '.jpg';
			$image_cert->save(storage_path($image_cert_path));

			$collage->url = $image_cert_path;
			$collage->realAuthor()->associate($user);
			$collage->save();

			$media = $weixin->uploadMedia(storage_path($image_cert_path));
			$media_id = $media->media_id;
			Config::set('collage_media_id_' . $collage->id, $media_id, $media->created_at + 86400 * 3);
		}

		$weixin->sendServiceMessage($user, '点击放大图片后，长按选择【保存图片】，然后去【发朋友圈】晒你的拼图吧↓↓↓');
		$weixin->sendServiceMessage($user, $media_id, 'image');
	}

	public function oAuth(Request $request, $account, $code, $scope = 'snsapi_userinfo')
	{
		$wx = new Weixin($account);

		if($scope === 'snsapi_base')
		{
			$oauth_info = $wx->getOAuthInfo($code);
			$profile_openid = Profile::where('key', 'like', 'wx_openid_%')->where('value', $oauth_info->openid)->first();
			
			if($profile_openid)
			{
				$user = $profile_openid->user;
			}
			else
			{
				return response(['message'=>'用户没有注册', 'code'=>200])->setStatusCode(200, '用户没有注册');
			}
		}
		
		elseif($scope === 'snsapi_userinfo')
		{
			$user_info = $wx->getUserInfoOAuth($code);
			
			if(isset($user_info->errcode) && $user_info->errcode)
			{
				abort('401', '微信网页授权失败');
			}
			
			$user = User::where('wx_unionid', $user_info->unionid)->first();
			
			if(!$user)
			{
				$user = new User(['wx_unionid'=>$user_info->unionid]);
				
				if($request->header('cip-from-wechat') === 'singlemessage')
				{
					$user->source = '微信消息分享';
				}
				elseif($request->header('from-user-wechat') === 'groupmessage')
				{
					$user->source = '微信群分享';
				}
				elseif($request->header('from-user-wechat') === 'timeline')
				{
					$user->source = '微信朋友圈分享';
				}
				
				if($request->header('from-user'))
				{
					$user->setProfile('from_user', $request->header('from-user'));
				}
				
				$user->save();
			}
			
			// 若是新用户，或已有用户信息不全，补全用户信息
			if(!$user->name || !$user->getProfile('wx_openid' . $wx->account))
			{
				Log::info('openid' . $wx->account . ': ' . $user_info->openid . '的微信资料不全，正在补全。');
				
				if(isset($user_info->nickname))
				{
					$user->fill([
						'wx_unionid'=>$user_info->unionid,
						'name'=>$user_info->nickname,
						'address'=>$user_info->province . ' ' . $user_info->city,
						'gender'=>$user_info->sex,
						'avatar'=>$user_info->headimgurl,
					]);
					
					$user->save();
					
					$user->setProfile('wx_openid' . $wx->account, $user_info->openid);
				}
			}
		}
		else
		{
			return abort(400, 'Invalid Scope');
		}

		app()->user = $user;
		
		if(!$user->token)
		{
			$token = Hash::make($user->name . $user->wx_unionid . microtime(true));
			$user->token = $token;
			$user->save();
		}

		$user->load('profiles');
		$user->addVisible('token', 'realname', 'mobile', 'profiles', 'subscribed_tags', 'points_rank', 'points_rank_position', 'membership', 'membership_label');
		$user->append('followed', 'level', 'host_event', 'badges', 'subscribed_tags', 'points_rank', 'points_rank_position', 'membership', 'membership_label');

		return response($user)->header('Token', $user->token);
	}

	public function getAccount(Request $request, $account = null)
	{
		$mp_accounts = collect(Config::get('wx_mp_accounts'))->except(['app_secret', 'token', 'mch_id', 'mch_key']);
		
		$hostname = parse_url($request->header('referer'), PHP_URL_HOST);
		
		if($account)
		{
			$mp_account = $mp_accounts->where('name', $account)->first();
		}
		else
		{
			$mp_account = $mp_accounts->where('hostname', $hostname)->first();
		}

		if(!$mp_account)
		{
			abort(404, 'Weixin mp account not exist for ' . ($account ?: $hostname) . '.');
		}

		$weixin = new Weixin($mp_account->name);
		$mp_account->signature = $weixin->generateJsapiSign();
		$mp_account->timestamp = $weixin->timestamp;
		$mp_account->nonce_str = $weixin->nonce_str;

		return response(collect($mp_account));
	}
	
	public function getAccountList()
	{
		$mp_accounts = collect(Config::get('wx_mp_accounts'))->except(['app_secret', 'token', 'mch_id', 'mch_key']);
		return response($mp_accounts);
	}

	public function paymentConfirm(Request $request, $account = null)
	{
		$payment_confirm = $response = json_decode(json_encode(simplexml_load_string($request->getContent(), null, LIBXML_NOCDATA)));

		if($payment_confirm->return_code !== 'SUCCESS')
		{
			Log::error($payment_confirm);
			return;
		}

		$weixin = new Weixin($account);
		if($weixin->app_id !== $payment_confirm->appid)
		{
			abort(400, 'Weixin app id not match: ' . $weixin->app_id . ' ' . $payment_confirm->appid);
		}
		
		$sign = $weixin->generatePaySign((array)$payment_confirm);

		if($payment_confirm->sign !== $sign)
		{
			abort(403, 'Invalid callback signature.');
		}

		$order = Order::find($payment_confirm->out_trade_no);

		if(!$order || !$order->exists)
		{
			abort(404, 'Order ' . $payment_confirm->out_trade_no . ' not found.');
		}
		
		app()->user = $order->user;

		if($order->status === 'pending')
		{
			$order->status = 'paid';
			
			if($order->posts->count())
			{
				$order->user->sendMessage('paid', app_url('pay/result/' . $order->id), [
					'first'=>['color'=>'#AA0000', 'value'=>'支付成功，请加小助手，并在好友验证信息里发送您的验证码“' . $order->code . '”'],
					'keyword1'=>$order->name,
					'keyword2'=>'¥' . $order->price,
					'keyword3'=>$order->created_at->toDateString(),
					'remark'=>['color'=>'#333333', 'value'=>'小助手将于开课前统一邀请您入群听课']
				]);
				
				$message = Sms::getOrderMessage($order);
				$this->dispatch(new SendSms($order->contact, $message));
			}
			elseif($order->membership)
			{
				$order->user->setProfile('membership', $order->membership,'public');
				
				OrderService::decrementCapacity($order->membership);
				$order->user->sendMessage('paid', app_url('payment/order/' . $order->id), [
					'first'=>['color'=>'#AA0000', 'value'=>'您好，您的支付已完成，会员权益已生效'],
					'keyword1'=>$order->name,
					'keyword2'=>'¥' . $order->price,
					'keyword3'=>$order->created_at->toDateString(),
					'remark'=>['color'=>'#333333', 'value'=>'点击链接，查看会员专享干货和专家课程！']
				]);
				$message = Sms::getOrderMessage($order);
				$this->dispatch(new SendSms($order->contact, $message));
			}
		}

		$gateway = $order->gateway;
		$gateway->payment_confirmation = $payment_confirm;
		$order->gateway = $gateway;
		
		$order->fill(['code'=>$order->code]);
		$order->save();
		
		$order->user->paidPosts()->saveMany($order->posts);
		$paid_posts = $order->user->getProfile('paid_posts') ?: [];
		$paid_posts = array_values(array_unique(array_merge($paid_posts, $order->posts->pluck('id')->toArray())));
		$order->user->setProfile('paid_posts', $paid_posts);
		
		ActionLog::create(['order'=>$order], '订单支付');

	}
	
	public function getTemplateList($account = null)
	{
		$weixin = new Weixin($account);
		$template_list = $weixin->getTemplateList();
		return $template_list;
	}
}
