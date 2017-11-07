<?php namespace App\Jobs;

use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Weixin;
use Traversable, Log;

class SendMessage extends Job implements ShouldQueue
{
    use InteractsWithQueue, SerializesModels;

    protected $user;
    protected $slug;
    protected $url;
    protected $data;
	protected $mp_account;

    /**
     * Create a new job instance.
     * $user mixed 一个用户模型, 一个用户ID, 或多个的的集合或数组
     * @return void
     */
    public function __construct($user, $slug, $url, $data, $mp_account = null)
    {
        $this->user = $user;
        $this->slug = $slug;
        $this->url = $url;
        $this->data = $data;
	    $this->mp_account = $mp_account ?: $user->entry_mp_account;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
    	if(!$this->mp_account)
		{
			Log::warning('用户' . $this->user->id . ' ' . $this->user->name . ' 没有可以发送模板的微信号, 模板消息发送失败');
			return;
		}
		
		$weixin = new Weixin($this->mp_account);
	
		if(is_array($this->user) || $this->user instanceof Traversable)
		{
			foreach($this->user as $user)
			{
				$weixin->sendTemplateMessage($user, $this->slug, $this->url, $this->data);
			}
		}
		else
		{
			$weixin->sendTemplateMessage($this->user, $this->slug, $this->url, $this->data);
		}
    }
}
