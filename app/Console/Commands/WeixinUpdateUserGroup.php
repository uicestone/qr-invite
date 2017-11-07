<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Post, App\Profile, App\Weixin;

class WeixinUpdateUserGroup extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'weixin:update-group {account? : 微信公众号代号} {--e|event= : 活动ID}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '更新邀请活动报名状态用户分组';

	/**
	 * Create a new command instance.
	 *
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
	    $event = Post::find($this->option('event'));

	    if($event->type !== 'event')
	    {
		    $this->error('Post ' . $event->id . ' 不是一个活动');
		    return;
	    }

	    $users_attended = [];
	    $users_about_attend = [];
	    $users_invited = Profile::with('user', 'user.profiles')->where('key', 'invited_by_user_id_in_event_' . $event->id)->get()->map(function($profile)
	    {
		    return $profile->user;
	    });

	    $this->info($event->title . ' 有 ' . $users_invited->count() . ' 位受邀用户');

	    foreach($users_invited as $user_invited)
	    {
		    if($user_invited->attendingEvents->contains($event))
		    {
			    $users_attended[] = $user_invited;
		    }
		    else
		    {
			    $count = Profile::where('key', 'invited_by_user_id_in_event_' . $event->id)->where('value', $user_invited->id)->count();

			    if($count > 0)
			    {
				    $users_about_attend[] = $user_invited;
			    }
		    }

	    }
	
		$wx = new Weixin($this->argument('account'));
		
	    $this->info(count($users_attended) . ' 位用户邀请满3个');
	    $this->info(count($users_about_attend) . ' 位用户邀请满1-2个');

	    $wx->updateUsersGroup($users_attended, '邀请满3个');
	    $wx->updateUsersGroup($users_about_attend, '邀请1-2个');
    }
}
