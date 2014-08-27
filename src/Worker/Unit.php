<?php

namespace Randr\Worker;

/**
 * Worker Unit.
 *
 * A single unit in a worker pool.
 *
 * @author Phillip Whelan <pwhelan@mixxx.org>
 */
class Unit extends \Evenement\EventEmitter
{
	public $permanent = false;
	public $pid;
	private $sockets = [];
	
	
	private function _start_permanent($loop)
	{
		socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $this->sockets);
		
		
		$pid = pcntl_fork();
		switch($pid) {
			case 0:
				$this->emit('fork');
				while(true)
				{
					$loop = \React\EventLoop\Factory::create();
					$pcntl = new \MKraemer\ReactPCNTL\PCNTL($loop);
					$pcntl->on(SIGUSR1, function() {
						$l = unpack("llen", socket_read($this->sockets[1], 4));
						$b = socket_read($this->sockets[1], $l['len']);
						$job = unserialize($b);
						
						
						$rc = $job->perform();
						
						$this->emit('finish', [$rc]);
					});
					
					$loop->run();
				}
				break;
			case -1:
				$this->emit('error');
				break;
			default:
				$this->pid = $pid;
				$this->emit('forked', [$pid]);
				break;
		}
	}
	
	public function __construct($loop, $permanent = false)
	{
		$this->permanent = (bool)$permanent;
		if ($this->permanent)
		{
			$this->_start_permanent($loop);
		}
	}
	
	public function run($job)
	{
		if ($this->permanent)
		{
			$serialized = serialize($job);
			socket_write($this->sockets[0], pack("l", strlen($serialized)) . $serialized);
			posix_kill($this->pid, SIGUSR1);
			
			return;
		}
		
		$pid = pcntl_fork();
		switch($pid) {
			case 0:
				$job->perform();
				exit(0);
				break;
			case -1:
				$this->emit('error');
				break;
			default:
				$this->emit('forked', [$pid]);
				break;
		}
	}
}
