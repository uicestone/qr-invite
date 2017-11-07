<?php namespace App\Http;

class Request extends \Illuminate\Http\Request  {
	
	/**
	 * read from http request body
	 * parse to data in array
	 */
	public function data($field = null)
	{
		if(is_null($field))
		{
			return array_replace_recursive($this->request->all(), $this->files->all());
		}
		elseif($this->files->get($field))
		{
			return $this->files->get($field);
		}
		else
		{
			$value = $this->request->get($field);
			
			if(is_array($value))
			{
				return $value;
			}
			
			if(strtolower($value) === 'null')
			{
				return null;
			}

			if(in_array(strtolower($value), ['true', 'false']))
			{
				return json_decode($value);
			}

			if($value === true || $value === false)
			{
				return $value;
			}

			return json_decode($value) ? json_decode($value, JSON_OBJECT_AS_ARRAY) : $value;
		}
	}
	
	/**
	 * Get query string arguments.
	 *
	 * add supports of comma separated and JSON arguments
	 */
	public function query($key = null, $default = null, $parse = true)
	{
		$args = parent::query($key, $default);
		
		if($parse)
		{
			$this->parse($args);
		}
		return $args;
	}
	
	protected function parse(&$arg)
	{
		if(is_array($arg))
		{
			foreach($arg as &$a)
			{
				static::parse($a);
			}
		}
		else
		{
			$decoded = json_decode($arg, JSON_OBJECT_AS_ARRAY);

			if(!is_null($decoded))
			{
				$arg = $decoded;
			}
			elseif(str_contains($arg, ','))
			{
				$arg = explode(',', $arg);
			}
		}
		
		return $arg;
		
	}

	public function header($key = null, $default = null)
	{
		$value = parent::header($key, $default);
		
		if($value === 'undefined' || $value === 'null')
		{
			return null;
		}
		
		return $value;
	}
}
