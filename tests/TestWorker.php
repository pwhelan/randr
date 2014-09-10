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
		$sleeps = [1, 1, 1, 1, 1, 1, 1, 1, 2, 2, 2, 2, 2, 3, 3, 4, 4, 5, 6, 7, 10];
		//print "DOIN' IT\n";
		//usleep($sleeps[rand(0, count($sleeps)-1)] * rand(100, 1000));
		//print "DONE\n";
	}
}
