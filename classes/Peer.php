<?php

require_once __DIR__.'/Download.php';
require_once __DIR__.'/Util.php';

class Peer {
	protected $download;
	protected $id;
	protected $status;
	protected $stream;
	protected $connected;
	protected $ip, $port;
	protected $totalBytesReceived, $totalBytesSent;
	protected $header;
	protected $payloadLength;
	protected $payload;
	protected $error;
	protected $hasSentHandshake;
	protected $sendBuffer;
	protected $bitfield;
	protected $requestCount = 0;

	public function __construct(Download $download, $ip, $port) {
		if (!Util::isIPv4($ip)) { throw new Exception("Must pass an IP"); }
		if ($port <= 0) { throw new Exception("Port must be positive"); }
		if ($port >= 65535) { throw new Exception("Port number is too big"); }

		$this->id = null;
		$this->download = $download;
		$this->ip = $ip;
		$this->port = $port;
		$this->status = self::STATUS_CHOKED;
		$this->totalBytesSent = 0;
		$this->totalBytesReceived = 0;
		$this->stream = null;
		$this->bitfield = null;
		$this->connected = false;
		$this->resetBuffers();
	}

	protected function resetBuffers() {
		$this->payload = '';
		$this->payloadLength = null;
		$this->hasSentHandshake = false;
		$this->sendBuffer = '';
	}

	public function disconnect() {
		if (isset($this->stream)) {
			$this->download->unregisterPeerStream($this);
			fclose($this->stream);
			$this->stream = null;
		}

		$this->connected = false;
		$this->resetBuffers();
	}

	public function connect() {
		if (isset($this->error)) {
			return;
		}

		if (isset($this->stream)) {
			return;
		}

		$this->resetBuffers();
		$this->connected = false;
		$this->status = self::STATUS_CHOKED;
		$errno = null;
		$errstr = null;
		$this->stream = stream_socket_client(
			"tcp://{$this->ip}:{$this->port}",
			$errno, $errstr,
			0,
			STREAM_CLIENT_ASYNC_CONNECT
		);

		if (empty($this->stream)) {
			throw new Exception("Cannot create stream");
		}

		if (isset($errno) && $errno !== 0) {
			throw new Exception("Cannot connect stream: $errstr");
		}

		if (!stream_set_blocking($this->stream, false)) {
			throw new Exception("Cannot set non-blocking stream");
		}

		$this->download->registerPeerStream($this);
	}

	public function hasPiece($pieceIndex) {
		$this->download->assertPieceIndex($pieceIndex);

		if (!isset($this->bitfield)) {
			return false;
		}

		$byteIndex = (int)floor($pieceIndex / 8);
		$bitIndex = ($byteIndex * 8) - $pieceIndex;
		$byte = ord($this->bitfield[$byteIndex]);
		$mask = (1 << $bitIndex);

		return ($byte & $mask) !== 0;
	}

	public function updateBitfield($pieceIndex) {
		$this->download->assertIndex($pieceIndex);

		if (!isset($this->bitfield)) {
			$byteCount = (int)ceil($this->download->getPieceCount() / 8);
			$this->bitfield = str_repeat(chr(0), $byteCount);
		}

		$byteIndex = (int)floor($pieceIndex / 8);
		$bitIndex = ($byteIndex * 8) - $pieceIndex;
		$byte = ord($this->bitfield[$byteIndex]);
		$byte |= (1 << $bitIndex);
		$byte = chr($byte);
		$this->bitfield = Util::updateString($this->bitfield, $byteIndex, $byte);
	}

	public function setStatus($flag) {
		$this->status |= $flag;
	}

	public function clearStatus($flag) {
		$this->status &= (~$flag);
	}

	public function hasError() {
		return isset($this->error);
	}

	public function getError() {
		return $this->error;
	}

	/** Gets a 20 byte ID each peer generated */
	public function getPeerID() {
		return $this->id;
	}

	/** Get the IP address of the peer */
	public function getIP() {
		return $this->ip;
	}

	/** Get the port of the peer */
	public function getPort() {
		return $this->port;
	}

	/** Get the IP/port of the peer */
	public function getAddress() {
		return "{$this->ip}:{$this->port}";
	}

	/** Check if the peer has set the choked status */
	public function isChoked() {
		return ($this->status & self::STATUS_CHOKED) !== 0;
	}

	/** Check if the peer has marked as interested */
	public function isInterested() {
		return ($this->status & self::STATUS_INTERESTED) !== 0;
	}

	public function getStream() {
		return $this->stream;
	}

	public function isConnected() {
		return isset($this->stream);
	}

	public function kill($reason) {
		echo "[DEBUG] Kill {$this->getAddress()} because $reason\n";
		$this->error = $reason;
		$this->disconnect();
	}

