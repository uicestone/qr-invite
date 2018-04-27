<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Config, App\Post, App\Profile, App\User, App\Weixin;
use Log;

class SendInvitationCard implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;
	
	protected $qrcode;
	protected $user;
	protected $mp_account;
	protected $should_invite = 3;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($qrcode, User $user, $mp_account)
    {
        $this->qrcode = $qrcode;
		$this->user = $user;
		$this->mp_account = $mp_account;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
		$qrcode = $this->qrcode;
		$user = $this->user;
		$wx = new Weixin($this->mp_account);
		
		$inviter = User::find($qrcode->scene_data->user_id); // 邀请人
		$event = Post::find($qrcode->scene_data->event_id);
	
		if(!$inviter)
		{
			Log::error('Inviter not found ' . $qrcode->scene_data->user_id);
			return;
		}
	
		if($inviter->id === $user->id)
		{
			return;
		}
	
		$user->load('profiles');
	
		// 如果用户已经被邀请过, 则仅再次发送邀请函
		if($user->getProfile('invited_by_user_id_in_event_' . $event->id))
		{
			$media_id = $wx->getInvitationCardMediaId($event, $user);
			$wx->sendServiceMessage($user, $media_id, 'image');
			return;
		}
	
		$user->setProfile('invited_by_user_id_in_event_' . $event->id, $inviter->id);
	
		$invited_users = Profile::where('key', 'invited_by_user_id_in_event_' . $event->id)->where('value', $inviter->id)->get()->map(function($profile){
			return $profile->user;
		});
	
		if(($this->should_invite - $invited_users->count()) > 0)
		{
			$message = Config::get('message_invitation_continue');
			$message = str_replace('{user_name}', $user->name, $message);
			$message = str_replace('{event_title}', $event->title, $message);
			$message = str_replace('{invite_more}', $this->should_invite - $invited_users->count(), $message);
			$wx->sendServiceMessage($inviter, $message);
		}
		elseif($invited_users->count() === $this->should_invite)
		{
			if($wx->supports('template_message'))
			{
				$inviter->sendMessage('event_attend', url($event->getMeta('assistant_card_path')), ['first'=>'你的好友【' . $user->name . '】已接受你的邀请。恭喜你已获得' . $event->getMeta('date') . '的【' . $event->title . '】免费参与资格', 'keynote1'=>$event->title, 'keynote2'=>$event->getMeta('date'), 'keynote3'=>'本公众号和导师微信群', 'remark'=>['value'=>"\n" . '点击本消息扫描二维码添加导师为好友，并将验证码【' . $inviter->human_code . '】发送给导师。', 'color'=>'#AA0000']]);
			}
			else
			{
				$message = Config::get('message_invitation_success');
				$message = str_replace('{user_name}', $user->name, $message);
				$message = str_replace('{event_title}', $event->title, $message);
				$message = str_replace('{inviter_human_code}', $inviter->human_code, $message);
				$wx->sendServiceMessage($inviter, $message);
			}
		
			// 通过客服消息再次发送小助手二维码
			if($event->getMeta('assistant_card_path'))
			{
				if(!$media_id = Config::get('assistant_card_media_id_event_' . $event->id))
				{
					$media = $wx->uploadMedia($event->getMeta('assistant_card_path'));
					Config::set('assistant_card_media_id_event_' . $event->id, $media->media_id, $media->created_at + 86400 * 3);
					$media_id = $media->media_id;
				}
			
				$wx->sendServiceMessage($inviter, $media_id, 'image');
				$event->attendees()->attach($inviter);
				$event->save();
			}
		
		}
		elseif($invited_users->count() > $this->should_invite)
		{
			$message = Config::get('message_invitation_success');
			$message = str_replace('{user_name}', $user->name, $message);
			$message = str_replace('{event_title}', $event->title, $message);
			$message = str_replace('{inviter_human_code}', $inviter->human_code, $message);
			$wx->sendServiceMessage($inviter, $message);
		}
	
		$media_id = $wx->getInvitationCardMediaId($event, $user);

		$message = Config::get('message_invitation_desc');
		$message = str_replace('{event_title}', $event->title, $message);
		$wx->sendServiceMessage($user, $message);
		$wx->sendServiceMessage($user, $media_id, 'image');
    }
}
