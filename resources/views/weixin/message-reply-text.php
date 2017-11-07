<xml>
	<ToUserName><![CDATA[<?=$received_message->FromUserName?>]]></ToUserName>
	<FromUserName><![CDATA[<?=$received_message->ToUserName?>]]></FromUserName> 
	<CreateTime><?=time()?></CreateTime>
	<MsgType><![CDATA[text]]></MsgType>
	<Content><![CDATA[<?=$content?>]]></Content>
</xml>
