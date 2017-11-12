<?php namespace App\Http\Controllers;

use App\ActionLog, App\Post, App\Tag, App\User;
use App\Http\Request;
use RuntimeException, Log, URL, Route, Cache;

class PostController extends Controller
{
	/**
	 * Display a listing of the resource.
	 *
	 * @param Request $request
	 * @return \Illuminate\Support\Facades\Response
	 */
	public function index(Request $request)
	{
		$query_args = $request->query();
		ksort($query_args);
		$query_hash = md5(json_encode($query_args));
		
		$query = Post::query();
		
		// 常规字段搜索
		foreach(['author_id', 'parent_id', 'is_anonymous'] as $field)
		{
			if($request->query($field))
			{
				if(is_array($request->query($field)))
				{
					$query->whereIn($field, $request->query($field));
				}
				else
				{
					$query->where($field, $request->query($field));
				}
			}
		}

		$query->ofType($request->query('type'));
		
		if($request->query('parent_type'))
		{
			$query->whereIn('parent_id', function($query) use($request)
			{
				$query->select('id')->from('posts');
				if(is_array($request->query('parent_type')))
				{
					$query->whereIn('type', $request->query('parent_type'));
				}
				else
				{
					$query->where('type', $request->query('parent_type'));
				}
			});
		}
		
		if(($request->query('author_id') || $request->query('parent_author_id')) && app()->user->id !== $request->query('author_id'))
		{
			$query->where('is_anonymous', false);
		}

		if($request->query('except_id'))
		{
			$query->where('posts.id', '!=', $request->query('except_id'));
		}

		// 关键字搜索
		if(!is_null($request->query('keyword')))
		{
			$query->search($request->query('keyword'));
		}
		
		// 标签搜索
		if($request->query('tag'))
		{
			$query->hasTag($request->query('tag'));
		}
		
		// 根据点赞用户过滤
		if($request->query('liked_user_id'))
		{
			$query->likedByUser($request->query('liked_user_id'));
		}
		
		// 根据收藏用户过滤
		if($request->query('favored_user_id'))
		{
			$query->favoredByUser($request->query('favored_user_id'));
		}
		
		// 根据参加用户过滤
		if($request->query('attending_user_id'))
		{
			$query->attendedByUser($request->query('attending_user_id'));
		}
		
		// 根据有权用户过滤
		if(!is_null($request->query('paid')) && app()->user->id)
		{
			if($request->query('paid'))
			{
				$query->paidByUser(app()->user->id);
			}
			else
			{
				$query->notPaidByUser(app()->user->id);
			}
		}
		
		// 根据当前用户是否参加过滤
		if(!is_null($request->query('attending')))
		{
			$query->attendedByUser(app()->user->id, $request->query('attending'));
		}
		
		// 根据真实/虚拟用户过滤
		if(!is_null($request->query('user_is_fake')))
		{
			$query->whereIn('author_id', function($query) use($request)
			{
				$query->select('id')->from('users')->where('is_fake', $request->query('user_is_fake'));
			});
		}
		
		if(!is_null($request->query('replied')) && !$request->query('replied'))
		{
			$query->has('children', '=', 0)
				->has('replies', '=', 0);
		}
		elseif($request->query('replied'))
		{
			if($request->query('replied') === 'specialist')
			{
				$query->whereIn('id', function($query)
				{
					$query->select('parent_id')->from('posts')->where('created_at', '<', date('Y-m-d H:i:s'))->where('type', 'answer')->whereNotNull('author_id')->whereIn('author_id', function($query)
					{
						$query->select('id')->from('users')->where('is_specialist', 2);
					});
				});
			}
			else
			{
				$query->has('children', '>', 0);
			}
		}

		if($request->query('parent_author_id'))
		{
			$query->whereIn('parent_id', function($query) use($request)
			{
				$query->select('id')->from('posts')->where('author_id', $request->query('parent_author_id'));
			});
		}

		if($request->query('reply_to_author_id'))
		{
			$query->where('author_id', '!=', $request->query('reply_to_author_id'))
			->where(function($query) use($request)
			{
				$query->whereIn('reply_to', function($query) use($request)
				{
					$query->select('id')->from('posts')->where('author_id', $request->query('reply_to_author_id'));
				})
				->orWhereIn('parent_id', function($query) use($request)
				{
					$query->select('id')->from('posts')->where('author_id', $request->query('reply_to_author_id'));
				});
			});
		}

		if($request->query('trashed'))
		{
			$query->onlyTrashed();
		}
		
		// 默认显示已发布的内容
		if(is_null($request->query('published')))
		{
			$query->published(true);
		}
		else
		{
			$query->published($request->query('published'));
		}
		
		if($request->query('status'))
		{
			$query->where('status', $request->query('status'));
		}
		
		if($request->query('created_after'))
		{
			$query->where('created_at', '>=', $request->query('created_after'));
		}
		
		if($request->query('created_before'))
		{
			$query->where('created_at', '<=', $request->query('created_before'));
		}
		
		if($request->query('visibility'))
		{
			$query->where('visibility', $request->query('visibility'));
			
			if($request->query('visibility') === 'public')
			{
				$query->where('content', '!=', '');
			}
		}
		
		if(!is_null($request->query('premium')))
		{
			if($request->query('premium'))
			{
				$query->whereIn('id', function($query)
				{
					$query->select('post_id')->from('metas')->where('key', 'price');
				});
			}
			else
			{
				$query->whereNotIn('id', function($query)
				{
					$query->select('post_id')->from('metas')->where('key', 'price');
				});
			}
		}

		if(in_array($request->query('group_by'), ['parent_id', 'reply_to', 'author_id']))
		{
			$query->groupBy($request->query('group_by'));

			if($request->query('group_by') === 'parent_id')
			{
				$query->with('parent.children', 'parent.children.realAuthor');
			}
		}
		
		if($request->query('order_by') === 'paid_at')
		{
			$query->join('post_pay', 'post_id', '=', 'posts.id')
				->orderBy('post_pay.created_at', 'desc');
		}

		// TODO 各控制器的排序、分页逻辑应当统一抽象
		// 分页（支持page+per_page，offset+limit两种方式）
		$pagination = [
			'offset' => $request->query('offset') ?: 0,
			'limit' => min($request->query('per_page') ?: ($request->query('limit') ?: 10), 1E3)
		];
		
		if($request->query('page'))
		{
			$request->query('per_page') && $pagination['limit'] = $request->query('per_page');
			$pagination['offset'] = ($request->query('page') - 1) * $pagination['limit'];
		}
		
		// 根据分页，确定列表位置
		$list_total = $list_start = $list_end = null;
		
		if($pagination['limit'])
		{
			// 管理后台, 分页前先计算总数
			if(app()->from_admin)
			{
				$list_total = $query->getQuery()->groups === null ? $query->count() : $query->get()->count();
			}
			
			$query->take($pagination['limit']);
			
			if($pagination['offset'])
			{
				$query->skip($pagination['offset']);
			}
		}
		
		if(!(app()->from_admin && app()->user->is_admin))
		{
			$query->where(function($query)
			{
				$query->where('visibility', 'public');
				
				if(app()->user->id)
				{
					$query->orWhere('author_id', app()->user->id);
				}
			});
		}
		
		// 智能排序
		if($request->query('order') === 'selected')
		{
			$cache_this_query = true;
			$query->selected();
		}
		
		// 随机排序
		if($request->query('order') === 'random')
		{
			$query->random();
		}
		
		// 排序字段
		$order_by = $request->query('order_by') ? $request->query('order_by') : 'created_at';

		// 排序顺序
		if($request->query('order'))
		{
			$order = $request->query('order');
		}
		elseif(in_array($order_by, ['likes', 'created_at', 'updated_at', 'status']))
		{
			$order = 'desc';
		}

		if($order_by && in_array($order_by, Post::$sortable))
		{
			$query->orderBy($order_by, isset($order) ? $order : 'asc');
		}
		
		$query->with('realAuthor', 'tags', 'poster', 'parent', 'replyTo');

		$posts = Cache::get('query_result_posts_' . $query_hash);
		
		if(is_null($posts))
		{
			if(isset($cache_this_query) && $cache_this_query)
			{
				Cache::put('query_result_posts_' . $query_hash, collect(), 5);
			}
			
			$posts = $query->get();
			
			if(isset($cache_this_query) && $cache_this_query)
			{
				Cache::put('query_result_posts_' . $query_hash, $posts, 5);
			}
		}
		
		$posts->map(function(Post $post) use($request)
		{
			$post->loadExtraData();

			if(!$request->get('parent_id'))
			{
				$post->addVisible('parent');

				if($post->parent)
				{
					$post->parent->loadExtraData(['liked', 'shared']);
				}
			}

			if($request->query('group_by') === 'parent_id')
			{
				if($request->query('author_id'))
				{
					$post->append('same_user_comments');
					$post->addVisible('same_user_comments');
				}
				else
				{
					$post->append('same_post_comments');
					$post->addVisible('same_post_comments');
				}
			}
			
			// 列表中文章的标签，排除正在搜索的标签
			$post->setRelation('tags', $post->tags->filter(function($tag) use($request)
			{
				return !in_array($tag->name, (array) $request->query('tag'));
			})
			->values());
			
			return $post;
		});
		
		if($pagination['limit'])
		{
			$list_start = $pagination['offset'] + 1;
			$list_end = $pagination['offset'] + $pagination['limit'];
			
			if(isset($list_total) && $list_end > $list_total)
			{
				$list_end = $list_total;
			}
		}
		else
		{
			$list_total = $posts->count();
			$list_start = 1; $list_end = $list_total;
		}

		$links = [];

		$query = $request->query();

		if($list_start > 1)
		{
			$links['prev'] = URL::current() . '?' . http_build_query(array_merge($query, ['offset' => $pagination['offset'] - $pagination['limit']]));
			$links['first'] = URL::current() . '?' . http_build_query(array_diff_key($query, ['offset' => null, 'page' => null]));
		}

		if(isset($list_total) && $list_end < $list_total)
		{
			$links['last'] = URL::current() . '?' . http_build_query(array_merge($query, ['offset' =>  $list_total - $list_total % $pagination['limit']]));;
		}

		$link_header = implode(', ', array_map(function($link, $rel)
		{
			return '<' . $link . '>; rel="' . $rel . '"';
		},
		$links, array_keys($links)));

		$response = response($posts)
			->header('Items-Total', $list_total)
			->header('Items-Start', $list_start)
			->header('Items-End', $list_end);

		if($link_header)
		{
			$response->header('Link', $link_header);
		}

		ActionLog::create([], '查看内容列表');
		
		return $response;
	}

