<?php namespace App\Events;

use App\User, App\Post;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use View;

class PostUpdated extends Event implements ShouldBroadcast
{
	public $post;

	/**
	 * Create a new event instance.
	 *
	 * @param Post $post
	 */
	public function __construct(Post $post)
	{
		$this->post = $post;

		if($post->parent)
		{
			event(new PostUpdated($post->parent));
		}
	}

	/**
	 * Get the channels the event should be broadcast on.
	 *
	 * @return array
	 */
	public function broadcastOn()
	{
		return ['post.' . $this->post->id];
	}
}
