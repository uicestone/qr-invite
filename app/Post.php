<?php namespace App;

use Symfony\Component\HttpFoundation\File\MimeType\ExtensionGuesser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\File;
use Illuminate\Support\Str;
use GuzzleHttp\Client;
use Yangqi\Htmldom\Htmldom;
use itbdw\QiniuStorage\QiniuStorage;
use Log, Cache, DB, RuntimeException;

class Post extends Model {

	use SoftDeletes;
	
	public static $sortable;
	public static $types;
	
	protected $fillable;
	protected $visible;
	protected $cachable;
	protected $attributes_by_types;

	protected $casts = [
		'id'=>'string',
		'poster_id'=>'string',
		'parent_id'=>'string',
		'is_anonymous'=>'boolean',
		'author_id'=>'string',
		'meta'=>'object',
		'weight'=>'float'
	];
	
	protected $dates = [
		'deleted_at',
		'created_at',
		'updated_at'
	];
	
	public function __construct(array $attributes = [])
	{
		$attributes_config = Config::get('post_attributes');
		$this->fillable = $attributes_config->fillable;
		$this->visible = $attributes_config->visible;
		$this->cachable = $attributes_config->cachable;
		$this->attributes_by_types = Config::get('post_attributes_by_types');
		
		parent::__construct($attributes);
	}
	
	// Model Relationships
	
	public function tags()
	{
		return $this->belongsToMany(Tag::class)->withTimestamps()->withPivot('is_hidden');
	}

	public function parent()
	{
		return $this->belongsTo(Post::class)->withTrashed();
	}
	
	public function children()
	{
		return $this->hasMany(Post::class, 'parent_id');
	}

	public function realAuthor()
	{
		return $this->belongsTo(User::class, 'author_id');
	}
	
	public function poster()
	{
		return $this->belongsTo(Post::class);
	}

	public function metas()
	{
		return $this->hasMany(Meta::class);
	}
	
	public function attendees()
	{
		return $this->belongsToMany(User::class, 'event_attend')->withPivot('info')->withTimestamps();
	}

	public function likedUsers()
	{
		return $this->belongsToMany(User::class, 'post_like')->withTimestamps();
	}
	
	public function favoredUsers()
	{
		return $this->belongsToMany(User::class, 'post_favorite')->withTimestamps();
	}
	
	public function sharedUsers()
	{
		return $this->belongsToMany(User::class, 'post_share')->withTimestamps();
	}
	
	public function paidUsers()
	{
		return $this->belongsToMany(User::class, 'post_pay')->withTimestamps();
	}
	
	public function replyTo()
	{
		return $this->belongsTo(Post::class, 'reply_to');
	}
	
	public function replies()
	{
		return $this->hasMany(Post::class, 'reply_to');
	}
	
	// Query Scopes
	
	public function scopeSearch($query, $keywords)
	{
		if(!$keywords)
		{
			return [];
		}
		
		$keywords = (array)$keywords;
		
		$query->where(function($query) use($keywords)
		{
			$query->where(function($query) use($keywords)
			{
				foreach($keywords as $keyword)
				{
					$query->orWhere('id', '=', $keyword);
				}
			})
				
				->orWhere(function($query) use($keywords)
				{
					foreach($keywords as $tag)
					{
						$query->orWhereIn('id', function($query) use($tag)
						{
							$query->select('post_id')->from('post_tag')->whereIn('tag_id', function($query) use($tag)
							{
								$query->select('id')->from('tags')->where('name', $tag);
							});
						});
					}
				})
				
				->orWhere(function($query) use($keywords)
				{
					foreach($keywords as $keyword)
					{
						$query->where('title', 'like', '%' . $keyword . '%');
					}
				});
		});
		
		return true;
		
	}
	
	public function scopeHasTag($query, $tag_names)
	{
		foreach((array)$tag_names as $tag_name)
		{
			$query->whereIn('id', function($query) use($tag_name)
			{
				$query->select('post_id')->from('post_tag')->whereIn('tag_id', function($query) use($tag_name)
				{
					$query->select('id')->from('tags')->where('name', $tag_name);
				});
			});
			
			// 存入最近搜索, 最多保留1000个最近搜索
			$tags_name_recent_search = Cache::get('tags_name_recent_search') ?: [];
			
			array_unshift($tags_name_recent_search, $tag_name);
			
			if(count($tags_name_recent_search) > 1E3)
			{
				array_pop($tags_name_recent_search);
			}
			
			Cache::forever('tags_name_recent_search', $tags_name_recent_search);
		}
	}
	
