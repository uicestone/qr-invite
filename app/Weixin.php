<?php namespace App;
/**
 * Weixin library for Laravel
 * @author Uice Lu <uicestone@gmail.com>
 * @version 0.61 (2014/1/9)
 */

use Intervention\Image\AbstractFont;
use Symfony\Component\HttpFoundation\File\MimeType\ExtensionGuesser;
use Illuminate\Foundation\Bus\DispatchesJobs;
use App\Jobs\SendServiceMessage;
use Intervention\Image\ImageManagerStatic as Image;
use Intervention\Image\AbstractFont as Font;
use GuzzleHttp\Client;
use Log, Request, Closure, URL, File, DB, RuntimeException, ErrorException;

class Weixin {
	
	use DispatchesJobs;
	
	public $name; // 公众号代号
	public $account; // 公众号代号，前面会带上_，在构建时被设置
	public $label; // 公众号名称
	public $app_id;
	private $token;
	private $app_secret;
	public $mch_id;
	private $mch_key;
	public $support_functions = [];
	public $belongs_to_app;
	public $hostname;
	
	public $user_groups; // 微信关注用户分组
	private $user; // 接受微信消息推送时，为当前用户模型
	private $message_raw; // 解析为对象后的原始微信消息
	private $message; // 接受微信消息推送时，为当前消息模型
	public $message_to_reply;
	public $message_replied_as_service = false;

	 // JSApi用得到的公用值
	public $nonce_str;
	public $timestamp;
	
	public function __construct($account = null)
	{
		$this->name = $account ?: app()->from_mp_account;
		$this->account = '_' . $this->name;
		
		$mp_account = collect(Config::get('wx_mp_accounts'))->where('name', $this->name)->first();
		
		if(!$mp_account)
		{
			throw new RuntimeException('微信号不存在' . $this->name);
		}
		
		foreach(array_keys((array)$mp_account) as $key)
		{
			$this->$key = $mp_account->$key;
		}
		
		$this->timestamp = time();
		$this->nonce_str = sha1(env('APP_KEY') . $this->timestamp);
	}
	
	/*
	 * 验证来源为微信
	 * 放在用于响应微信消息请求的脚本最上端
	 */
	public function verify($timestamp, $nonce, $signature, $echostr = null)
	{
		$sign = [
			$this->token,
			$timestamp,
			$nonce
		];
		
		sort($sign, SORT_STRING);
		
		if(sha1(implode($sign)) !== $signature)
		{
			exit('Signature verification failed.');
		}
		
		if($echostr)
		{
			$this->message_to_reply = $echostr;
		}

		return $this;
	}
	
	/**
	 * 对curl的一层封装
	 * @param string $url
	 * @param array $data
	 * @param string $method
	 * @param string $type
	 * @return object|string response
	 */
	protected function call($url, $data = null, $method = 'GET', $type = 'form-data')
	{
		if(!is_null($data) && $method === 'GET'){
			$method = 'POST';
		}
		
		switch(strtoupper($method))
		{
			case 'GET':
				$response = file_get_contents($url);
				break;
			case 'POST':
				$ch = curl_init($url);
				
				if($type === 'xml')
				{
					$xml = '<xml>';
					foreach ($data as $key => $value)
					{
						if (is_numeric($value))
						{
							$xml .= '<'.$key.'>' . $value . '</' . $key . '>';
						}
						else
						{
							$xml .= ' <' . $key . '><![CDATA[' . $value . ']]></' . $key . '>';
						}
					}
					$xml .= '</xml>';
					$data = $xml;

					$content_type = 'application/xml';

				}
				elseif($type === 'json')
				{
					$data = json_encode($data, JSON_UNESCAPED_UNICODE);
					$content_type = 'application/json';
				}
				
				curl_setopt_array($ch, [
					CURLOPT_POST => TRUE,
					CURLOPT_RETURNTRANSFER => TRUE,
					CURLOPT_POSTFIELDS => $data,
					CURLOPT_HTTPHEADER => isset($content_type) ? [
						'Content-Type: ' . $content_type
					] : [],
					CURLOPT_SSL_VERIFYHOST => FALSE,
					CURLOPT_SSL_VERIFYPEER => FALSE,
				]);
				$response = curl_exec($ch);

				if(!$response)
				{
					Log::error('[' . str_replace('_', '', $this->account) . '] Weixin API call failed. ' . curl_error($ch) . ' url:' . $url);
				}

				curl_close($ch);
				break;
			default:
				$response = null;
		}

		if(!is_null(json_decode($response)))
		{
			$response = json_decode($response);
		}
		elseif(strpos($response, '<xml') === 0)
		{
			$response = json_decode(json_encode(simplexml_load_string($response, null, LIBXML_NOCDATA)));
		}

		Log::info('[' . str_replace('_', '', $this->account) . '] Weixin API called: ' . $url);

		if(isset($response->errcode) && $response->errcode)
		{
			if($response->errcode === 40001)
			{
				$access_token = $this->getAccessToken(true);
				Log::warning('[' . str_replace('_', '', $this->account) . '] Access token refreshed, will retry.');
				sleep(1);
				parse_str(parse_url($url, PHP_URL_QUERY), $query);
				$old_access_token = $query['access_token'];
				$url = str_replace('access_token=' . $old_access_token, 'access_token=' . $access_token, $url);
				
				return $this->call($url, $data, $method, $type);
			}

			Log::error('[' . str_replace('_', '', $this->account) . '] Weixin API call failed. ' . json_encode($response));
		}

		return $response;
	}
	
	/**
	 * 获得站点到微信的access token
	 * 并缓存于站点数据库
	 * 可以判断过期并重新获取
	 */
	protected function getAccessToken($force_refresh = false)
	{
		$access_token = Config::get('wx_access_token' . $this->account);
		
		if($access_token && !$force_refresh)
		{
			return $access_token;
		}
		
		$query_args = [
			'grant_type'=>'client_credential',
			'appid'=>$this->app_id,
			'secret'=>$this->app_secret
		];
		
		$return = $this->call('https://api.weixin.qq.com/cgi-bin/token?' . http_build_query($query_args));
		
		if(isset($return->access_token))
		{
			Config::set('wx_access_token' . $this->account, $return->access_token, time() + $return->expires_in - 60);
			return $return->access_token;
		}
	}