	/**
	 * Store a newly created resource in storage.
	 *
	 * @param Request $request
	 * @return \Illuminate\Http\Response
	 */
	public function store(Request $request)
	{
		$post = new Post();
		
		if(!$request->data('type'))
		{
			abort(400, '请指定文章类型');
		}

		return $this->update($request, $post);
	}
	
	/**
	 * @param Request $request
	 * @param Post $post
	 * @return \Illuminate\Http\Response
	 */
	public function restore(Request $request, Post $post = null)
	{
		$post->restore();
		return $this->show($request, $post);
	}

	/**
	 * Display the specified resource.
	 *
	 * @param Request $request
	 * @param Post $post
	 * @return \Illuminate\Http\Response
	 */
	public function show(Request $request, Post $post)
	{
		if($post->trashed() && !app()->user->can('edit_post'))
		{
			abort(404, '该' . $post->type_label . '(' . $post->id . ')已被删除');
		}
		
		$post->load('parent', 'poster', 'tags', 'metas');
		
		if(isset($post->type_config->detail_attributes))
		{
			foreach($post->type_config->detail_attributes as $attribute)
			{
				$post->addVisible($attribute);
				
				if(!$post->isFillable($attribute))
				{
					$post->append($attribute);
				}
			}
		}
		
		$except_attributes = [];

		$post->loadExtraData($except_attributes);

		$post->addVisible('parent', 'metas');
		
		if($post->parent)
		{
			$post->parent->loadExtraData();
			$post->parent->addVisible('metas');
		}

		$post->tags->transform(function($tag)
		{
			return $tag->append('is_hidden');
		});

		if(!app()->from_admin && isset($post->type_config->with_tail) && $post->type_config->with_tail)
		{
			$tail = '';

			$load_tail = $post->getMeta('load_tail');
			
			if(is_null($load_tail) || $load_tail)
			{
				$partial = Post::where('type', 'partial')->where('abbreviation', $post->type)->first();
				
				if($partial)
				{
					$tail .= $partial->content;
				}
			}

			$post->content .= $tail;
		}
		
		if($post->price && $request->query('promotion_code'))
		{
			$post->price_promotion = $post->getPricePromotion($request->query('promotion_code'));
			$post->addVisible('price_promotion');
		}
		
		if(!app()->from_admin && str_contains(Route::currentRouteName(), 'post.show'))
		{
			$post->timestamps = false;
			$post->increment('views');
		}

		$response = response($post);

		ActionLog::create(['post'=>$post], '查看内容');

		return $response;
	}
	
