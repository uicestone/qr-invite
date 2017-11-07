<?php namespace App;

use Illuminate\Database\Eloquent\Model;
use Cache, Log;

class Config extends Model {
	
	protected $table = 'config';
	protected $fillable = ['key', 'value', 'expires_at', 'comments'];
	protected $casts = ['id'=>'string'];
	protected static $loaded = [];
	
	public $timestamps = false;
	
	public function getDates()
	{
		return ['expires_at'];
	}
	
	public function getValueAttribute($value)
	{
		$decoded = json_decode($value);
		return is_null($decoded) ? $value : $decoded;
	}
	
	public function setValueAttribute($value)
	{
		if(is_array($value) || is_object($value))
		{
			$value = json_encode($value, JSON_UNESCAPED_UNICODE);
		}

		if(!is_null(json_decode($value)))
		{
			$value = json_encode(json_decode($value), JSON_UNESCAPED_UNICODE);
		}
		
		$this->attributes['value'] = $value;
	}
	
	public static function get($key)
	{
		if(array_key_exists($key, self::$loaded))
		{
			return self::$loaded[$key];
		}

		$item = static::where('key', $key)->where(function($query)
		{
			$query->where('expires_at', '>', date('Y-m-d H:i:s'))->orWhereNull('expires_at');
		})
		->first();
		
		if($item)
		{
			if(!$item->expires_at)
			{
				self::$loaded[$key] = $item->value;
			}
			
			return $item->value;
		}
		
		return null;
	}
	
	public static function set($key, $value, $expires_at = null)
	{
		$item = static::firstOrNew(['key'=>$key]);
		$item->value = $value;

		if($expires_at)
		{
			if(is_string($expires_at))
			{
				$expires_at = strtotime($expires_at);
			}
			elseif($expires_at < 4E8)
			{
				$expires_at += time();
			}

			$item->expires_at = $expires_at;
		}

		$item->save();
		return $item;
	}
	
	public static function remove($key)
	{
		$ids = static::where('key', $key)->pluck('id');
		static::destroy($ids);
	}
	
	public static function clearExpired()
	{
		$item_ids = static::where('expires_at', '<', date('Y-m-d H:i:s'))->get()->pluck('id')->toArray();
		static::destroy($item_ids);
	}

	public static function autoLoad()
	{
		$config_items = Cache::get('config');

		if($config_items)
		{
			$config_items = array_map(function($item)
			{
				if(is_string($item) && json_decode($item))
				{
					return json_decode($item);
				}
				return $item;
			},
			$config_items);

			return self::$loaded = $config_items;
		}

		self::clearExpired();
		
		Log::warning('正在从数据库载入配置项');
		self::$loaded = Config::whereNull('expires_at')->get()->pluck('value', 'key')->toArray();

		Cache::put('config', self::$loaded, 60);

		return self::$loaded;
	}
}
