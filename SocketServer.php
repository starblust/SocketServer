<?php
namespace Starblust;

class SocketServer{

  /** @var int LogConsole */
  const LogConsole = 0;
  /** @var int LogFile */
  const LogFile = 1;
  
  /** @var int $time_limit seconds
   * socket server loop time limit
   */
  private static $time_limit = 60;  

  /** @var array $params
   * socket server creation parameters
  */
  private static $params = [
    'host' => '127.0.0.1',
    'port' => 8888
  ];

  /** @var resource $socket 
   * 
  */
  private static $socket = null;

  /** @var string $magic_key
   * string that needed when does handshake
   */
  private static $magic_key = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

  /** @var string $log_file
   * log filename path
   */
  private static $log_file = 'socket_server.log';

  /**
   * creating the socket server and running the sockets processing
   * run only in cli mode
   *
   * @throws Exception
   **/
  public static function run()
  {
    if (\php_sapi_name() !== 'cli'){
      throw new \Exception("The server can be started only in cli mode", 1);
    }
    // check is we can write to log
    if (\file_exists(__DIR__.'/'.self::$log_file && !\is_writable(__DIR__.'/'.self::$log_file)) || 
       !\is_writeable(__DIR__))
    {
      throw new \Exception("can't write to a log file", 1);
    }
    if (self::create()){
      self::socket_processing();
    }
  }

  /**
   * creates a socket endpoint connection
   * 
   * @return bool
   **/
  private static function create()
  {
    $socket = \socket_create(AF_INET, SOCK_STREAM, getprotobyname('tcp'));
    if (!$socket){
        self::log(self::getSocketError(), self::LogConsole);
        return false;
    }
    $is_binded = \socket_bind($socket, 
                              self::$params['host'], 
                              self::$params['port']);
    if (false === $is_binded){
        self::log(self::getSocketError(), self::LogConsole);
        return false;
    }
    if (false === \socket_set_nonblock($socket)){
        self::log(self::getSocketError($socket), self::LogConsole);
        return false;
    }
    if (false === \socket_listen($socket)){
        self::log(self::getSocketError(), self::LogConsole);
        self::terminate();
        return false;
    }
    self::$socket = $socket;
    return true;
  }

  /**
   * handle the connecting sockets
   *
   * @return null
   **/
  private static function socket_processing()
  {
    $time_limit = \time() + self::$time_limit;
    $clients = [];
    while (\time() < $time_limit) {

        $new_client = \socket_accept(self::$socket);

        if ($new_client && !\in_array($new_client, $clients)) {

            $message = \socket_read($new_client, 4096);

            if (false === self::do_handshake($new_client, $message)){
              self::log(self::getSocketError(), self::LogFile);
              self::terminate();
              break;
            }

            $clients[] = $new_client;

        }

        $read = $clients;
        $write = [];
        $except = [];
        $readed_num = 0;

        if ($read) {
            $readed_num = \socket_select($read, $write, $except, 0);
        }
        if ($readed_num > 0){
            foreach ($read as $read_sock){
                $message = \socket_read($read_sock, 8000);
                $message = self::decode($message);
                $send_message = self::prepareMessageHead(\strlen($message)).$message;
                $str_len = \strlen($send_message);
                foreach ($clients as $client) {
                    if ($client == $read_sock) continue;
                        if (false === \socket_write($client, $send_message, $str_len)){
                            unset($clients[\array_search($client, $clients)]);
                            self::log(self::getSocketError(), self::LogFile);
                        }
                }
            }
        }
        $read = [];
    }
    self::terminate();
  }

  /**
  ** terminate a socket server
  ** socket_shutdown:
  ** 0 - reading, 1 - writing, 2 - both
  ** return bool
  */
  public static function terminate(): bool
  {
    return self::$socket && \socket_shutdown(self::$socket, 2) &&
           \socket_close(self::$socket);
  }

  /**
   * perfom handshake with client
   *
   * @return bool
   **/
  private static function do_handshake($new_client, string $client_message): bool
  {
    $headers = \explode("\r\n", $client_message);
    $websocket_key = '';
    foreach ($headers as $header) {
        if (\strpos($header, 'Sec-WebSocket-Key') !== false) {
            $pair = \explode(':', $header);
            $websocket_key = \trim($pair[1]);
            break;
        }
    }
    if (!$websocket_key){
        self::log('Sec-Websocket-Key not found in headers', self::LogFile);
        return false;
    }
    $websocket_key = \base64_encode(\sha1($websocket_key . self::$magic_key, true));

    $send_header = 'HTTP/1.1 101 Switching Protocols' . "\r\n" .
                   'Upgrade: websocket' . "\r\n" .
                   'Connection: Upgrade' . "\r\n" .
                   'Sec-WebSocket-Accept: ' . $websocket_key . "\r\n\r\n";


    return (bool)\socket_write($new_client, $send_header, strlen($send_header));
  }

  /**
   * decodes the client's message
   *
   * @param string $message
   * @return string
   **/
  private static function decode(string $message): string
  {
      if (!$message) return '';
      $len = ord($message[1]) & 127;
      if ($len === 126) {
          $ofs = 8;
      } elseif ($len === 127) {
          $ofs = 14;
      } else {
          $ofs = 6;
      }
      $text = '';
      for ($i = $ofs; $i < \strlen($message); $i++) {
          $text .= $message[$i] ^ $message[$ofs - 4 + ($i - $ofs) % 4];
      }
      return $text;
  }

  /**
   * prepares the first info bytes for message
   *
   * @param int $str_len
   * @return string
   **/
  private static function prepareMessageHead(int $str_len): string
  {
      if ($str_len <= 0) return '';
      $first_byte = 129;
      if ($str_len < 126) {
          return pack('CC', $first_byte, $str_len);
      } elseif ($str_len < 65536) {
          return pack('CCn', $first_byte, 126, $str_len);
      } else {
          return pack('CCNN', $first_byte, 127, 0, $str_len);
      }
  }

  /**
  * logging info
  *
  * @param string $socket
  * @param int $log_device 
  * ( LogConsole - print to console, LogFile - file)
  */
  private static function log(string $message = '', int $log_device = self::LogConsole)
  {
    if ($log_device === self::LogConsole){
      echo 'Socket error: '.$message.PHP_EOL;
    }
    elseif ($log_device === self::LogFile){
      $errormsg = 'date: '.date('d.m.y h:i:s')."\t".'error: '.$message;
      \file_put_contents(__DIR__.self::$log_file, $message, FILE_APPEND);
    }
  }

  /**
   * get a socket error message
   * 
   * @param resource $socket
   * @return string
   **/
  private static function getSocketError($socket = null){
    return \socket_strerror(($socket) ? \socket_last_error($socket) : \socket_last_error());
  }

}
