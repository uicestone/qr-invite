<?php namespace App\Http\Controllers;

use App\ActionLog, App\Order;
use App\Services\OrderService;
use Illuminate\Http\Request;
use Log;

class OrderController extends Controller
{
	/**
	 * Display a listing of the resource.
	 *
	 * @param Request $request
	 * @return \Illuminate\Support\Facades\Response
	 */
	public function index(Request $request)
	{
		$query = Order::with('posts', 'user');

		if(!app()->from_admin || !app()->user->is_admin)
		{
			$query->where('user_id', app()->user->id);
		}
		
		if($request->query('type') === 'membership')
		{
			$query->member();
		}
		
		if($request->query('type') === 'post')
		{
			$query->post();
		}
		
		if($request->query('status'))
		{
			$query->ofStatus($request->query('status'));
		}
		
		if($request->query('code') && app()->user->can('edit_order'))
		{
			$query->where('code', 'like', $request->query('code') . '%');
		}
		
		if($request->query('post_id'))
		{
			$query->join('order_post', 'orders.id', '=', 'order_post.order_id')->where('order_post.post_id', $request->query('post_id'));
		}
		
		if($request->query('user_id'))
		{
			$query->where('user_id', $request->query('user_id'));
		}
		
		if($request->query('created_after'))
		{
			$query->where('created_at', '>=', $request->query('created_after'));
		}
		
		if($request->query('created_before'))
		{
			$query->where('created_at', '<', $request->query('created_before'));
		}
		
		// TODO 各控制器的排序、分页逻辑应当统一抽象
		$order_by = $request->query('order_by') ? $request->query('order_by') : 'orders.created_at';

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
		$price_sum = $query->sum('price');

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

		$orders = $query->get()->map(function($order)
		{
			$order->append('code');
			$order->posts->map(function($post)
			{
				$post->loadExtraData(['author', 'liked'], ['tags'], ['content']);
				return $post;
			});
			return $order;
		});

		return response($orders)->header('Items-Total', $list_total)->header('Items-Start', $list_start)->header('Items-End', $list_end)->header('Price-Sum', $price_sum);
	}

	/**
	 * Store a newly created resource in storage.
	 *
	 * @param Request $request
	 * @return \Illuminate\Support\Facades\Response
	 */
	public function store(Request $request)
	{
		$user           = app()->user;
		$gateway        = $request->query('gateway');
		$post_ids       = $request->data('posts');
		$membership     = $request->data('membership');
		$promotion_code = $request->data('promotion_code');
		
		if(!in_array($gateway, ['weixinpay']))
		{
			abort(400, 'Invalid payment gateway.');
		}
		
		if($post_ids)
		{
			$order = OrderService::createPostsOrder($user, $post_ids, $promotion_code);
		}
		elseif( $membership )
		{
			$user_membership = $user->getProfile('membership');
			if($user_membership < $membership )
			{
				$order = OrderService::createMemberOrder($user, $membership, $promotion_code);
			}
			else
			{
				return abort(409, '请不要重复购买');
			}
		}
		else
		{
			return abort(400, 'Nothing to buy.');
		}

		// 若有订单且内容完全一致, 支付该订单
		if($request->query('gateway') === 'weixinpay' && $order->wasRecentlyCreated)
		{
			OrderService::updateOrderGateway($order);
		}

		ActionLog::create(['order'=>$order], '创建订单');
		
		return $this->show($order);
	}

	/**
	 * Display the specified resource.
	 *
	 * @param Order $order
	 * @return \Illuminate\Support\Facades\Response
	 */
	public function show(Order $order)
	{
		if($order->user && !app()->user->is_admin && app()->user->id !== $order->user->id)
		{
			return abort(403);
		}

		$order->load('posts', 'user');

		$order->posts->map(function($post)
		{
			$post->loadExtraData(['author', 'liked'], ['tags'], ['content']);
			$post->load('metas');
			$post->addVisible('metas');
			return $post;
		});

		$order->append('code', 'qr_code');

		ActionLog::create(['order'=>$order], '查看订单');

		return response($order);
	}

	/**
	 * Update the specified resource in storage.
	 *
	 * @param Request $request
	 * @return \Illuminate\Support\Facades\Response
	 */
	public function update(Request $request, Order $order)
	{
		if(!app()->from_admin || !app()->user->can('edit_order'))
		{
			abort(403);
		}
		
		if($request->data('status') !== $order->status && $request->data('status') === 'in_group' && $order->status !== 'paid')
		{
			abort(403, '未支付, 不能入群');
		}
		
		$order->fill($request->data());
		$order->save();
		return $this->show($order);
	}

	/**
	 * Remove the specified resource from storage.
	 *
	 * @param Order $order
	 */
	public function destroy(Order $order)
	{
		//
	}
	
	public function getCapacity()
	{
		$capacity = OrderService::getCapacity();
		return response(['key' => 'capacity', 'value' => $capacity]);
	}
}
