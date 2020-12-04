<?php
/*
**	Rose\Ext\Router
**
**	Copyright (c) 2020-2021, RedStar Technologies, All rights reserved.
**	https://rsthn.com/
**
**	THIS LIBRARY IS PROVIDED BY REDSTAR TECHNOLOGIES "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES,
**	INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A 
**	PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL REDSTAR TECHNOLOGIES BE LIABLE FOR ANY
**	DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT 
**	NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; 
**	OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, 
**	STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE
**	USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
*/

namespace Rose\Ext\Router;

use Rose\Errors\FalseError;
use Rose\Errors\Error;

use Rose\IO\Directory;
use Rose\IO\Path;
use Rose\IO\File;

use Rose\Configuration;
use Rose\Strings;
use Rose\Regex;
use Rose\Text;
use Rose\Gateway;
use Rose\Session;
use Rose\Map;
use Rose\Expr;


/*
**	Content router extension.
*/

class Router
{
	private static $instance;

	private $base;
	private $cache;

	/*
	**	Returns the single instance of this class.
	*/
	public static function getInstance()
	{
		return self::$instance;
	}

	/*
	**	Initializes the singleton and registers the 'router' service.
	*/
	public static function init()
	{
		self::$instance = new Router();

		self::$instance->base = 'resources/content';
		self::$instance->cache = 'volatile/content';

		//if (!Path::exists(self::$instance->cache))
		//	Directory::create(self::$instance->cache, true);

		Gateway::registerService ('router', self::$instance);
	}

	/*
	**	Entry point of the service. Will determine which content page to load or will run another action (such as redirecting to another
	**	service) based on the 'Router' configuration section.
	*/
	public function main()
	{
		$gateway = Gateway::getInstance();

		$path = $gateway->relativePath;
		if (Text::substring($path, -1) == '/') $path = Text::substring($path, 0, -1);

		// Check if the relative path is registered as a service redirection.
		$conf = Configuration::getInstance()->Router;
		if ($conf)
		{
			foreach ($conf->__nativeArray as $key => $value)
			{
				if (!Text::startsWith($path, $key))
					continue;

				$relative_path = Text::substring($path, Text::length($key));

				// Action of the form "service:name" will redirect to a service given its name.
				if (Text::startsWith($value, 'service:'))
				{
					$gateway->relativePath = $relative_path;
					$value = Text::substring($value, 8);

					$serv = $gateway->getService($value);
					if (!$serv) throw new Error ("Service `" . $value . "` is not registered.");

					return $serv->main();
				}

				// Action of the form "location:url" will redirect to a given URL using HTTP 'location' header.
				if (Text::startsWith($value, 'location:'))
					return Gateway::header('Location: ' . Text::substring($value, 9));
			}
		}

		Session::open (false);

		$this->content ($path ? $path : '/home');
	}

	/*
	**	Expands an expression using the '{' and '}' characters as delimiters.
	*/
	public function expand ($path, $data)
	{
		return Expr::expand(Expr::parseTemplate(File::getContents($path), '{', '}'), $data);
	}

	/*
	**	Attempts to load and return content given a relative target path.
	*/
	public function content ($target_path, $src_path=null)
	{
		$gateway = Gateway::getInstance();

		$cur_path = $src_path ? $src_path : $target_path;

		$cache = $this->cache . $target_path;
		$path = $this->base . $target_path;

		$file = null;

		$has_private = Path::exists($path.'/private.html');
		$has_public = Path::exists($path.'/public.html');
		$has_general = Path::exists($path.'/index.html');

		if (Session::$data->user != null)
		{
			if ($has_private)
				$file = $path.'/private.html';
			else if ($has_general)
				$file = $path.'/index.html';
		}
		else
		{
			if ($has_public)
				$file = $path.'/public.html';
			else if ($has_general)
				$file = $path.'/index.html';
			else if ($has_private)
			{
				if ($src_path)
				{
					http_response_code(404);
					throw new Error('Not Found: ' . $src_path);
				}

				return $this->content('/login', $target_path);
			}
		}

		if ($file == null)
		{
			if ($src_path)
			{
				http_response_code(404);
				throw new Error('Not Found: ' . $src_path);
			}

			return $this->content('/404', $target_path);
		}

		// *********************************************
		$data = new Map ([
			'router' => [
				'path' => Path::dirname($file),
				'url' => $gateway->ep.Path::dirname($file),

				'target' => $target_path,
				'source' => $cur_path,
				'target_url' => $gateway->ep.Text::substring($target_path, 1),
				'source_url' => $gateway->ep.Text::substring($cur_path, 1)
			]
		]);

		$data->content = $this->expand ($file, $data);

		$conf = Path::dirname($file).'/folder.conf';
		$conf = Path::exists($conf) ? Configuration::loadFrom ($conf) : new Map();

		$name = Path::name($file);

		$layout = 'layouts/'.$name.'.html';

		if ($conf->has('layouts') && $conf->layouts->has($name))
			$layout = $conf->layouts->{$name};

		if (Path::exists($layout))
			$output = $this->expand ($layout, $data);
		else
			$output = $data->content;

		$conf = Configuration::getInstance()->Router;
		if ($conf->show_default_lang == 'true')
			$output = Text::replace('////', $gateway->ep.Strings::getInstance()->lang.'/', $output);
		else
			$output = Text::replace('////', $gateway->ep.(Strings::getInstance()->lang != Configuration::getInstance()->Locale->lang ? Strings::getInstance()->lang.'/' : ''), $output);

		$output = Text::replace('///', $gateway->ep, $output);

		echo $output;
	}
};

// Initialize the router service.
Router::init();