	public function scopeLikedByUser($query, $liked_user_id)
	{
		$query->whereIn('id', function($query) use($liked_user_id)
		{
			return $query->select('post_id')->from('post_like')->where('user_id', $liked_user_id);
		});
	}
	
	public function scopefavoredByUser($query, $favored_user_id)
	{
		$query->whereIn('id', function($query) use($favored_user_id)
		{
			return $query->select('post_id')->from('post_favorite')->where('user_id', $favored_user_id);
		});
	}
	
	public function scopeAttendedByUser($query, $attending_user_id, $attended = true)
	{
		$method = $attended ? 'whereIn' : 'whereNotIn';
		
		$query->$method('id', function($query) use($attending_user_id)
		{
			return $query->select('post_id')->from('event_attend')->where('user_id', $attending_user_id);
		});
	}
	
	public function scopePaidByUser($query, $paid_user_id)
	{
		$query->whereIn('id', function($query) use($paid_user_id)
		{
			return $query->select('post_id')->from('post_pay')->where('user_id', $paid_user_id);
		});
	}
	
	public function scopeNotPaidByUser($query, $user_id)
	{
		$user = User::find($user_id);
		$paid_post_ids = $user->getProfile('paid_posts');
		
		if(!$paid_post_ids)
		{
			return;
		}
		
		$query->whereNotIn('id', $paid_post_ids);
	}
	
	public function scopeOfType($query, $type)
	{
		if($type === 'any')
		{
			return $query;
		}
		
		if(is_array($type))
		{
			return $query->whereIn('type', $type);
		}
		
		return $query->where('type', $type);
	}
	
	public function scopePublished($query, $published = true)
	{
		$query->where('posts.created_at', $published ? '<' : '>=', date('Y-m-d H:i:s'));
	}
	
	/**
	 * @deprecated
	 */
	public function scopeSpecialistCommented($query)
	{
		$query->whereHas('children', function($query)
		{
			$query->where('type', 'comment')->where('is_anonymous', false)->whereIn('author_id', function($query)
			{
				$query->select('id')->from('users')->where('is_specialist', '=', 2);
			});
		});
	}
	
	public function scopeSelected($query)
	{
		$query->select('posts.*')->orderByRaw('least(pow(greatest(length(`posts`.`content`) - 50, 0), 0.5), 10) + 5 * log(10, `posts`.`likes` + 1) + 5 * log(10, `posts`.`comments_count` + 1) + 20 * pow(0.5, (unix_timestamp() - unix_timestamp(`posts`.`created_at`)) / 86400) + `posts`.`weight` desc');
	}
	
	public function scopeRandom($query)
	{
		$query->orderByRaw('RAND()');
	}
	
	// Mutators
	
	protected function mutateAttribute($key, $value)
	{
		if(in_array($key, $this->cachable))
		{
			$cached = Cache::tags('post_' . $this->id)->get('post_attribute_' . $this->id . '_' . $key);
			
			if(!is_null($cached))
			{
				return $cached;
			}
		}
		
		$result = $this->{'get'.Str::studly($key).'Attribute'}($value);
		
		if(in_array($key, $this->cachable))
		{
//			Log::debug('Cache set ' . $key . ' for post ' . $this->id);
			Cache::tags('post_' . $this->id)->put('post_attribute_' . $this->id . '_' . $key, $result, rand(720, 1440));
		}
		
		return $result;
	}
	
	public function getSameUserCommentsAttribute()
	{
		if(!$this->parent)
		{
			Log::error('Parent not found for post ' . $this->id);
		}

		return $this->parent->children->filter(function($comment)
		{
			return $comment->type === $this->type
				&& $comment->realAuthor->id === $this->realAuthor->id
				&& (!$comment->is_anonymous || app()->user->id === $this->realAuthor->id);
		})
		->map(function($comment)
		{
			$comment->loadExtraData();
			return $comment;
		})
		->sortByDesc('updated_at')->values();
	}

