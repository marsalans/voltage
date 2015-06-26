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
	protected $streams;
	protected $streamLookup;
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
		$this->streams = array();
		$this->streamLookup = array();
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
			//socket_close($this->udp);
			//$this->udp = null;
			//echo "[DEBUG] UDP read error\n";
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
		return count($this->streams);
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

		if (empty($this->streams)) {
			return;
		}

		$readStreams = $this->streams;
		$writeStreams = $this->streams;
		$errorStreams = $this->streams;

		$changed = stream_select($readStreams, $writeStreams, $errorStreams, 0, 1000 * 50);

		if ($changed <= 0) {
			return;
		}

		foreach ($errorStreams as $stream) {
			$this->disconnectPeer($stream, "Stream error");
		}

		gc_collect_cycles();

		foreach ($readStreams as $stream) {
			$this->readPeer($stream);
		}

		gc_collect_cycles();

		foreach ($writeStreams as $stream) {
			$this->drainPeer($stream);
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

	public function registerPeerStream(Peer $peer) {
		$stream = $peer->getStream();
		if (!$stream) { return; }
		$this->streams[(int)$stream] = $stream;
		$this->streamLookup[(int)$stream] = $peer;
	}

	public function unregisterPeerStream(Peer $peer) {
		$stream = $peer->getStream();
		if (!$stream) { return; }
		unset($this->streams[(int)$stream]);
		unset($this->streamLookup[(int)$stream]);
	}

	public function disconnectPeer($stream, $killReason=null) {
		$peer = @$this->streamLookup[(int)$stream];

		if (!$peer) {
			unset($this->streams[(int)$stream]);
			return;
		}

		$this->emit('peer-disconnect', $peer);

		if (isset($killReason)) {
			$peer->kill($killReason);
		} else {
			$peer->disconnect();
		}

		unset($this->peers[$peer->getAddress()]);
		unset($this->streams[(int)$stream]);
		unset($this->streamLookup[(int)$stream]);
	}

	public function readPeer($stream) {
		$peer = @$this->streamLookup[(int)$stream];

		if (!isset($peer)) {
			$this->disconnectPeer($stream);
			return;
		}

		try {
			$peer->read();
		} catch (Exception $e) {
			trigger_error($e);
			$peer->kill("Exception while reading");
		}
	}

	public function drainPeer($stream) {
		$peer = @$this->streamLookup[$stream];

		if (!isset($peer)) {
			$this->disconnectPeer($stream);
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
