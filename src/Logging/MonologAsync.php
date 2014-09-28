<?php

/**
 * WARNING: this file does not support autoloaders!
 */


namespace Monolog\Handler
{
	use Monolog\Logger;
	
	
	/**
	 * Logs to Cube Asynchronously. Adapted from Wan Chen's CubeHandler.
	 *
	 * @author Wan Chen <kami@kamisama.me>
	 * @author Phillip Whelan <pwhelan@mixxx.org>
	 *
	 */
	class CubeAsyncHandler extends AbstractProcessingHandler
	{
		protected $udpConnection = null;
		protected $httpConnection = null;
		protected $scheme = null;
		protected $host = null;
		protected $port = null;
		protected $acceptedSchemes = array('udp'); // TODO: http
		protected $pending = [];
		
		
		/**
		 * Create a Cube handler
		 *
		 * @throws UnexpectedValueException when given url is not a valid url.
		 *         A valid url must consists of three parts : protocol://host:port
		 *         Only valid protocol used by Cube are http and udp
		 */
		public function __construct($url, $level = Logger::DEBUG, $bubble = true)
		{
			$urlInfos = parse_url($url);
			if (!isset($urlInfos['scheme']) || !isset($urlInfos['host']) || !isset($urlInfos['port']))
			{
				throw new \UnexpectedValueException('URL "'.$url.'" is not valid');
			}

			if (!in_array($urlInfos['scheme'], $this->acceptedSchemes))
			{
				throw new \UnexpectedValueException(
					'Invalid protocol (' . $urlInfos['scheme']  . ').'
					. ' Valid options are ' . implode(', ', $this->acceptedSchemes));
			}
			
			$this->scheme = $urlInfos['scheme'];
			$this->host = $urlInfos['host'];
			$this->port = $urlInfos['port'];
			
			parent::__construct($level, $bubble);
		}
		
		/**
		 * Set the EventLoop.
		 */
		public function setEventLoop($loop)
		{
			$this->loop = $loop;
		}
		
		/**
		 * Establish a connection to an UDP socket
		 *
		 * @throws LogicException when unable to connect to the socket
		 */
		public function connectUdp()
		{
			if (!class_exists('\React\Datagram\Socket'))
			{
				throw new MissingExtensionException(
					'The sockets extension is required to use udp URLs with the CubeHandler'
				);
			}
			
			
			$resolver = (new \React\Dns\Resolver\Factory())
				->createCached('8.8.8.8', $this->loop);
			
			
			(new \React\Datagram\Factory($this->loop, $resolver))
				->createClient("{$this->host}:{$this->port}")
					->then(function (\React\Datagram\Socket $client) {
						
						$this->udpConnection = $client;
						while (($msg = array_shift($this->pending)))
						{
							$this->writeUdp($msg);
						}
					});
			
		}
		
		/**
		 * Establish a connection to a http server
		 */
		protected function connectHttp()
		{
			throw new \Exception("Unimplemented");
		}

		/**
		 * {@inheritdoc}
		 */
		protected function write(array $record)
		{
			$date = $record['datetime'];

			$data = array('time' => $date->format('Y-m-d\TH:i:s.uO'));
			unset($record['datetime']);

			if (isset($record['context']['type'])) {
				$data['type'] = $record['context']['type'];
				unset($record['context']['type']);
			} else {
				$data['type'] = $record['channel'];
			}

			$data['data'] = $record['context'];
			$data['data']['level'] = $record['level'];
			
			$this->{'write'.$this->scheme}(json_encode($data));
		}
		
		protected function writeUdp($data)
		{
			if (!$this->udpConnection) {
				$this->pending[] = $data;
				return;
			}
			
			$this->udpConnection->send($data);
		}
		
		protected function writeHttp($data)
		{
			throw new \Exception("Unimplemented");
		}
	}
	
	/**
	 * Logs to Cube Asynchronously using React/Socket, since React/Datagram
	 * seems a bit unstable at the moment. Adapted from Wan Chen's CubeHandler.
	 *
	 * @author Wan Chen <kami@kamisama.me>
	 * @author Phillip Whelan <pwhelan@mixxx.org>
	 *
	 */
	class CubeAsyncSocketHandler extends CubeAsyncHandler
	{
		/**
		 * Establish a connection to an UDP socket
		 *
		 * @throws LogicException when unable to connect to the socket
		 */
		public function connectUdp()
		{
			$fd = stream_socket_client("udp://{$this->host}:{$this->port}", $errno, $errstr, 0, STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT);
			if (!$fd)
			{
				die("WTF: {$errstr}\n");
			}
			stream_set_blocking($fd, 0);
			$this->udpConnection = new \React\Socket\Connection($fd, $this->loop);
			
			while (($msg = array_shift($this->pending)))
			{
				$this->writeUdp($msg);
			}
		}
		
		/**
		* Establish a connection to a http server
		*/
		protected function connectHttp()
		{
			throw new \Exception("Unimplemented");
		}
		
		protected function writeUdp($data)
		{
			if (!$this->udpConnection) {
				$this->pending[] = $data;
				return;
			}
			
			$this->udpConnection->write($data);
		}
	}
	
}

namespace MonologInit
{
	class MonologInitAsync extends MonologInit
	{
		public function setEventLoop($loop)
		{
			$this->loop = $loop;
		}
		
		public function __construct($loop, $handler = false, $target = false)
		{
			$this->setEventLoop($loop);
			parent::__construct($handler, $target);
		}
		
		public function initCubeAsyncHandler($args)
		{
			$reflect  = new \ReflectionClass('\Monolog\Handler\CubeAsyncHandler');
			$handler = $reflect->newInstanceArgs($args);
			$handler->setEventLoop($this->loop);
			$handler->connectUdp();
			
			return $handler;
		}
		
		public function initCubeAsyncSocketHandler($args)
		{
			$reflect  = new \ReflectionClass('\Monolog\Handler\CubeAsyncSocketHandler');
			$handler = $reflect->newInstanceArgs($args);
			$handler->setEventLoop($this->loop);
			$handler->connectUdp();
			
			return $handler;
		}
	}
}