	/**
	 * @param Post $post
	 * @return \Illuminate\Http\Response
	 */
	public function display(Post $post)
	{
		return view('post.content', compact('post'));
	}

	/**
	 * Update the specified resource in storage.
	 *
	 * @param Request $request
	 * @param Post $post
	 * @return \Illuminate\Http\Response
	 */
	public function update(Request $request, Post $post)
	{
		if(!app()->user->id && !in_array($request->data('type'), ['collage']))
		{
			abort(401, '用户没有登录');
		}

		// 如果只包含特定数据, 那么理解为patch请求
		if(!array_diff(array_keys($request->data()), ['liked', 'shared', 'attended', 'favored', 'paid']))
		{
			return $this->patch($request, $post);
		}

		// 用户可以更新自己发的文章，管理员可以更新所有文章
		if(!$post->isWritable())
		{
			abort(403, '无权编辑此文章');
		}
		
		if($request->data('created_at') === '' || $request->data('updated_at') === '')
		{
			abort(400, 'Invalid Date Format');
		}
		
		$post->fill($request->data());
		
		if(app()->user->can('edit_' . $post->type) && $request->data('author') && !empty($request->data('author')['id']) && $author = User::find($request->data('author')['id']))
		{
			$post->realAuthor()->associate($author);
		}
		elseif(!$post->realAuthor)
		{
			$post->realAuthor()->associate(app()->user);
		}

		if($request->data('parent'))
		{
			$parent_id = $request->data('parent')['id'];
		}
		
		if($request->data('parent_id'))
		{
			$parent_id = $request->data('parent_id');
		}
		
		if(isset($parent_id))
		{
			$parent_post = Post::find($parent_id);
			
			if(!$parent_post)
			{
				abort(400, 'Parent post id: ' . $parent_id . ' not found');
			}

			$post->parent()->associate($parent_post);
		}
		elseif(isset($post->type_config->require_parent) && $post->type_config->require_parent)
		{
			abort(400, $post->type_label . ' 需要一个上级内容');
		}

		if($request->data('reply_to'))
		{
			$reply_to_post = Post::find($request->data('reply_to'));

			if(!$reply_to_post)
			{
				abort(400, 'Post id: ' . $request->data('reply_to') . ' not found');
			}

			$post->replyTo()->associate($reply_to_post);
		}

		if($request->hasFile('file') && $request->data('file')->isValid())
		{
			$post->uploadFile($request->data('file'));
		}
		
		if($request->hasFile('poster') && $request->data('poster')->isValid())
		{
			$poster = new Post();
			$poster->uploadFile($request->data('poster'));
			$poster->realAuthor()->associate(app()->user);
			$poster->save();
			
			$post->poster()->associate($poster);
		}
		elseif($request->data('poster'))
		{
			$poster = Post::find($request->data('poster')['id']);
			$post->poster()->associate($poster);
		}
		
		$post->save();

		if($request->data('metas'))
		{
			$post->updateMetas($request->data('metas'));
		}

		// 上传图片
		foreach(['images', 'audios', 'videos'] as $file_type)
		{
			if(!$request->data($file_type) || !is_array($request->data($file_type)))
			{
				break;
			}

			Log::info('uploading ' . $file_type . ' for Post ' . $post->id);

			foreach($request->data($file_type) as $file)
			{
				if(is_array($file) && isset($file['id']))
				{
					continue;
				}

				$file_post = new Post();

				// 直接上传文件
				if(is_a($file, 'Symfony\Component\HttpFoundation\File\UploadedFile') && $file->isValid())
				{
					$file_post->uploadFile($file);
				}
				
				// 上传的是微信ServerID
				elseif(is_string($file) && preg_match('/^wx:\/\//', $file))
				{
					Log::info('file is weixin media, id: ' . $file);
					$file_post->url = $file;
					$file_post->title = date('YmdHis');
				}
				
				// 作为base64
				elseif(is_string($file))
				{
					$file_post->uploadFileFromContent($file);
				}
				
				else
				{
					return abort(400, '文件上传失败, 未能识别格式');
				}

				$file_post->parent()->associate($post);
				$file_post->realAuthor()->associate($post->realAuthor);

				$file_post->save();
			}
		}

		$tags = $tag_ids =[];

		if($request->data('tag'))
		{
			$tags[] = $request->data('tag');
		}
		
		if(is_array($request->data('tags')))
		{
			$tags = array_merge($tags, $request->data('tags'));
		}

		foreach($tags as $tag)
		{
			if(isset($tag['id']))
			{
				if(isset($tag['is_hidden']))
				{
					$tag_ids[$tag['id']] = ['is_hidden'=>$tag['is_hidden']];
				}
				else
				{
					$tag_ids[] = $tag['id'];
				}
			}
			elseif(!$tag)
			{
				continue;
			}
			else
			{
				$tag_model = Tag::firstOrCreate(['name'=>isset($tag['name']) ? $tag['name'] : $tag]);

				if(isset($tag['is_hidden']) && $tag['is_hidden'])
				{
					$tag_ids[$tag_model->id] = ['is_hidden'=>true];
				}
				else
				{
					$tag_ids[] = $tag_model->id;
				}
			}
		}

		if($tag_ids)
		{
			$post->tags()->sync($tag_ids);
		}

		foreach($tag_ids as $index => $tag_id)
		{
			if(is_array($tag_id))
			{
				$tag_id = $index;
			}

			$tag = Tag::find($tag_id);
			$tag->post = $tag->posts()->count();
		}

		$action = $post->wasRecentlyCreated ? '发布内容' : '更新内容';

		$post = Post::find($post->id);

		$response = $this->show($request, $post);

		ActionLog::create(['post'=>$post], $action);

		return $response;
	}

