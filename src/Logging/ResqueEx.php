<?php

namespace Randr\Logging;


/*
 * Implements Resque Ex compatible logging. This is used for logging to a
 * Cube server for ResqueBoard.
 */
class ResqueEx
{
	public function __construct($wp)
	{
		$wp->on('start', function($worker) {
			$worker->log([
				'message' => 'Starting worker ' . $worker,
				'data' => [
					'type' => 'start',
					'worker' => (string) $worker
				]],
				\Resque_Worker::LOG_TYPE_INFO
			);
		});
		
		$wp->on('stop', function($worker) {
			
			$worker->log([
				'message' => 'Exiting...',
				'data' => [
					'type'		=> 'shutdown',
					'worker'	=> getmypid() //(string)$worker
				]],
				\Resque_Worker::LOG_TYPE_INFO
			);
		});
		
		$wp->on('process', function($resque, $worker, $job) {
			$resque->log([
				'message' => 'Processing ID:' . $job->payload['id'] .  ' in ' . $job->queue,
				'data' => [
					'type' => 'process',
					'worker' => function_exists('gethostname') ?
						gethostname() : php_uname('n') .
						':' . getmypid(),
					'job_id' => $job->payload['id']
				]],
				\Resque_Worker::LOG_TYPE_INFO
			);
		});
		
		$wp->on('got', function($worker, $job) {
			$worker->log([
				'message' => 'got ' . $job,
				'data' => [
					'type' => 'got',
					'args' => $job
				]],
				\Resque_Worker::LOG_TYPE_INFO
			);
		});
		
		$wp->on('check', function($worker, $queue) {
			$worker->log([
				'message' => 'Checking ' . $queue,
				'data' => [
					'type' => 'check',
					'queue' => $queue
				]],
				\Resque_Worker::LOG_TYPE_DEBUG
			);
		});
		
		$wp->on('found', function($worker, $queue) {
			$worker->log([
				'message' => 'Found job on ' . $queue,
				'data' => [
					'type' => 'found',
					'queue' => $queue
				]],
				\Resque_Worker::LOG_TYPE_DEBUG
			);
		});
		
		$wp->on('done', function($worker, $job, $time) {
			
			$worker->log([
				'message' => 'done ID:' . $job->payload['id'],
				'data' => [
					'type' => 'done',
					'job_id' => $job->payload['id'],
					'time' => $time
				]],
				\Resque_Worker::LOG_TYPE_INFO
			);
			
		});
		
		$wp->on('fail', function($worker, $job, $message, $time) {
			
			$worker->log([
				'message' => $job . ' failed: ' . $message,
				'data' => [
					'type' => 'fail',
					'log' => $message,
					'job_id' => $job->payload['id'],
					'time' => $time //round(microtime(true) - $startTime, 3) * 1000
				]],
				\Resque_Worker::LOG_TYPE_ERROR
			);
			
		});
		
		$wp->on('forked', function($worker, $job = null) {
			$msg = 'Forked'.($job ?
				' for ID: '.$job->payload['id'] : ''
			);
			
			$worker->log([
				'message' => $msg,
				'data' => [
					'type' => 'fork',
					'log' => $msg,
					'job_id' => $job ?
						$job->payload['id'] :
						'worker: '.$worker
				]],
				\Resque_Worker::LOG_TYPE_DEBUG
			);
		});
	}
}
