<?php

namespace Randr\Worker;

use \MKraemer\ReactPCNTL;

/**
 * Worker Pool.
 *
 * A worker pool that allows both preforking and dynamic growth. The initialize
 * method must be called for preforking to work. At the moment Any extra workers
 * are simply killed off once used. No attempt is made to reclaim any preforked
 * processes or to regenerate them in case of fail.
 *
 * @author Phillip Whelan <pwhelan@mixxx.org>
 */
class Pool extends \Evenement\EventEmitter
{
	/**
	 * A list of the current workers, indexed by process id.
	 */
	private $workers = [];
	
	/**
	* A list of the current workers, indexed by process id.
	*/
	private $running = [];
	
	/**
	 * A list of the unused pooled workers.
	 */
	private $pool = [];
	
	/**
	 * A list of the current workers, indexed by process id.
	 */
	private $sockets = [];
	
	/**
	 * The event loop this worker pool is attached to.
	 */
	private $loop;
	
	/**
	 * Default TTL for workers.
	 */
	private $worker_ttl = -1;
	
	
	public function run($job)
	{
		if (count($this->pool) > 0)
		{
			$worker = array_shift($this->pool);
			$this->workers[$worker->pid] = $worker;
		}
		else
		{
			$worker = new Unit();
			$worker = new Unit($this->loop);
			$worker->on('forked', function($pid) use ($worker) {
				$this->workers[$pid] = $worker;
			});
		}
		
		$this->emit('process', [$worker, $job]);
		$worker->run($job);
		
		return $worker;
	}
	
	public function __construct($loop, $queues = null, $worker_ttl = -1)
	{
		$this->loop = $loop;
		$this->worker_ttl = $worker_ttl;
		
		
		$pcntl = new ReactPCNTL\PCNTL($loop);
		$pcntl->on(SIGCHLD, function() {
			
			$pid = pcntl_wait($status);
			if (isset($this->workers[$pid]))
			{
				$worker = $this->workers[$pid];
				$worker->emit('exit', [pcntl_wexitstatus($status)]);
				
				
				// permanent workers should not die
				if ($worker->permanent)
				{
					$worker->emit('fail', [pcntl_wexitstatus($status)]);
				}
				else
				{
					$worker->emit('done');
				}
				
				unset($this->workers[$pid]);
			}
			else
			{
				for ($i = 0; $i < count($this->pool) ; $i++)
				{
					if ($this->pool[$i]->pid == $pid)
					{
						array_splice($this->pool, $i, 1);
						$this->NewWorkerUnit();
						
						break;
					}
				}
			}
		});
		
		
		$pcntl->on(SIGUSR2, function() {
			print "Workers:\n";
			foreach ($this->pool as $worker)
			{
				print "[I] {$worker->pid} ttl={$worker->ttl}\n";
			}
			foreach ($this->workers as $worker)
			{
				print "[R] {$worker->pid} ttl={$worker->ttl}\n";
			}
		});
		
		
		$this->sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
		
		for ($i = 0; $i < 2; $i++)
		{
			stream_set_blocking($this->sockets[$i], 0);
			//stream_set_chunk_size($this->sockets[$i], 512);
			//if ($i == 0) stream_set_read_buffer($this->sockets[$i], 512);
			//if ($i == 1) stream_set_write_buffer($this->sockets[$i], 512);
		}
		
		$loop->addReadStream($this->sockets[0], function($socket) {
			
			do {
				$buf = stream_socket_recvfrom($socket, 512);
				if (!$buf)
				{
					break;
				}
				
				if (strlen($buf) != 512)
				{
					print "BAD NEWS\n";
				}
				
				
				$info = unpack("Npid/Nstatus", substr($buf, 0, 8));
				
				
				if (isset($this->workers[$info['pid']]))
				{
					$worker = $this->workers[$info['pid']];
					$worker->emit('exit', [$info['status']]);
					
					
					if ($worker->ttl > 0)
					{
						$worker->ttl--;
					}
					
					unset($this->workers[$info['pid']]);
					$this->pool[] = $worker;
				}
				else
				{
					$workers = array_filter(
						$this->pool,
						function ($worker) use ($info) {
							return $worker->pid == $info['pid'];
						}
					);
					
					if (count($workers) > 0) print "WORKER ALREADY IN POOL\n";
					else print "NO SUCH WORKER\n";
					//print_r($worker);
				}
				
			} while (0); //$buf);
		});
		
		
		if ($queues && ($worker_ttl > 1 || $worker_ttl < 0))
		{
			// Count the maximum workers and add half.
			// Good enough rule of thumb for now.
			$workernum = $queues
				->map(function($queue) {
					return $queue->workers->max;
				})
				->reduce(function($res, $item) {
					if ($res < $item) {
						$res = $item;
					}
					return $res;
				}, 0);
			
			$workernum *= 1.5;
			$workernum = round($workernum);
			
			for ($i = 0; $i < $workernum; $i++)
			{
				$this->NewWorkerUnit();
			}
		}
	}
	
	private function NewWorkerUnit()
	{
		$this->pool[] = new Unit(true, [
			'onFork'=> function($worker) {
				$worker->on('finish', function($status) {
					$rc = stream_socket_sendto(
						$this->sockets[1], 
						pack("NN", getmypid(), $status) . 
							str_repeat("A", 512 - 8)
					);
					fflush($this->sockets[1]);
					
					if ($rc != 512)
					{
						print "UH OH!\n";
					}
				});
			},
			'ttl'	=> $this->worker_ttl
		]);
	}
}