	public function read() {
		if (!isset($this->stream) || isset($this->error)) {
			return;
		}

		if (!$this->readHandshake()) {
			return;
		}

		$this->readPayloadLength();
		$this->readPayload();
	}

	protected function readHandshake() {
		if (isset($this->id)) {
			return true;
		}

		if (isset($this->payload)) {
			$remaining = 68 - strlen($this->payload);
			$this->payload .= $this->readData($remaining);
		} else {
			$this->payload = $this->readData(68);
		}

		if (strlen($this->payload) < 68) {
			return false;
		}

		if (ord($this->payload[0]) !== 19) {
			$this->kill("Protocol should start with character 19");
			return false;
		}

		$signature = substr($this->payload, 1, 19);

		if ($signature !== 'BitTorrent protocol') {
			$this->kill("Invalid protocol signature '$signature'");
			return false;
		}

		$reservedBytes = substr($this->payload, 20, 8);
		$hash = substr($this->payload, 28, 20);

		if ($hash !== $this->download->getTorrent()->getInfoHash()) {
			$this->kill("Invalid torrent hash");
			return false;
		}

		$this->id = substr($this->payload, 48, 20);

		if (strlen($this->id) !== 20) {
			$this->kill("Peer ID is not 20 bytes long");
			return false;
		}

		$this->payload = '';
		$this->payloadLength = null;
		return true;
	}

	protected function readData($len) {
		if (!$this->connected || !isset($this->stream) || isset($this->error)) {
			return null;
		}

		if ($len < 0) {
			throw new Exception("Tried to read negative");
		}

		if ($len >= (1024 * 256)) {
			throw new Exception("Tried to read too much");
		}

		$buffer = fread($this->stream, $len);

		if ($buffer === false) {
			$this->kill("Stream read error");
			return null;
		}

		if (strlen($buffer) <= 0) {
			return null;
		}

		//echo "[DEBUG] Received $bytesReceived bytes from {$this->getAddress()}\n";

		return $buffer;
	}

	protected function readPayloadLength() {
		if (isset($this->payloadLength)) {
			return;
		}

		$this->payload .= $this->readData(4 - strlen($this->header));

		if (strlen($this->payload) >= 4) {
			$this->payloadLength = Util::decodeInt($this->payload);
			$this->payload = '';

			if ($this->payloadLength <= 0) {
				$this->payloadLength = null;
			}
		}
	}

	protected function readPayload() {
		if (!isset($this->payloadLength)) {
			return;
		}

		if (isset($this->payload)) {
			$remaining = $this->payloadLength - strlen($this->payload);
			$this->payload .= $this->readData($remaining);
		} else {
			$this->payload = $this->readData($this->payloadLength);
		}

		if (strlen($this->payload) < $this->payloadLength) {
			return;
		}

		$controlByte = ord($this->payload[0]);
		$data = substr($this->payload, 1);
		$this->payload = '';
		$this->payloadLength = null;

		switch ($controlByte) {
		case self::PACKET_CHOKE:          return $this->readChoke($data);
		case self::PACKET_UNCHOKE:        return $this->readUnchoke($data);
		case self::PACKET_INTERESTED:     return $this->readInterested($data);
		case self::PACKET_NOTINTERESTED:  return $this->readNotInterested($data);
		case self::PACKET_HAVE:           return $this->readHave($data);
		case self::PACKET_BITFIELD:       return $this->readBitfield($data);
		case self::PACKET_REQUEST:        return $this->readRequest($data);
		case self::PACKET_PIECE:          return $this->readPiece($data);
		case self::PACKET_CANCEL:         return $this->readCancel($data);
		}

		return $this->readUnknown($controlByte, $data);
	}

	protected function readChoke($data) {
		echo "[DEBUG] Choke from {$this->getAddress()}\n";
		$this->setStatus(self::STATUS_CHOKED);
	}

	protected function readUnchoke($data) {
		echo "[DEBUG] Unchoke from {$this->getAddress()}\n";
		$this->clearStatus(self::STATUS_CHOKED);
		$this->sendRequest();
	}

	protected function readInterested($data) {
		echo "[DEBUG] interested from {$this->getAddress()}\n";
		$this->setStatus(self::STATUS_INTERESTED);
	}

	protected function readNotInterested($data) {
		echo "[DEBUG] NotInterested from {$this->getAddress()}\n";
		$this->clearStatus(self::STATUS_INTERESTED);
	}

	protected function readHave($data) {
		$pieceIndex = Util::decodeInt(substr($data, 0, 4));
		echo "[DEBUG] Peer has piece #$pieceIndex {$this->getAddress()}\n";
	}

