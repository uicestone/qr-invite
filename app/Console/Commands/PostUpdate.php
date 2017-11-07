<?php namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Post;

class PostUpdate extends Command {

	/**
	 * The name and signature of the console command.
	 *
	 * @var string
	 */
	protected $signature = 'post:update
		{--n2nn : 单个换行替换为两个} {--nn2n : 两个换行替换为一个} {--br2n : br标签替换为换行} {--strip-br-p : 去除p和br标签}
		{--save-wx-image : 下载文章中包含的微信图片到本地}';

	/**
	 * The console command description.
	 *
	 * @var string
	 */
	protected $description = '对文章进行批量处理';

	/**
	 * Create a new command instance.
	 */
	public function __construct()
	{
		parent::__construct();
	}

	/**
	 * Execute the console command.
	 *
	 * @return mixed
	 */
	public function handle()
	{
		$posts = Post::whereIn('type', ['article', 'insight', 'topic', 'event'])->get();

		foreach($posts as $post)
		{
			if($this->option('strip-br-p'))
			{
				$post->content = preg_replace('/<p.*?>\s*<br.*?>\s*<\/p>/', '', $post->content);
			}

			if($this->option('br2n'))
			{
				$post->content = preg_replace('/\s*<br.*?>\s*/', "\n", $post->content);
			}

			if($this->option('n2nn'))
			{
				$post->content = preg_replace('/(?<!\n)\n(?!\n)/', "\n\n", $post->content);
			}

			if($this->option('nn2n'))
			{
				$post->content = str_replace('\n\n', "\n", $post->content);
			}

			if($this->option('save-wx-image'))
			{
				$post->saveWeixinImages();
			}

			$post->timestamps = false;
			$post->save();
		}
	}
}