	/**
	 * 用户在同一个上级文章下收到的所有评论
	 * 同parent下同replyTo的评论
	 *
	 * @return mixed
	 */
	public function getSamePostCommentsAttribute()
	{
		return $this->parent->children->filter(function($comment)
		{
			$comment_reply_to = $comment->replyTo ?: $comment->parent;
			$this_reply_to = $this->replyTo ?: $this->parent;

			return $comment->type === $this->type // 类型相同 (都为comment)
				&& $comment_reply_to->id === $this_reply_to->id // 回复的对象相同
				&& $comment_reply_to->realAuthor->id !== $comment->realAuthor->id // 排除本人回复
				&& (!$comment->is_anonymous || app()->user->id === $this->realAuthor->id);
		})
		->map(function(Post $comment)
		{
			$comment->loadExtraData();
			return $comment;
		})
		->sortByDesc('updated_at')->values();
	}

	public function getLatestQuestionsAttribute()
	{
		return $this->children
			->where('type', 'question')
			->filter(function($post)
			{
				return $post->created_at->diffInSeconds(null, false) >= 0;
			})
			->sortByDesc('created_at')->take(5)->values()->map(function(Post $post)
			{
				$post->loadExtraData();
				$post->setVisible(array_diff($post->getVisible(), ['comments', 'answers', 'event']));
				return $post;
			})
			->values();
	}

	public function getLatestCommentsAttribute()
	{
		return $this->children
			->filter(function($post)
			{
				return $post->type === 'comment' && $post->created_at->diffInSeconds(null, false) >= 0;
			})
			->sortByDesc('created_at')->take(3)->values()->map(function(Post $post)
			{
				$post->loadExtraData();
				$post->setVisible(array_diff($post->getVisible(), ['comments', 'answers', 'event']));
				return $post;
			})
			->values();
	}
	
	public function getOriginalCommentsAttribute()
	{
		return $this->children
			->filter(function($post)
			{
				return $post->type === 'comment' && $post->created_at->diffInSeconds(null, false) >= 0;
			})
			->sortBy('created_at')->take(3)->values()->map(function(Post $post)
			{
				$post->loadExtraData();
				$post->setVisible(array_diff($post->getVisible(), ['comments', 'answers', 'event']));
				return $post;
			})
			->values();
	}
	
	public function getLatestLikedUsersAttribute()
	{
		return $this->likedUsers
			->sortByDesc('pivot.created_at')->take(10)->values();
	}

	public function getImagesAttribute()
	{
		return $this->children
			->where('type', 'image')
			->filter(function($post)
			{
				return $post->created_at->diffInSeconds(null, false) >= 0;
			})
			->map(function($image)
			{
				return $image->loadExtraData();
			})
			->values();
	}

	public function getAudiosAttribute()
	{
		return $this->children
			->where('type', 'audio')
			->filter(function($post)
			{
				return $post->created_at->diffInSeconds(null, false) >= 0;
			})
			->sortBy('created_at')
			->map(function($image)
			{
				return $image->loadExtraData();
			})
			->values();
	}
	
	public function getVideosAttribute()
	{
		return $this->children
			->where('type', 'video')
			->filter(function($post)
			{
				return $post->created_at->diffInSeconds(null, false) > 0;
			})
			->map(function($image)
			{
				return $image->loadExtraData();
			})
			->values();
	}
	
	public function getSpecialistAnswerAttribute()
	{
		$post = $this->children
			->where('type', 'answer')
			->filter(function($post)
			{
				return $post->realAuthor && $post->realAuthor->is_specialist > 0 && !$post->is_anonymous && $post->created_at->diffInSeconds(null, false) > 0;
			})
			->sortBy('created_at')
			->last();

		$post && $post->loadExtraData();

		return $post;
	}

	public function getLikedAttribute()
	{
		if(!app()->user->id)
		{
			return false;
		}
		
		$liked_posts = app()->user->getProfile('liked_posts');
		
		if(is_array($liked_posts) && in_array($this->id, $liked_posts))
		{
			return true;
		}
		
		return false;
	}
	
	public function getFavoredAttribute()
	{
		if(!app()->user->id)
		{
			return false;
		}
		
		$favored_posts = app()->user->getProfile('favored_posts');
		
		if(is_array($favored_posts) && in_array($this->id, $favored_posts))
		{
			return true;
		}
		
		return false;
	}
	
