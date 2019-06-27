<?php

require_once __DIR__.'/Bencode.php';
require_once __DIR__.'/Util.php';

class Torrent {
	protected $hash;
	protected $pieceSize;
	protected $pieces;
	protected $pieceFiles;
	protected $files;
	protected $fileSizes;
	protected $fileOffsets;
	protected $trackers;
	protected $totalSize;

	public function __construct($hash, array $meta) {
		if (strlen($hash) !== 20) { throw new Exception("Torrent hashes should be 20 bytes"); }
		$this->hash = $hash;

		$this->pieceSize = (int)$meta['info']['piece length'];
		$base = log($this->pieceSize, 2);
		$remainder = $base - floor($base);

		if ($remainder >= 0.001) { throw new Exception("Piece size should be a power of two");}
		if ($base < 10) { throw new Exception("Piece size too small"); }
		if ($base > 25) { throw new Exception("Piece size too big"); }

		$this->pieces = self::decodePieceHashes($meta['info']['pieces']);

		if (count($this->pieces) <= 0) {
			throw new Exception("Unable to read torrent pieces");
		}

		$this->files = [];
		$this->fileSizes = [];
		$this->fileOffsets = [];
		$this->pieceFiles = [];
		$pos = 0;

		for ($i=0; $i < count($this->pieces); $i++) {
			$this->pieceFiles[$i] = [];
		}

		if (empty($meta['info']['files'])) {
			$meta['info']['files'] = array(
				array(
					'path' => array($meta['info']['name']),
					'length' => (int)$meta['info']['length']
				)
			);
		}

		foreach ($meta['info']['files'] as $file) {
			$fileSize = (int)$file['length'];
			$filePath = implode('/', $file['path']);
			$filePath = trim($filePath, '/');

			$this->files[] = $filePath;
			$this->fileSizes[$filePath] = $fileSize;
			$this->fileOffsets[$filePath] = $pos;

			$startPiece = (int)floor($pos / $this->pieceSize);
			$endPiece = (int)ceil(($pos + $fileSize) / $this->pieceSize);

			for ($i=$startPiece; $i < $endPiece; $i++) {
				$this->pieceFiles[$i][] = $filePath;
			}

			$pos += $fileSize;
		}

		$this->totalSize = $pos;

		if ($this->totalSize <= 0) {
			throw new Exception("Torrent contains no data");
		}

		$this->trackers = [];

		foreach ($meta['announce-list'] as $value) {
			if (is_array($value)) {
				$value = $value[0];
			}

			if (strlen($value) > 0) {
				$this->trackers[] = $value;
			}
		}
	}

	/** Gets the torrent 'info' SHA1 hash (as 20 raw bytes) */
	public function getInfoHash() {
		return $this->hash;
	}

	/** Get the info hash encoded as hex */
	public function getInfoHashHex() {
		return Util::encodeHex($this->hash);
	}

	/** Get size in bytes of the total data in the torrent */
	public function getTotalSize() {
		return $this->totalSize;
	}

	/** Get the number of pieces in the torrent */
	public function getPieceCount() {
		return count($this->pieces);
	}

	/** Get the size of each piece in the file */
	public function getPieceSize() {
		return $this->pieceSize;
	}

	/** Check if a piece index isn't out of bounds */
	public function assertPieceIndex($i) {
		if ($i < 0 || $i >= count($this->pieces)) {
			throw new Exception("Piece index out of bounds");
		}
	}

	/** Get the SHA1 hash of a piece */
	public function getPieceHash($i) {
		$this->assertPieceIndex($i);
		return $this->pieces[$i];
	}

	/** Get a list of files that touch a piece */
	public function getPieceFiles($i) {
		$this->assertPieceIndex($i);
		return $this->pieceFiles[$i];
	}

	/** Get all the files in this torrent */
	public function getFiles() {
		return $this->files;
	}

	/** Get the size (in bytes) of a file within the torrent */
	public function getFileSize($file) {
		return $this->fileSizes[$file];
	}

	/** Get the global position within the torrent of a file */
	public function getFileOffset($file) {
		return $this->fileOffsets[$file];
	}

	/** Get an array of tracker URLs */
	public function getTrackers() {
		return $this->trackers;
	}

	/** Read a torrent from raw data */
	public static function read($data) {
		$pos = 1;
		$len = strlen($data);

		if ($data[0] !== 'd') {
			throw new Exception("Torrent data doesn't contain bencoding");
		}

		$meta = [];
		$hash = null;

		while ($pos < $len) {
			if ($data[$pos] === 'e') {
				$pos++;
				break;
			}

			$key = Bencode::decodeString($data, $pos);

			if ($key === 'info') {
				$start = $pos;
				$value = Bencode::decodeValue($data, $pos);
				$end = $pos;

				$hash = sha1(substr($data, $start, $end - $start), true);
			} else {
				$value = Bencode::decodeValue($data, $pos);
			}

			$meta[$key] = $value;
		}

		if (!isset($hash)) {
			throw new Exception("Torrent doesn't contain 'info' data");
		}

		return new self($hash, $meta);
	}

	public static function decodePieceHashes($str) {
		$len = strlen($str);
		$count = (int)($len / 20);
		$list = [];

		for ($i=0; $i < $count; $i++) {
			$list[$i] = substr($str, $i * 20, 20);
		}

		return $list;
	}
}
