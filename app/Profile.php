<?php namespace App;

use Illuminate\Database\Eloquent\Model;

class Profile extends Model {
	
	protected $fillable = ['key', 'value', 'visibility', 'user_id'];
	protected $casts = ['id'=>'string'];

	public function user()
	{
		return $this->belongsTo(User::class);
	}

	public function getValueAttribute($value)
	{
		if(!is_null(json_decode($value)) || strtolower($value) === 'null')
		{
			return json_decode($value);
		}

		return $value;
	}
	
	public function setValueAttribute($value)
	{
		if(!is_string($value))
		{
			$this->attributes['value'] = json_encode($value, JSON_UNESCAPED_UNICODE);
		}
		else
		{
			$this->attributes['value'] = $value;
		}
	}
	
}