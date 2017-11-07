<?php namespace App;

use Illuminate\Database\Eloquent\Model;

class Meta extends Model {
	
	protected $fillable = ['key', 'value'];
	protected $casts = ['id'=>'string'];

	public function post()
	{
		return $this->belongsTo(Post::class);
	}
	
	public function getValueAttribute($value)
	{
		if(!is_null(json_decode($value)))
		{
			return json_decode($value);
		}
		
		return $value;
	}
	
	public function setValueAttribute($value)
	{
		if(!is_string($value))
		{
			$this->attributes['value'] = json_encode($value);
		}
		else
		{
			$this->attributes['value'] = $value;
		}
	}
	
}