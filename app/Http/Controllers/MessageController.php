<?php namespace App\Http\Controllers;

use App\Message, App\User;
use App\Http\Request;
use PushNotification;

class MessageController extends Controller
{
	/**
	 * Display a listing of the resource.
	 *
	 * @param Request $request
	 * @return \Illuminate\Support\Facades\Response
	 */
	public function index(Request $request)
	{
		if(!app()->user->exists)
		{
			abort(400, '登陆后才能查看消息');
		}

		$query = Message::query();
		
		// 管理员可以查看所有用户消息
		if(app()->from_admin && app()->user->can('edit_user'))
		{
			if($request->query('user_id'))
			{
				$query->where('user_id', $request->query('user_id'));
			}
		}
		// 普通用户只能查看自己的消息
		else
		{
			$query->where('user_id', app()->user->id);
		}

		if(!is_null($request->query('is_read')))
		{
			$query->where('is_read', $request->query('is_read'));
		}
		
		if($request->query('event'))
		{
			if(is_array($request->query('event')))
			{
				$query->whereIn('event', $request->query('event'));
			}
			else
			{
				$query->where('event', $request->query('event'));
			}
		}
		
		if($request->query(('mp_account')))
		{
			$query->where('mp_account', $request->query('mp_account'));
		}
		
		if($request->query('meta'))
		{
			$query->where('meta', 'like', '%' . $request->query('meta') . '%');
		}

		// TODO 各控制器的排序、分页逻辑应当统一抽象
		$order_by = $request->query('order_by') ? $request->query('order_by') : 'id';

		if($request->query('order'))
		{
			$order = $request->query('order');
		}
		
		if($order_by)
		{
			$query->orderBy($order_by, isset($order) ? $order : 'desc');
		}
		
		$page = $request->query('page') ? $request->query('page') : 1;
		
		$per_page = $request->query('per_page') ? $request->query('per_page') : 10;
		
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

		if($request->query('group_by'))
		{
			$query->select(array_merge((array)$request->query('group_by'), [\DB::raw('count(*) as count')]))->groupBy($request->query('group_by'));
		}
		
		$query->with('sender', 'user');

		$messages = $query->get();
		
		return response($messages)->header('Items-Total', $list_total)->header('Items-Start', $list_start)->header('Items-End', $list_end);

	}

	/**
	 * 通过API请求发送消息
	 *
	 * @param Request $request
	 * @return \Illuminate\Support\Facades\Response
	 */
	public function store(Request $request)
	{
		$message = new Message();
		$response = $this->update($request, $message);

		return $response;
	}

	/**
	 * Display the specified resource.
	 *
	 * @param  Message $message
	 * @return \Illuminate\Support\Facades\Response
	 */
	public function show(Message $message)
	{
		return response($message);
	}

	/**
	 * Update the specified resource in storage.
	 *
	 * @param Request $request
	 * @param  Message $message
	 * @return \Illuminate\Support\Facades\Response
	 */
	public function update(Request $request, Message $message)
	{
		$message->fill($request->data());
		$message->save();
		return $this->show($message);
	}
	
	/**
	 * @param Request $request
	 * @return \Illuminate\Support\Facades\Response
	 */
	public function updateMultiple(Request $request)
	{
		if(!app()->user->exists)
		{
			abort(400, '未指定用户');
		}

		if($request->query('user_id') && app()->from_admin && app()->user->can('edit_user'))
		{
			$user = User::find($request->query('user_id'));
		}
		else
		{
			$user = app()->user;
		}

		if(!$request->query('event') || is_null($request->data('is_read')))
		{
			abort(400, '参数不全, 需要query: event和body: is_read');
		}

		Message::where('user_id', $user->id)->where('event', $request->query('event'))->update(['is_read'=>$request->data('is_read')]);

		return $this->index($request);
	}

	/**
	 * Remove the specified resource from storage.
	 *
	 * @param  Message $message
	 */
	public function destroy(Message $message)
	{
		$message->delete();
	}

}