	public function getCallbackIp()
	{
		$result = $this->call('https://api.weixin.qq.com/cgi-bin/getcallbackip?access_token=' . $this->getAccessToken());
		return $result->ip_list;
	}
	
	/**
	 * 获得关注者openid列表
	 * @param string $next_openid
	 * @return array $openids
	 */
	public function getUserOpenids($next_openid = null)
	{
		$url = 'https://api.weixin.qq.com/cgi-bin/user/get?access_token=' . $this->getAccessToken() . '&next_openid=' . $next_openid;

		$result = $this->call($url);

		if(!isset($result->data))
		{
			return [];
		}

		$openids = $result->data->openid;

		// 将这些openid在系统中标记为已关注
		DB::statement("INSERT IGNORE INTO `profiles` (`user_id`, `key`, `value`, `created_at`) SELECT `user_id`, 'wx_subscribed" . $this->account . "', 'true', NOW() FROM `profiles` WHERE `key` = 'wx_openid" . $this->account . "' AND `value` IN ('" . implode("','", $openids)  . "')", []);

		if($result->next_openid)
		{
			$next_openids = $this->getUserOpenids($result->next_openid);

			if($next_openids)
			{
				$openids = array_merge($openids, $next_openids);
			}
		}

		if(!$next_openid)
		{
			// TODO 取消关注的同步到数据库
		}

		return $openids;
	}

	/**
	 * 直接获得用户信息
	 * 仅在用户与公众账号发生消息交互的时候才可以使用
	 * 换言之仅可用于响应微信消息请求的脚本中
	 * @param string $openid 用户的微信openid，即微信消息中的FromUsername
	 * @param string $lang
	 * @return object
	 */
	public function getUserInfo($openid, $lang = 'zh_CN')
	{
		
		$url = 'https://api.weixin.qq.com/cgi-bin/user/info?';
		
		$query_vars = [
			'access_token'=>$this->getAccessToken(),
			'openid'=>$openid,
			'lang'=>$lang
		];
		
		$url .= http_build_query($query_vars);
		
		$user_info = $this->call($url);

		if(!is_object($user_info))
		{
			Log::error('[' . str_replace('_', '', $this->account) . '] User info parse failed. ' . $user_info);
		}

		return $user_info;
		
	}
	
	/**
	 * 生成OAuth授权地址
	 * @param string $redirect_uri 授权后跳转的网址
	 * @param bool $require_user_info 是否请求微信昵称等详细用户信息，是的话将需要用户授权
	 * @param string $state
	 * @return string
	 */
	public function generateOAuthUrl($redirect_uri, $require_user_info = false, $state = '')
	{
		
		$url = 'https://open.weixin.qq.com/connect/oauth2/authorize?';
		
		$query_args = [
			'appid'=>$this->app_id,
			'redirect_uri'=>$redirect_uri,
			'response_type'=>'code',
			'scope'=>$require_user_info ? 'snsapi_userinfo' : 'snsapi_base',
			'state'=>$state
		];
		
		$url .= http_build_query($query_args) . '#wechat_redirect';
		
		return $url;
	}
	
	/**
	 * 生成授权地址并跳转
	 * @param string $redirect_uri 授权后跳转的网址
	 * @param string $state
	 * @param string $scope
	 */
	public function oauthRedirect($redirect_uri = null, $state = '', $scope = 'snsapi_base')
	{
		if(headers_sent())
		{
			exit('Could not perform an OAuth redirect, headers already sent');
		}
		
		$url = $this->generateOAuthUrl($redirect_uri, $state, $scope);
		
		header('Location: ' . $url);
		exit;
	}
	
	/**
	 * 根据一个OAuth授权请求中的code，获得并存储用户授权信息
	 * @param bool $require_user_info 是否请求微信昵称等详细用户信息，是的话将需要用户授权
	 * @param string $code
	 * @return object
	 */
	public function getOAuthInfo($code, $require_user_info = false)
	{
		$url = 'https://api.weixin.qq.com/sns/oauth2/access_token?';
		
		$query_args = [
			'appid'=>$this->app_id,
			'secret'=>$this->app_secret,
			'code'=>$code,
			'grant_type'=>'authorization_code'
		];

		$auth_result = $this->call($url . http_build_query($query_args));

		if(isset($auth_result->errcode))
		{
			Log::warning('[' . str_replace('_', '', $this->account) . '] Invalid OAuth code ' . $code . ', will retry.');
			redirect($this->generateOAuthUrl(url()->full(), $require_user_info));exit;
		}
		
		$auth_result->expires_at = $auth_result->expires_in + time();
		
		return $auth_result;
	}
	
	/**
	 * 刷新用户OAuth access token
	 * 通常不应直接调用此方法，而应调用getOAuthInfo()
	 * @deprecated 由于每次失去cookie就重新请求认证，因此这个方法目前用不到
	 * @param string $refresh_token
	 * @return object
	 */
	protected function refreshOAuthToken($refresh_token)
	{
		
		$url = 'https://api.weixin.qq.com/sns/oauth2/refresh_token?';
		
		$query_args = [
			'appid'=>$this->app_id,
			'grant_type'=>'refresh_token',
			'refresh_token'=>$refresh_token,
		];
		
		$url .= http_build_query($query_args);
		
		$auth_result = $this->call($url);
		
		return $auth_result;
	}
	
	/**
	 * OAuth方式获得用户信息
	 * 注意，access token的scope必须包含snsapi_userinfo，才能调用本函数获取
	 * @param string $lang
	 * @return object
	 */
	public function getUserInfoOAuth($code = null, $access_token = null, $openid = null, $lang = 'zh_CN')
	{
		
		$url = 'https://api.weixin.qq.com/sns/userinfo?';

		if($code)
		{
			$auth_info = $this->getOAuthInfo($code, true);
			$access_token = $auth_info->access_token;
			$openid = $auth_info->openid;
		}

		$query_vars = [
			'access_token'=>$access_token,
			'openid'=>$openid,
			'lang'=>$lang
		];
		
		$url .= http_build_query($query_vars);
		
		$user_info = $this->call($url);
		
		return $user_info;
	}

