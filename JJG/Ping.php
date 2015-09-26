<?php

/**
 * Ping for PHP.
 *
 * This class pings a host.
 *
 * The ping() method pings a server using 'exec', 'socket', or 'fsockopen', and
 * and returns FALSE if the server is unreachable within the given ttl/timeout,
 * or the latency in milliseconds if the server is reachable.
 *
 * Quick Start:
 * @code
 *   include 'path/to/Ping/JJG/Ping.php';
 *   use \JJG\Ping as Ping;
 *   $ping = new Ping('www.example.com');
 *   $latency = $ping->ping();
 * @endcode
 *
 * @version 1.0.3
 * @author Jeff Geerling.
 */

namespace JJG;

class Ping {

  private $host;
  private $ttl;
  private $wait;
  private $port = 80;
  private $data = 'Ping';

  /**
   * Called when the Ping object is created.
   *
   * @param string $host
   *   The host to be pinged.
   * @param int $ttl
   *   Time-to-live (TTL) (You may get a 'Time to live exceeded' error if this
   *   value is set too low. The TTL value indicates the scope or range in which
   *   a packet may be forwarded. By convention:
   *     - 0 = same host
   *     - 1 = same subnet
   *     - 32 = same site
   *     - 64 = same region
   *     - 128 = same continent
   *     - 255 = unrestricted
   *   The TTL is also used as a general 'timeout' value for fsockopen(), so if
   *   you are using that method, you might want to set a default of 5-10 sec to
   *   avoid blocking network connections.
   *
   * @throws \Exception if the host is not set.
   */
  public function __construct($host, $ttl = 255, $wait = 10) {
    if (!isset($host)) {
      throw new \Exception("Error: Host name not supplied.");
    }

    $this->host = $host;
    $this->ttl = $ttl;
    $this->wait = $wait;
  }

  /**
   * Set the ttl (in hops).
   *
   * @param int $ttl
   *   TTL in hops.
   */
  public function setTtl($ttl) {
    $this->ttl = $ttl;
  }

  /**
   * Get the ttl.
   *
   * @return int
   *   The current ttl for Ping.
   */
  public function getTtl() {
    return $this->ttl;
  }

  /**
   * Set the host.
   *
   * @param string $host
   *   Host name or IP address.
   */
  public function setHost($host) {
    $this->host = $host;
  }

  /**
   * Get the host.
   *
   * @return string
   *   The current hostname for Ping.
   */
  public function getHost() {
    return $this->host;
  }

  /**
   * Set the wait time.
   *
   * @param string $wait
   *   wait name or IP address.
   */
  public function setwait($wait) {
    $this->wait = $wait;
  }

  /**
   * Get the wait time.
   *
   * @return string
   *   The current wait time for Ping.
   */
  public function getwait() {
    return $this->wait;
  }

  /**
   * Set the port (only used for fsockopen method).
   *
   * Since regular pings use ICMP and don't need to worry about the concept of
   * 'ports', this is only used for the fsockopen method, which pings servers by
   * checking port 80 (by default).
   *
   * @param int $port
   *   Port to use for fsockopen ping (defaults to 80 if not set).
   */
  public function setPort($port) {
    $this->port = $port;
  }

  /**
   * Get the port (only used for fsockopen method).
   *
   * @return int
   *   The port used by fsockopen pings.
   */
  public function getPort() {
    return $this->port;
  }

  /**
   * Ping a host.
   *
   * @param string $method
   *   Method to use when pinging:
   *     - exec (default): Pings through the system ping command. Fast and
   *       robust, but a security risk if you pass through user-submitted data.
   *     - fsockopen: Pings a server on port 80.
   *     - socket: Creates a RAW network socket. Only usable in some
   *       environments, as creating a SOCK_RAW socket requires root privileges.
   *
   * @return mixed
   *   Latency as integer, in ms, if host is reachable or FALSE if host is down.
   */
  public function ping($method = 'exec') {
    $latency = false;

    switch ($method) {
      case 'exec':
        $latency = $this->pingExec();
        break;

      case 'fsockopen':
        $latency = $this->pingFsockopen();
        break;

      case 'socket':
        $latency = $this->pingSocket();
        break;
    }

    // Return the latency.
    return $latency;
  }

