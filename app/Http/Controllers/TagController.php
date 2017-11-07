<?php namespace App\Http\Controllers;

use App\Tag;
use Input;

/**
 * 对App\Tag模型进行增删改读
 * @todo 仅对内容管理员提供
 */
class TagController extends Controller
{
	/**
	 * Display a listing of the resource.
	 *
	 * @return \Illuminate\Http\Response
	 */
	public function index()
	{
		$query = Tag::query();
		
		// TODO 各控制器的排序、分页逻辑应当统一抽象
		if(Input::query('keyword') && is_string(Input::query('keyword')))
		{
			$query->where('name', 'like', '%' . Input::query('keyword') . '%');
		}

		if(Input::query('type'))
		{
			$query->where('type', Input::query('type'));
		}
		
		if(Input::query('related_tag'))
		{
			$query->whereIn('id', function($query)
			{
				$query->select('tag_id')->from('post_tag')->whereIn('post_id', function($query)
				{
					$query->select('post_id')->from('post_tag')->where('tag_id', function($query)
					{
						$query->select('id')->from('tags')->where('name', Input::query('related_tag'));
					});
				});
				
				if(Input::query('post_type'))
				{
					$query->whereIn('post_id', function($query)
					{
						$query->select('id')->from('posts')->where('type', Input::query('post_type'));
					});
				}
			})
			->where('name', '!=', Input::query('related_tag'));
		}

		$order_by = Input::query('order_by') ? Input::query('order_by') : 'id';

		if(Input::query('order'))
		{
			$order = Input::query('order');
		}
		
		if($order_by)
		{
			$query->orderBy($order_by, isset($order) ? $order : 'desc');
		}
		
		$page = Input::query('page') ? Input::query('page') : 1;
		
		$per_page = Input::query('per_page') ? Input::query('per_page') : 10;
		
		$list_total = $query->count();
		
		if(!$list_total)
		{
			$list_start = $list_end = 0;
		}
		elseif($per_page)
		{
			$query->skip(($page - 1) * $per_page)->take($per_page);
			$list_start = ($page - 1) * $per_page + 1;
			$list_end = ($page - 1) * $per_page + $per_page;
			if($list_end > $list_total)
			{
				$list_end = $list_total;
			}
		}
		else
		{
			$list_start = 1; $list_end = $list_total;
		}

		$tags = $query->get();
		
		return response($tags)->header('Items-Total', $list_total)->header('Items-Start', $list_start)->header('Items-End', $list_end);

	}

	/**
	 * Store a newly created resource in storage.
	 *
	 * @return \Illuminate\Http\Response
	 */
	public function store()
	{
		$tag = new Tag();
		return $this->update($tag);
	}

	/**
	 * Display the specified resource.
	 *
	 * @param  Tag $tag
	 * @return \Illuminate\Http\Response
	 */
	public function show(Tag $tag)
	{
		return response($tag);
	}

	/**
	 * Update the specified resource in storage.
	 *
	 * @param  Tag $tag
	 * @return \Illuminate\Http\Response
	 */
	public function update(Tag $tag)
	{
		$tag->fill(Input::data());
		$tag->save();
		return $this->show($tag);
	}

	/**
	 * Remove the specified resource from storage.
	 *
	 * @param  Tag $tag
	 */
	public function destroy(Tag $tag)
	{
		$tag->delete();
	}

}
