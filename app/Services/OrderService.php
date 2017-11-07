<?php


namespace App\Services;

use App\Config as ConfigModel;
use App\User   as UserModel;
use App\Order  as OrderModel;
use App\Post   as PostModel;

use App\Weixin as WeixinService;
use Carbon\Carbon;
use Log;

/**
 * Class OrderService
 * @package App\Services
 * @todo 拆分与Order共同使用的的Interface和Trait
 */
class OrderService
{
	
	/**
	 * 获取剩余会员名额
	 * @return mixed
	 */
	public static function getCapacity()
	{
		return collect(ConfigModel::get('memberships'))->sum('capacity');
	}
	
	/**
	 * 减少会员名额
	 * @param integer $membership 会员等级
	 * @param integer $number 数量
	 */
	public static function decrementCapacity($membership, $number = 1)
	{
		$memberships = collect(ConfigModel::get('memberships'))->map(function($item) use ($membership, $number) {
			if ($item && $item->level == $membership) {
				$item->capacity -= round($number * ($item->capacity - 5) * (0.02 + rand(-5, 5) / 1000));
			}
			return $item;
		});
		ConfigModel::set('memberships', $memberships);
	}
	
	/**
	 * 更新订单gateway信息
	 * @param OrderModel $order
	 * @param string $pay_confirm
	 */
	public static function updateOrderGateway(OrderModel $order, $pay_confirm = '')
	{
		if($order->gateway)
		{
			$order->gateway->payment_confirmation = $pay_confirm;
		}
		else
		{
			$weixin = new WeixinService();
			$prepay_id = $weixin->unifiedOrder($order->id, $order->price, app()->user->getProfile('wx_openid' . $weixin->account), api_url('wx/payment-confirm/' . substr($weixin->account, 1)), $order->name);
			$pay_sign_args = $weixin->generateJsPayArgs($prepay_id);
			$order->gateway = $pay_sign_args;
		}
		$order->save();
	}
	
	/**
	 * 创建购买主题订单
	 * @param UserModel $user
	 * @param array $post_ids
	 * @return OrderModel
	 */
	public static function createPostsOrder(UserModel $user, $post_ids = [], $promotion_code = null)
	{
		$order_price = 0; $order_name = ''; $posts = collect();
		
		foreach ($post_ids as $post_id)
		{
			$post = PostModel::find($post_id);
			
			if (!$post)
			{
				abort(404, '没有对应的内容');
			}
			
			if($user->paidPosts->contains($post))
			{
				abort(409, '您已有权查看' . $post->title . ', 无须重复购买');
			}
			
			$order_price += $post->getMeta('price_' . $promotion_code) ?: $post->getMeta('price');
			$order_name  .= $post->title;
			$posts->push($post);
		}
		
		$order = self::getPostPendingOrder($user);
		
		if($order)
		{
			if($order->price != $order_price)
			{
				$order->status = OrderModel::STATUS_CANCELLED;
				$order->save();
			}
			
			if($order->gateway)
			{
				if($order->created_at->diffInSeconds(Carbon::now()) >= 7200)
				{
					$order->status = OrderModel::STATUS_EXPIRED;
					$order->save();
				}
			}
		}
		
		
		if(!$order || (in_array($order->status, [ OrderModel::STATUS_CANCELLED, OrderModel::STATUS_EXPIRED])))
		{
			$order_data = [
				'contact'    => $user->mobile,
				'status'     => OrderModel::STATUS_PENDING,
				'price'      => $order_price,
				'name'       => $order_name,
			];
			$order = self::createOrder($user, $order_data);
			$order->posts()->saveMany($posts);
		}
		
		return $order;
	}
	