	public function getSharedAttribute()
	{
		if(!app()->user->id)
		{
			return false;
		}
		
		$shared_posts = app()->user->getProfile('shared_posts');
		
		if(is_array($shared_posts) && in_array($this->id, $shared_posts))
		{
			return true;
		}
		
		return false;
	}
	
	public function getAttendedAttribute()
	{
		if(!app()->user)
		{
			return null;
		}
		
		$attending_events = app()->user->getProfile('attending_events');
		
		if(is_array($attending_events) && in_array($this->id, $attending_events))
		{
			return true;
		}
		
		return false;
	}

	public function getUrlAttribute($url)
	{
		if($this->parent && !$this->parent->isPaidContentReadable())
		{
			return null;
		}
		
		// 将wx://{media_id}转换为URL
		if(preg_match('/^wx:\/\/(.*)/', $url, $match) && app()->from_mp_account)
		{
			$wx = new Weixin();
			$url = $wx->getMediaUrl($match[1]);
		}

		// 给相对URL加上前缀
		elseif($url && !preg_match('/^http:\/\/|^https:\/\//', $url))
		{
			$url = (env('CDN_PREFIX') ?: url() . '/') . $url;
		}

		// 为HTTPS请求替换CDN前缀
		if(env('CDN_PREFIX_SSL') && app()->is_secure)
		{
			$url = str_replace(env('CDN_PREFIX'), env('CDN_PREFIX_SSL'), $url);
		}
		
		return $url;
	}
	
	public function setUrlAttribute($url)
	{
		// 将CDN地址移除
		$url = str_replace(env('CDN_PREFIX'), '', $url);
		$url = str_replace(env('CDN_PREFIX_SSL'), '', $url);
		
		$this->attributes['url'] = $url;
	}
	
	public function setTypeAttribute($type)
	{
		if(!isset(self::$types->$type))
		{
			throw new RuntimeException('unknown Post type: ' . $type);
		}
		
		$this->attributes['type'] = $type;
	}
	
	public function getTypeLabelAttribute()
	{
		return $this->type_config->label;
	}
	
	public function getTypeConfigAttribute()
	{
		if(empty(static::$types->{$this->type}))
		{
			throw new RuntimeException('unknown Post type: ' . $this->type);
		}
		
		return static::$types->{$this->type};
	}

	public function setTitleAttribute($title)
	{
		if(is_null($title))
		{
			$title = '';
		}

		$this->attributes['title'] = $title;
	}

	public function getAbbreviationAttribute($value)
	{
		if(!$value && !app()->from_admin)
		{
			return $this->title;
		}

		return $value;
	}
	
	public function getExcerptAttribute($excerpt)
	{
		if(!$excerpt && !app()->from_admin)
		{
			$content = preg_replace('/<style.*?>.*?<\/style>/', '', $this->getOriginal('content'));
			$excerpt = str_limit(trim(strip_tags($content)), (isset($this->type_config->excerpt_limit) ? $this->type_config->excerpt_limit : 500) * 2);
		}
		
		return trim(strip_tags($excerpt));
	}
	
	public function getContentAttribute($content)
	{
		if(!app()->from_admin && $this->trashed())
		{
			return null;
		}
		
		if(!$this->isPaidContentReadable())
		{
			$content = preg_replace('/<img.*?>[\s\S]*/', '', $content);
		}

		if(env('CDN_PREFIX_SSL') && app()->is_secure)
		{
			$content = str_replace(env('CDN_PREFIX'), env('CDN_PREFIX_SSL'), $content);
		}
		
		if(!app()->from_admin && $this->type === 'topic' && $this->premium)
		{
			if($this->paid)
			{
				$content = preg_replace('/[\-\s]*PAYMENT REQUIRED[\-\s]*/', '', $content);
			}
			else
			{
				$content = preg_replace('/^[\s\S]*[\-\s]*PAYMENT REQUIRED[\-\s]*/', '', $content);
			}
		}

		if(!app()->from_admin)
		{
			$content = preg_replace('/\<img.*?data\-video\-src\="(.*?)".*?\>/', '<video controls src="$1" style="width:100%"></video>', $content);
		}
		
		if(in_array($this->type, ['article', 'insight', 'course', 'topic', 'event', 'partial']))
		{
			$content = json_decode(json_encode(wpautop($content))); // TODO This solves a yao nie problem.
			
			if(app()->user_agent === 'iOS app')
			{
				$content = '<style type="text/css"> p { color: #6D6D6D; font-size:15px; line-height:1.5 } img { width: 220pt; } </style>' . $content;
			}
		}
		else
		{
			$content = strip_tags($content);
		}
		
		return $content;
	}
	
