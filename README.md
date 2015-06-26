
# php-voltage

`voltage` is a BitTorrent client written entirely in PHP. It uses the `socket_`
functions which are typically available in most PHP environments.

## Features

 * Works in places where you can't install things or run binaries
 * Supports low memory usage and is `memory_limit` aware
 * Simple piece cache system
 * UDP and HTTP tracker support (UDP not available in some hosting)
 * Automatically resumes download
 * Supports download from only an `info_hash` (currently only uses torcache.net)

## Usage

Download a release from Github and extract it:

	wget -O voltage.tgz https://github.com/krisives/voltage/archive/master.tar.gz
	tar xf voltage.tgz
	mv voltage-master voltage
	cd voltage

Downloading Ubuntu 15.04 via torrent:

	mkdir downloads
	./voltage downloads FC8A15A2FAF2734DBB1DC5F7AFDC5C9BEAEB1F59
