<?php namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use App\Weixin, App\Config;

class WeixinUpdateMenu extends Command {

	/**
	 * The console command name.
	 *
	 * @var string
	 */
	protected $signature = 'weixin:update-menu {account? : 微信公众号代号} {--user-group= : 特定微信公众号用户组名称}';


	/**
	 * The console command description.
	 *
	 * @var string
	 */
	protected $description = '更新一个微信公众号的菜单';

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
	public function fire()
	{
		$weixin = new Weixin($this->argument('account'));

		$config_key = 'wx_menu';

		$match_rule = [];

		if($this->option('user-group'))
		{
			$user_groups = $weixin->getUserGroups();
			$user_group = collect($user_groups)->where('name', $this->option('user-group'))->first();

			if(!$user_group)
			{
				$this->error('用户组 ' . $this->option('user-group') . ' 没有找到');
				return;
			}

			$config_key .= '_g' . $user_group->id;
			$match_rule['group_id'] = $user_group->id;
		}

		$menu_config = Config::firstOrCreate(['key' => $config_key . $weixin->account]);
		
		if(!$menu_config->value)
		{
			$menu = $weixin->getMenu();
			$menu_config->value = $menu->menu;
			$menu_config->save();
			return $menu_config->value;
		}
		
		$menu = $menu_config->value;

		if(!$match_rule)
		{
			$weixin->removeMenu();
		}
		
		$result = $weixin->createMenu($menu, $match_rule);
		
		$this->info(json_encode($result));
		$this->info(json_encode($weixin->getMenu(), JSON_UNESCAPED_UNICODE));
	}

	/**
	 * Get the console command arguments.
	 *
	 * @return array
	 */
	protected function getArguments()
	{
		return [
			['account', InputArgument::REQUIRED, 'Wechat account code.'],
		];
	}

	/**
	 * Get the console command options.
	 *
	 * @return array
	 */
	protected function getOptions()
	{
		return [
//			['example', null, InputOption::VALUE_OPTIONAL, 'An example option.', null],
		];
	}

}