	/**
	 * 生成一个带参数二维码的信息，并存入Config模型
	 * @param string|array|object $scene_name 场景名称, 场景数据
	 * @param bool $is_temporary 是否为永久二维码，默认为永久
	 * @param int $expires_in 临时二维码的过期时间，默认为最长的7天
	 * @return array 二维码信息，包括获取的URL和有效期等
	 */
	public function generateQrCode($scene_name = '', $is_temporary = false, $expires_in = 518400)
	{
		$url = 'https://api.weixin.qq.com/cgi-bin/qrcode/create?access_token=' . $this->getAccessToken();
		
		$last_scene_id_config = Config::firstOrCreate(['key'=>'wx_last' . ($is_temporary ? '' : '_limit') . '_qrcode_scene_id' . $this->account]);
		!$last_scene_id_config->value && $last_scene_id_config->value = $is_temporary ? 1e5 : 0;
		$last_scene_id_config->value ++;
		
		if(!$is_temporary && $last_scene_id_config->value > 100000)
		{
			$last_scene_id_config->value = 1; // 强制重置
		}
		
		$scene_id = $last_scene_id_config->value;
		
		$data = [
			'action_name'=>$is_temporary ? 'QR_SCENE' : 'QR_LIMIT_SCENE',
			'action_info'=>['scene'=>['scene_id'=>$scene_id]],
		];
		
		if($is_temporary)
		{
			$data['expire_seconds'] = $expires_in;
		}
		
		$response = $this->call($url, $data, 'POST', 'json');
		
		if(!property_exists($response, 'ticket'))
		{
			return $response;
		}
		
		$qrcode = (object)[
			'url'=>'https://mp.weixin.qq.com/cgi-bin/showqrcode?ticket=' . urlencode($response->ticket),
			'ticket'=>$response->ticket,
			'scene_name'=>is_string($scene_name) ? $scene_name : '',
			'scene_data'=>is_string($scene_name) ? (object)[] : $scene_name,
		];
		
		if($is_temporary)
		{
			$qrcode->expires_at = time() + $response->expire_seconds;
		}
		
		$config_item = Config::set('wx_qrscene_' . $scene_id . $this->account, $qrcode, isset($qrcode->expires_at) ? $qrcode->expires_at : null);
		$last_scene_id_config->save();
		return $config_item;
	}
	
	/**
	 * 删除微信公众号会话界面菜单
	 */
	public function removeMenu()
	{
		$url = 'https://api.weixin.qq.com/cgi-bin/menu/delete?access_token=' . $this->getAccessToken();
		return $this->call($url);
	}
	
	/**
	 * 创建微信公众号会话界面菜单
	 * @param array|object $data
	 * @return object
	 */
	public function createMenu($data, $match_rule = null)
	{
		if(!$match_rule)
		{
			$url = 'https://api.weixin.qq.com/cgi-bin/menu/create?access_token=' . $this->getAccessToken();
		}
		else
		{
			$url = 'https://api.weixin.qq.com/cgi-bin/menu/addconditional?access_token=' . $this->getAccessToken();
		}

		$ch = curl_init($url);

		if($match_rule)
		{
			$data->matchrule = (object)$match_rule;
		}

		curl_setopt_array($ch, [
			CURLOPT_POST => TRUE,
			CURLOPT_RETURNTRANSFER => TRUE,
			CURLOPT_HTTPHEADER => [
				'Content-Type: application/json'
			],
			CURLOPT_POSTFIELDS => json_encode($data, JSON_UNESCAPED_UNICODE)
		]);
		
		$response = json_decode(curl_exec($ch));
		
		return $response;
		
	}
	
	/**
	 * 获得微信公众号会话界面菜单
	 */
	public function getMenu()
	{
		$menu = $this->call('https://api.weixin.qq.com/cgi-bin/menu/get?access_token=' . $this->getAccessToken());
		return $menu;
	}

	public function initMessage()
	{
		$this->onMessage('', function(){});
	}
	
