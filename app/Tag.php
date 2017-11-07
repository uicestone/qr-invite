<?php namespace App;

use Illuminate\Database\Eloquent\Model;

class Tag extends Model {
	
	protected $fillable = ['name', 'type', 'color', 'weight', 'description', 'posts'];
	protected $visible = ['id', 'name', 'type', 'color'];
	protected $casts = ['id'=>'string', 'weight'=>'float'];

	public function posts()
	{
		return $this->belongsToMany(Post::class);
	}
	
	public function getIsHiddenAttribute()
	{
		if(!$this->pivot)
		{
			return;
		}
		
		return (bool) $this->pivot->is_hidden;
	}
}
