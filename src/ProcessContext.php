<?php

namespace Randr;


class ProcessContext extends \ArrayObject
{
	public function __construct($props)
	{
		foreach($props as $key => $val)
		{
			$this->{$key} = $val;
		}
		//parent::__construct($props, \ArrayObject::ARRAY_AS_PROPS);
	}
	
	public function __call($func, $args)
	{
		if (isset($this->$func))
		{
			return call_user_func_array($this->$func, $args);
		}
	}
}
