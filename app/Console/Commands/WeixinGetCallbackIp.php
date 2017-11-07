<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Weixin;

class WeixinGetCallbackIp extends Command
{
	/**
	 * The name and signature of the console command.
	 *
	 * @var string
	 */
	protected $signature = 'weixin:get-callback-ip {account? : 微信公众号代号}';

	/**
	 * The console command description.
	 *
	 * @var string
	 */
	protected $description = '获得微信接口IP';

	/**
	 * Create a new command instance.
	 *
	 * @return void
	 */
	public function __construct()
	{
		parent::__construct();
	}

	/**
	 * Execute the console command.
	 *
	 * @return mixed
	 */
	public function handle()
	{
		$mp_account = $this->argument('account');
		$weixin = new Weixin($mp_account);
		$ips = $weixin->getCallbackIp();

		foreach($ips as $ip)
		{
			$this->info($ip);
		}
	}
}
