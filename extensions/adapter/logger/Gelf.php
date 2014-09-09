<?php
/**
 * Lithium: the most rad php framework
 *
 * @copyright     Copyright 2012, Union of RAD (http://union-of-rad.org)
 * @license       http://opensource.org/licenses/bsd-license.php The BSD License
 */


namespace li3_gelf\extensions\adapter\logger;

use InvalidArgumentException;

use lithium\core\NetworkException;
use lithium\core\ConfigException;

/**
 * The `Gelf` logger implements support for the [ GELF](http://www.graylog2.org/about/gelf) message system
 * Roughly ported from https://github.com/Graylog2/gelf-php
 */
class Gelf extends \lithium\core\Object {

	/**
	 * Array that maps `Logger` message priority names to GELF-compatible priority levels.
	 *
	 * @var array
	 */
	protected $_levels = array(
		'emergency' => 0,
		'alert'  	=> 1,
		'critical'  => 2,
		'error' 	=> 3,
		'warning' 	=> 4,
		'notice' 	=> 5,
		'info' 	    => 6,
		'debug' 	=> 7,

	);

  /**
	 * @var integer
	 */
	const CHUNK_SIZE_WAN = 1420;

	/**
	 * @var integer
	 */
	const CHUNK_SIZE_LAN = 8154;

	/**
	 * @var string
	 */
	const GRAYLOG2_PROTOCOL_VERSION = '1.0';

	protected $_connection = null;

	protected $_autoConfig = array('connection');

	public function __construct(array $config = array()) {

		$defaults = array(
			'host'     		=> '127.0.0.1',
			'port'     		=> 12201,
			'chunkSize' 	=> self::CHUNK_SIZE_WAN,
			'registered' 	=> false
		);
		$config += $defaults;

		if (empty($config['host'])) {
			throw new ConfigException('$host must be set.');
		}

		if(!is_numeric($config['port'])) {
			throw new ConfigException('$port must be an integer.');
		}


		if(!is_numeric($config['chunkSize'])) {
			throw new ConfigException('$chunkSize must be an integer.');
		}

		parent::__construct($config + $defaults);
	}

	public function write($type, $message, array $options = array()) {
		$_self =& $this;
		$_levels = $this->_levels;

		return function($self, $params) use (&$_self, $_levels,$type) {
			$level = 5;
			$options = $params['options'];

			$level = $_levels[$type];

			if (isset($options['level']) && isset($_priorities[$options['level']])) {
				$priority = $_priorities[$options['level']];
			}
			return $_self->notify($params['message'], compact('level') + $options);
		};
	}

	public function notify($description = '', $options = array()) {
			$current = array('file' => 'unkonwn', 'line' => 0);
			$trace = debug_backtrace();
			foreach($trace as $key => $files) {
				if(isset($files['file']) && (strpos($files['file'], 'Logger.php') !== false)) {
					$current = $trace[$key+1];
					break;
				}
			}

			if(isset($options['additional'])) {
				if(is_object($options['additional'])) {
					$options['additional'] = get_object_vars($options['additional']);
				}
				if(isset($this->_config['defaults'])) {
					$options['additional'] += $this->_config['defaults'];
				}
				if(is_array($options['additional'])) {
					foreach($options['additional'] as $key => $value) {
						$options["_" . trim($key)] = $value;
					}
				} else {
					$options["_" . trim($options['additional'])] = $options['additional'];
				}
				unset($options['additional']);
			}

			$defaults = array('level' 		  => 5,
							  'version' 	  => self::GRAYLOG2_PROTOCOL_VERSION,
							  'timestamp' 	  => time(),
							  'file'		  => $current['file'],
							  'short_message' => $description,
							  'host'		  => $_SERVER['REMOTE_ADDR'],
							  'version'		  => self::GRAYLOG2_PROTOCOL_VERSION,
							  'line'		  => $current['line']);

			$message = $options += $defaults;

			// Encode the message as json string and compress it using gzip
			$preparedMessage = $this->getPreparedMessage($message);

			// Open a udp connection to graylog server
			$socket = $this->_connection();

			// Several udp writes are required to publish the message
			if($this->isMessageSizeGreaterChunkSize($preparedMessage)) {

				// A unique id which consists of the microtime and a random value
				$messageId = $this->getMessageId();

				// Split the message into chunks
				$messageChunks = $this->getMessageChunks($preparedMessage);
				$messageChunksCount = count($messageChunks);

				// Send chunks to graylog server
				foreach(array_values($messageChunks) as $messageChunkIndex => $messageChunk) {
						$bytesWritten = $this->writeMessageChunkToSocket(
						$socket,
						$messageId,
						$messageChunk,
						$messageChunkIndex,
						$messageChunksCount
					);

					if(false === $bytesWritten) {
						// Abort due to write error
						return false;
					}
				}
			} else {
				// A single write is enough to get the message published
				if(false === $this->writeMessageToSocket($socket, $preparedMessage)) {
					// Abort due to write error
					return false;
				}
			}

			// This increases stability a lot if messages are sent in a loop
			// A value of 20 means 0.02 ms
			usleep(20);

			// Message successful sent
			return true;


	}

