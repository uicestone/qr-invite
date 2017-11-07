<?php namespace App\Http\Controllers;

use App\Jobs\SendMessage;
use App\Task, App\Config;
use App\User;
use Input, Log;

/**
 * 对App\Task模型进行增删改读
 */
class TaskController extends Controller
{
	/**
	 * Display a listing of the resource.
	 *
	 * @return \Illuminate\Http\Response
	 */
	public function index()
	{
		$query = Task::query();
		
		$order_by = Input::query('order_by') ? Input::query('order_by') : 'id';

		if(Input::query('order'))
		{
			$order = Input::query('order');
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

		$tasks = $query->get();
		
		return response($tasks)->header('Items-Total', $list_total)->header('Items-Start', $list_start)->header('Items-End', $list_end);

	}

	/**
	 * Store a newly created resource in storage.
	 *
	 * @return \Illuminate\Http\Response
	 */
	public function store()
	{
		$task = new Task();
		return $this->update($task);
	}

	/**
	 * Display the specified resource.
	 *
	 * @param  Task $task
	 * @return \Illuminate\Http\Response
	 */
	public function show(Task $task)
	{
		return response($task);
	}

	/**
	 * Update the specified resource in storage.
	 *
	 * @param  Task $task
	 * @return \Illuminate\Http\Response
	 */
	public function update(Task $task)
	{
		$task->fill(Input::data());
		
		if(Input::data('user_keyword'))
		{
			$query_user = User::query();
			
			foreach(explode(' ', Input::data('user_keyword')) as $syntax)
			{
				$query_user->matchSyntax($syntax);
			}
			
			$user_ids = $query_user->get()->implode('id', ',');
			
			$data = $task->data ?: (object)[];
			$data->user_ids = $user_ids;
			$task->data = $data;
		}
		
		if(!Input::data('starts_at'))
		{
			$task->starts_at = null;
		}
		
		if($task->status !== '已定时' && $task->starts_at && $task->starts_at->diffInSeconds(null, false) <= 0)
		{
			$task->status = '已定时';
			
			if($task->type === '模板消息批量发送')
			{
				$data = $task->data;
				
				$users = [];
				
				if(isset($data->user_ids) && is_string($data->user_ids))
				{
					$users = explode(',', $data->user_ids);
				}
				
				$job = new SendMessage($users, $data->template_id, $data->message_url, $data->message_data, $data->mp_account);
				
				$this->dispatch($job->delay($task->starts_at->timestamp - time()));
			}
			
			Log::info('任务' . $task->id . '已定时在' . $task->starts_at . '开始运行');
		}
		
		if(isset($task->data->run_test) && $task->data->run_test)
		{
			if($task->type === '模板消息批量发送')
			{
				$data = $task->data;
				$test_receiver_ids = Config::get('wx_notice_test_receivers');
				$job = new SendMessage($test_receiver_ids, $data->template_id, $data->message_url, $data->message_data, $data->mp_account);
				$this->dispatch($job);
				unset($data->run_test);
				$task->data = $data;
			}
		}
		
		$task->save();
		
		return $this->show($task);
	}

	/**
	 * Remove the specified resource from storage.
	 *
	 * @param  Task $task
	 */
	public function destroy(Task $task)
	{
		$task->delete();
	}

}
