<?php

require_once __DIR__.'/Torrent.php';
require_once __DIR__.'/Peer.php';
require_once __DIR__.'/Tracker.php';
require_once __DIR__.'/PieceCache.php';
require_once __DIR__.'/EventEmitter.php';

class Download extends EventEmitter {
	protected $torrent;
	protected $myPeerID;
	protected $dir;
	protected $pieceCache;
	protected $peers;
	protected $sockets;
	protected $udp;
	protected $trackers;
	protected $maxPeers = 20;
	protected $lastFlush;
	protected $totalUploaded = 0;
	protected $totalDownloaded = 0;

	public function __construct(Torrent $torrent, $dir) {
		parent::__construct();

		$dir = realpath($dir);
		$dir = rtrim($dir, '/');

		if (!is_dir($dir)) {
			throw new Exception("Cannot find download directory '$dir'");
		}

		$this->torrent = $torrent;
		$this->dir = $dir;
		$this->myPeerID = sha1(time().rand(), true);
		$this->pieceCache = new PieceCache($torrent, $dir);
		$this->lastFlush = time();
		$this->peers = array();
		$this->sockets = array();
		$this->trackers = array();
		$this->udp = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);


		if (empty($this->udp)) {
			throw new Exception("Cannot create UDP socket");
		}