	/**
	 * 仿收到消息时间触发回调
	 * 至少在微信响应页面内调用一次，否则无法正确处理默认响应
	 * @param string|array $type MsgType, or array(MsgType, Event)
	 * @param Closure $callback
	 * @return $this|null
	 */
	public function onMessage($type, Closure $callback)
	{
		$message_raw = (object) (array) simplexml_load_string(Request::getContent(), 'SimpleXMLElement', LIBXML_NOCDATA);

		$this->message_raw = $message_raw;

		// 一次请求内首次调用此方法，存message
		if(is_null($this->message))
		{
			Log::info('[' . str_replace('_', '', $this->account) . '] 收到微信消息：' . json_encode($message_raw, JSON_UNESCAPED_UNICODE));
			
			if(!property_exists($message_raw, 'FromUserName'))
			{
				Log::error('[' . str_replace('_', '', $this->account) . '] 收到的微信消息没有openid');
				return;
			}

			// 尝试用openid获得已有用户信息
			$openid_profile_existed = Profile::where('key', 'wx_openid' . $this->account)->where('value', $message_raw->FromUserName)->first();

			if($openid_profile_existed)
			{
				$this->user = $openid_profile_existed->user;
			}
			// 尝试用unionid获得已有用户信息
			else
			{
				Log::info('[' . str_replace('_', '', $this->account) . '] 未找到openid' . $this->account . ': ' . $message_raw->FromUserName . '的信息，尝试查找unionid。');
				$user_info = $this->getUserInfo($message_raw->FromUserName);
				if(isset($user_info->unionid))
				{
					$this->user = User::where('wx_unionid', $user_info->unionid)->first();
				}
				else
				{
					Log::error('[' . str_replace('_', '', $this->account) . '] UnionID not found. ' . json_encode($user_info));
				}
			}
			
			// 准备创建新用户
			if(is_null($this->user) && (!isset($message_raw->Event) || !in_array($message_raw->Event, ['unsubscribe', 'MASSSENDJOBFINISH'])))
			{
				Log::info('[' . str_replace('_', '', $this->account) . '] 未找到openid' . $this->account . ': ' . $message_raw->FromUserName . '的信息，创建新用户。');
				$this->user = new User();
			}
			
			// 若是新用户，或已有用户信息不全，补全用户信息
			if($this->user && (!$this->user->name || $this->user->getProfile('wx_openid' . $this->account) !== $message_raw->FromUserName))
			{
				Log::info('[' . str_replace('_', '', $this->account) . '] openid' . $this->account . ': ' . $message_raw->FromUserName . '的微信资料不全，正在补全。');
				
				if(!isset($user_info))
				{
					$user_info = $this->getUserInfo($message_raw->FromUserName);
				}
				
				if(property_exists($user_info, 'nickname'))
				{
					$this->user->fill([
						'name'=>$user_info->nickname,
						'address'=>$user_info->province . ' ' . $user_info->city,
						'gender'=>$user_info->sex,
						'avatar'=>$user_info->headimgurl,
					]);

					if (isset($user_info->unionid))
					{
						$this->user->fill([
							'wx_unionid'=>$user_info->unionid
						]);
					}

					$this->user->save();
				}
				
				$this->user->setProfile('wx_openid' . $this->account, $message_raw->FromUserName);
			}

			if(!property_exists($message_raw, 'Event') || !in_array($message_raw->Event, ['TEMPLATESENDJOBFINISH', 'unsubscribe', 'MASSSENDJOBFINISH']))
			{
				$this->user->last_active_at = date('Y-m-d H:i:s', $message_raw->CreateTime);
			}

//			if(property_exists($message_raw, 'Event') && $message_raw->Event === 'LOCATION')
//			{
//				$this->user->latitude = $message_raw->Latitude;
//				$this->user->longitude = $message_raw->Longitude;
//				$this->user->precision = $message_raw->Precision;
//			}

			if(!property_exists($message_raw, 'Event') || $message_raw->Event !== 'unsubscribe')
			{
				$this->user->save();
			}
			
			app()->user = $this->user;

			$this->message = new Message();

			$this->message->fill([
				'type'=>$message_raw->MsgType,
				'mp_account'=>substr($this->account, 1),
				'event'=>property_exists($message_raw, 'Event') ? $message_raw->Event : '',
				'meta'=>$message_raw
			]);

			$this->message->sender()->associate($this->user);

			try
			{
				$this->message->save();
				
				if($this->message->type !== 'event' || in_array($this->message->event, ['SCAN', 'VIEW', 'CLICK', 'subscribe', 'merchant_order', 'unsubscribe']))
				{
					ActionLog::create([], '微信' . $this->message->action_name, 'mp_account' . $this->account);
				}
			}
			catch(RuntimeException $exception)
			{
				// A "Dupulicate Entry" exception is considered normal here
				if(!str_contains($exception->getMessage(), 'Duplicate entry'))
				{
					throw $exception;
				}
			}
			
			if($this->message->event === 'subscribe')
			{
				$this->user->setProfile('wx_subscribed' . $this->account, true, 'private');
				$this->user->load('profiles');
				$this->user->promote('subscribe');

				if($this->message->event_key)
				{
					$source_config = Config::get('wx_' . $this->message->event_key . $this->account);
					
					if(!$source_config || !property_exists($source_config, 'scene_name'))
					{
						Log::warning('[' . str_replace('_', '', $this->account) . '] ' . $this->message->event_key . $this->account . ' 不是可用的场景');
					}
					else
					{
						$this->user->source = $source_config->scene_name;
						$this->user->save();
					}
				}

				if($this->supports('template_message') && $message_to_receive = $this->user->getProfile('messages_to_receive'))
				{
					$this->user->setProfile('messages_to_receive', null);
					
					if(is_array($message_to_receive))
					{
						foreach($message_to_receive as $message)
						{
							$this->user->sendMessage($message->slug, $message->url, $message->data);
						}
					}
				}
			}
			elseif($this->message->event === 'unsubscribe')
			{
				$this->user->setProfile('wx_subscribed' . $this->account, false);
				$this->user->promote('subscribe', [], true);
			}
			elseif($this->message->event === 'SCAN')
			{
				if($this->user->is_fake)
				{
					$qrcode = Config::get('wx_qrscene_' . $this->message->event_key . $this->account);
					$this->sendServiceMessage($this->user, '二维码参数：' . (isset($qrcode->scene_name) ? $qrcode->scene_name . ' ' : '') . 'wx_qrscene_' . $this->message->event_key . $this->account);
				}
			}

			// 消息自动回复

			// 关注自动回复
			if($this->message->content === '关注回复' || $this->message->event === 'subscribe')
			{
				return $this->replyMessage(Config::get('wx_welcome_message' . $this->account));
			}

			// 自定义规则自动回复
			foreach(Config::get('wx_auto_reply' . $this->account) ?: [] as $auto_reply)
			{
				if(empty($auto_reply->rule) || empty($auto_reply->reply) || !$this->matchMessage($auto_reply->rule))
				{
					continue;
				}

				if(isset($auto_reply->reply->text))
				{
					return $this->replyMessage($auto_reply->reply->text);
				}

				if(isset($auto_reply->reply->posts) && is_array($auto_reply->reply->posts))
				{
					$this->replyPostMessage(array_map(function($post_id)
					{
						return Post::find($post_id);
					},
					$auto_reply->reply->posts));
				}

				if(isset($auto_reply->reply->service) && is_array($auto_reply->reply->service))
				{
					$this->message_replied_as_service = true;
					
					foreach($auto_reply->reply->service as $service_message)
					{
						if(is_string($service_message))
						{
							$type = 'text'; $content = $service_message;
						}
						else
						{
							$content = $service_message->content;
							$type = $service_message->type;
						}
						
						if(isset($auto_reply->reply->delay))
						{
							$job = new SendServiceMessage($this->user, $type, $content);
							$this->dispatch($job->delay($auto_reply->reply->delay));
						}
						else
						{
							$this->sendServiceMessage($this->user, $content, $type);
						}
					}
				}

				if(isset($auto_reply->profiles))
				{
					foreach((array)$auto_reply->profiles as $key => $value)
					{
						$this->user->setProfile($key, $value);

						if(strpos($key, 'attend_') === 0 && $event = Post::find(str_replace('attend_', '', $key)))
						{
							$event->attendees()->attach($this->user, ['info'=>$auto_reply->rule]);
							$event->save();
						}
					}
				}
			}
		}
		
		if(!$this->matchMessage($type))
		{
			return;
		}
		
		$callback($this->message, $this->user);
		
		return $this;
	}

