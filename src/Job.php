<?php

namespace Randr;

class Job extends \Resque_Job
{
	public static function reserve($payload)
	{
		throw new \Exception('Unsupported');
	}
}
