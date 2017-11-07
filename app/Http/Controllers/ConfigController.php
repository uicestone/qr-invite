<?php namespace App\Http\Controllers;

use App\Http\Request;
use App\Config, App\Weixin;

/**
 * 对App\Config模型进行增删改读
 */
class ConfigController extends Controller
{

	/**
	 * Display a listing of the resource.
	 *
	 * @param Request $request
	 * @return \Illuminate\Http\Response
	 */
	public function index(Request $request)
	{
		if(!app()->user->can('list_config'))
		{
			abort(403, '无权列出配置');
		}

		$query = Config::query();
		$query->whereNull('expires_at');
		
		if($request->query('keyword') && is_string($request->query('keyword')))
		{
			$query->where('key', 'like', '%' . $request->query('keyword') . '%');
		}
		
		if($request->query('key'))
		{
			$query->where('key', $request->query('key'));
		}
		
		// TODO 各控制器的排序、分页逻辑应当统一抽象
		$query->orderBy('expires_at')->orderBy('key');
		
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

		$configs = $query->get();
		
		return response($configs)->header('Items-Total', $list_total)->header('Items-Start', $list_start)->header('Items-End', $list_end);

	}

	/**
	 * Store a newly created resource in storage.
	 *
	 * @param Request $request
	 * @return \Illuminate\Http\Response
	 */
	public function store(Request $request)
	{
		// 生成微信带参数验证码
		// TODO 暂时和配置项储存逻辑放在一起，之后应在界面上予以区分
		if($request->query('generate_wx_qr'))
		{
			$mp_account = $request->data('key');
			$qr_value = $request->data('value');
			$wx = new Weixin($mp_account);
			return response($wx->generateQrCode($qr_value, !$request->data('value')));
		}
		
		$config = new Config();
		return $this->update($request, $config);
	}

	/**
	 * Display the specified resource.
	 *
	 * @param Request $request
	 * @param  mixed $id_or_key
	 * @return \Illuminate\Http\Response
	 */
	public function show(Request $request, $id_or_key)
	{
		if(!app()->user->can('list_config'))
		{
			abort(403, '无权显示配置');
		}
		
		if(is_a($id_or_key, Config::class))
		{
			$config = $id_or_key;
		}
		elseif(ctype_digit($id_or_key))
		{
			$config = Config::where('id', $id_or_key)->first();
		}
		else
		{
			$config = Config::where('key', $id_or_key)->first();
		}
		
		$config_output = $config->toArray();
		
		if($request->query('decode') === false && !is_string($config_output['value']))
		{
			$config_output['value'] = json_encode($config->value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
		}

		return response($config_output);
	}

	/**
	 * Update the specified resource in storage.
	 *
	 * @param Request $request
	 * @param  $id_or_key
	 * @return \Illuminate\Http\Response
	 */
	public function update(Request $request, $id_or_key)
	{
		if(!app()->user->can('edit_config'))
		{
			abort(403, '无权更新配置');
		}
		
		if(is_a($id_or_key, Config::class))
		{
			$config = $id_or_key;
		}
		elseif(ctype_digit($id_or_key))
		{
			$config = Config::where('id', $id_or_key)->first();
		}
		else
		{
			$config = Config::where('key', $id_or_key)->first();
		}

		$config->fill($request->data());
		$config->save();
		return $this->show($request, $config);
	}

	/**
	 * Remove the specified resource from storage.
	 *
	 * @param  $id_or_key
	 * @return \Illuminate\Http\Response
	 */
	public function destroy($id_or_key)
	{
		if(!app()->user->can('edit_config'))
		{
			abort(403, '无权删除配置');
		}
		
		if(ctype_digit($id_or_key))
		{
			$config = Config::where('id', $id_or_key)->first();
		}
		else
		{
			$config = Config::where('key', $id_or_key)->first();
		}
		
		$config->delete();
		
		return response(['message'=>'配置已删除', 'code'=>200]);
	}

}
