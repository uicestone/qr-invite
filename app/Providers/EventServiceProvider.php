<?php namespace App\Providers;

use App\Events\ActionLogCreated;
use Illuminate\Contracts\Events\Dispatcher as DispatcherContract;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

use App\Post, App\Config, App\ActionLog, App\User;
use App\Events\PostUpdated;
use Log, Cache;

class EventServiceProvider extends ServiceProvider {

	/**
	 * The event listener mappings for the application.
	 *
	 * @var array
	 */
	protected $listen = [
		'App\Events\ActionLogCreated' => [
			'App\Listeners\ActionLogListener'
		]
	];

	/**
	 * The subscriber classes to register.
	 *
	 * @var array
	 */
	protected $subscribe = [
		
	];

	/**
	 * Register any other events for your application.
	 *
	 * @return void
	 */
	public function boot()
	{
		parent::boot();
		
		Post::creating(function(Post $post)
		{
			// 创建文章时如果内容是一个微信图文链接, 那么下载内容
			if($post->isWeixinArticleLink())
			{
				$post->copyWeixinArticleContent($post->url);
			}

			// 将文章内容中的微信图片转存到本地
			$post->saveWeixinImages();
		});

		Post::created(function($post)
		{
			if($post->parent)
			{
				// 上级评论数更新
				if(in_array($post->type, ['question', 'answer', 'discussion', 'comment']))
				{
					$post->parent->propagateIncrement('comments_count', 1);
				}
				$post->parent->touch();
			}

			// 更新用户积分
			if($post->realAuthor)
			{
				$post->message = $post->realAuthor->promote('post', ['post'=>$post]);
			}
			else
			{
				Log::error('Post ' . $post->id . ' created, but no author found. Promotion failed.');
			}

			// 关注专家新解答
			if($post->type === 'answer' && $post->is_published && !$post->is_anonymous && $post->realAuthor && $post->realAuthor->is_specialist)
			{
				$post->realAuthor->followedUsers->each(function(User $user) use($post)
				{
					if($post->parent->realAuthor->id === $user->id)
					{
						return;
					}

					$user->sendMessage('new_answer', app_url('course/question/' . $post->parent->id, 'bluaoak'), ['first'=>'您关注的“' . $post->realAuthor->name . '”有新的问答啦 ^_^', 'keyword1'=>$post->parent->title, 'keyword2'=>str_limit(trim(strip_tags($post->content)), 60), 'keyword3'=>(string)$post->created_at, 'remark'=>'点击此消息，马上查看他的更多问答'], 3600);
				});
			}

			// 收到回答
			if($post->type === 'answer' && $post->realAuthor && $post->is_published && $post->parent && $post->parent->realAuthor->id !== $post->realAuthor->id)
			{
				$post->parent->realAuthor->sendMessage('new_answer', app_url('course/question/' . $post->parent->id, 'blueoak'), ['first'=>'您提的问题有新的回答啦 ^_^', 'keyword1'=>$post->parent->title, 'keyword2'=>str_limit(trim(strip_tags($post->content)), 60), 'keyword3'=>(string)$post->created_at, 'remark'=>'点击此消息，马上查看详情']);
			}

			// 收到回复
			if($post->type === 'comment' && ($post->replyTo || $post->parent))
			{
				$reply_to_user = $post->replyTo ? $post->replyTo->realAuthor : $post->parent->realAuthor;

				if($reply_to_user->id !== $post->realAuthor->id)
				{
					$reply_to_user->sendMessage('new_reply', app_url('my/reply'), ['first'=>'您收到了回复', 'keyword1'=>$post->author->name, 'keyword2'=>(string)$post->created_at, 'keyword3'=>str_limit(trim(strip_tags($post->content)), 60), 'remark'=>'点击此消息，查看回复详情']);
				}
			}

		});

		Post::updated(function($post)
		{
			Cache::tags('post_' . $post->id)->flush();
//			Log::debug('清除了Post ' . $post->id . '的缓存');

			if($post->parent_id)
			{
				$post->parent->touch();
			}

			// 触发广播
			event(new PostUpdated($post));
		});

		Post::deleted(function($post)
		{
			if($post->parent)
			{
				// 上级评论数更新
				if($post->type === 'comment')
				{
					$post->parent->propagateDecrement('comments_count', 1);
					$post->parent->touch();
				}

				// 触发上级的广播
				event(new PostUpdated($post->parent));
			}

			// 更新用户积分
			$post->realAuthor->promote('post', ['post'=>$post], true);
			
		});
		
		Post::restored(function($post)
		{
			// 用户积分更新
			$post->realAuthor->promote('post', ['post'=>$post]);
		});

		Config::saved(function($config)
		{
			if(!$config->expires_at)
			{
				Cache::forget('config');
				Config::autoLoad();
			}
		});
		
		User::created(function($user)
		{
			ActionLog::create(['user'=>$user], '创建用户');
		});

		ActionLog::created(function($log)
		{
			event(new ActionLogCreated($log));
		});

	}
}
