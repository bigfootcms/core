<?php

/**
 * Bigfoot class
 *
 * @author Nicholas Maietta
 */
class Bigfoot extends Prefab {

	private static $instance;
	private $dbh;
	private static $auth;
	
	public $content;
	public $inner_content;
	
	public $prepared;
	public $security;
	public $meta;
	public $theme;
	public $script;
	public $internal;
	public $status;
	public $content_area;
	public $permission;
	public $html;
	
	private function __construct() {
		// Use this to build instance?
	}

	public static function CMS($config=NULL) {
		base::instance()->set("vpath", ((substr(base::instance()->get("PATH"),-1)=='/')?substr(base::instance()->get("PATH"),0,-1).'/':base::instance()->get("PATH")));
		base::instance()->set("sitelevel", ('/'. substr(substr(base::instance()->get("vpath"),1), 0, strpos(substr(base::instance()->get("vpath"),1), '/'))));
		base::instance()->set("directory", dirname(base::instance()->get("vpath")));
		if ( base::instance()->get("settings.plugins_dir") ) {
			base::instance()->set('AUTOLOAD', base::instance()->get("ROOT").base::instance()->get("settings.plugins_dir"));
		}
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
			if ( !Base::instance()->get("HOOKS") ) {
				Base::instance()->set("HOOKS", new Hooks());
			}
		}
		if ( $config !== NULL && file_exists($config) ) {
			base::instance()->config($config);
			$plugins = base::instance()->get("plugins");
			if ( is_array($plugins) ) {
				
				foreach($plugins as $name=>$loader) {
					

					$loader = base::instance()->get("ROOT").$loader;
					if ( file_exists($loader) ) {
						chdir(dirname($loader));
						
						Base::instance()->set("plugin_basename", basename(dirname($loader)));
						Base::instance()->set("plugin_path", str_replace(Base::instance()->get("ROOT"), "", dirname($loader)));
						Base::instance()->set('PLUGINS', dirname($loader) .'/');
						
						if ( file_exists(dirname($loader).'/plugin.ini') ) Base::instance()->config(dirname($loader).'/plugin.ini');
						if ( file_exists(dirname($loader).'/plugin.class.php') ) include(dirname($loader).'/plugin.class.php');
						if ( file_exists($loader) ) include($loader);
					}
				}
			}
			chdir(base::instance()->get("ROOT"));
		}
		self::$instance->connect();
		base::instance()->get("HOOKS")->do_action('CMS');
		return self::$instance;
	}
	
	public function RegisterPlugin($config) {
		
		
		Base::instance()->get("HOOKS")->do_action('RegisterPlugin');
	}
	
	public function connect() {
		try {
			$this->dbh = new DB\SQL(
				Base::instance()->get('WebsiteDB.dsn'), Base::instance()->get('WebsiteDB.user'), Base::instance()->get('WebsiteDB.pass')
				, array( PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_PERSISTENT=>false, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_OBJ)
			);
			Base::instance()->set("dbh", $this->dbh);
		} catch (PDOException $e) {
			die('Connection failed: ' . $e->getMessage());
		}
		Base::instance()->get("HOOKS")->add_action('RegisterPlugin', function(){
			
		});
	}

	public function run() {
		Base::instance()->set('ONERROR', function(){ return false; });
		Base::instance()->set('DEBUG', true);
		Base::instance()->set('CACHE', false);
		Base::instance()->set("QUIET", false);
		
		/* Change these based on config */
		Base::instance()->get("HOOKS")->do_action('run');
		Base::instance()->get("HOOKS")->do_action('pre_config');

		$this->connect();
		Base::instance()->get("HOOKS")->do_action('db_connect');
		Base::instance()->get("HOOKS")->do_action('navigation', $this->getNavigationArr());
		if ( Base::instance()->get("PATH") == Base::instance()->get("vpath") && stripos(strrev(Base::instance()->get("PATH")), 'lmth.xedni') === 0 ) {
			header("Location: ".str_replace("index.html", "", Base::instance()->get("vpath")));
		}
		$this->detect_device();
		Base::instance()->get("HOOKS")->do_action("custom");
		$this->get_content();
		$this->security = ( isset($this->content->security) )  ? json_decode($this->content->security)  : array();
		$this->meta     = ( isset($this->content->meta_data) ) ? json_decode($this->content->meta_data) : array();
		$this->theme    = ( isset($this->content->theme) )     ? json_decode($this->content->theme)     : array();

		$this->template = ( !isset($this->theme->template) ) ? "default.html" : $this->theme->template;
		$this->prepared = (object) array("status"=>200, "page_title"=>"Error 404 - Page Not Found");
		
		if ( isset($this->content->page_title) ) {
			$this->prepared->page_title = $this->content->page_title;
		}
		
		Base::instance()->get("HOOKS")->do_action('security');
		
		if (  !empty($this->content->internal_path) ) {
			if (file_exists(Base::instance()->get('ROOT').$this->content->internal_path)) {
				$this->prepared->script = Base::instance()->get('ROOT').$this->content->internal_path;
			}
		} elseif ( isset($this->content->virtual_path) ) {
			if ( isset($this->content->virtual_path) ) {
				if ( isset($this->content->content) ) {
					$this->prepared->content = stripcslashes(htmlspecialchars_decode($this->content->content));
				} else {
					if ( substr($this->content->virtual_path, -1) == '/' && Base::instance()->get("vpath") != "/" ) {
						if (file_exists(Base::instance()->get('ROOT').$this->content->virtual_path.'index.php')) {
							$this->prepared->script = Base::instance()->get('ROOT').$this->content->virtual_path.'index.php';
						}
					}
				}
			} else {
				if ( isset($this->content->content) && strlen($this->content->content) > 0 ) {
					$this->prepared->content = $this->content->content; // Needs testing as it might never get picked up.
				} else {
					echo "Error: No script available to handle this page.";
				}
			}
		}

		if ( isset($this->prepared->internal) ) {
			$this->prepared->response = $this->prepared->internal;
		} elseif ( isset($this->prepared->content) || isset($this->prepared->script) ) {
			if ( isset($this->prepared->script) && file_exists($this->prepared->script)) {
				ob_start();
				include($this->prepared->script);
				$this->prepared->content = ob_get_clean();
			}
		} else {
			$this->prepared->status = 404;
			if ( !isset($this->permissions->cms) ) {
				$this->prepared->response  = "<h4>404 Not Found</h4>\n";
				$this->prepared->response .= "<p>The requested resource could not be found but may be available again in the future.</p>";
			} else {
				$this->prepared->response  = "<h4>Ready for content</h4>\n";
				$this->prepared->response .= "<p>Until static or dynamic content is assigned to this VirtualPath or SiteLevel, the public will see a general Error 404 response.</p>";
			}
		}
		
		$this->inner_content = ( $this->prepared->status == 200 ) ? $this->prepared->content : $this->prepared->response;
		
		if ( in_array(Base::instance()->get("vpath"), array_keys(Base::instance()->get("ROUTES")) )) {
			Base::instance()->set("QUIET", false);
			ob_start();
			Base::instance()->run();
			ob_get_clean();
			
			if ( Base::instance()->get("RESPONSE") ) {
				$this->prepared->status = 200;
				$this->inner_content = Base::instance()->get("RESPONSE");
			}
			if ( Base::instance()->get("page_title") ) {
				$this->prepared->page_title = Base::instance()->get("page_title");
			}	
		}

		if (  !empty(Base::instance()->get("page_title")) ) {
			 $this->prepared->page_title =Base::instance()->get("page_title");
		}
		$this->select_template();
		$this->process_template();
	}
	
	public function set_content($content) {
		Base::instance()->set("content", $content);
	}
	
	public function db() {
		return $this->dbh;
	}	

	private function detect_device() {
		$detect = new Mobile_Detect;
		Base::instance()->set("deviceType", ( $detect->isMobile() ? ( $detect->isTablet() ? 'tablet' : 'phone' ) : 'desktop' ));
	}
	
	private function get_content() {
		$vpathParts = explode("/", Base::instance()->get("vpath"));
		$count = count($vpathParts);
		$path = Base::instance()->get("vpath");
		$query = $this->dbh->prepare('SELECT * FROM content WHERE virtual_path = "'.Base::instance()->get("vpath").'" LIMIT 1');
		$query->execute();
		$sql = "";
		if ( $query->rowCount() == 0 ) {
			for ($i = $count; $i >= 1; $i--) {
				if ( dirname(Base::instance()->get("vpath")) == "/" ) { continue; }
				if ( dirname($path) != "/" ) {
					$sql = 'WHERE virtual_path = "'.dirname($path).'/ " ';
				}
				$path = dirname($path);
				$query = $this->dbh->prepare('SELECT * FROM content '.$sql.' LIMIT 1');
				$query->execute();		
				if ( $query->rowCount() == 1 ) {
					$this->content = $query->fetchAll()[0];
					break;
				}
			}
		} else {
			$this->content = $query->fetchAll()[0];
		}
	}
	
	public function isContentProtected() {
		if ( $this->content->protected == "Y" ) {
			return true;
		}
		return false;
	}

	public function inMaintenanceMode() {
		$triggerFile = Base::instance()->get('ROOT') . "/maintenance.txt";
		if ( file_exists($triggerFile) ) {
			return file_get_contents($triggerFile);
		}
		return false;
	}	
	
	public function select_template() {
		$ext = pathinfo(basename($this->template), PATHINFO_EXTENSION);
		$possibilities = array_unique(array(
			  Base::instance()->get('ROOT') . "/Templates/" . basename($this->template, '.'.$ext) . '/' . Base::instance()->get("deviceType") . '.' . $ext
			, Base::instance()->get('ROOT') . "/Templates/" . basename($this->template, '.'.$ext) . '.' . Base::instance()->get("deviceType") . '.' . $ext
			, Base::instance()->get('ROOT') . "/Templates/" . basename($this->template, '.'.$ext) . '.' . $ext
			, Base::instance()->get('ROOT') . "/Templates/default/".Base::instance()->get("deviceType").".html"
			, Base::instance()->get('ROOT') . "/Templates/default.".Base::instance()->get("deviceType").".html"
			, Base::instance()->get('ROOT') . "/Templates/default.html"
		));

		$nothing_so_far = true;
		foreach($possibilities as $check) {
			if ( file_exists($check) ) {
				$this->template = Template::instance()->resolve(file_get_contents($check));
				unset($nothing_so_far);
				break;
			}
		}
		
		if ( isset($nothing_so_far) ) {
			$possibilities = str_replace(Base::instance()->get('ROOT'), __DIR__, $possibilities);
			foreach($possibilities as $check) {
				if ( file_exists($check) ) {
					$this->template = Template::instance()->resolve(file_get_contents($check));
					unset($nothing_so_far);
					break;
				}
			}
		}

		if ( isset($nothing_so_far) ) {
			 die("No templates found in /Templates/ or ".str_replace($f3->get('ROOT'), '', __DIR__)."/Templates/.");
		}
		
	}
	
	public function process_template() {
	
		$html = new PureHTML();
		$html->scan($this->template, "head");
		$this->template = $html->scrub($this->template);
		
		// Dynamics are standalone PHP files that do not require the use of the F3 system
		if ( strlen($this->inner_content) > 0 ) {
			$domOfDynamic = new DOMDocument();
			$domOfDynamic->loadHTML($this->inner_content);
			$frag = $domOfDynamic->saveHTML();
			$html->scan($frag);
			$scrubbed_dynamic = $html->scrub($frag);
			$this->template = $html->splice($this->template, $scrubbed_dynamic, "content");
		}

		$dynamics_depth = 5;
		$already_processed = array();
		$already_processed[] = "content";
		for($i=0; $i<=($dynamics_depth-1); $i++) {
			libxml_use_internal_errors(true);
			$tmpDOM = new DOMDocument();
			$tmpDOM->loadHTML(html_entity_decode($this->template, ENT_HTML5));
			foreach($tmpDOM->getElementsByTagName('*') as $tag) {
				if ( !in_array($tag->getAttribute("id"), $already_processed) ) {
					$already_processed[] = $tag->getAttribute("id");
					$typeOfDynamic = ( file_exists($_SERVER['DOCUMENT_ROOT']."/dynamics/".$tag->getAttribute("id").".php" ) )
						? ( ( file_exists($_SERVER['DOCUMENT_ROOT']."/dynamics/".$tag->getAttribute("id").".html" ) )
						? "html" : "php") : false;
					if ( $typeOfDynamic !== false && ( $typeOfDynamic == "html" || $typeOfDynamic == "php" ) ) {
						ob_start();
						include($_SERVER['DOCUMENT_ROOT']."/dynamics/".$tag->getAttribute("id").".php");
						$dynamic_output = ob_get_clean();
						if ( $typeOfDynamic == "html" ) {						
							$unscrubbed_dynamic_content = trim((Template::instance()->render('/dynamics/'.$tag->getAttribute("id").'.html')));
							if ( strlen($unscrubbed_dynamic_content) > 0 ) {
								$html->scan($unscrubbed_dynamic_content);
								$scrubbed_dynamic = $html->scrub($unscrubbed_dynamic_content);
								$this->template = $html->splice($this->template, $scrubbed_dynamic, $tag->getAttribute("id"));
							}
						}
						if ( $typeOfDynamic !== false && $typeOfDynamic == "php" ) {
							$domOfDynamic = new DOMDocument();
							if ( strlen($dynamic_output) > 0 ) {
								$domOfDynamic->loadHTML($dynamic_output);
								$frag = $domOfDynamic->saveHTML();
								$html->scan($frag);
								$scrubbed_dynamic = $html->scrub($frag);
								$this->template = $html->splice($this->template, $scrubbed_dynamic, $tag->getAttribute("id"));
							}
						}
					}
				}
			}	
		}
		
		base::instance()->get("HOOKS")->do_action('end_of_dom', $html);
		$html->scan($this->template, "body");
		$doc = $html->rebuild($this->template);
		$html->title($doc, ( isset($this->prepared->page_title) ? html_entity_decode($this->prepared->page_title) : html_entity_decode($this->content->page_title)));
		Base::instance()->get("HOOKS")->do_action('page_title');
		$this->html = $html->beautifyDOM($doc);
	}

	/* Magic paths are convenient for hiding the real path of a file. Use only JS, CSS and SCSS assets. */
	public function MagicAssets($fake=false, $real=false) {
		if ( $fake !== false && $real !== false ) {
			$_SESSION['MAGIC'][$fake] = $real;
			return true;
		}

		if ( !empty($_SESSION['MAGIC']) ) {
			foreach($_SESSION['MAGIC'] as $symbol=>$link) {
				Base::instance()->route('GET|POST '.$symbol, function($f3) use ($symbol, $link) {	
					$ext = pathinfo($link, PATHINFO_EXTENSION);
					$filename = basename($link);
					$directory = $f3->get("ROOT").dirname($link)."/";
					if ( $ext == "js" ) {
						$js =  Web::instance()->minify($filename, null, true, $directory);
						$js = preg_replace('/(?:(?:\/\*(?:[^*]|(?:\*+[^*\/]))*\*+\/)|(?:(?<!\:|\\\|\'|\")\/\/.*))/', '', $js);
						header("Content-type: application/x-javascript");
						echo Template::instance()->resolve(Template::instance()->parse($js));
						exit();
					} elseif( $ext == "css" ) {
						header("Content-type: text/css");
					} else {
						header("Content-type: text/css");
						echo "/* SCSS currently disabled */";
						exit;
						$css = file_get_contents($directory.$filename);
						$js = str_replace("{{uuid}}", $f3->get("SESSION.uuid"), $js);
						$scss = new Compiler();
						echo $scss->compile($css);
						exit;
					}
					echo Template::instance()->resolve(Template::instance()->parse(file_get_contents($f3->get("ROOT").'/'.$link)));
					exit;
				});
			}
			return true;
		}
		return false;
	}
	
	function pagination($total, $offset=0, $default_ipp=12) {
		$current_page = 1;
		if( !empty($_GET['page']) ) {
			$current_page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT);
			if ( false === $current_page ) { $current_page = 1; $method_used = "page"; }
		}
		if( !empty($_GET['ipp']) ) {
			$ipp = filter_input(INPUT_GET, 'ipp', FILTER_VALIDATE_INT);
			$ipp = ( false === $ipp ) ? $default_ipp : $ipp;
		} else {
			$ipp = $default_ipp;
		}

		$remaining_ipp = ((float)($total/$ipp)-(int)(float)($total/$ipp))*$ipp;
		if( !empty($_GET['offset']) ) {
			$offset = filter_input(INPUT_GET, 'offset', FILTER_VALIDATE_INT);
			if ( false === $offset ) { $offset = 0; };
		} else {
			$offset = ($current_page - 1) * $ipp;
		}
		if ( $offset < 0 ) { $offset = 0; }
		if ( $offset >= $total ) { $offset = $total - $remaining_ipp; }
		$number_of_pages = ceil($total / $ipp);
		$page = array();
		for($i=0; $i<$number_of_pages; $i++) {
			$s = $i * $ipp;
			$l = $s + $ipp;
			if ( $l >= $total ) { $l = $total; }
			if ( $offset >= $s && $offset <= $l) { $current_page = $i + 1; }
			$page_number = ( $i == 0 ) ? 1 : $i+1;
			$pages[$i] = array("offset"=>$s, "ipp"=>$l, "page"=>$page_number);
		}
		if ( $current_page == 0 ) { $current_page = 1; }
		$pages[$current_page-1]['class'] = "current";

		$first_page_data = $pages[0];
		$first_page_data['text'] = 'First '.$first_page_data['ipp'];

		$max = count($pages);
		$last_page_data = $pages[$max-1];
		$last_page_data['remaining_ipp'] = $last_page_data['ipp'] - $last_page_data['offset'];
		$last_page_data['text'] = 'Last ' . $last_page_data['remaining_ipp'];

		$prev_page = ( $current_page > 1 ) ? $current_page - 1 : NULL;
		$next_page =( $current_page < $number_of_pages ) ? $current_page + 1 : NULL;

		$current_offset = $offset;
		$current_limit = $ipp;
		if ( !isset($method_used) ) { $method_used = "combo"; }
		
		return array("current_offset"=>$current_offset, "current_limit"=>$current_limit, "method_used"=>$method_used, "number_of_pages"=>$number_of_pages, "current_page"=>$current_page, "prev_page"=>$prev_page, "next_page"=>$next_page, "first_page"=>$first_page_data, "last_page"=>$last_page_data, "pages"=>$pages);
	}
	
	public function getNavigationArr() {
		if ( isset($this->getNavigationArr) && is_array($this->getNavigationArr) ) {
			return $this->getNavigationArr;
		}
		$query = $this->dbh->prepare("SELECT `pid`,`virtual_path`, `protected`, `navPlacement`, `weight`, `page_title`, `nav_title` FROM `content` WHERE 1 ORDER BY `weight`, `virtual_path`");
		$query->execute();
		$this->getNavigationArr = (array) $query->fetchAll(PDO::FETCH_ASSOC);
		return $this->getNavigationArr;
	}
	
	public function getSiteMap() {
		$index = array_map(function($v){
			if ( Base::instance()->get("vpath") == $v['virtual_path'] ) $v['isVisiting'] = true;
			if ( $v['pid'] == $v['virtual_path'] ) $v['isParent'] = true;
			if ( $v['nav_title'] == "" && $v['page_title'] != "" ) $v['nav_title'] = $v['page_title'];
			return $v;
		}, $this->getNavigationArr());

		foreach($index as $k=>$v) { $index[$v['virtual_path']] = $v; unset($index[$k]); }
		foreach ($index as $k=>&$v) { $index[$v['pid']]['children'][$k] = &$v; if ( isset($index[$k]['children']) ) { $roots[] = $v['children']; unset($index[$k]['children']); } unset($v); }
		foreach($roots as $v) { $sitemap[key($v)] = $v[key($v)]; }
		unset($index, $roots, $k, $v);
		return $sitemap;
	}
	
	public function requires_plugin($class) {
		if ( !class_exists($class) ) {
			return false;
		}
		return $class::instance();
	}
	
	public function __destruct() {
		echo $this->html;
	}
	
}

?>