	private function matchMessage($condition)
	{
		// 'text'
		if($condition === 'text' && $this->message_raw->MsgType === 'text')
		{
			return true;
		}

		// '{regex}'
		if(is_string($condition) && $this->message_raw->MsgType === 'text' && preg_match('/' . $condition . '/', $this->message_raw->Content))
		{
			return true;
		}

		// 'qrscene_{id}'
		if(is_string($condition) && isset($this->message_raw->Event) && in_array($this->message_raw->Event, ['subscribe', 'SCAN']) && str_replace('qrscene_', '', $this->message_raw->EventKey) === str_replace('qrscene_', '', $condition))
		{
			return true;
		}
		
		// 'click_{key}'
		if(is_string($condition) && isset($this->message_raw->Event) && $this->message_raw->Event === 'CLICK' && $this->message_raw->EventKey === preg_replace('/^click_/', '', $condition))
		{
			return true;
		}
		
		// ['event', '{event_name}']
		if(is_array($condition) && isset($condition[0]) && $condition[0] === 'event' && isset($this->message_raw->Event) && strtolower($condition[1]) === strtolower($this->message_raw->Event))
		{
			return true;
		}

		if(is_array($condition) && !is_integer(array_keys($condition)[0]))
		{
			$condition = (object) $condition;
		}

		// ['text'=>'{regex}']
		if(isset($condition->text) && isset($this->message_raw->Content) && preg_match('/' . $condition->text . '/', $this->message_raw->Content))
		{
			return true;
		}

		// ['event'=>'{event_name}']
		if(isset($condition->event) && isset($this->message_raw->Event) && strtolower($condition->event) === strtolower($this->message_raw->Event))
		{
			return true;
		}

		// [{condition}, {condition}]
		if(is_array($condition) && is_array($condition[0]) && array_reduce($condition, function($previous, $current){return $previous || $this->matchMessage($current);}, false))
		{
			return true;
		}

		return false;
	}

	public function replyMessage($content = null, $type = 'text')
	{
		if(!$content)
		{
			if(!$this->message_to_reply && !$this->message_replied_as_service && $this->message->type === 'text' && Config::get('wx_default_reply' . $this->account))
			{
				$this->replyMessage(Config::get('wx_default_reply' . $this->account));
			}
			
			return $this->message_to_reply;
		}
		if(!$content || $this->message_to_reply)
		{
			return null;
		}

		$received_message = $this->message_raw;
		$mp_account = substr($this->account, 1);
		
		switch($type)
		{
			case 'image':
				$this->message_to_reply = view('weixin.message-reply-image', compact('content', 'received_message', 'mp_account'));
				break;
			case 'text':
			default:
			Log::info('[' . str_replace('_', '', $this->account) . '] 向用户' . $this->user->id . ' ' . $this->user->name . ' 发送了消息: ' . json_encode($content, JSON_UNESCAPED_UNICODE));
			$this->message_to_reply = view('weixin.message-reply-text', compact('content', 'received_message', 'mp_account'));
		}
		
		return null;
	}

	public function replyPostMessage($reply_posts)
	{
		if($this->message_to_reply)
		{
			return;
		}

		if(!is_array($reply_posts))
		{
			$reply_posts = [$reply_posts];
		}

		$reply_posts = array_filter($reply_posts, function($post)
		{
			return isset($post->id);
		});

		$reply_posts_count = count($reply_posts);

		$received_message = $this->message_raw;
		
		$mp_account = substr($this->account, 1);

		$this->message_to_reply = view('weixin/message-reply-news', compact('reply_posts_count', 'received_message', 'reply_posts', 'mp_account'));
	}

	public function transferCustomerService()
	{
		$received_message = $this->message_raw;
		$this->message_to_reply = view('weixin/transfer-customer-service', ['fromUser'=>$received_message->fromUserName]);
	}
	
	/**
	 * 发送客服消息
	 * @param User $to_user
	 * @param string $contents
	 * @param string $type
	 * @return bool
	 */
	public function sendServiceMessage($to_user, $contents, $type = 'text')
	{
		Log::info('[' . str_replace('_', '', $this->account) . '] 即将向用户' . $to_user->id . ' ' . $to_user->name . ' 发送客服消息: ' . json_encode($contents, JSON_UNESCAPED_UNICODE));
		
		if(!$to_user->last_active_at || $to_user->last_active_at->timestamp < time() - 3600 * 48){
			Log::warning('[' . str_replace('_', '', $this->account) . '] ' . $to_user->name . ' 已超过48小时未活动，客服消息发送失败');
			return false;
		}
		
		if(!$to_user->getProfile('wx_subscribed' . $this->account))
		{
			Log::warning('[' . str_replace('_', '', $this->account) . '] 用户' . $to_user->id . ' ' . $to_user->name . '没有关注' . str_replace('_', '', $this->account) . ', 无法发送客服消息');
			return false;
		}

		if(!$to_user->getProfile('wx_openid' . $this->account))
		{
			Log::error('[' . str_replace('_', '', $this->account) . '] 用户' . $to_user->id . ' ' . $to_user->name . '没有openid' . $this->account . ', 无法发送客服消息');
			return false;
		}
		
		$openid = $to_user->getProfile('wx_openid' . $this->account);
		
		$data = ['touser'=>$openid, 'msgtype'=>$type];
		switch($type)
		{
			case 'image':
				if(URL::isValidUrl($contents))
				{
					$contents = Config::get('wx_media_id_' . md5($contents));
					
					if(!$contents)
					{
						$media = $this->uploadMedia($contents);
						$contents = $media->media_id;
						Config::set('wx_media_id_' . md5($contents), $contents, time() + 86400 * 3);
					}
				}
				
				$data['image']['media_id'] = $contents;
				break;
			case 'text':
			default:
				$data['text']['content'] = $contents;
				break;
		}
		
		$result = $this->call('https://api.weixin.qq.com/cgi-bin/message/custom/send?access_token=' . $this->getAccessToken(), $data, 'POST', 'json');

		if(!$result->errcode)
		{
			Log::info('[' . str_replace('_', '', $this->account) . '] 向用户' . $to_user->id . ' ' . $to_user->name . ' 发送了客服消息: ' . json_encode($contents, JSON_UNESCAPED_UNICODE));
			return true;
		}
		
		Log::warning('[' . str_replace('_', '', $this->account) . '] 向用户' . $to_user->id . ' ' . $to_user->name . ' 客服消息发送失败' . json_encode($result));
		
		return false;
	}
	
