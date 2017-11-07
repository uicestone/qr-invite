<?php namespace App\Events;

use App\ActionLog;

class ActionLogCreated extends Event
{
	// 这里没有使用 SerializesModels 是因为 logs 表使用了archive存储引擎, 序列化产生的数据库读操作使用了大量CPU
	
	public $action_log;

	/**
	 * Create a new event instance.
	 *
	 * @param ActionLog $log
	 */
	public function __construct(ActionLog $action_log)
	{
		$this->action_log = $action_log;
	}
}
