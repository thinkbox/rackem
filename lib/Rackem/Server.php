<?php
namespace Rackem;

class Server
{
	public $reload = true;
	private $app, $host, $port, $running;

	public function __construct($host = '0.0.0.0', $port = 9393, $app)
	{
		declare(ticks=1);
		$this->host = $host;
		$this->port = $port;
		$this->app = $app;

		\Rackem::$handler = new \Rackem\Handler\Rackem();
	}

	public function app()
	{
		if(file_exists($this->app)) return include($this->app);
		$app = $this->app;
		return new $app();
	}

	public function handle_client($socket)
	{
		if($socket === false) return;

		$buffer = '';

		stream_set_blocking($socket, 0);
		while(true)
		{
			$chunk = stream_socket_recvfrom($socket, 4096);
			$buffer .= $chunk;
			if(strlen($buffer) > 0 && $chunk == '') break;
		}

		if($buffer == '' || $buffer === false) return;

		$client = stream_socket_get_name($socket, true);
		$res = $this->reload? $this->process_from_cli($buffer, $client) : $this->process($buffer, $client);

		fwrite($socket, $res);
	}

	public function process($buffer, $client)
	{
		$start = microtime(true);
		ob_start();
		$req = $this->parse_request($buffer);
		$env = $this->env($req);

		$app = $this->app();
		$res = new Response($app->call($env));
		$output = ob_get_clean();
		fwrite($env['rack.errors'], $output);
		// fwrite($env['rack.errors'], $this->log_request($req, $res));
		if($env['rack.logger'])
		{
			$time = microtime(true) - $start;
			fwrite($env['rack.logger']->stream, $this->log_request($req, $res, $client, $time));
			$env['rack.logger']->close();
		}
		fclose($env['rack.input']);
		if(is_resource($env['rack.errors'])) fclose($env['rack.errors']);
		return $this->write_response($req, $res);
	}

	public function start()
	{
		$this->init();
		$this->running = true;

		while($this->step())
		{

		}
	}

	public function step()
	{
		$read = array($this->master);
		$write = array();
		$except = null;

		if(@stream_select($read, $write, $except, 0, 200000) > 0)
		{
			$client = stream_socket_accept($this->master);
			$this->handle_client($client);
			if(is_resource($client))
			{
				stream_socket_shutdown($client, STREAM_SHUT_RDWR);
				fclose($client);
			}
		}

		return $this->running;
	}

	public function stop()
	{
		$this->running = false;
		fclose($this->master);
		echo ">> Stopping...\n";
		exit(0);
	}

/* private */

	protected function init()
	{
		$this->master = @stream_socket_server("tcp://{$this->host}:{$this->port}", $errno, $errstr);
		if($this->master === false)
		{
			echo ">> Failed to bind socket.\n", socket_strerror(socket_last_error()), "\n";
			exit(2);
		}
		stream_set_blocking($this->master, 0);

		echo "== Rack'em on http://{$this->host}:{$this->port}\n";
		echo ">> Rack'em web server\n";
		echo ">> Listening on {$this->host}:{$this->port}, CTRL+C to stop\n";
		if(function_exists('pcntl_signal'))
		{
			pcntl_signal(SIGINT, array($this, "stop"));
			pcntl_signal(SIGTERM, array($this, "stop"));
			pcntl_signal(SIGCHLD, array($this, "child_handler"));
		}
	}

	protected function child_handler()
	{
		pcntl_waitpid(-1, $status);
	}

	protected function env($req)
	{
		$env = array(
			'REQUEST_METHOD' => $req['method'],
			'SCRIPT_NAME' => "",
			'PATH_INFO' => $req['request_url']['path'],
			'SERVER_NAME' => $req['request_url']['host'],
			'SERVER_PORT' => $this->port,
			'SERVER_PROTOCOL' => $req['protocol'],
			'QUERY_STRING' => $req['request_url']['query'],
			'rack.version' => \Rackem::version(),
			'rack.url_scheme' => $req['request_url']['scheme'],
			'rack.input' => fopen('php://temp', 'r+b'),
			'rack.errors' => fopen('php://stderr', 'wb'),
			'rack.multithread' => false,
			'rack.multiprocess' => false,
			'rack.run_once' => false,
			'rack.session' => array(),
			'rack.logger' => new \Rackem\Logger(fopen('php://stderr', 'wb'))
		);
		if(isset($req['headers']['Content-Type']))
		{
			$env['CONTENT_TYPE'] = $req['headers']['Content-Type'];
			unset($req['headers']['Content-Type']);
		}
		if(isset($req['headers']['Content-Length']))
		{
			$env['CONTENT_LENGTH'] = $req['headers']['Content-Length'];
			unset($req['headers']['Content-Length']);
		}
		fwrite($env['rack.input'], $req['body']);
		rewind($env['rack.input']);
		foreach($req['headers'] as $k=>$v) $env[strtoupper(str_replace("-","_","http_$k"))] = $v;
		return new \ArrayObject($env);
	}

