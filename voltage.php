<?php

require_once __DIR__.'/classes/Util.php';
require_once __DIR__.'/classes/Torrent.php';
require_once __DIR__.'/classes/Download.php';

if (count($argv) < 3) {
	die("USAGE: voltage <dir> <torrent>\n");
}

$dir = $argv[1];
$torrentFile = $argv[2];

if (preg_match('#^[a-f0-9]{40}$#i', $torrentFile)) {
	$torrentData = file_get_contents("http://thetorrent.org/$torrentFile.torrent");

	if (strlen($torrentData) <= 0) {
		die("ERROR: Failed to download torrent from torcache.net\n");
	}

	if ($torrentData[0] !== 'd') {
		$torrentData = gzdecode($torrentData);
	}
} else {
	if (!file_exists($torrentFile)) {
		die("ERROR: Unable to open torrent file '$torrentFile'\n");
	}

	$torrentData = file_get_contents($torrentFile);

	if (strlen($torrentData) <= 0) {
		die("ERROR: Failed to read torrent '$torrentFile'\n");
	}
}

try {
	$torrent = Torrent::read($torrentData);
} catch (Exception $e) {
	trigger_error($e);
	die("ERROR: Failed to parse torrent\n");
}

echo " Info Hash: {$torrent->getInfoHashHex()}\n";
echo " Piece Size: ".Util::formatSize($torrent->getPieceSize())."\n";
echo " Total Size: ".Util::formatSize($torrent->getTotalSize())."\n";

$download = new Download($torrent, $dir);

$download->on('allocate', function ($finished, $total) {
	$p = (int)($finished / $total * 100.0);
	echo "Allocating Files ... ($p%)         \r";
});

$download->on('check', function ($finished, $total) {
	$p = (int)($finished / $total * 100.0);
	echo "Checking Files ... ($p%)           \r";
});

$download->start();

echo "Starting download ...               \r";

$lastTotal = 0;
$lastTime = time();
$downloadSpeed = '0K/s';

while (!$download->isComplete()) {
	$download->tick();

	$p = $download->getProgress();
	$x = $download->getConnectedPeerCount();
	$y = $download->getPeerCount();

	$now = time();
	$span = $now - $lastTime;

	if ($span > 0) {
		$lastTime = $now;
		$total = $download->getDownloaded();
		$delta = $total - $lastTotal;
		$lastTotal = $total;

		$downloadSpeed = Util::formatSize($delta).'/s';
	}

	echo "Downloading ... ($p%) ($x/$y peers) ($downloadSpeed)           \r";

	//sleep(1);
}

$download->finish();
