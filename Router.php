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

use Rose\Main;
use Rose\Configuration;
use Rose\Strings;
use Rose\Regex;
use Rose\Text;
use Rose\Gateway;
use Rose\Session;
use Rose\Arry;
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

		self::$instance->base = Main::$CORE_DIR.'/content';
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
		while (Text::endsWith($path, '/')) $path = Text::substring($path, 0, -1);

		// Check if the relative path is registered as a service redirection.
		$conf = Configuration::getInstance()->Router;
		if ($conf)
		{
			foreach ($conf->__nativeArray as $key => $value)
			{
				$result = Regex::_matchFirst ('`^'.$key.'`', $path);
				if (!$result->length) continue;

				$relative_path = Text::substring($path, Text::length($result->get(0)));

				// Action of the form "service:name" will redirect to a service given its name.
				if (Text::startsWith($value, 'service:'))
				{
					$value = Expr::eval(Text::substring($value, 8), $result);

					parse_str(parse_url($value, PHP_URL_QUERY), $args);
					$gateway->request->merge(new Map($args), true);

					$value = parse_url($value, PHP_URL_PATH);

					$name = Text::split('/', $value)->get(0);
					$value = Text::substring($value, Text::length($name));

					$gateway->relativePath = $value . $relative_path;
					if ($gateway->relativePath == '/') $gateway->relativePath = '';

					$gateway->ep .= $result->get(0);

					$serv = $gateway->getService($name);
					if (!$serv) throw new Error ("Service `" . $name . "` is not registered.");

					return $serv->main();
				}

				// Action of the form "location:url" will redirect to a given URL using HTTP 'location' header.
				if (Text::startsWith($value, 'location:'))
				{
					$value = Expr::eval(Text::substring($value, 9), $result);
					return Gateway::header('Location: ' . $value);
				}

				// Default action is to change the relative path and merge query parameter.
				$value = Expr::eval($value, $result);

				parse_str(parse_url($value, PHP_URL_QUERY), $args);
				$gateway->request->merge(new Map($args), true);

				$path = parse_url($value, PHP_URL_PATH);
			}
		}

		Session::open (false);

		if (Path::exists('layouts/startup.fn'))
			Expr::expand(Expr::parseTemplate(File::getContents('layouts/startup.fn'), '(', ')'), new Map());

		$this->content ($path ? $path : '/home');
	}

	/*
	**	Expands an expression using the '{' and '}' characters as delimiters.
	*/
	public function expand ($path, $data)
	{
		return Expr::expand(Expr::parseTemplate(File::getContents($path), '{', '}', false, 1, false), $data);
	}

	/*
	**	Attempts to load and return content given a relative target path.
	*/
	public function content ($target_path, $src_path=null)
	{
		$gateway = Gateway::getInstance();

		if (Text::endsWith($target_path, '/'))
			$target_path = Text::substring($target_path, 0, -1);

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
					throw new Error('Not Accessible: ' . $src_path);
				}

				return $this->content('/login', $target_path);
			}
		}

		if ($file == null)
		{
			if ($src_path)
			{
				http_response_code(404);
				throw new Error('Not Found: ' . ($target_path == '/404' ? $src_path : $target_path));
			}

			return $this->content('/404', $target_path);
		}

		// *********************************************
		$data = new Map ([
			'router' => [
				'path' => Path::dirname($file),
				'url' => $gateway->ep.'/'.Path::dirname($file),

				'target' => $target_path,
				'source' => $cur_path,
				'target_url' => $gateway->ep.'/'.Text::substring($target_path, 1),
				'source_url' => $gateway->ep.'/'.Text::substring($cur_path, 1)
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
			$output = Text::replace('////', $gateway->ep.'/'.Strings::getInstance()->lang.'/', $output);
		else
			$output = Text::replace('////', $gateway->ep.'/'.(Strings::getInstance()->lang != Configuration::getInstance()->Locale->lang ? Strings::getInstance()->lang.'/' : ''), $output);

		$output = Text::replace('///', $gateway->ep.'/', $output);

		echo $output;
	}
};

// Initialize the router service.
Router::init();
