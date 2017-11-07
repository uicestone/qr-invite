<?php namespace App;

use Buzz, Log;

class Sms {
	
	public static function send($mobile, $text)
	{
		if(is_array($mobile))
		{
			$results = [];
			
			foreach(array_chunk($mobile, 500) as $mobiles)
			{
				$response = self::send(implode(',', $mobiles), $text);
				$results[] = $response;
			}
			
			return $results;
		}
		
		Log::info('即将向 ' . $mobile . ' 发送短信 ' . $text);
		
		$client = new Buzz\Browser();
		$response = $client->post('http://yunpian.com/v1/sms/send.json', [], http_build_query([
			'apikey'=>env('YUNPIAN_APIKEY'),
			'mobile'=>$mobile,
			'text'=>$text
		]));
		
		Log::info('向 ' . $mobile . ' 发送了短信 ' . $text . '. ' . json_encode($response->getContent(), JSON_UNESCAPED_UNICODE));
		
		return json_decode($response->getContent());
	}

}