	public function setContentAttribute($content)
	{
		$content = preg_replace('/<p.*?>|<\/p>/', "\n", $content);
		$content = str_replace(env('CDN_PREFIX_SSL'), env('CDN_PREFIX'), $content);
		if(strpos($content, "\n") === 0)
		{
			$content = substr($content, 1);
		}
		$content = preg_replace('/\n{3,}/', "\n\n", $content);
		$content = preg_replace('/\s*<br.*?>\s*/', "\n", $content);
		$this->attributes['content'] = $content;
	}
	
	public function getViewsAttribute($value)
	{
		if(app()->from_admin)
		{
			return (int)$value;
		}
		
		$diffInHours = $this->created_at->diffInSeconds() / 3600;
		$decorated_value =  $value + round((log10($diffInHours + 1) * 100) * (1 + sqrt((int)$this->getOriginal('comments_count'))) * (($this->random_coefficient - 0.5) / 5 + 1));
		return shortenNumber($decorated_value);
	}
	
	public function getLikesAttribute($value)
	{
	 	if(app()->from_admin)
	 	{
	 		return (int)$value;
	 	}
		
		return shortenNumber($value);
	}

	public function getRepostsAttribute($value)
	{
		if(app()->from_admin)
		{
			return (int)$value;
		}

		return shortenNumber($value);
	}

	public function getCommentsCountAttribute($value)
	{
		if(app()->from_admin)
		{
			return (int)$value;
		}

		return shortenNumber($value);
	}
	
	public function getFavoredUsersCountAttribute($value)
	{
		if(app()->from_admin)
		{
			return (int)$value;
		}
		
		return shortenNumber($value);
	}
	
	public function getRandomCoefficientAttribute()
	{
		return crc32($this->title . $this->created_at) / pow(2, 32);
	}

	public function getCreatedAtHumanAttribute()
	{
		return $this->created_at->diffForHumans();
	}
	
	public function getUpdatedAtHumanAttribute()
	{
		return $this->updated_at->diffForHumans();
	}

	public function getAuthorAttribute()
	{
		if(!app()->from_admin && $this->trashed())
		{
			return null;
		}

		$author = $this->realAuthor;

		if(($this->is_anonymous || !$author) && !(app()->from_admin && app()->user->can('edit_' . $this->type)))
		{
			$anonymous = new User();
			$anonymous->fill(['name'=>'匿名用户', 'is_anonymous'=>true]);

			if(!$author)
			{
				return $anonymous;
			}

			foreach(['gender', 'wx_unionid', 'roles'] as $key)
			{
				$anonymous->$key = $author->$key;
			}

			$anonymous->append('avatar');

			return $anonymous;
		}
		else
		{
			$author->append('badges', 'level', 'followed', 'host_event');
			$author->setVisible(['id', 'name', 'gender', 'address', 'avatar', 'roles', 'following_user_count', 'followed_user_count', 'badges', 'level', 'followed', 'host_event']);
			return $author;
		}
	}

	public function getIsPublishedAttribute()
	{
		return $this->created_at->diffInSeconds() >= 0;
	}

	public function getRelatedPostsAttribute()
	{
		$ids = $this->getMeta('related_posts');

		if(!$ids)
		{
			return [];
		}

		$relatives = Post::whereIn('id', $ids)->orderByRaw('FIELD(`id`, ' . implode(',', $ids) . ')')->get();

		$relatives->transform(function($relative)
		{
			return $relative->loadExtraData(['author', 'liked'], ['tags']);
		});

		return $relatives;
	}

	public function getPremiumAttribute()
	{
		return (bool)$this->getMeta('price');
	}

	public function getPriceAttribute()
	{
		return $this->getMeta('price');
	}
	
