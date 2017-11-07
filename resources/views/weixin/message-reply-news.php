<xml>
	<ToUserName><![CDATA[<?=$received_message->FromUserName?>]]></ToUserName>
	<FromUserName><![CDATA[<?=$received_message->ToUserName?>]]></FromUserName>
	<CreateTime><?=time()?></CreateTime>
	<MsgType><![CDATA[news]]></MsgType>
	<ArticleCount><?=$reply_posts_count?></ArticleCount>
	<Articles>
		<?php foreach($reply_posts as $post){ ?>
		<item>
			<Title><![CDATA[<?=$post->title?>]]></Title> 
			<Description><![CDATA[<?=$post->excerpt?>]]></Description>
			<PicUrl><![CDATA[<?=$post->poster->url?>]]></PicUrl>
			<Url><![CDATA[<?=($post->url ?: app_url($post->type . '/' . $post->id))?>?from-mp-account=<?=$mp_account?>&from-scene=auto_reply]]></Url>
		</item>
		<?php } ?>
	</Articles>
</xml> 