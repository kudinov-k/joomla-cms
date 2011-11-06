<?php
/**
 * @package     Joomla.Platform
 * @subpackage  Client
 *
 * @copyright   Copyright (C) 2005 - 2011 Open Source Matters, Inc. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

defined('JPATH_PLATFORM') or die;

jimport('joomla.environment.uri');
jimport('joomla.client.http');
JLoader::register('JHttpResponse', JPATH_PLATFORM.'/joomla/client/http.php');
jimport('joomla.github.objects.githubpulls');
jimport('joomla.github.objects.githubgists');
jimport('joomla.github.objects.githubissues');
jimport('joomla.github.githubobject');

/**
 * HTTP client class.
 *
 * @package     Joomla.Platform
 * @subpackage  Client
 * @since       11.1
 */
class JGithub
{
	const AUTHENTICATION_NONE = 0;
	const AUTHENTICATION_BASIC = 1;
	const AUTHENTICATION_OAUTH = 2;

	/**
	 * Authentication Method
	 *
	 * Possible values are 0 - no authentication, 1 - basic authentication, 2 - OAuth
	 *
	 * @var    string
	 * @since  11.1
	 */
	protected $authentication_method = 0;

	protected $gists = null;

	protected $issues = null;

	protected $pulls = null;

	protected $credentials = array();

	/**
	 * Constructor.
	 *
	 * @param   array  $options  Array of configuration options for the client.
	 *
	 * @return  void
	 *
	 * @since   11.1
	 */
	public function __construct($options = array())
	{
		if (isset($options['username']) && isset($options['password'])) {
			$this->credentials['username'] = $options['username'];
			$this->credentials['password'] = $options['password'];
			$this->authentication_method = JGithub::AUTHENTICATION_BASIC;
		} elseif (isset($options['token'])) {
			$this->credentials['token'] = $options['token'];
			$this->authentication_method = JGithub::AUTHENTICATION_OAUTH;
		} else {
			$this->authentication_method = JGithub::AUTHENTICATION_NONE;
		}

		$this->http = curl_init();
	}

	/**
	 * Magic method to lazily create API objects
	 *
	 * @param   string  $name  Name of property to retrieve
	 *
	 * @return  mixed  API object (gists, issues, pulls, etc)
	 *
	 * @since   11.3
	 */
	public function __get($name)
	{
		if ($name == 'gists') {
			if ($this->gists == null) {
				$this->gists = new JGithubGists($this);
			}
			return $this->gists;
		}

		if ($name == 'issues') {
			if ($this->issues == null) {
				$this->issues = new JGithubIssues($this);
			}
			return $this->issues;
		}

		if ($name == 'pulls') {
			if ($this->pulls == null) {
				$this->pulls = new JGithubPulls($this);
			}
			return $this->pulls;
		}

	}

	/**
	 * Perform a Github API call
	 *
	 * @param   string         $path             Path to object to manipulate
	 * @param   string         $verb             Verb (request method) to use
	 * @param   array          $data             Data to send. This data will be JSON encoded before being sent to server
	 * @param	array          $options          Request options
	 *
	 * @return  JHttpResponse  Request response
	 *
	 * @since   11.3
	 */
	public function sendRequest($path, $verb = 'get', $data = array(), $options = array())
	{
		// initialize curl request
		$this->http = curl_init();

		// setup baseline curl options
		$curl_options = array(
			CURLOPT_URL => 'https://api.github.com' . $path,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HEADER => false,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_USERAGENT => 'JGithub',
			CURLOPT_CONNECTTIMEOUT => 120,
			CURLOPT_TIMEOUT => 120,
			CURLINFO_HEADER_OUT => true,
			CURLOPT_HTTPHEADER => array('Content-type: application/json'),
			CURLOPT_CAINFO => dirname(__FILE__) . '/github/cacert.pem',
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST, 2
		);

		// set authentication information for the request
		switch ($this->authentication_method)
		{
			case JGithub::AUTHENTICATION_BASIC:
				$curl_options[CURLOPT_USERPWD] = $this->credentials['username'].':'.$this->credentials['password'];
				break;

			case JGithub::AUTHENTICATION_OAUTH:
				if (strpos($path, '?') === false) {
					$path .= '?access_token='.$this->credentials['token'];
				} else {
					$path .= '&access_token='.$this->credentials['token'];
				}
				break;
		}

		// initialize curl options according to request type
		switch ($verb) {
			case 'post':
				$curl_options[CURLOPT_POST] = 1;
				$curl_options[CURLOPT_POSTFIELDS] = json_encode($data);
				break;

			case 'put':
				$curl_options[CURLOPT_POST] = 1;
				$curl_options[CURLOPT_POSTFIELDS] = '';
				$curl_options[CURLOPT_CUSTOMREQUEST] = 'PUT';
				$curl_options[CURLOPT_HTTPGET] = false;
				break;

			case 'patch':
				$curl_options[CURLOPT_POSTFIELDS] = json_encode($data);
			case 'delete':
				$curl_options[CURLOPT_CUSTOMREQUEST] = strtoupper($verb);
				$curl_options[CURLOPT_POST] = false;
				$curl_options[CURLOPT_HTTPGET] = false;

				break;

			case 'get':
				$curl_options[CURLOPT_POSTFIELDS] = null;
				$curl_options[CURLOPT_POST] = false;
				$curl_options[CURLOPT_HTTPGET] = true;

				break;
		}

		curl_setopt_array($this->http, $curl_options);

		$response = new JHttpResponse;
		$response->body = json_decode(curl_exec($this->http));

		$request_data = curl_getinfo($this->http);
		$response->headers = $request_data['request_header'];
		$response->code = $request_data['http_code'];

		curl_close($this->http);
		return $response;
	}
}