	/**
	 * Update minor relational attributes of a resource
	 *
	 * @param Request $request
	 * @param Post $post
	 * @return \Illuminate\Http\Response
	 */
	public function patch(Request $request, Post $post)
	{
		$action = '操作'; $response = ['code'=>200];

		if(!is_null($request->data('liked')))
		{
			// 点赞
			if($request->data('liked') && !$post->likedUsers->contains(app()->user->id))
			{
				$post->increment('likes', 1);
				$post->realAuthor->promote('liked', ['post'=>$post]);
				$post->likedUsers()->attach(app()->user);
				$post->touch();
				app()->user->setProfile('liked_posts', array_values(array_unique(array_merge(app()->user->getProfile('liked_posts') ?: [], [$post->id]))));
				app()->user->load('profiles');
				$response = app()->user->promote('like', ['post'=>$post]);
				$action = '点赞内容';
			}
			// 取消点赞
			elseif(!$request->data('liked') && $post->likedUsers->contains(app()->user->id))
			{
				$post->decrement('likes', 1);
				$post->realAuthor->promote('liked', ['post'=>$post], true);
				$post->likedUsers()->detach(app()->user);
				$post->touch();
				app()->user->setProfile('liked_posts', array_values(array_diff(app()->user->getProfile('liked_posts') ?: [], [$post->id])));
				app()->user->load('profiles');
				$response = app()->user->promote('like', ['post'=>$post], true);
				$action = '取消点赞内容';
			}
		}
		
		elseif(!is_null($request->data('favored')))
		{
			// 收藏
			if($request->data('favored') && !$post->favoredUsers->contains(app()->user->id))
			{
				$post->increment('favored_users_count', 1);
				$post->realAuthor->promote('favored', ['post'=>$post]);
				$post->favoredUsers()->attach(app()->user);
				$post->touch();
				app()->user->setProfile('favored_posts', array_values(array_unique(array_merge(app()->user->getProfile('favored_posts') ?: [], [$post->id]))));
				app()->user->load('profiles');
				$response = app()->user->promote('favorite', ['post'=>$post]);
				$action = '收藏内容';
			}
			// 取消收藏
			elseif(!$request->data('favored') && $post->favoredUsers->contains(app()->user->id))
			{
				$post->decrement('favored_users_count', 1);
				$post->realAuthor->promote('favored', ['post'=>$post], true);
				$post->favoredUsers()->detach(app()->user);
				$post->touch();
				app()->user->setProfile('favored_posts', array_values(array_diff(app()->user->getProfile('favored_posts') ?: [], [$post->id])));
				app()->user->load('profiles');
				$response = app()->user->promote('favorite', ['post'=>$post], true);
				$action = '取消收藏内容';
			}
		}
		
		// 分享
		elseif($request->data('shared'))
		{
			$post->increment('reposts', 1);
			
			$post->realAuthor->promote('shared', ['post'=>$post]);
			$post->sharedUsers()->attach(app()->user);
			app()->user->setProfile('shared_posts', array_values(array_unique(array_merge(app()->user->getProfile('shared_posts') ?: [], [$post->id]))));
			app()->user->load('profiles');
			$response = app()->user->promote('share', ['post'=>$post]);
			
			$action = '分享内容';
		}

		// 参加
		elseif(!is_null($request->data('attended')) && $request->data('attended') && !$post->attendees->contains(app()->user->id))
		{
			$post->attendees()->attach(app()->user);
			$post->countAttendees();
			app()->user->setProfile('attending_events', array_values(array_unique(array_merge(app()->user->getProfile('attending_events') ?: [], [$post->id]))));
			app()->user->load('profiles');
			$action = '参加活动';
		}
		
		// 申请阅读权限
		elseif(!is_null($request->data('paid')) && $request->data('paid') && !$post->paid)
		{
			abort(402, '付费内容, 购买后才能查看');
			$action = '申请阅读权限';
		}
		else
		{
			abort(400, '无效' . $action);
		}

		$post->timestamps = true;
		$post->loadExtraData();

		ActionLog::create(['post'=>$post], $action);

		return array_merge($response, ['post'=>$post]);
	}

