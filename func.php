<?php
	
	return function () {
		
		$response = function ($response) {
			$code = ((intval($response[0]) > 0) ? intval($response[0]) : 0);
			$codes = array('200'=>'OK','201'=>'Created','204'=>'No Content','301'=>'Moved','302'=>'Found','304'=>'Not Modified','307'=>'Temporary Redirect','400'=>'Bad Request','401'=>'Not Authorized','403'=>'Forbidden','404'=>'Not Found','406'=>'Not Acceptable','424'=>'Method Failure','429'=>'Too Many Requests','454'=>'Session Not Found','500'=>'Internal Server Error','501'=>'Not Implemented','503'=>'Service Unavailable','0'=>'Error');
			header('HTTP/1.0 '.$code.' '.((strlen($codes[(''.intval($code))])) ? $codes[(''.intval($code))] : ''));
			if (isset($response[2]) && $response[2] === 'html') {
				header('Content-type: text/html; charset=utf-8;');
				echo isset($response[1]) ? $response[1] : '';
			} else {
				header('Content-type: application/json; charset=utf-8;');
				if ($code === 200 || $code === 201) {
					$encoded = isset($response[1]) ? json_encode($response[1]) : false;
					echo $encoded ? $encoded : '{}';
				}
			}
			return array(null);
		};
		
		$route = function ($deployment, $settings, $routes, $server, $request, $get, $files, $cookie, $root) use ($response) {
			
			# - Parse and process environment configuration:
			$env = array(
				'root' => $root,
				'deployment' => strtolower($deployment),
				'domain' => call_user_func(function ($domain, $ssl) {
					$domain = ((strpos($domain, '://') === false) ? 'http'.($ssl ? 's' : '').'://'.$domain : $domain);
					$components = parse_url($domain);
					if (!isset($components['host']) && strlen($components['host'])) { return false; }
					return 'http'.($ssl ? 's' : '').'://'.$components['host'].((isset($components['port']) && strlen($components['port'])) ? ':'.intval($components['port']) : '');
				}, $settings['domain'], $settings['ssl']),
				'ssl' => $settings['ssl'] ? true : false,
				'unsafe' => $settings['unsafe'] ? true : false,
				'origins' => call_user_func(function ($origins, $ssl) {
					for ($i = 0, $n = count($origins), $_ = array(); $i < $n; $i++) {
						if (is_string($origins[$i])) {
							$domain = ((strpos($origins[$i], '://') === false) ? 'http'.($ssl ? 's' : '').'://'.$origins[$i] : $origins[$i]);
							$components = parse_url($domain);
							if (isset($components['host']) && strlen($components['host'])) {
								$_[] = 'http'.($ssl ? 's' : '').'://'.$components['host'].((isset($components['port']) && strlen($components['port'])) ? ':'.intval($components['port']) : '');
							}
						}
					}
					return $_;
				}, $settings['origins'], $settings['ssl'])
			);
			
			# - Deniable conditions:
			if (!is_dir($env['root'])) { $response(array(503)); return array(503); }
			if ($env['unsafe'] !== true) {
				if (!$env['domain']) { $response(array(503)); return array(503); }
				if ($env['deployment'] === 'production') {
					if ($server['HTTP_HOST'] !== parse_url($env['domain'])['host']) {
						$response(array(406)); return array(406);
					}
					if ($env['ssl'] === true && intval($server['SERVER_PORT']) !== 443) {
						$response(array(426)); return array(426);
					}
				}
			}
			
			# - Headers:
			if (($env['unsafe'] === true) || (isset($server['HTTP_ORIGIN']) && in_array(strtolower((string)$server['HTTP_ORIGIN']), $env['origins']))) {
				header('Access-Control-Allow-Origin: '.$server['HTTP_ORIGIN']);
				header('Access-Control-Allow-Headers: Accept, Accept-Language, Content-Language, Content-Type, Origin');
				if (strtolower($server['REQUEST_METHOD']) === 'options') {
					header('Access-Control-Allow-Methods: HEAD, GET, PUT, PATCH, POST, DELETE, TRACE');
					exit();
				}
				header('Access-Control-Allow-Credentials: true');
				header('Access-Control-Max-Age: 86400');
			}
			
			# - Process path:
			$path = call_user_func(function ($uri) {
				$components = explode('/', (($uri[0] === '/') ? $uri : '/'.$uri));
				for ($i = 0, $n = count($components); $i < $n; $i++) {
					if (!$components[$i]) {
						unset($components[$i]);
					} else {
						break;
					}
				}
				$path = '/'.trim(preg_replace('/[^a-z0-9\-_\/\.]/', '', strtolower(strtok(implode('/', $components), '?'))), '/');
				return array(
					'path' => $path,
					'components' => $path === '/' ? array() : array_slice(explode('/', $path), 1)
				);
			}, $server['REQUEST_URI']);
			
			# - Process routes:
			$routes = call_user_func(function ($routes, $method) {
				return call_user_func(function ($routes) {
					$regex = ''; $params = array();
					foreach ($routes as $k => $v) {
						$route = call_user_func(function ($components) {
							if (count($components)) {
								$r = ''; $p = array();
								for ($i = 0, $c = count($components); $i < $c; $i++) {
									$v = $components[$i];
									if (is_string($v) && strlen($v)) {
										$x = preg_replace('/[^a-z0-9\-_]/', '', $v);
										if (strlen($x)) {
											$r .= ($v[0] === ':') ? '/[^/]+' : '/'.$x;
											if ($v[0] === ':') {
												$p[$i] = $x;
											}
										} else {
											return false;
										}
									}
								}
								return strlen($r) ? array('('.$r.')', $p) : false;
							} else {
								return array('(/)', array());
							}
						}, preg_split('~/~', trim(preg_replace('/[^a-z0-9\-_\/\:]/', '', strtolower($k)), '/'), -1, PREG_SPLIT_NO_EMPTY));
						if ($route && strlen($route[0])) {
							$regex .= strlen($regex) ? '|'.$route[0] : '~^(?:'.$route[0];
							$params[] = array($v, $route[1]);
						}
					}
					return strlen($regex) ? array($regex.')$~', $params) : false;
				}, call_user_func(function ($routes, $method) {
					return array_unique(((in_array(strtolower((string)$method), array('delete','get','patch','post','put','head','options','trace'))) ? $routes[strtolower($method)] + $routes['all'] : $routes['get'] + $routes['all']));
				}, $routes, $method));
			}, $routes, $server['REQUEST_METHOD']);
			
			# - Request route:
			$route = call_user_func(function ($env, $routes, $path) {
				return (($routes && preg_match($routes[0], $path['path'], $matches)) ? call_user_func(function ($route, $components) {
					$params = array();
					foreach ($route[1] as $k => $v) {
						$params[$v] = $components[$k];
					}
					return array(
						'path' => $route[0],
						'params' => $params
					);
				}, $routes[1][(count(array_splice($matches, 1))-1)], $path['components']) : false);
			}, $env, $routes, $path);
			
			# - Response:
			if (!$route) { $response(array(501)); return array(501); }
			if (!file_exists($env['root'].'/'.$route['path'])) { $response(array(404)); return array(404); }
			if (pathinfo($route['path'], PATHINFO_EXTENSION) === 'html') {
				header('HTTP/1.0 200 OK');
				header('Content-type: text/html; charset=utf-8;');
				require($env['root'].'/'.$route['path']);
			} else {
				$function = (pathinfo($route['path'], PATHINFO_EXTENSION) === 'php') ? require($env['root'].'/'.$route['path']) : false;
				if (!is_callable($function)) { $response(array(424)); return array(424); }
				$response($function(
					array(
						'env' => $env,
						'req' => array(
							'params' => $route['params'],
							'body' => &$request,
							'query' => &$get,
							'files' => &$files,
							'cookie' => &$cookie,
							'ip' => $server['REMOTE_ADDR'],
							'agent' => $server['HTTP_USER_AGENT']
						)
					)
				));
			}
			
			return array(null);
			
		};
		
		return array(
			'route' => $route
		);
		
	};
	
?>