	protected function log_request($req, $res, $client, $time)
	{
		$date = @date("D M d H:i:s Y");
		$time = sprintf('%.4f', $time);
		$request = $req['method'].' '.$req['request_url']['path'].' '.$req['protocol'].'/'.$req['version'];

		return "{$client} - - [{$date}] \"{$request}\" {$res->status} - {$time}\n";
	}

	protected function get_url_parts($request, $parts)
	{
		$url = array(
			'path'   => $request,
			'scheme' => 'http',
			'host'   => '',
			'port'   => '',
			'query'  => ''
		);

		if(isset($parts['headers']['Host']))
			$url['host'] = $parts['headers']['Host'];
		elseif(isset($parts['headers']['host']))
			$url['host'] = $parts['headers']['host'];
		
		if(strpos($url['host'], ':') !== false)
		{
			$host = explode(':', $url['host']);
			$url['host'] = trim($host[0]);
			$url['port'] = (int) trim($host[1]);
			if($url['port'] == 443) $url['scheme'] = 'https';
		}

		$path  = $url['path'];
		$query = strpos($path, '?');
		if($query)
		{
			$url['query'] = substr($path, $query + 1);
			$url['path'] = substr($path, 0, $query);
		}

		return $url;
	}

	protected function parse_parts($req)
	{
		$start = null;
		$headers = array();
		$body = '';

		$lines = preg_split('/(\\r?\\n)/',$req, -1, PREG_SPLIT_DELIM_CAPTURE);
		for($i=0, $total = count($lines); $i < $total; $i += 2)
		{
			$line = $lines[$i];
			if(empty($line))
			{
				if($i < $total - 1) $body = implode('', array_slice($lines, $i + 2));
				break;
			}

			if(!$start)
			{
				$start = explode(' ', $line, 3);
			}elseif(strpos($line, ':'))
			{
				$parts = explode(':', $line, 2);
				$key = trim($parts[0]);
				$value = isset($parts[1])? trim($parts[1]) : '';
				if(!isset($headers[$key]))
					$headers[$key] = $value;
				elseif(!is_array($headers[$key]))
					$headers[$key] = array($headers[$key], $value);
				else
					$headers[$key][] = $value;
			}
		}

		return array(
			'start'   => $start,
			'headers' => $headers,
			'body'    => $body
		);
	}

	protected function parse_request($raw)
	{
		if(!$raw) return false;

		$parts = $this->parse_parts($raw);

		if(isset($parts['start'][2]))
		{
			$start = explode('/', $parts['start'][2]);
			$protocol = strtoupper($start[0]);
			$version = isset($start[1])? $start[1] : '1.1';
		}else
		{
			$protocol = 'HTTP';
			$version = '1.1';
		}

		$parsed = array(
			'method'   => strtoupper($parts['start'][0]),
			'protocol' => $protocol,
			'version'  => $version,
			'headers'  => $parts['headers'],
			'body'     => $parts['body']
		);

		$parsed['request_url'] = $this->get_url_parts($parts['start'][1], $parsed);

		return $parsed;
	}

	protected function process_from_cli($buffer, $client)
	{
		$res = "";
		$spec = array(
			0 => array("pipe", "rb"),
			1 => array("pipe", "wb"),
			2 => array("pipe", "wb")
		);

		$app = file_exists($this->app) ? $this->app : "--basic";

		$proc = proc_open(dirname(dirname(__DIR__))."/bin/rackem $app --process --client $client", $spec, $pipes);
		stream_set_blocking($pipes[2], 0);
		if(!is_resource($proc)) return "";

		fwrite($pipes[0], $buffer);
		fclose($pipes[0]);

		$res = stream_get_contents($pipes[1]);
		fclose($pipes[1]);

		echo stream_get_contents($pipes[2]);
		fclose($pipes[2]);

		proc_close($proc);
		return $res;
	}

	protected function write_response($req, $res)
	{
		list($status, $headers, $body) = $res->finish();
		$phrase = Utils::status_code($status);
		$body = implode("", $body);
		$head = "{$req['protocol']}/{$req['version']} $status $phrase\r\n";

		$raw_headers = array();

		foreach($headers as $key=>$values)
			foreach(explode("\n",$values) as $value) $raw_headers[] = "$key: $value";

		$head .= implode("\r\n", $raw_headers);
		return "$head\r\n\r\n$body";
	}

}