	public function getPricePromotion($promotion_code)
	{
		return $this->getMeta('price_' . $promotion_code);
	}
	
	public function getPriceOriginAttribute()
	{
		return $this->getMeta('price_origin');
	}
	
	public function getPaidAttribute()
	{
		$paid_posts = app()->user->getProfile('paid_posts');
		return $paid_posts && is_array($paid_posts) && in_array($this->id, $paid_posts);
	}
	
	public function getCollageGeneratingQrcodeAttribute()
	{
		$qrcode_config_key = Config::get('collage_generating_qrcode_' . $this->id);
		
		if(!$qrcode_config_key)
		{
			$wx = new Weixin();
			$qrcode_config_item = $wx->generateQrCode(['name'=>'collage', 'collage_id'=>$this->id], true);
			Config::set('collage_generating_qrcode_' . $this->id, $qrcode_config_item->key, $qrcode_config_item->expires_at->timestamp);
			return $qrcode_config_item->value->url;
		}

		$qrcode = Config::get($qrcode_config_key);

		if($qrcode)
		{
			return $qrcode->url;
		}
		
		return null;
	}

	public function getAttendeesCountAttribute()
	{
		if($attendees_count = $this->getMeta('attendees_count'))
		{
			return $attendees_count;
		}
		
		$attendees_count = $this->attendees()->count();

		$this->setMeta('attendees_count', $attendees_count);

		return $attendees_count;
	}
	
