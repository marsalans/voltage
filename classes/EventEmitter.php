<?php

class EventEmitter {
	protected $listeners;

	public function __construct() {
		$this->listeners = [];
	}

	public function on($event, $listener) {
		if (empty($this->listeners[$event])) {
			$this->listeners[$event] = [];
		}

		$this->listeners[$event][] = $listener;
	}

	public function emit() {
		$args = func_get_args();
		$event = array_shift($args);

		if (empty($this->listeners[$event])) {
			return;
		}

		foreach ($this->listeners[$event] as $listener) {
			call_user_func_array($listener, $args);
		}
	}
}
