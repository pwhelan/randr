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
	public $job;
	public $start;
	private $sockets = [];
	
	private function perform($job)
	{
		$success = false;
		try {
			if (!$job instanceof \Randr\Job)
			{
				throw new \Exception('Job is not a class');
			}
			
			\Resque_Event::trigger('afterFork', $job);
			$rc = $job->perform();
			//$this->log([
			//	'message' => 'done ID:' . $job->payload['id'],
			//	'data' => [
			//		'type' => 'done',
			//		'job_id' => $job->payload['id'],
			//		'time' => round(microtime(true) - $startTime, 3) * 1000
			//	]
			//], self::LOG_TYPE_INFO)
			$success = true;
		}
		catch (Exception $e)
		{
			/*$this->log([
				'message' => $job . ' failed: ' . $e->getMessage(),
				'data' => [
					'type' => 'fail',
					'log' => $e->getMessage(),
					'job_id' => $job->payload['id'],
					'time' => round(microtime(true) - $startTime, 3) * 1000
				]],
				Resque_Worker::LOG_TYPE_ERROR
			);
			*/
			if ($job instanceof \Randr\Job)
			{
				$job->fail($e);
			}
			print "ERROR: ".$e->getMessage()."\n";
		}
		
		$this->emit('finish', [$success]);
		$job->updateStatus(\Resque_Job_Status::STATUS_COMPLETE);
		
		
		if ($this->ttl > 0)
		{
			$this->ttl--;
		}
		
		if ($this->ttl == 0)
		{
			print "TIME TO DIE for ".getmypid()."\n";
			exit(0);
		}
	}
	
	private function _start_permanent(array $options)
	{
		$this->sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
		
		for ($i = 0; $i < 2; $i++)
		{
			stream_set_blocking($this->sockets[$i], 0);
			//stream_set_chunk_size($this->sockets[$i], 512);
			//$i == 0 ? stream_set_read_buffer($this->sockets[$i], 512) :
			//		stream_set_write_buffer($this->sockets[$i], 512);
		}
		
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
						$buf = "";
						do
						{
							$part = fread($this->sockets[0], 512 - strlen($buf));
							if (!$part)
							{
								goto done;
							}
							
							$buf .= $part;
						} while (strlen($buf) < 512);
						
						
						if (strlen($buf) < 512)
						{
							print "BUF IS TOO SHORT\n";
						}
						
						
						$header = (object)unpack("Npackets/Nlen", substr($buf, 0, 16));
						//print "HEADER = ".print_r($header, true)."\n";
						
						for ($i = 1; $i < $header->packets; $i++)
						{
							$packet = "";
							do
							{
								$part = fread($this->sockets[0], 512 - strlen($packet));
								if (!$part)
								{
									goto done;
								}
								
								$packet .= $part;
							} while (strlen($packet) < 512);
							
							$buf .= $packet;
						}
						
						$content = substr($buf, 8, $header->len);
						if ($header->len > strlen($content))
						{
							print "INCOMPLETE OR BAD PACKET, MISSING: ".($header->len - strlen($content))." bytes\n";
							break;
						}
						
						/*
						print wordwrap(preg_replace('/(.{8})/', '$1 ', bin2hex($buf)));
						print "\n";
						*/
						
						$job = @unserialize($content);
						if (!$job) {
							print "UNABLE TO UNSERIALIZE JOB:\n".$job."\n";
							break;
						}
						
						$this->perform($job);
						
					} while($buf);
					done:
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
		$this->job = $job;
		$this->start = microtime(true);
		
		
		if ($this->permanent)
		{
			$serialized = serialize($job);
			$numpackets = 1 + floor((strlen($serialized)+8) / 512);
			$padding = (512 * $numpackets) - (strlen($serialized)+8);
			
			$packets = pack("NN", $numpackets, strlen($serialized)) .
				$serialized . str_repeat("A", $padding);
			
			$rc = fwrite($this->sockets[1],	$packets);
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