	/**
	 * @param resource $socket
	 * @param float $messageId
	 * @param string $messageChunk
	 * @param integer $messageChunkIndex
	 * @param integer $messageChunksCount
	 * @return integer|boolean
	 */
	protected function writeMessageChunkToSocket($socket, $messageId, $messageChunk, $messageChunkIndex, $messageChunksCount) {
		return fwrite(
			$socket,
			$this->prependChunkInformation($messageId, $messageChunk, $messageChunkIndex, $messageChunksCount)
		);
	}

	/**
	 * @param resource $socket
	 * @param string $preparedMessage
	 * @return integer|boolean
	 */
	protected function writeMessageToSocket($socket, $preparedMessage) {
		return fwrite($socket, $preparedMessage);
	}

	/**
	 * @param float $messageId
	 * @param string $data
	 * @param integer $sequence
	 * @param integer $sequenceSize
	 * @throws InvalidArgumentException
	 * @return string
	 */
	protected function prependChunkInformation($messageId, $data, $sequence, $sequenceSize) {
		if(!is_string($data) || $data === '') {
			throw new InvalidArgumentException('Data must be a string and not be empty.');
		}

		if(!is_integer($sequence) || !is_integer($sequenceSize)) {
			throw new InvalidArgumentException('Sequence number and size must be integer.');
		}

		if($sequenceSize <= 0) {
			throw new InvalidArgumentException('Sequence size must be greater than 0.');
		}

		if($sequence > $sequenceSize) {
			throw new InvalidArgumentException('Sequence size must be greater than sequence number.');
		}

		return pack('CC', 30, 15) . substr(md5($messageId, true), 0, 8) . pack('CC', $sequence, $sequenceSize) . $data;
	}

	protected function _connection() {
		if ($this->_connection) {
			return $this->_connection;
		}

		if ($this->_connection = stream_socket_client(sprintf('udp://%s:%d', gethostbyname($this->_config['host']), $this->_config['port']))) {
			return $this->_connection;
		}
		throw new NetworkException("Gelf connection failed.");
	}

	/**
	 * @param array $message
	 * @return string
	 */
	protected function getPreparedMessage( Array $message) {
		return gzcompress(json_encode($message));
	}


	/**
	 * @param string $preparedMessage
	 * @return boolean
	 */
	protected function isMessageSizeGreaterChunkSize($preparedMessage) {
		return (strlen($preparedMessage) > $this->_config['chunkSize']);
	}

	/**
	 * @return float
	 */
	protected function getMessageId() {
		return (float) (microtime(true) . mt_rand(0, 10000));
	}

	/**
	 * @param string $preparedMessage
	 * @return array
	 */
	protected function getMessageChunks($preparedMessage) {
		return str_split($preparedMessage,  $this->_config['chunkSize']);
	}

}

?>