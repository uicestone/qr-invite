<?php namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Post, App\User, App\Weixin;
use RuntimeException;

class WeixinDownloadMedia extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'weixin:download-media {account? : 微信公众号代号}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '下载微信媒体文件到本地, 更新数据库并触发七牛';

    /**
     * Create a new command instance.
     */
    public function __construct()
    {
        parent::__construct();
        app()->user = new User();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $weixin = new Weixin($this->argument('account'));
        $posts = Post::where('url', 'like', 'wx://%')->get();

		$posts->each(function(Post $post) use($weixin)
		{
			preg_match('/wx:\/\/(.*)/', $post->getOriginal('url'), $match);
			$media_id =  $match[1];
			
			try
			{
				$local_path = $weixin->downloadMedia($media_id);
			}
			catch(RuntimeException $exception)
			{
				$this->error($exception->getMessage());
				return;
			}
			
			$post->uploadFile($local_path);
	
            $this->info($media_id . ' 已上传至CDN, URL: ' . $post->url);
		});
    }
}