		foreach ($torrent->getTrackers() as $url) {
			$this->addTracker($url);
		}
	}

	public function getTorrent() {
		return $this->torrent;
	}

	public function getPieceCount() {
		return $this->torrent->getPieceCount();
	}

	public function getPieceSize() {
		return $this->torrent->getPieceSize();
	}

	public function assertPieceIndex($i) {
		$this->torrent->assertPieceIndex($i);
	}

	public function addTracker($url) {
		$tracker = Tracker::create($this, $url);

		if (empty($tracker)) {
			return;
		}

		$key = $tracker->getKey();

		if (isset($this->trackers[$key])) {
			return $this->trackers[$key];
		}

		$this->trackers[$key] = $tracker;
		return $tracker;
	}

	public function getTrackerByAddress($address) {
		return @$this->trackers[$address];
	}

	public function getDownloaded() {
		return $this->totalDownloaded;
	}

	public function getUploaded() {
		return $this->totalUploaded;
	}
	public function start() {
		$allocatedPieces = $this->pieceCache->allocate($this);
		gc_collect_cycles();

		$this->pieceCache->check($allocatedPieces, $this);
		gc_collect_cycles();
	}

	public function tick() {
		$this->updateTrackers();
		$this->updatePeers();

		$now = time();
		$span = $now - $this->lastFlush;

		if ($span >= 30) {
			$this->lastFlush = $now;
			$this->pieceCache->flush(true);
		}
	}

	public function finish() {
		if ($this->udp) {
			socket_close($this->udp);
			$this->udp = null;
		}

		$this->pieceCache->close();
		gc_collect_cycles();
	}

	public function sendUDP($ip, $port, $packet) {
		$bytesSent = socket_sendto(
			$this->udp,
			$packet,
			strlen($packet),
			0,
			$ip,
			$port
		);

		if ($bytesSent === false) {

		}
	}

	protected function updateTrackers() {
		$this->receiveUDP();

		foreach ($this->trackers as $tracker) {
			$tracker->update();
		}
	}

	protected function receiveUDP() {
		if (!isset($this->udp)) {
			return;
		}

		$buffer = null;
		$ip = null;
		$port = null;

		$bytesRead = socket_recvfrom(
			$this->udp,
			$buffer,
			1024 * 4,
			MSG_DONTWAIT,
			$ip,
			$port
		);

		if ($bytesRead === false) {
			socket_close($this->udp);
			$this->udp = null;

			echo "[DEBUG] UDP read error\n";
			return;
		}

		if ($bytesRead <= 0) {
			return;
		}

		echo "[DEBUG] Received $bytesRead UDP bytes from $ip:$port\n";

		$tracker = $this->getTrackerByAddress("$ip:$port");

		if (empty($tracker) || !($tracker instanceof TrackerUDP)) {
			echo "[DEBUG] Missing tracker for $ip:$port\n";
			return;
		}

		$tracker->receiveData($buffer);
	}

	public function getConnectedPeerCount() {
		return count($this->sockets);
	}

	protected function connectToPeers() {
		if ($this->getConnectedPeerCount() >= $this->maxPeers) {
			return;
		}

		foreach ($this->peers as $peer) {
			if ($peer->hasError()) {
				continue;
			}

			try {
				$peer->connect();
			} catch (Exception $e) {
				$peer->kill("Unable to connect");
				return;
			}

			if ($this->getConnectedPeerCount() >= $this->maxPeers) {
				return;
			}
		}
	}

	protected function updatePeers() {
		if (empty($this->peers)) {
			return;
		}

		$this->connectToPeers();

		if (empty($this->sockets)) {
			return;
		}

		$readSockets = $this->sockets;
		$writeSockets = $this->sockets;
		$errorSockets = $this->sockets;

		$changed = socket_select($readSockets, $writeSockets, $errorSockets, 50);

		if ($changed <= 0) {
			return;
		}

		foreach ($errorSockets as $address => $socket) {
			$this->disconnectPeer($address, "Socket error");
			unset($readSockets[$address]);
			unset($writeSockets[$address]);
		}

		gc_collect_cycles();

		foreach ($readSockets as $address => $socket) {
			$this->readPeer($address);
		}

		gc_collect_cycles();

		foreach ($writeSockets as $address => $socket) {
			$this->drainPeer($address);
		}

		gc_collect_cycles();
		$this->pieceCache->flush();
		gc_collect_cycles();
	}

	public function getPeerID() {
		return $this->myPeerID;
	}

	public function getPeers() {
		return $this->peers;
	}

	public function getPeerCount() {
		return count($this->peers);
	}

	public function getPeerByAddress($address) {
		return @$this->peers[$address];
	}

	public function addPeer($ip, $port) {
		if ($port <= 0) {
			return;
		}

		if (strpos($ip, '.') === false) {
			$ip = long2ip($ip);
		}

		$address = "$ip:$port";

		if (isset($this->peers[$address])) {
			return $this->peers[$address];
		}

		echo "[DEBUG] Added peer $address\n";

		$peer = new Peer($this, $ip, $port);
		$this->peers[$address] = $peer;
		return $peer;
	}

	public function registerPeerSocket(Peer $peer) {
		if (!$peer->getSocket()) { return; }
		$this->sockets[$peer->getAddress()] = $peer->getSocket();
	}

	public function unregisterPeerSocket(Peer $peer) {
		unset($this->sockets[$peer->getAddress()]);
	}

	public function disconnectPeer($address, $killReason=null) {
		$peer = @$this->peers[$address];

		if (!$peer) {
			unset($this->sockets[$address]);
			return;
		}

		$this->emit('peer-disconnect', $peer);

		if (isset($killReason)) {
			$peer->kill($killReason);
		} else {
			$peer->disconnect();
		}

		unset($this->peers[$address]);
		unset($this->sockets[$address]);
	}

	public function readPeer($address) {
		$peer = @$this->peers[$address];

		if (!isset($peer)) {
			$this->disconnectPeer($address);
			return;
		}

		if (!$peer->isDoneConnecting()) {
			return;
		}

		try {
			//do {
			$peer->read();
			//} while ($more);
		} catch (Exception $e) {
			trigger_error($e);
			$peer->kill("Exception while reading");
		}
	}

	public function drainPeer($address) {
		$peer = @$this->peers[$address];

		if (!isset($peer)) {
			$this->disconnectPeer($address);
			return;
		}

		if (!$peer->isDoneConnecting()) {
			return;
		}

		try {
			$peer->drain();
		} catch (Exception $e) {
			trigger_error($e);
			$peer->kill("Exception while sending");
		}
	}

	public function hasPiece($i) {
		return $this->pieceCache->hasPiece($i);
	}

	public function getProgress() {
		return $this->pieceCache->getProgress();
	}

	public function isComplete() {
		return $this->pieceCache->isComplete();
	}

	public function getRemaining() {
		$size = $this->torrent->getPieceSize();
		$count = $this->pieceCache->getCompletedPieceCount();
		return $this->torrent->getTotalSize() - ($count * $size);
	}

	public function getRandomUnfinishedPiece() {
		return $this->pieceCache->getRandomUnfinishedPiece();
	}

	public function getPieceSequence($i) {
		return $this->pieceCache->getPieceSequence($i);
	}

	public function commit($pieceIndex, $offset, $data) {
		$this->totalDownloaded += strlen($data);

		$this->pieceCache->commit($pieceIndex, $offset, $data);
	}
}
