<?php namespace App\Jobs;

use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Weixin;
use Traversable;

class SendServiceMessage extends Job implements ShouldQueue
{
    use InteractsWithQueue, SerializesModels;

    protected $user;
	protected $type;
	protected $content;
	protected $mp_account;

    /**
     * Create a new job instance.
     * $user mixed 一个用户模型, 一个用户ID, 或多个的的集合或数组
     * @return void
     */
    public function __construct($user, $type, $content, $mp_account = null)
    {
        $this->user = $user;
        $this->type = $type;
        $this->content = $content;
	    $this->mp_account = $mp_account ?: $user->entry_mp_account;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
		$weixin = new Weixin($this->mp_account);
	
		if(is_array($this->user) || $this->user instanceof Traversable)
		{
			foreach($this->user as $user)
			{
				$weixin->sendServiceMessage($user, $this->content, $this->type);
			}
		}
		else
		{
			$weixin->sendServiceMessage($this->user, $this->content, $this->type);
		}
    }
}