	/**
	 * 获得供后端使用JSAPI密钥
	 * @return object
	 */
	public function getJsapiTicket(){
		
		$jsapi_ticket = Config::get('wx_jsapi_ticket' . $this->account);
		
		if(!$jsapi_ticket)
		{
			$access_token = $this->getAccessToken();
			$jsapi_ticket = $this->call('https://api.weixin.qq.com/cgi-bin/ticket/getticket?access_token=' . $access_token . '&type=jsapi');
			Config::set('wx_jsapi_ticket' . $this->account, $jsapi_ticket, $jsapi_ticket->expires_in + time() - 60);
		}
		
		return $jsapi_ticket;
	}
	
	/**
	 * 获得供前端使用的JSAPI签名
	 */
	public function generateJsapiSign()
	{
		$sign_data = [
			'noncestr'=>$this->nonce_str,
			'jsapi_ticket'=>$this->getJsapiTicket()->ticket,
			'timestamp'=>$this->timestamp,
			'url'=>URL::previous()
		];
		
		ksort($sign_data, SORT_STRING);
		
		$sign_string = urldecode(http_build_query($sign_data));
		$sign = sha1($sign_string);
		
		return $sign;
	}
	
	/**
	 * 获得模板消息ID
	 * 一般不使用，因为微信后台可以获得
	 * @param string $template_id_short
	 */
	public function getTemplateMessageId($template_id_short)
	{
		$url = 'https://api.weixin.qq.com/cgi-bin/template/api_add_template?access_token=' . $this->getAccessToken();
		$result = $this->call($url, ['template_id_short'=>$template_id_short], 'POST', 'json');
		
		if(!property_exists($result, 'template_id'))
		{
			Log::error('[' . str_replace('_', '', $this->account) . '] 获得模板消息' . $template_id_short . ' ID失败 ' . json_encode($result));
			return;
		}
		
		return $result->template_id;
	}
	
	public function getTemplateList()
	{
		$url = 'https://api.weixin.qq.com/cgi-bin/template/get_all_private_template?access_token=' . $this->getAccessToken();
		$result = $this->call($url);
		if(isset($result->template_list))
		{
			return $result->template_list;
		}
	}
	
	/**
	 * 发送模板消息
	 * @param User|int $user_or_user_id 接受消息的用户模型或用户ID
	 * @param string $template_id_or_slug 模版ID或模板代号，在Config模型中寻找键名为wx_template_id_{$$template_id_or_slug}的值
	 * @param string $url 模板消息的链接，空字符串表示无链接
	 * @param object|array $data
	 * @param string $top_color
	 * @return mixed
	 */
	public function sendTemplateMessage($user_or_user_id, $template_id_or_slug, $url = null, $data = [], $top_color = '#1C80BF')
	{
		if(ctype_digit($user_or_user_id))
		{
			$user = User::find($user_or_user_id);
		}
		else
		{
			$user = $user_or_user_id;
		}
		
		if(!$user || !$user instanceof User || !$user->exists)
		{
			return null;
		}
		
		Log::info('[' . str_replace('_', '', $this->account) . '] 即将向用户' . $user->id . ' ' . $user->name . ' 发送模板消息 ' . $template_id_or_slug . ' ' . $url . ' ' . json_encode($data, JSON_UNESCAPED_UNICODE));
		
		if(!$this->supports('template_message'))
		{
			Log::warning('[' . substr($this->account, 1) . '] 不支持模板消息, 无法发送');
			return null;
		}

		if(is_object($data))
		{
			$data = (array) $data;
		}
		
		$api = 'https://api.weixin.qq.com/cgi-bin/message/template/send?access_token=' . $this->getAccessToken();
		
		foreach($data as $key => &$value)
		{
			if(is_object($value))
			{
				$value = (array) $value;
			}

			if(!is_array($value))
			{
				$value = [
					'value'=>$value,
				];
			}
			
			if($key === 'first')
			{
				$value['value'] = trim($value['value']) . "\n";
			}
			
			if($key === 'remark')
			{
				$value['value'] = "\n" . trim($value['value']);
			}
			
			if(!isset($value['color']))
			{
				if($key === 'first')
				{
					$value['color'] = '#888888';
				}
				elseif($key === 'remark')
				{
					$value['color'] = '#AA0000';
				}
				else
				{
					$value['color'] = '#1C80BF';
				}
			}
		}
		
		if(!$user->getProfile('wx_subscribed' . $this->account))
		{
			Log::warning('[' . str_replace('_', '', $this->account) . '] 用户' . $user->id . ' ' . $user->name . '没有关注公众号, 无法发送模板消息');
			return null;
		}

		if(!$user->getProfile('wx_openid' . $this->account))
		{
			Log::error('[' . str_replace('_', '', $this->account) . '] 用户' . $user->id . ' ' . $user->name . '没有openid' . $this->account . ', 无法发送模板消息');
			return null;
		}
		
		if(strlen($template_id_or_slug) === 43)
		{
			$template_id = $template_id_or_slug;
		}
		else
		{
			$template_id = Config::get('wx_template_id_' . $template_id_or_slug . $this->account);
		}
		
		if(!$template_id)
		{
			Log::error('[' . str_replace('_', '', $this->account) . '] 获得模板消息' . $template_id_or_slug . '的ID失败，配置表中未找到');
			return null;
		}

		// TODO 在URL后拼上from-mp-account

		$result = $this->call($api, [
			'touser'=>$user->getProfile('wx_openid' . $this->account),
			'template_id'=>$template_id,
			'url'=>$url,
			'topcolor'=>$top_color,
			'data'=>$data
		], 'POST', 'json');
		
		if(empty($result))
		{
			Log::debug('向用户' . $user->id . ' ' . $user->name . ' 发送模板消息失败, 寄存稍后发送');
			$user->setProfile('messages_to_receive', array_merge($user->getProfile('messages_to_receive') ?: [], [['slug'=>$template_id_or_slug, 'url'=>$url, 'data'=>$data]]));
		}
		
		Log::info('[' . str_replace('_', '', $this->account) . '] 向用户' . $user->id . ' ' . $user->name . ' 发送了模板消息 ' . $template_id_or_slug . ' ' . $url . ' ' . json_encode($data, JSON_UNESCAPED_UNICODE));

		sleep(1);

		return $result && isset($result->errcode) && $result->errcode === 0;
	}

