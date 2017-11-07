<?php

namespace App\Console\Commands;

use App\Profile;
use App\User;
use Illuminate\Console\Command;

class UserUpdateChildren extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'user:update-children';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '更新user.profiles.孩子信息';

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
	    $profiles = Profile::where('key', '孩子')
		            ->where('id', 1384363)
		            ->get();
	    
	    $bar = $this->output->createProgressBar(count($profiles));
	    
	    $profiles->each(function(Profile $profile) use($bar) {
		    $children = $profile->value;
		    $temp_child = [];
		    if (count($children))
		    {
			    try{
				    foreach($children as $child)
				    {
					    $temp_child[] = $this->compileAttribute($child);
				    }
				    $result = $this->filterChild($temp_child);
				    if(count($result) > 1)
				    {
					    $profile->value = $result;
					    $profile->save();
				    }
				    else
				    {
					    $profile->delete();
				    }
			    }
			    catch (\Exception $e)
			    {
				    print_r($e->getTraceAsString());
				    exit();
			    }
			
		    }
		    else{
			    $this->info(json_encode($profile));
			    $profile->delete();
		    }
		    $bar->advance();
	    });
	   
	    $bar->finish();
    }
    
    protected function compileAttribute($child)
    {
	    if(property_exists($child,'年龄') && !property_exists($child, 'grade'))
	    {
		    $child->grade = $child->{"年龄"};
		    unset($child->{"年龄"});
	    }
	    else
        {
		    unset($child->{"年龄"});
	    }
	
	    if(property_exists($child,'年级') && !property_exists($child, 'grade'))
	    {
		    $child->grade = $child->{"年级"};
		    unset($child->{"年级"});
	    }
	    else
	    {
		    unset($child->{"年级"});
	    }
	
	    if(property_exists($child,'性别') && !property_exists($child, 'sex'))
	    {
		    $child->sex = $child->{"性别"};
		    unset($child->{"性别"});
	    }
	    else
	    {
		    unset($child->{"性别"});
	    }
	
	    if(property_exists($child,'地区') && !property_exists($child, 'city'))
	    {
		    $child->city = $child->{"地区"};
		    unset($child->{"地区"});
	    }
	    else
	    {
		    unset($child->{"地区"});
	    }
	
	    if(property_exists($child,'学校') && !property_exists($child, 'school'))
	    {
		    $child->school = $child->{"学校"};
		    unset($child->{"学校"});
	    }
	    else
	    {
		    unset($child->{"学校"});
	    }
	    var_dump($child);
	    return $child;
    }
    
    protected function formatProperty($child)
    {
	    $exits_property = ['name', 'birth', 'grade', 'school'];
	    $temp_child = (object)[];
	    foreach($exits_property as $property)
	    {
		    if(!property_exists($child, $property))
		    {
			    $temp_child->{$property} = '';
		    }
		    else
	        {
		        $temp_child->{$property} = $child->{$property};
	        }
	    }
	    return $temp_child;
    }
    
    protected function mergeChild($children)
    {
	    $temp = [];
	    
	    foreach($children as $child)
	    {
		    foreach($child as $key => $val)
		    {
			    try{
				    $this->info($key);
				    $temp[$key] = $val;
				    
			    } catch (\Exception $e)
			    {
				    print_r($e->getTraceAsString());
			    }
		    }
	    }
	    return $temp;
    }
    
    protected function filterChild($children)
    {
	    if(count($children) > 1)
	    {
		    foreach($children as $key => $child) {
			    if(is_object($child)){
				    $child = json_decode(json_encode($child),true);
				    if(count($child) < 4)
				    {
					    unset($children[$key]);
				    }
				    else
				    {
					    $children[$key] = $this->formatProperty(json_decode(json_encode($child)));
				    }
			    }
		    }
	    }
	    elseif(count($children) > 0)
	    {
		    foreach($children as $key => $child) {
			    if(is_object($child)){
				    $children[$key] = $this->formatProperty($child);
			    }
		    }
	    }
	    
	    return $children;
    }
}