	/**
	 * 创建会员订单
	 * @param UserModel $user
	 * @param integer $membership
	 * @param string $promotion_code
	 * @return OrderModel
	 */
	public static function createMemberOrder(UserModel $user, $membership, $promotion_code = '')
	{
		$memberships = collect(ConfigModel::get('memberships'));

		$buy_membership  = $memberships->where('level', $membership)->first();
		$user_membership = $user->getProfile('membership');
		$user_before_membership = $memberships->where('level', $user_membership)->first();
		
		$order_name  = $buy_membership->label . '会员 1学期';
		
		$order_price = self::getMembershipOrderPrice($buy_membership, $user_before_membership, $promotion_code);
		$order = self::getMemberPendingOrder($user);
		if($order)
		{
			if($order->price != $order_price)
			{
				$order->status = OrderModel::STATUS_CANCELLED;
				$order->save();
			}
			
			if($order->gateway)
			{
				if($order->created_at->diffInSeconds(Carbon::now()) >= 7200)
				{
					$order->status = OrderModel::STATUS_EXPIRED;
					$order->save();
				}
			}
		}
		
		if(!$order || (in_array($order->status, [ OrderModel::STATUS_CANCELLED, OrderModel::STATUS_EXPIRED])))
		{
			$order_data = [
				'membership' => $buy_membership->level,
				'contact'    => $user->mobile,
				'status'     => OrderModel::STATUS_PENDING,
				'price'      => $order_price,
				'name'       => $order_name,
			];
			$order = self::createOrder($user, $order_data);
		}
		return $order;
	}
	
	/**
	 * 获取当前会员等级的购买价格
	 * @param $membership
	 * @param $user_membership
	 * @param string $promotion_code
	 * @return mixed
	 */
	public static function getMembershipOrderPrice($membership, $user_membership, $promotion_code)
	{
		if (!$membership || !isset($membership->price))
		{
			abort(400, '未定义会员等级' . $membership);
		}
		
		if ($user_membership)
		{
			Log::debug('用户 ' . app()->user->id . ' 此前的会员等级为: ' . $user_membership->level);
			
			if($membership->level <= $user_membership->level)
			{
				abort(409, '用户已经为' . $user_membership->label . ', 无需重复购买');
			}
		}
		else
		{
			$user_membership = (object)['price' => 0, 'level' => 0];
		}
		
		$order_price = $membership->price - $user_membership->price;
		
		if ($promotion_code && property_exists($membership, 'price_' . $promotion_code) )
		{
			$promotion_price = $membership->{'price_' . $promotion_code};
			$order_price = $promotion_price;
		}
		
		if(app()->environment() === 'testing')
		{
			$test_price = round($order_price / 10000, 2);
			$order_price = $test_price < 0.01 ? 0.01 : $test_price;
		}
		
		return $order_price;
	}
	
	
	/**
	 * 获取用户待支付会员订单
	 * @param UserModel $user
	 * @return mixed
	 */
	protected static function getMemberPendingOrder(UserModel $user )
	{
		$order = OrderModel::where('user_id', $user->id)
				->where('membership', '>', 0)
				->where('status', OrderModel::STATUS_PENDING)
				->first();
		
		return $order;
	}
	
	/**
	 * 获取会员待支付内容订单
	 * @param UserModel $user
	 * @return mixed
	 */
	protected static function getPostPendingOrder(UserModel $user)
	{
		return OrderModel::where('user_id', $user->id)
			->where('status', OrderModel::STATUS_PENDING)
			->has('posts')
			->first();
	}
	
	/**
	 * 创建订单
	 * @param UserModel $user
	 * @param $data
	 * @return OrderModel
	 */
	protected static function createOrder(UserModel $user, $data)
	{
		$order = new OrderModel();
		$order->fill($data);
		$order->user()->associate($user);
		$order->save();
		return $order;
	}
	
	/**
	 *
	 * @return mixed
	 */
	public static function getUnPaidOrders()
	{
		return OrderModel::with(['user'])->where('membership', '>', 0)
			->whereIn('status', [OrderModel::STATUS_EXPIRED, OrderModel::STATUS_PENDING])
			->groupBy('user_id')
			->get();
	}
}
