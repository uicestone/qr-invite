<?php namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Meta, App\Profile;
use Log;

class ProfileJsonCheck extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'profile:json-check';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '检查Profile的JSON格式';

    /**
     * Create a new command instance.
     *
     * @return void
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
    	$bar = $this->output->createProgressBar(Profile::where('value', 'like', '{%')->orWhere('value', 'like', '[%')->count());
        Profile::where('value', 'like', '{%')->orWhere('value', 'like', '[%')->chunk(1E4, function($profiles) use($bar)
        {
			$profiles->each(function(Profile $profile)
			{
				if(is_string($profile->value))
				{
					Log::warning('错误的Profile JSON格式: ' . $profile->id . ' ' . $profile->value);
				}

				if(is_object($profile->value) && array_reduce(array_keys((array)$profile->value), function($previous, $value){return $previous && is_numeric($value);}, true))
				{
					$profile->value = array_values((array)$profile->value);
					$profile->save();
					Log::info('已修复似乎是数组的JSON对象: ' . $profile->id . ' ' . $profile->key . ' ' . $profile->getOriginal('value'));
				}
				
				if(str_contains($profile->getOriginal('value'), '\\u'))
				{
					Log::info('Profile' . $profile->id . ' 已转为未转义的Unicode');
					$profile->value = $profile->value;
					$profile->save();
				}
			});
	
			$bar->advance(1E4);
        });
	
//		$this->output->createProgressBar(Meta::where('value', 'like', '{%')->orWhere('value', 'like', '[%')->count())->start();
//		$this->output->progressStart();
		Meta::where('value', 'like', '{%')->orWhere('value', 'like', '[%')->chunk(1E4, function($metas)
		{
			$metas->each(function($meta)
			{
				if(is_string($meta->value))
				{
					$this->error('错误的Meta JSON格式: ' . $meta->id . ' ' . $meta->value);
				}

				if(is_object($meta->value) && array_reduce(array_keys((array)$meta->value), function($previous, $value){return $previous && is_numeric($value);}, true))
				{
					$this->error('似乎是数组的JSON对象: ' . $meta->id . ' ' . $meta->key . ' ' . $meta->getOriginal('value'));
					$meta->key = array_values((array)$meta->value);
					$meta->save();
					$this->info('已修复: ' . $meta->id);
				}
			});
			
//			$this->output->progressAdvance(1E4);
		});
//		$this->output->progressFinish();
    }
}