	/**
	 * 下载媒体文件
	 * @param string $media_id
	 * @return string $path
	 */
	public function downloadMedia($media_id)
	{
		$path = storage_path('uploads/' . sha1($media_id));
		$content = $this->call('http://file.api.weixin.qq.com/cgi-bin/media/get?access_token=' . $this->getAccessToken() . '&media_id=' . $media_id);
		
		if(strpos($content, '{') === 0 && $message = json_decode($content))
		{
			throw new RuntimeException('下载微信媒体文件错误 ' . json_encode($message, JSON_UNESCAPED_UNICODE));
		}
		
		file_put_contents($path, $content);
		
		return $path;
	}

	/**
	 * 获得微信媒体文件下载链接
	 * 注意: 链接有效期受access_token时效限制
	 * 把access_token暴露给用户是不推荐的做法
	 * @param $media_id
	 * @return string
	 */
	public function getMediaUrl($media_id)
	{
		return 'http://file.api.weixin.qq.com/cgi-bin/media/get?access_token=' . $this->getAccessToken() . '&media_id=' . $media_id;
	}

	/**
	 * 上传媒体文件
	 * @param $path
	 * @param $type
	 */
	public function uploadMedia($path, $type = 'image')
	{
		if(URL::isValidUrl($path))
		{
			$url = $path;
			$path = storage_path('uploads/' . md5($url));
			$file_contents = file_get_contents($url);
			file_put_contents($path, $file_contents);
			$guesser = ExtensionGuesser::getInstance();
			$extension = $guesser->guess(File::mimeType($path));
			File::move($path, $path . '.' . $extension);
			$path = $path . '.' . $extension;
		}

		$data = ['media'=>curl_file_create($path, File::mimeType($path))];

		$media = $this->call('https://api.weixin.qq.com/cgi-bin/media/upload?access_token=' . $this->getAccessToken() . '&type=' . $type, $data);

		return $media;
	}

	public function createUserGroup($name)
	{
		$result = $this->call('https://api.weixin.qq.com/cgi-bin/groups/create?access_token=' . $this->getAccessToken(), ['group'=>['name'=>$name]], 'POST', 'json');
		return $result->group;
	}

	public function getUserGroups()
	{
		$result = $this->call('https://api.weixin.qq.com/cgi-bin/groups/get?access_token=' . $this->getAccessToken());
		return $result->groups;
	}

	public function updateUserGroup($user, $to_group_name)
	{
		if(!$this->user_groups)
		{
			$this->user_groups = collect($this->getUserGroups());
		}

		$to_group = $this->user_groups->where('name', $to_group_name)->first();

		if(!$to_group)
		{
			$to_group = $this->createUserGroup($to_group_name);
			$this->user_groups->push($to_group);
		}

		$openid = $user->getProfile('wx_openid' . $this->account);

		if(!$openid)
		{
			Log::error('[' . str_replace('_', '', $this->account) . '] 用户' . $user->id . ' ' . $user->name . ' 没有openid' . $this->account . ', 无法移动分组');
			return;
		}

		return $this->call('https://api.weixin.qq.com/cgi-bin/groups/members/update?access_token=' . $this->getAccessToken(), [
			'openid'=>$openid,
			'to_groupid'=>$to_group->id
		]);
	}

	public function updateUsersGroup($users, $to_group_name)
	{
		if(is_array($users))
		{
			$users = collect($users);
		}

		if($users->count() > 50)
		{
			foreach($users->chunk(50) as $users_chunk)
			{
				$this->updateUsersGroup($users_chunk, $to_group_name);
			}
			return;
		}

		if(!$this->user_groups)
		{
			$this->user_groups = collect($this->getUserGroups());
		}

		$to_group = $this->user_groups->where('name', $to_group_name)->first();

		if(!$to_group)
		{
			$to_group = $this->createUserGroup($to_group_name);
			$this->user_groups->push($to_group);
		}

		$openids = [];
		foreach($users as $user)
		{
			$openid = $user->getProfile('wx_openid' . $this->account);

			if(!$openid)
			{
				Log::error('[' . str_replace('_', '', $this->account) . '] 用户' . $user->id . ' ' . $user->name . ' 没有openid' . $this->account . ', 无法移动分组');
				continue;
			}

			$openids[] = $openid;
		};

		$this->call('https://api.weixin.qq.com/cgi-bin/groups/members/batchupdate?access_token=' . $this->getAccessToken(), [
			'openid_list'=>$openids,
			'to_groupid'=>$to_group->id
		], 'POST', 'json');
	}
	
	/**
	 * 通过环境变量等方式判断微信号是否支持某项功能
	 * @param string $function	可以是 template_message 等
	 * @return bool
	 */
	public function supports($function)
	{
		return in_array($function, $this->support_functions);
	}

