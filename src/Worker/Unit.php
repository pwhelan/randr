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
	public $ttl = -1;
	private $sockets = [];
	
	
	private function _start_permanent(array $options)
	{
		$this->sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
		
		stream_set_blocking($this->sockets[0], 0);
		stream_set_blocking($this->sockets[1], 0);
		
		stream_set_chunk_size($this->sockets[0], 512);
		stream_set_chunk_size($this->sockets[1], 512);
		
		
		$pid = pcntl_fork();
		switch($pid) {
			case 0:
				$this->pid = getmypid();
				
				if (array_key_exists('onFork', $options) && is_callable($options['onFork']))
				{
					$cb = $options['onFork'];
					$cb($this);
				}
				
				$loop = \React\EventLoop\Factory::create();
				
				
				$loop->addReadStream($this->sockets[0], function() {
					
					do
					{
						$buf = fread($this->sockets[0], 512);
						if (!$buf)
						{
							break;
						}
						
						$header = (object)unpack("lpackets/llen", $buf);
						for ($i = 1; $i < $header->packets; $i++)
						{
							$buf .= fread($this->sockets[0], 512);
						}
						
						$job = unserialize(substr($buf, 8, $header->len));
						
						$rc = $job->perform();
						$this->emit('finish', [$rc]);
						
						if ($this->ttl > 0)
						{
							$this->ttl--;
						}
						
						if ($this->ttl == 0)
						{
							exit(0);
						}
						
					} while($buf);
					
				});
				
				$loop->run();
				
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
	
	public function __construct($permanent = false, array $options = [])
	{
		$this->permanent = (bool)$permanent;
		
		
		if (array_key_exists('ttl', $options))
		{
			$this->ttl = $options['ttl'];
		}
		
		if ($this->permanent)
		{
			$this->_start_permanent($options);
		}
	}
	
	public function run($job)
	{
		if ($this->permanent)
		{
			$serialized = serialize($job);
			$packets = round((strlen($serialized)+8) / 512) + 1;
			$padding = (512 * $packets) - (strlen($serialized)+8);
			
			$rc = fwrite(
				$this->sockets[1],
				pack("ll", $packets, strlen($serialized)) .
				$serialized . str_repeat("A", $padding)
			);
			fflush($this->sockets[1]);
			
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