	/**
	 * Remove the specified resource from storage.
	 *
	 * @param Request $request
	 * @param Post $post
	 * @return \Illuminate\Http\Response
	 */
	public function destroy(Request $request, Post $post = null)
	{
		if(is_null($post) && $request->query('id'))
		{
			$ids = $request->query('id');
			
			if(!is_array($ids))
			{
				$ids = [$ids];
			}
			
			$posts = Post::whereIn('id', $ids)->get();
			
			$posts->each(function($post)
			{
				try
				{
					$this->destroy($post);
				}
				catch(RuntimeException $exception)
				{
					Log::error($exception->getMessage());
				}
			});
			
			return null;
		}
		
		if(!app()->user->id)
		{
			abort(401, '用户没有登录，无法删除该' . $post->type_label);
		}
		
		if(!app()->user->can('edit_post') && (!$post->realAuthor || app()->user->id !== $post->realAuthor->id))
		{
			abort(403, '用户不是文章的作者，无权删除该' . $post->type_label);
		}
		
		if($post->type === 'question' && $post->specialist_answer)
		{
			abort(403, '问题已有专家解答, 不能删除');
		}

		$request->query('force') && app()->user->can('delete_' . $post->type) ? $post->forceDelete() : $post->delete();

		ActionLog::create(['post'=>$post], '删除内容');

		return response(['message'=>'删除' . $post->type_label . '成功', 'code'=>200]);
	}
}