	public function getMembershipAttribute()
	{
		$membership = $this->getMeta('membership');
		return $membership ?: 0;
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
	
	// Additional Methods
	
	public function getMeta($key)
	{
		$item = $this->metas->where('key', $key)->first();
		
		if($item)
		{
			return $item->value;
		}
		
		return null;
	}
	
	public function setMeta($key, $value)
	{
		$this->metas()->updateOrCreate(['key'=>$key], ['key'=>$key, 'value'=>$value]);
	}
	
	public function updateMetas($metas)
	{
		foreach($metas as $index => $meta)
		{
			if(is_integer($index))
			{
				$key = $meta['key'];
				$value = $meta['value'];
			}
			else
			{
				$key = $index;
				$value = $meta;
			}
			
			if(is_null($value))
			{
				$meta = Meta::where('post_id', $this->id)->where('key', $key)->first();
				
				if($meta)
				{
					$meta->delete();
				}
			}
			else
			{
				$meta = Meta::firstOrNew(['key'=>$key, 'post_id'=>$this->id]);
				$meta->value = $value;
				$this->metas()->save($meta);
			}
		}
	}
	
	/**
	 * 为不同type的Post加载不同的显示不同的字段, 加载不同的属性和关联模型
	 *
	 * @param array $except_attributes
	 * @param array $except_relations
	 * @param array $except_visible
	 * @return $this
	 */
	public function loadExtraData($except_attributes = [], $except_relations = [], $except_visible = [])
	{
		$append_attributes = $load_relations = $add_visible = [];
		
		$attributes_by_types = $this->attributes_by_types;
		
		foreach((array)$attributes_by_types->visible as $types_string => $visible)
		{
			if($types_string)
			{
				$add_visible = array_merge($add_visible, $visible);
				continue;
			}
			
			$types = preg_split('/\,[\s]*/', $types_string);
			
			if(in_array($this->type, $types))
			{
				$add_visible = array_merge($add_visible, $visible);
			}
		}
		
		$add_visible = array_diff($add_visible, $except_visible);
		
		foreach((array)$attributes_by_types->relation as $types_string => $relations)
		{
			if(!$types_string)
			{
				$load_relations = array_merge($load_relations, $relations);
				continue;
			}
			
			$types = preg_split('/\,[\s]*/', $types_string);
			
			if(in_array($this->type, $types))
			{
				$load_relations = array_merge($load_relations, $relations);
			}
		}
		
		$load_relations = array_diff($load_relations, $except_relations);
		
		foreach((array)$attributes_by_types->append as $types_string => $attributes)
		{
			if(!$types_string)
			{
				$append_attributes = array_merge($append_attributes, $attributes);
				continue;
			}
			
			$types = preg_split('/\,[\s]*/', $types_string);
			
			if(in_array($this->type, $types))
			{
				$append_attributes = array_merge($append_attributes, $attributes);
			}
		}
		
		$append_attributes = array_diff($append_attributes, $except_attributes);
		
		foreach($load_relations as $relation)
		{
			if(!$this->relationLoaded($relation))
			{
				$this->load($relation);
			}
		}
		
		if(in_array('poster', $load_relations) && $this->poster)
		{
			$this->poster->loadExtraData();
		}
		
		if(in_array('replyTo', $load_relations) && $this->replyTo)
		{
			$this->replyTo->loadExtraData(['latest_comments']);
		}
		
		$this->append($append_attributes);
		$this->addVisible(array_merge($add_visible, $append_attributes, $load_relations));
		
		if(in_array('tags', $load_relations))
		{
			$this->setRelation('tags', $this->tags->filter(function($tag)
			{
				return app()->from_admin || !$tag->pivot->is_hidden;
			})
				->map(function($tag)
				{
					$tag->is_hidden = $tag->pivot->is_hidden;
					return $tag;
				})
				->values());
		}
		
		return $this;
	}
	
	/**
	 * 计算一个Post的参与者数量, 存入metas
	 */
	public function countAttendees()
	{
		$attendees_count = $this->attendees()->count();
		$this->setMeta('attendees_count', $attendees_count);
		return $attendees_count;
	}
	
	// 对每一个上级文章累加一个字段，直到顶层
	public function propagateIncrement($field, $by = 1)
	{
		$this->increment($field, $by);
		
		if($this->parent)
		{
			$this->parent->propagateIncrement($field, $by);
		}
	}
	
	// 对每一个上级文章累减一个字段，直到顶层
	public function propagateDecrement($field, $by = 1)
	{
		$this->decrement($field, $by);
		
		if($this->parent)
		{
			$this->parent->propagateDecrement($field, $by);
		}
	}
	
	protected function incrementOrDecrementAttributeValue($column, $amount, $method)
	{
		$this->{$column} = $this->getOriginal($column) + ($method == 'increment' ? $amount : $amount * -1);
		$this->syncOriginalAttribute($column);
	}

	// 计算下级内容总数
	public function countAllChildren()
	{
		$count = $this->children()->whereIn('type', ['question', 'answer', 'discussion', 'comment'])->count();
		
		if($count === 0)
		{
			return 0;
		}
		
		if($count < 1E4)
		{
			foreach($this->children as $child)
			{
				$count += $child->countAllChildren();
			}
		}
		else
		{
			$this->children()->whereIn('type', ['question', 'answer', 'discussion', 'comment'])->chunk(1E4, function($children) use(&$count)
			{
				$children->each(function($child) use(&$count)
				{
					$count += $child->countAllChildren();
				});
			});
		}
		
		return $count;
	}
	
	// 将所有下级标记为删除
	public function deleteAllChildren()
	{
		if($this->children)
		{
			$this->children->each(function($child)
			{
				$child->delete();
			});
		}
	}
	
	public function isWritable()
	{
		return !$this->exists || ($this->realAuthor && app()->user->id === $this->realAuthor->id) || app()->user->can('edit_' . $this->type);
	}
	
	public function isPaidContentReadable()
	{
		if(!is_null($this->membership) && app()->user->membership >= $this->membership)
		{
			return true;
		}
		elseif(app()->from_admin && app()->user->can('edit_insight'))
		{
			return true;
		}
		else
		{
			return $this->paid;
		}
	}

	/**
	 * 对微信文章内容作格式修整
	 * 下载文章中包含的微信图片到本地, 更新content属性, 但不会写入
	 * 如果没有封面,使用内容中的第一张图片作为封面
	 */
	public function saveWeixinImages()
	{
		$content = $this->getAttributeFromArray('content');
		$content = preg_replace('/ data-[^src].*?\=".*?"/', '', $content);
		$content = str_replace('data-src', 'src', $content);
		$content = str_replace('white-space: normal;', '', $content);
		$content = str_replace(' style=""', '', $content);

		preg_match_all('/<img.*? src="(.*?)"/', $content, $matches);

		foreach($matches[1] as $index => $image_url)
		{
			if(!in_array(parse_url($image_url, PHP_URL_HOST), Config::get('image_hosts')))
			{
				continue;
			}

			preg_match('/^.*\//', $image_url, $match);
			$url = $match[0];
			
			$file_store_name = md5($url);
			$local_path = storage_path('uploads/' . $file_store_name);
			(new Client())->get($url, ['sink' => $local_path]);
			
			$file = new File($local_path);
			
			$extension = $file->guessExtension();
			$file_store_name .= '.' . $extension;
			$file->move(storage_path('uploads/'), $file_store_name);
			$disk = QiniuStorage::disk('qiniu');
			$disk->putFile('uploads/' . $file_store_name, storage_path('uploads/' . $file_store_name));
			
			$content = str_replace($image_url, env('CDN_PREFIX') . 'uploads/' . $file_store_name, $content);

			// 调用第一张图作为封面, 更新poster_id属性, 但不会写入
			if($index === 0)
			{
				$poster = new Post(['type'=>'image', 'url'=>'uploads/' . $file_store_name]);
				$poster->realAuthor()->associate($this->realAuthor);
				$poster->save();
				$this->poster()->associate($poster);
			}
		}

		$this->content = $content;
	}

	// 判断文章的内容是否是一篇微信文章的链接
	public function isWeixinArticleLink()
	{
		$content = strip_tags($this->getAttributeFromArray('content'));
		$result = preg_match('/^http[s]?:\/\/mp.weixin.qq.com\/s/', $content);

		if($result)
		{
			$this->url = htmlspecialchars_decode($content);
		}

		return $result;
	}

	// 从微信文章复制内容和标题
	public function copyWeixinArticleContent($url)
	{
		$response = (new Client())->get($url);
		$content_dom = new Htmldom($response->getBody());
		$this->url = $url;
		
		$rich_media_title = $content_dom->find('.rich_media_title');
		$rich_media_content = $content_dom->find('.rich_media_content');
		
		if(!is_array($rich_media_title) || !$rich_media_title)
		{
			throw new RuntimeException('rich_media_title not found in url ' . $url);
		}
		
		if(!is_array($rich_media_content) || !$rich_media_content)
		{
			throw new RuntimeException('rich_media_content not found in url ' . $url);
		}
		
		$this->title = trim($rich_media_title[0]->innertext);
		$this->content = trim($rich_media_content[0]->innertext);
	}
	
	/**
	 * @param File|string $file file or path
	 * @return Post
	 */
	public function uploadFile($file)
	{
		if(is_string($file))
		{
			$file = new File($file);
		}
		
		$type = explode('/', $file->getMimeType())[0];
		$extension = $file->guessExtension();
		
		if(empty($extension))
		{
			throw new RuntimeException('file extension not resolved');
		}
		$file_store_name = md5_file($file->getRealPath()) . '.' . $extension;
		
		$this->title = method_exists($file, 'getClientOriginalName') ? $file->getClientOriginalName() : $file->getFilename();
		$this->url = 'uploads/' . $file_store_name;
		
		if(!$this->type)
		{
			$this->type = $type;
		}
		
		$file->move(storage_path('uploads'), $file_store_name);
		$disk = QiniuStorage::disk('qiniu');
		$disk->putFile('uploads/' . $file_store_name, storage_path('uploads/' . $file_store_name));
		
		return $this;
	}
	
	public function uploadFileFromContent($content)
	{
		preg_match('/data:(.*?);base64,/', $content, $matches);
		
		if(!$matches)
		{
			throw new RuntimeException('文件上传失败, 未找到Base64 MIME信息');
		}
		
		$mime = $matches[1];
		$extension = ExtensionGuesser::getInstance()->guess($mime);
		
		if(!$extension)
		{
			abort(400, 'file extension name not resolved');
		}
		
		$file_store_name = md5($content) . '.' . $extension;
		
		$this->type = explode('/', $mime)[0];
		$this->title = date('YmdHis') . '.' . $extension;
		$this->url = 'uploads/' . $file_store_name;
		
		$content = base64_decode(preg_replace('/data:(.*?);base64,/', '', $content));
		
		$disk = QiniuStorage::disk('qiniu');
		$disk->put('uploads/' . $file_store_name, $content);
	}
	
	public function getUserVerifyCode()
	{
		$seconds = time() - strtotime('midnight today') + 10000;
		$code = $seconds . $this->id;
		return $code;
	}
	
}
