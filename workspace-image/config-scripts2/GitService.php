<?php

abstract class GitService {

	protected $https = FALSE;
	protected $domain;
	protected $user = 'git';
	protected $port = 22;
	protected $shortName;
	protected $tokenParam;
	protected $token;
	protected $apiSubDomain;
	protected $apiVersion;
	protected $apiUri;
	protected $apiBaseUri;
	protected $apiUris = [
		'user-keys' => '',
	];
	protected $postJson;
	protected $existingKeys = NULL;
	protected $serviceUser = NULL;
	protected $repositories = NULL;

	public function __construct(Git $git)
	{
		$config = $git->serviceConfig($this->config_service_name);

		if (array_key_exists('https', $config))
		{
			$this->https($config['https']);
		}
		
		if (array_key_exists('domain', $config))
		{
			$this->domain($config['domain']);
		}

		if (array_key_exists('user', $config))
		{
			$this->user($config['user']);
		}

		if (array_key_exists('port', $config))
		{
			$this->port($config['port']);
		}
		$shortName = array_key_exists('short-name', $config) ? $config['short-name'] : $this->config_service_name;
		$this->shortName($shortName);

		if (array_key_exists('token', $config))
		{
			$this->token($config['token']);
		}

		if (array_key_exists('api-version', $config))
		{
			$this->apiVersion($config['api-version']);
		}
	}

	public function register()
	{
		if ($this->addSsh())
		{
			$this->deleteDeletedWorkspaceSshKeys();
		}
		return $this;
	}

	public function https($https)
	{
		$this->https = $https;
		return $this;
	}

	public function domain($domain)
	{
		$this->domain = $domain;
		return $this;
	}

	public function user($user)
	{
		if (preg_match('/your-.*-here/i', $user))
		{
			return $this;
		}
		$this->user = $user;
		return $this;
	}

	public function port($port)
	{
		$this->port = $port;
		return $this;
	}

	public function shortName($shortName)
	{
		$this->shortName = $shortName;
		return $this;
	}

	public function token($token)
	{
		if (preg_match('/your-.*-here/i', $token))
		{
			return $this;
		}
		$this->token = $token;
		return $this;
	}

	public function apiVersion($apiVersion)
	{
		$this->apiVersion = $apiVersion;
		return $this;
	}

	public function addSsh()
	{
		$ssh = new Ssh();
		$ssh->host($this->shortName);
		$ssh->hostname($this->domain);
		$ssh->user($this->user);
		$ssh->port($this->port);
		$identityfile = getenv('HOME')."/.ssh/".str_replace('.', '_', $this->domain)."_rsa";
		$ssh->identityfile($identityfile);
		$ssh->stricthostkeychecking(FALSE);
		$ssh->addConfigEntry();

		$sendKey = TRUE;
		$title = SSH_KEY_TITLE;

		if ( ! $this->token)
		{
			Logger::log("Please set your ".get_called_class()." token.", 0);
			return FALSE;
		}

		$existingKeys = $this->listKeys();
		if ($existingKeys === FALSE)
		{
			return FALSE;
		}

		foreach($existingKeys as $key)
		{
			if ($key['title'] === $title)
			{
				$sendKey = FALSE;
				$local_key = substr(file_get_contents($identityfile.'.pub'), 0, strlen($key['key']));
				if ($local_key != $key['key'])
				{
					$this->deleteSshKey($key['id'], $key['title']);
					$sendKey = TRUE;
				}
				else
				{
					Logger::log("Key ".$key['title']." already exists", 1);
				}
				
				break;
			}
		}

		if ($sendKey)
		{
			$this->sendSshKey($title, file_get_contents($identityfile.'.pub'));
		}

		return TRUE;
	}

	public function deleteDeletedWorkspaceSshKeys()
	{
		exec("docker inspect $(docker ps -aq)", $res);
		$containers = json_decode(implode($res));
		$hostnames = array();
		foreach($containers as $c)
		{
			$hostnames[] = $c->Config->Hostname;
		}
		
		foreach ($this->listKeys() ?: array() as $key)
		{
			if ( ! SSH_KEY_BASE_TITLE || substr($key['title'], 0, strlen(SSH_KEY_BASE_TITLE)) != SSH_KEY_BASE_TITLE)
			{
				continue;
			}
			
			if( ! in_array(substr(strstr($key['title'], '@'), 1), $hostnames))
			{
				$this->deleteSshKey($key['id'], $key['title']);
			}
		}
	}

	public function getServiceUser()
	{
		if ($this->serviceUser === NULL)
		{
			$this->serviceUser = $this->api('user')->get();
		}
		return $this->serviceUser;
	}

	public function getServiceUsername()
	{
		$user = $this->getServiceUser();

		if ( ! array_key_exists('username', $user))
		{
			throw new Exception("Could not get user from ".get_called_class(), 1);
		}

		return $user['username'];
	}

	public function getRepositories()
	{
		if ($this->repositories === NULL)
		{
			$this->repositories = $this->api('repositories')->get();
		}
		return $this->repositories;
	}

	protected function getProtocol()
	{
		return $this->https ? 'https' : 'http';
	}

	protected function getApiUrl()
	{
		$domain = $this->domain;
		if ($this->apiSubDomain)
		{
			$domain = $this->apiSubDomain.'.'.$domain;
		}
		
		$url = $this->getProtocol()."://".$domain;
		if ($this->apiUri)
		{
			$url .= '/'.trim($this->apiUri, '/');
		}
	
		return $url;
	}

	protected function listKeys($fresh = FALSE)
	{
		if ($this->existingKeys === NULL || $fresh)
		{
			$this->existingKeys = $this->api('user-keys')->get();
			
			if ( ! is_array($this->existingKeys) || ($this->existingKeys !== array() && ( ! is_array(current($this->existingKeys)) || ! array_key_exists('id', current($this->existingKeys)))))
			{
				Logger::log("Invalid ".get_called_class(). " credentials.", 0);
				$this->existingKeys = FALSE;
			}
		}
		return $this->existingKeys;
	}

	protected function sendSshKey($title, $key)
	{
		Logger::log("Sending key $title", 1);
		return $this->api('user-keys')->data([
			'title' => $title,
			'key' => $key,
		], $this->postJson)->post();
	}

	protected function deleteSshKey($id, $label = FALSE)
	{
		Logger::log("Delete key ".($label ? "$label ($id)" : $id), 1);
		return $this->api('user-keys', $id)->delete();
	}

	protected function api($apiUriType, $addition = FALSE)
	{
		if ( ! array_key_exists($apiUriType, $this->apiUris))
		{
			throw new Exception("Unsupported uri: $apiUriType in ".get_called_class()." service", 1);
		}

		$uri = $this->apiUris[$apiUriType];

		if ($addition)
		{
			$uri .= '/'.$addition;
		}

		$curl = new Curl();
		return $curl->url($this->getApiUrl())
					->uri($uri)
					->query($this->tokenParam, $this->token);
	}
}
