<?php namespace App;

use Illuminate\Database\Eloquent\Model;

class Task extends Model {
	
	protected $fillable = ['type', 'status', 'data', 'starts_at', 'comments'];
	protected $casts = ['id'=>'string', 'data'=>'object'];
	protected $dates = ['created_at', 'updated_at', 'starts_at'];

	public function author()
	{
		return $this->belongsTo(User::class);
	}
}