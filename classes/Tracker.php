<?php

require_once __DIR__.'/Download.php';
require_once __DIR__.'/TrackerUDP.php';
require_once __DIR__.'/TrackerHTTP.php';

abstract class Tracker {
	protected $url;
	protected $next;
	protected $error;

	public function __construct(Download $download, $url) {
		$this->download = $download;
		$this->url = $url;
		$this->next = null;
	}

	public function hasError() {
		return isset($this->error);
	}

	public function getError() {
		return $this->error;
	}

	public function setNext($next) {
		if ($next <= 0 || $next > time() + 60 * 3) {
			$this->next = time() + 60 * 3;
		} else {
			$this->next = $next;
		}
	}

	public function isWaiting() {
		if (isset($this->next)) {
			$now = time();

			if ($now < $this->next) {
				return true;
			}
		}

		return false;
	}

	public function update() {
		if ($this->hasError() || $this->isWaiting()) {
			return;
		}

		$this->next = time() + 60 * 3;
		$this->sendUpdate();
	}

	public abstract function getKey();
	protected abstract function sendUpdate();

	public static function create(Download $download, $url) {
		$class = null;

		if (strpos($url, 'http') === 0) {
			$class = 'TrackerHTTP';
		} else if (strpos($url, 'udp') === 0) {
			$class = 'TrackerUDP';
		} else {
			return null;
		}

		return new $class($download, $url);
	}
}