	public function getInvitationCardMediaId($event, $user)
	{
		$invitation_card_media_id = Config::get('invitation_card_media_id_event_' . $event->id  . '_user_' . $user->id);

		if(!$invitation_card_media_id)
		{
			Log::info('[' . str_replace('_', '', $this->account) . '] 正在为用户' . $user->id . ' ' . $user->name . ' 准备 ' . $event->id . ' ' . $event->title . ' 的邀请函');
			$invitation_card_local_path = storage_path('uploads/' . md5($event->getMeta('invitation_cover_path')));
			
			if(!File::exists($invitation_card_local_path))
			{
				(new Client())->get($event->getMeta('invitation_cover_path'), ['sink' => $invitation_card_local_path]);
				Log::info('邀请函背景下载完成');
			}
			
			$image_invitation_card = Image::make($invitation_card_local_path);
			
			// 将二维码拼入邀请函
			$qr_code_config_item = Config::where('value', 'like', '%"name":"invitation"%')->where('value', 'like', '%"event_id":' . $event->id . ',%')->where('value', 'like', '%"user_id":' . $user->id . '}%')->first();

			if(!$qr_code_config_item)
			{
				Log::info('[' . str_replace('_', '', $this->account) . '] 正在为用户' . $user->id . ' ' . $user->name . ' 生成邀请活动 ' . $event->id . ' ' . $event->title . ' 的二维码');
				$qr_code_config_item = $this->generateQrCode(['name'=>'invitation', 'event_id'=>(int)$event->id, 'user_id'=>(int)$user->id], true);
			}
			
			Log::info('[' . str_replace('_', '', $this->account) . '] 正在为用户' . $user->id . ' ' . $user->name . ' 下载邀请活动 ' . $event->id . ' ' . $event->title . ' 的二维码');
			$image_qrcode = Image::make($qr_code_config_item->value->url);
			
			Log::info('[' . str_replace('_', '', $this->account) . '] 正在为用户' . $user->id . ' ' . $user->name . ' 下载头像 ' . $user->avatar);
			$avatar_path = storage_path('uploads/' . md5($user->avatar));
			(new Client())->get($user->avatar, ['sink' => $avatar_path]);
			$image_avatar = Image::make($avatar_path);
			
			$image_invite_card_path = storage_path('uploads/' . md5($event->id . '-invitation-' . $user->id) . '.jpg');
			
			$image_invitation_card->text(mb_strstripmb4($user->name), 175, 225, function(Font $font)
			{
				$font->file(env('FONT_PATH') . 'msyh.ttf');
				$font->size(36);
				$font->color([255, 105, 64]);
			})
			->insert($image_avatar->resize(100, 100), 'top-left', 53, 193)
			->insert($image_qrcode->resize(250, 250), 'top-left', 195, 755)
			->save($image_invite_card_path);

			Log::info('[' . str_replace('_', '', $this->account) . '] 正在为用户' . $user->id . ' ' . $user->name . ' 上传邀请活动 ' . $event->id . ' ' . $event->title . ' 的邀请函');
			$media = $this->uploadMedia($image_invite_card_path);
			Config::set('invitation_card_media_id_event_' . $event->id  . '_user_' . $user->id, $media->media_id, $media->created_at + 86400 * 3);
			$invitation_card_media_id = $media->media_id;
		}

		return $invitation_card_media_id;
	}

	public function generatePaySign(array $data)
	{
		unset($data['sign']);
		$data = array_filter($data);
		ksort($data, SORT_STRING);
		$string1 = urldecode(http_build_query($data));
		$string2 = $string1 . '&key=' . $this->mch_key;
		return strtoupper(md5($string2));
	}

	/**
	 * 统一支付接口,可接受 JSAPI/NATIVE/APP下预支付订单,返回预支付订单号。 NATIVE支付返回二维码 code_url。
	 * @param string $order_id
	 * @param float $total_price
	 * @param string $openid
	 * @param string $notify_url
	 * @param string $order_name
	 * @param string $trade_type
	 * @param string $attach
	 */
	public function unifiedOrder($order_id, $total_price, $openid, $notify_url, $order_name, $trade_type = 'JSAPI', $attach = ' '){

		$url = 'https://api.mch.weixin.qq.com/pay/unifiedorder';

		$args = [
			'appid'=>$this->app_id,
			'mch_id'=>$this->mch_id,
			'nonce_str'=>rand(1E15, 1E16-1),
			'body'=>str_limit($order_name, 42),
			'attach'=>$attach,
			'out_trade_no'=>$order_id,
			'total_fee'=>round($total_price * 100),
			'spbill_create_ip'=>$_SERVER['REMOTE_ADDR'],
			'time_start'=>date('YmdHis'),
			'notify_url'=>$notify_url,
			'trade_type'=>$trade_type,
			'openid'=>$openid
		];

		$args['sign'] = $this->generatePaySign($args);

		$query_data = array_map(function($value){return (string) $value;}, $args);

		$response = $this->call($url, $query_data, 'POST', 'xml');

		if($response->return_code === 'SUCCESS' && $response->result_code === 'SUCCESS')
		{
			return $trade_type === 'JSAPI' ? $response->prepay_id : $response->code_url;
		}
		else
		{
			throw new RuntimeException('payment failed ' . json_encode($response, JSON_UNESCAPED_UNICODE));
		}
	}

	/**
	 * 生成支付接口参数，供前端调用
	 * @param string $notify_url 支付结果通知url
	 * @param string $order_id 订单号，必须唯一
	 * @param int $total_price 总价，单位为分
	 * @param string $order_name 订单名称
	 * @param string $attach 附加信息，将在支付结果通知时原样返回
	 * @return array
	 */
	public function generateJsPayArgs($prepay_id){

		$args = [
			'appId'=>$this->app_id,
			'timeStamp'=>time(),
			'nonceStr'=>rand(1E15, 1E16-1),
			'package'=>'prepay_id=' . $prepay_id,
			'signType'=>'MD5'
		];

		$args['paySign'] = $this->generatePaySign($args);

		return array_map(function($value){return (string) $value;}, $args);
	}

	/**
	 * 生成微信收货地址共享接口参数，供前端调用
	 * @return array
	 */
	public function generateJsEditAddressArgs(){

		$args = [
			'appId'=>(string) $this->app_id,
			'scope'=>'jsapi_address',
			'signType'=>'sha1',
			'addrSign'=>'',
			'timeStamp'=>(string) time(),
			'nonceStr'=>(string) rand(1E15, 1E16-1)
		];

		$sign_args = [
			'appid'=>$this->app_id,
			'url'=>"http://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]",
			'timestamp'=>$args['timeStamp'],
			'noncestr'=>$args['nonceStr'],
			'accesstoken'=>$this->get_oauth_token($_GET['code'])->access_token
		];

		ksort($sign_args, SORT_STRING);
		$string1 = urldecode(http_build_query($sign_args));

		$args['addrSign'] = sha1($string1);

		return $args;

	}
}
