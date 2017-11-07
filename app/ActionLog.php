<?php namespace App;

use Illuminate\Database\Eloquent\Model;
use Log, Input;

class ActionLog extends Model
{
	protected $table = 'logs';
	protected $fillable = ['action', 'meta', 'user_agent', 'ip'];
	protected $casts = ['id'=>'string'];
	protected $dates = ['created_at'];
	public $timestamps = false;

	public function user()
	{
		return $this->belongsTo(User::class);
	}
	
	public function getMetaAttribute($value)
	{
		$decoded = json_decode($value);
		
		if(is_null($decoded))
		{
			return (object)[];
		}
		
		return $decoded;
	}
	
	public function setMetaAttribute($value)
	{
		if(is_array($value))
		{
			$value = (object)$value;
		}
		elseif(!is_object($value))
		{
			Log::error('Fail to set log meta, not object or array: ' . var_export($value, true));
		}
		
		$encoded = json_encode($value, JSON_UNESCAPED_UNICODE);
		
		$this->attributes['meta'] = $encoded;
	}

	/**
	 * @param array $attributes
	 * @param string $action
	 * @return static
	 */
	public static function create(array $attributes = [], $action = '', $agent = null)
	{

		$info = '';

		if(app()->user->exists)
		{
			$info .= 'User: ' . app()->user->id . ' ' . app()->user->name . ' ';
			app()->user->touch();
		}

		$info .= $action;
		$attributes['action'] = $action;
		$attributes['user_agent'] = $agent ?: Input::server('HTTP_USER_AGENT');
		$attributes['ip'] = Input::ip();

		$log = new ActionLog();
		$log->fill($attributes);

		$log->user()->associate(app()->user);

		$meta = Input::query();
		
		if(isset($attributes['tag']))
		{
			$info .= ' Tag: ' . $attributes['tag']->name;
		}

		if(isset($attributes['post']))
		{
			$meta['post_id'] = $attributes['post']->id;
			$meta['post_title'] = str_limit($attributes['post']->title, 20);
			$meta['post_type'] = $attributes['post']->type;
			
			$info .= ' Post (' . $attributes['post']->type . '): ' . $attributes['post']->id . ' ' . $attributes['post']->title;
		}
		
		$from = (object)array_filter([
			'mp_account'=>Input::header('cip-from-mp-account'),
			'user'=>Input::header('cip-from-user'),
			'wechat'=>Input::header('cip-from-wechat'),
			'scene'=>Input::header('cip-from-scene'),
			'skipped_users'=>Input::header('cip-from-skipped-users'),
			'app'=>(bool)Input::header('cip-from-app')
		], function($item)
		{
			return !is_null($item);
		});
		
		$meta['from'] = $from;
		
		$meta['request_from'] = Input::header('cip-request-from');
		
		if(isset($attributes['order']))
		{
			$meta['order_id'] = $attributes['order']->id;
			$meta['order_price'] = $attributes['order']->price;
		}
		
		if(isset($attributes['slug']))
		{
			$meta['slug'] = $attributes['slug'];
		}
		
		if(isset($attributes['url']))
		{
			$meta['url'] = $attributes['url'];
		}
		
		$info .= ' ' . $attributes['ip'] . ' (' . $attributes['user_agent'] . ')';

		Log::info($info);
		
		$log->meta = (object)$meta;
		$log->duration = microtime(true) - LARAVEL_START;

		$log->save();
	}
}
