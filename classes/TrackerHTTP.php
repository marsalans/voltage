<?php

require_once __DIR__.'/Tracker.php';
require_once __DIR__.'/Bencode.php';

class TrackerHTTP extends Tracker {
  public function __construct(Download $download, $url) {
    parent::__construct($download, $url);
  }

  public function getKey() {
    return $this->url;
  }

  protected function sendUpdate() {
    $query = http_build_query(array(
      'info_hash' => $this->download->getTorrent()->getInfoHash(),
      'peer_id' => $this->download->getPeerID(),
      'downloaded' => $this->download->getDownloaded(),
      'uploaded' => $this->download->getUploaded(),
      'left' => $this->download->getRemaining(),
      'port' => 0,
      'compact' => 1
    ));

    $response = file_get_contents("{$this->url}?$query");

    if ($response === false || strlen($response) <= 0) {
      $this->error = "No HTTP response";
      return;
    }

    try {
      $response = Bencode::decode($response);
    } catch (Exception $e) {
      trigger_error($e);

      $this->error = "Bencoding error";
      return;
    }

    var_dump($response);

    if (empty($response)) {
      return;
    }

    $this->setNext(time() + (int)$response['interval']);

    if (empty($response['peers'])) {
      return;
    }

    if (is_string($response['peers'])) {
      $response['peers'] = $this->decodePeerList($response['peers']);
    }

    foreach ($response['peers'] as $item) {
      $this->download->addPeer($item['ip'], $item['port']);
    }
  }

  protected function decodePeerList($str) {
    $len = strlen($str);
    $peerCount = (int)($len / 6);
    $list = [];

    for ($i=0; $i < $peerCount; $i++) {
      $chunk = substr($str, $i * 6, 6);

      $list[] = array(
        'ip' => Util::decodeInt(substr($chunk, 0, 4)),
        'port' => Util::decodeShort(substr($chunk, 4, 2))
      );
    }

    return $list;
  }
}
