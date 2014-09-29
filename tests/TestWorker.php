<?php

class TestWorker
{
	/*
	public function setUp()
	{
		print "SETTING UP\n";
	}
	
	public function tearDown()
	{
		print "TEAR IT DOWN\n";
	}
	*/
	
	public function perform()
	{
		// Create touch file for cron job
		if (count($this->args) > 0 && isset($this->args[0]))
		{
			file_put_contents(__DIR__.'/../cron.output', $this->args['time']);
		}
	}
}
