<?php namespace App;

use Illuminate\Database\Eloquent\Model;
use Log;

class Order extends Model {
	
	protected $fillable = ['name', 'membership', 'status', 'price', 'contact', 'gateway', 'code', 'comment'];
	protected $casts = ['id'=>'string', 'gateway'=>'object', 'user_id'=>'string'];
	protected $appends = ['membership_label'];

	//'pending','paid','cancelled','in_group'
	const STATUS_PENDING    = 'pending';
	const STATUS_PAID       = 'paid';
	const STATUS_CANCELLED  = 'cancelled';
	const STATUS_INGROUP    = 'in_group';
	const STATUS_EXPIRED    = 'expired';
	const STATUS_REFUND     = 'refund';

	public static $statusOpt = [
		self::STATUS_PENDING    => '待支付',
		self::STATUS_PAID       => '已支付',
		self::STATUS_CANCELLED  => '已取消',
		self::STATUS_INGROUP    => '入群',
		self::STATUS_EXPIRED    => '超时支付',
		self::STATUS_REFUND     => '退款',
		
	];

	// query scopes
	public function scopePending($query)
	{
		return $query->where('status', self::STATUS_PENDING);
	}

	public function scopeInGroup($query)
	{
		return $query->where('status', self::STATUS_INGROUP);
	}

	public function scopeCancelled($query)
	{
		return $query->where('status', self::STATUS_CANCELLED);
	}

	public function scopePaid($query)
	{
		return $query->where('status', self::STATUS_PAID);
	}
	
	public function scopeRefund($query)
	{
		return $query->where('status', self::STATUS_REFUND);
	}
	
	public function scopeOfStatus($query, $status)
	{
		if($status === 'failed')
		{
			$status = ['cancelled', 'expired', 'refund'];
		}
		
		if($status === 'success')
		{
			$status = ['in_group', 'paid'];
		}
		
		if(is_array($status))
		{
			$query->whereIn('status', $status);
		}
		else
		{
			$query->where('status', $status);
		}
	}

	public function scopeMember($query)
	{
		return $query->whereNotNull('membership');
	}

	public function scopePost($query)
	{
		return $query->whereNull('membership');
	}

	//relations
	public function posts()
	{
		return $this->belongsToMany(Post::class)->withTimestamps();
	}

	public function user()
	{
		return $this->belongsTo(User::class);
	}

	public function getCodeAttribute($value)
	{
		if(!$this->user)
		{
			return null;
		}
		
		if(!$value && in_array($this->status, ['paid', 'in_group']))
		{
			return substr(crc32(md5($this->id . '-' . $this->user->id .  '-' . env('APP_KEY'))), 0, 6);
		}
		return $value;
	}

	public function getPrepayIdAttribute()
	{
		$package = $this->gateway->package;
		$prepay_id = explode('=', $package)[1];
		return $prepay_id ? $prepay_id : 0;
	}
	
	public function getQrCodeAttribute()
	{
		if($this->membership)
		{
			return Config::get('membership_assistant_qr_code');
		}
		else
		{
			return Config::get('membership_assistant_qr_code');
		}
	}
	
	public function getMembershipLabelAttribute()
	{
		$memberships = collect(Config::get('memberships'));
		
		$membership = $memberships->where('level', $this->membership)->first();
		
		if(!$membership || !isset($membership->label))
		{
			Log::warning('未定义会员等级' . $this->membership);
			return null;
		}
		
		return $membership->label;
	}
	
}
