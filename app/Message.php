<?php namespace App;

use Illuminate\Database\Eloquent\Model;

class Message extends Model {
	
	protected $fillable = ['name', 'meta', 'content', 'is_read', 'type', 'event', 'mp_account'];
	protected $casts = ['is_read'=>'bool'];
	
	public function user()
	{
		return $this->belongsTo(User::class);
	}

	public function sender()
	{
		return $this->belongsTo(User::class);
	}
	
	public function getMetaAttribute($value)
	{
		return json_decode($value);
	}
	
	public function setMetaAttribute($value)
	{
		$this->attributes['meta'] = json_encode($value, JSON_UNESCAPED_UNICODE);
	}
	
	public function getEventKeyAttribute()
	{
		// EventKey为空的时候，微信会给"{}"，此处处理为null
		if(property_exists($this->meta, 'EventKey') && (is_string($this->meta->EventKey) || (array)$this->meta->EventKey))
		{
			return $this->meta->EventKey;
		}
	}
	
	public function getLatitudeAttribute()
	{
		if(property_exists($this->meta, 'Latitude'))
		{
			return $this->meta->Latitude;
		}
		
		if(property_exists($this->meta, 'Location_X'))
		{
			return $this->meta->Location_X;
		}
		
		if(property_exists($this->meta, 'SendLocationInfo'))
		{
			return $this->meta->SendLocationInfo->Location_X;
		}
	}
	
	public function getLongitudeAttribute()
	{
		if(property_exists($this->meta, 'Longitude'))
		{
			return $this->meta->Longitude;
		}
		
		if(property_exists($this->meta, 'Location_Y'))
		{
			return $this->meta->Location_Y;
		}
		
		if(property_exists($this->meta, 'SendLocationInfo'))
		{
			return $this->meta->SendLocationInfo->Location_Y;
		}
	}
	
	public function getContentAttribute($value)
	{
		if($value)
		{
			return $value;
		}

		if(property_exists($this->meta, 'Content'))
		{
			return $this->meta->Content;
		}
	}
	
	public function getFromUserNameAttribute()
	{
		if(property_exists($this->meta, 'FromUserName'))
		{
			return $this->meta->FromUserName;
		}
	}

	public function getActionNameAttribute()
	{
		if($this->type !== 'event')
		{
			$type_label = '';

			switch($this->type)
			{
				case 'text':
					$type_label = '文本';break;
				case 'link':
					$type_label = '链接';break;
				case 'image':
					$type_label = '图片';break;
				case 'location':
					$type_label = '位置';break;
				case 'shortvideo':
					$type_label = '小视频';break;
				case 'voice':
					$type_label = '语音';break;
			}

			return $type_label . '消息';
		}
		else
		{
			$event_label = '';

			switch($this->event)
			{
				case 'SCAN':
					$event_label = '扫描带参数二维码';break;
				case 'VIEW':
					$event_label = '菜单链接点击';break;
				case 'CLICK':
					$event_label = '菜单回复点击';break;
				case 'subscribe':
					$event_label = '关注';break;
				case 'unsubscribe':
					$event_label = '取消关注';break;
				case 'merchant_order':
					$event_label = '订单';break;
			}
			
			return $event_label;
		}
	}
	
}
