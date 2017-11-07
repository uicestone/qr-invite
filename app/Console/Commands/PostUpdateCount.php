<?php namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Tag, App\Post;

class PostUpdateCount extends Command {

	/**
	 * The console command name.
	 *
	 * @var string
	 */
	protected $signature = 'post:update-count
			{--c|children : 更新下级内容数}
			{--l|likes : 更新赞数}
			{--f|favorites : 更新收藏数}
			{--t|tag : 更新标签文章数}';

	/**
	 * The console command description.
	 *
	 * @var string
	 */
	protected $description = '手动更新文章的评论数, 赞数, 收藏数, 标签下的文章数';

	/**
	 * Execute the console command.
	 *
	 * @return mixed
	 */
	public function handle()
	{

		if($this->option('tag'))
		{
			$this->countTagPosts();
			return;
		}
		
		$bar = $this->output->createProgressBar(Post::count());
		$bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s%');

		Post::chunk(1E3, function($posts) use($bar)
		{
			foreach($posts as $post)
			{
				if($this->option('children'))
				{
					$post->comments_count = $post->countAllChildren();
				}

				if($this->option('likes'))
				{
					$post->likes = $post->likedUsers()->count();
				}
				
				if($this->option('favorites'))
				{
					$post->favored_users_count = $post->favoredUsers()->count();
				}
				
				$post->timestamps = false;

				$post->save();
			}
			
			$bar->advance(1E3);
		});
	}

	protected function countTagPosts()
	{
		foreach(Tag::all() as $tag)
		{
			$post_count = $tag->posts()->count();

			if($post_count === $tag->post_count)
			{
				continue;
			}

			$tag->posts = $post_count;
			$tag->save();
		}
	}
}