	protected function readBitfield($data) {
		$this->bitfield = $data;
		echo "[DEBUG] Bitfield from {$this->getAddress()}\n";
	}

	protected function readRequest($data) {
		echo "[DEBUG] Request from {$this->getAddress()}\n";
	}

	protected function readPiece($data) {
		$this->requestCount = max($this->requestCount - 1, 0);
		$pieceIndex = Util::decodeInt(substr($data, 0, 4));
		$offset = Util::decodeInt(substr($data, 4, 4));
		$data = substr($data, 8);

		$this->download->commit($pieceIndex, $offset, $data);
		$x = strlen($data);
		//echo "[DEBUG] Data for peice #$pieceIndex ($x) from {$this->getAddress()}\n";

		if ($this->download->hasPiece($pieceIndex)) {
			$pieceIndex = null;
		}

		$pieceIndex = $this->sendRequest($pieceIndex);
		$this->autoSendRequest($pieceIndex);
	}

	protected function readCancel($data) {
		echo "[DEBUG] Cancel from {$this->getAddress()}\n";
	}

	protected function readUnknown($type, $data) {
		echo "[DEBUG] Unknown message type '$type'\n";
	}

	public function drain() {
		if (!isset($this->stream)) {
			return;
		}

		if (feof($this->stream)) {
			$this->kill("Stream error");
			return;
		}

		$this->connected = 1;
		$this->sendHandshake();
	}

	protected function sendHandshake() {
		if ($this->hasSentHandshake) {
			return;
		}

		$this->hasSentHandshake = true;
		$this->sendData(
			chr(19).
			'BitTorrent protocol'.
			str_repeat(chr(0), 8).
			$this->download->getTorrent()->getInfoHash().
			$this->download->getPeerID()
		);

		$this->sendUnchoke();
		$this->sendInterested();

		$pieceIndex = $this->sendRequest();
		//$this->autoSendRequest($pieceIndex);
	}

	protected function autoSendRequest($pieceIndex) {
		do {
			$count = $this->requestCount;

			if (isset($this->pieceIndex)) {
				if ($this->download->hasPiece($pieceIndex)) {
					$pieceIndex= null;
				}
			}

			$this->sendRequest($pieceIndex);

			if ($count === $this->requestCount) {
				break;
			}
		} while ($this->requestCount < 5);
	}

	protected function sendUnchoke() {
		$this->sendPacket(chr(self::PACKET_UNCHOKE));
	}

	protected function sendInterested() {
		$this->sendPacket(chr(self::PACKET_INTERESTED));
	}

	protected function sendRequest($pieceIndex=null) {
		if (!isset($pieceIndex)) {
			$pieceIndex = $this->download->getRandomUnfinishedPiece();
		}

		$chunkSize = 1024 * 16;
		$chunkCount = (int)ceil($this->download->getPieceSize() / $chunkSize);
		$sequence = $this->download->getPieceSequence($pieceIndex);
		$sequence = $sequence % $chunkCount;
		$offset = $sequence * $chunkSize;
		$len = min($chunkSize, $this->download->getPieceSize() - $offset);

		//echo "[DEBUG] Requesting piece #$pieceIndex from {$this->getAddress()}\n";

		$this->sendPacket(
			chr(self::PACKET_REQUEST).
			Util::encodeInt($pieceIndex).
			Util::encodeInt($offset).
			Util::encodeInt($len)
		);

		$this->requestCount++;
		return $pieceIndex;
	}

	public function sendData($data) {
		if (isset($this->error)) {
			return;
		}

		$this->sendBuffer .= $data;

		if (!isset($this->stream) || !$this->connected) {
			return;
		}

		$len = strlen($this->sendBuffer);
		$data = null;

		while ($len > 0) {
			if (feof($this->stream)) {
				$this->kill("Stream ended while writing");
				return;
			}

			$bytesSent = fwrite($this->stream, $this->sendBuffer, $len);

			if ($bytesSent === false) {
				$this->kill("Stream write error");
				return;
			}

			if ($bytesSent <= 0) {
				break;
			}

			$this->sendBuffer = substr($this->sendBuffer, $bytesSent);
			$len = strlen($this->sendBuffer);
		}
	}

	public function sendPacket($packet) {
		return $this->sendData(Util::encodeInt(strlen($packet)) . $packet);
	}

	const PACKET_CHOKE = 0;
	const PACKET_UNCHOKE = 1;
	const PACKET_INTERESTED = 2;
	const PACKET_NOTINTERESTED = 3;
	const PACKET_HAVE = 4;
	const PACKET_BITFIELD = 5;
	const PACKET_REQUEST = 6;
	const PACKET_PIECE = 7;
	const PACKET_CANCEL = 8;

	const STATUS_CHOKED = 1;
	const STATUS_INTERESTED = 2;
}