  /**
   * The exec method uses the possibly insecure exec() function, which passes
   * the input to the system. This is potentially VERY dangerous if you pass in
   * any user-submitted data. Be SURE you sanitize your inputs!
   *
   * @return int
   *   Latency, in ms.
   */
  private function pingExec() {
    $latency = false;

    $ttl = escapeshellcmd($this->ttl);
    $host = escapeshellcmd($this->host);
    $wait = escapeshellcmd($this->wait);
    // Exec string for Windows-based systems.
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
      $wait = $wait * 1000;
      // -n = number of pings; -i = ttl.
      $exec_string = sprintf('ping -n 1 -i %c -w %c %s', $ttl, $wait, $host);
    }
    // Exec string for UNIX-based systems (Mac, Linux).
    else {
      if (strtoupper(substr(PHP_OS, 0, 5)) !== 'LINUX') {
        $wait = $wait * 1000;
      }
      // -n = numeric output; -c = number of pings; -t = ttl.
      $exec_string = sprintf('ping -n  -c 1 -t %c -W %c %s', $ttl, $wait, $host);
    }
    exec($exec_string, $output, $return);

    // Strip empty lines and reorder the indexes from 0 (to make results more
    // uniform across OS versions).
    $output = array_values(array_filter($output));

    // If the result line in the output is not empty, parse it.
    if (!empty($output[1])) {
      // Search for a 'time' value in the result line.
      $response = preg_match("/time(?:=|<)(?<time>[\.0-9]+)(?:|\s)ms/", $output[1], $matches);

      // If there's a result and it's greater than 0, return the latency.
      if ($response > 0 && isset($matches['time'])) {
        $latency = round($matches['time']);
      }
    }

    return $latency;
  }

  /**
   * The fsockopen method simply tries to reach the host on a port. This method
   * is often the fastest, but not necessarily the most reliable. Even if a host
   * doesn't respond, fsockopen may still make a connection.
   *
   * @return int
   *   Latency, in ms.
   */
  private function pingFsockopen() {
    $start = microtime(true);
    // fsockopen prints a bunch of errors if a host is unreachable. Hide those
    // irrelevant errors and deal with the results instead.
    $fp = @fsockopen($this->host, $this->port, $errno, $errstr, $this->ttl);
    if (!$fp) {
      $latency = false;
    }
    else {
      $latency = microtime(true) - $start;
      $latency = round($latency * 1000);
    }
    return $latency;
  }

  /**
   * The socket method uses raw network packet data to try sending an ICMP ping
   * packet to a server, then measures the response time. Using this method
   * requires the script to be run with root privileges, though, so this method
   * only works reliably on Windows systems and on Linux servers where the
   * script is not being run as a web user.
   *
   * @return int
   *   Latency, in ms.
   */
  private function pingSocket() {
    // Create a package.
    $type = "\x08";
    $code = "\x00";
    $checksum = "\x00\x00";
    $identifier = "\x00\x00";
    $seq_number = "\x00\x00";
    $package = $type . $code . $checksum . $identifier . $seq_number . $this->data;

    // Calculate the checksum.
    $checksum = $this->calculateChecksum($package);

    // Finalize the package.
    $package = $type . $code . $checksum . $identifier . $seq_number . $this->data;

    // Create a socket, connect to server, then read socket and calculate.
    if ($socket = socket_create(AF_INET, SOCK_RAW, 1)) {
      socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, array(
        'sec' => 10,
        'usec' => 0,
      ));
      // Prevent errors from being printed when host is unreachable.
      @socket_connect($socket, $this->host, null);
      $start = microtime(true);
      // Send the package.
      @socket_send($socket, $package, strlen($package), 0);
      if (socket_read($socket, 255) !== false) {
        $latency = microtime(true) - $start;
        $latency = round($latency * 1000);
      }
      else {
        $latency = false;
      }
    }
    else {
      $latency = false;
    }
    // Close the socket.
    socket_close($socket);
    return $latency;
  }

  /**
   * Calculate a checksum.
   *
   * @param string $data
   *   Data for which checksum will be calculated.
   *
   * @return string
   *   Binary string checksum of $data.
   */
  private function calculateChecksum($data) {
    if (strlen($data) % 2) {
      $data .= "\x00";
    }

    $bit = unpack('n*', $data);
    $sum = array_sum($bit);

    while ($sum >> 16) {
      $sum = ($sum >> 16) + ($sum & 0xffff);
    }

    return pack('n*', ~$sum);
  }
}
