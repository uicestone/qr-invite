<?php

namespace App\Jobs;

use Illuminate\Contracts\Bus\SelfHandling;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\User, App\Weixin;
use Log;

class SyncWeixinUserSubscribeTime extends Job implements ShouldQueue
{
    
    protected $user;
    protected $weixin_mp_account;
    protected $openid;
    protected $user_info;
    
    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(User $user, $weixin_mp_account, $openid, $user_info)
    {
        $this->user = $user;
        $this->weixin_mp_account = $weixin_mp_account;
        $this->openid = $openid;
        $this->user_info = $user_info;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        if(!$this->user_info)
        {
            $weixin = new Weixin($this->weixin_mp_account);
            $this->user_info = $weixin->getUserInfo($this->openid);
        }

        if(date('Ym', $this->user_info->subscribe_time) < date('Ym', strtotime($this->user->created_at)))
        {
            Log::debug('用户' . $this->user->id . ' ' . $this->user->name . ' 实际关注时间为' . date('Y-m-d H:i:s', $this->user_info->subscribe_time) . ', 早于' . $this->user->created_at);
            $this->user->created_at = date('Y-m-d H:i:s', $this->user_info->subscribe_time);
            $this->user->save();
        }
    }
}
