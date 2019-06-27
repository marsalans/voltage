<?php

require_once __DIR__.'/Tracker.php';
require_once __DIR__.'/Util.php';

class TrackerUDP extends Tracker {
  protected $ip, $port;
  protected $transID, $connectionID;

  public function __construct(Download $download, $url) {
    parent::__construct($download, $url);

    if (!preg_match('#^udp://([^\\:]+)\\:(\d+)#', $url, $match)) {
      $this->error = "Cannot parse URL";
      return;
    }

    $this->ip = gethostbyname($match[1]);

    if (!Util::isIPv4($this->ip)) {
      $this->error = "Unable to resolve IP for '$url'";
      return;
    }

    $this->port = (int)$match[2];

    if ($this->port <= 0) {
      $this->error = "Unable to parse UDP port";
      return;
    }

    $this->transID = rand();
    $this->connectionID = null;
  }

  public function getKey() {
    return "{$this->ip}:{$this->port}";
  }

  const CONNECT_REQUEST = 'JNN';
  const CONNECT_RESPONSE = 'Naction/NtransID/JconnectionID';
  const ANNOUNCE_REQUEST = 'JNNa20a20JJJNNNNn';
  const ANNOUNCE_RESPONSE = 'Naction/NtransID/Ninterval/Nleechers/Nseeders';
  const ANNOUNCE_RESPONSE_PEER = 'Nip/nport';

  protected function sendUpdate() {
    if (isset($this->connectionID)) {
      return $this->sendAnnounce();
    } else {
      return $this->sendConnect();
    }
  }

  protected function sendConnect() {
    echo "[DEBUG] Sending connect...\n";

    $packet = pack(
      self::CONNECT_REQUEST,
      0x41727101980,
      0,
      $this->transID
    );

    $this->download->sendUDP($this->ip, $this->port, $packet);
  }

  protected function sendAnnounce() {
    echo "[DEBUG] Sending announce...\n";

    $ip = 0;
    $port = 0;
    $key = rand();

    $packet = pack(
      self::ANNOUNCE_REQUEST,
      $this->connectionID,
      1,
      $this->transID,
      $this->download->getTorrent()->getInfoHash(),
      $this->download->getPeerID(),
      $this->download->getDownloaded(),
      $this->download->getRemaining(),
      $this->download->getUploaded(),
      0,
      $ip,
      $key,
      -1,
      $port
    );

    $this->download->sendUDP($this->ip, $this->port, $packet);
  }

  public function receiveData($data) {
    $header = Util::decodeInt(substr($data, 0, 4));

    echo "[DEBUG] Received UDP Message $header\n";

    switch ($header) {
    case 0: return $this->receiveConnect($data);
    case 1: return $this->receiveAnnounce($data);
    }
  }

  protected function receiveConnect($data) {
    $data = unpack(self::CONNECT_RESPONSE, $data);

    if ($this->transID !== (int)$data['transID']) {
      echo "[DEBUG] Transaction ID doesnt match\n";

      $this->error = "Transaction ID doesnt match";
      return;
    }

    $this->connectionID = (int)$data['connectionID'];
    $this->sendAnnounce();
  }

  protected function receiveAnnounce($data) {
    $header = substr($data, 0, 20);
    $header = unpack(self::ANNOUNCE_RESPONSE, $header);
    $count = (int)((strlen($data) - 20) / 6);

    $transID = (int)$header['transID'];
    $interval = (int)$header['interval'];
    $leechers = (int)$header['leechers'];
    $seeders = (int)$header['seeders'];

    if ($transID !== $this->transID) {
      echo "[DEBUG] Transaction ID doesn't match\n";
      return;
    }

    $this->setNext(time() + $interval);

    for ($i=0; $i < $count; $i++) {
      $chunk = substr($data, 20 + $i * 6, 6);
      $item = unpack(self::ANNOUNCE_RESPONSE_PEER, $chunk);
      $this->download->addPeer($item['ip'], $item['port']);
    }
  }
}
