<?php namespace App\Jobs;

use App\Sms;
use App\User;
use Illuminate\Contracts\Bus\SelfHandling;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendSms extends Job implements ShouldQueue
{
    
    protected $user;
	protected $mobile;
	protected $text;
    
    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($mobile, $text)
    {
		if($mobile instanceof User)
		{
			$this->user = $mobile;
		}
		else
		{
			$this->mobile = $mobile;
		}
		
		$this->text = $text;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
		if($this->user)
		{
			$this->user->fresh();
			$mobile = $this->user->mobile;
		}
		else
		{
			$mobile = $this->mobile;
		}
		
        Sms::send($mobile, $this->text);
    }
}
