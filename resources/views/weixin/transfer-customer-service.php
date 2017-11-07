<xml>
	<ToUserName><![CDATA[<?=$received_message['FromUserName']?>]]></ToUserName>
	<FromUserName><![CDATA[<?=$received_message['ToUserName']?>]]></FromUserName>
	<CreateTime><?=time()?></CreateTime>
	<MsgType><![CDATA[transfer_customer_service]]></MsgType>
</xml>