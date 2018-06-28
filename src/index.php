<?php

$f3 = Base::instance();

$f3->set("HOOKS", new Hooks());
$f3->get("HOOKS")->do_action("genesis");

function RegisterPlugin($params) {
	global $f3;
	if ( !$f3->get("SESSION.PLUGIN") ) {
		$f3->set("SESSION.PLUGIN", \Delight\Auth\Auth::createUuid());
	}
	$backtrace = debug_backtrace();
	$parts = explode("/", $backtrace[0]['file']);
	$name = array_pop(array_diff(explode("/", $backtrace[0]['file']), explode("/", __FILE__)));
	$key = array_search($name, $parts);
	$namespace = $parts[$key-1];
	$details =(object) $f3->get("plugins")[$namespace][$name];
	
	$details = (object) array_merge((array) $details, (array) $params);
	if ( isset($details->path) ) {
		$f3->set("MAGIC_PATH_REAL", $details->path);
	}
	$f3->set("PLUGIN", $details);
	$f3->set("MAGIC_PATH", '/'.$f3->get("SESSION.PLUGIN"));
	return $details;
}

function MagicAssets() {
	global $f3;
	if ( !$f3->get("MAGIC_PATH_REAL") ) {
		return;
	}
	$plugin= $f3->get("PLUGIN");
	if ( $plugin->enabled != true ) {
		return;
	}

	/* MAGIC PATH. Just include {{@MAGIC_PATH}}/js/code.js in your HTML templates and you are good to go. */
	if ( substr($f3->get("vpath"), 0, strlen($f3->get("MAGIC_PATH"))) == $f3->get("MAGIC_PATH") ) {
		foreach([
			  str_replace($f3->get("MAGIC_PATH"), $f3->get("ROOT").'/'.rtrim($plugin->pages, "/"),$f3->get("vpath"))
			, str_replace($f3->get("MAGIC_PATH"), $f3->get("MAGIC_PATH_REAL"), $f3->get("vpath"))
		] as $possibility) {
			if ( file_exists($possibility) ) {
				$file = $possibility;
				continue;
			}
		}
		if ( !isset($file) ) {
			$f3->error(403);
		} else {
			if ( substr(strrev($f3->get("vpath")), 0, 4) == "ssc.") {
				header("Content-type: text/css", true);
			}
			if ( substr(strrev($f3->get("vpath")), 0, 3) == "sj.") {
				header("Content-type: application/javascript", true);
			}
			echo Template::instance()->resolve(file_get_contents($file));
			exit;
		}
	}
}

/* CONTENT PARSER */
register_shutdown_function(function(){
	global $f3;
	global $dbh;
	
	try {
		$dbh = new DB\SQL($f3->get('MySQL.dsn'), $f3->get('MySQL.user'), $f3->get('MySQL.pass'), array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_PERSISTENT=>false));
	} catch (PDOException $e) {
		trigger_error('Connection failed: ' . $e->getMessage());
	}

	$f3->set("vpath", ((substr($f3->get("PATH"),-1)=='/')?substr($f3->get("PATH"),0,-1).'/':$f3->get("PATH")));
	$f3->set("sitelevel", ('/'. substr(substr($f3->get("vpath"),1), 0, strpos(substr($f3->get("vpath"),1), '/'))));

	$f3->set("directory", dirname($f3->get("vpath")));
	$f3->set('ONERROR', function(){return true;});
	$f3->set('DEBUG', false);
	$f3->set('CACHE', false);
	if ( $f3->get("PATH") == $f3->get("vpath") && stripos(strrev($f3->get("PATH")), 'lmth.xedni') === 0 ) {
		header("Location: ".str_replace("index.html", "", $f3->get("vpath")));
	}
	
	$f3->get("HOOKS")->do_action("pre_dbh");

	$detect = new Mobile_Detect;
	$f3->set("deviceType", ( $detect->isMobile() ? ( $detect->isTablet() ? 'tablet' : 'phone' ) : 'desktop' ));

	$f3->get("HOOKS")->do_action("post_dbh");

	if ( $f3->get("settings.plugins_dir") ) $f3->set('AUTOLOAD', $f3->get("ROOT").$f3->get("settings.plugins_dir"));

	/* Build a Site Map. Primarily used in Navigational components. */
	$query = $dbh->prepare("SELECT `virtual_path`, `pid`, `id`, `protected`, `navPlacement`, `weight`, `page_title`, `nav_title` FROM `content` WHERE 1");
	$query->execute();
	$resources = $query->fetchAll(PDO::FETCH_OBJ);
	foreach($resources as $resource) {
		if (substr($resource->virtual_path, -1) == '*') { $resource->virtual_path = str_replace("*", "index.html", $resource->virtual_path); }
		if ( $f3->get("vpath")  == $resource->virtual_path ) { $resource->isVisiting = true; }
		if ( $resource->pid == $resource->virtual_path ) { $row['isParent'] = true; }
		$ids[$resource->virtual_path] = $resource->id;
		$idsByWeight[$resource->virtual_path] = $resource->weight;
	}

	$pathsByWeight = array_flip($idsByWeight);
	foreach($pathsByWeight as $k =>$v) {
		$pathsByWeight[$k] = implode(", ", array_keys($idsByWeight, $k));
	}
	ksort($pathsByWeight);
	$pathsByWeight = array_flip($pathsByWeight);
	foreach($resources as $resource) {
		$resource->parentId = (ctype_digit($ids[$resource->pid])==true)
			? $ids[$resource->pid]
			: $ids[$resource->virtual_path];
		$final[] = (array) $resource;
	}

	foreach($final as $k=>$v) { $final[$v['virtual_path']] = $v; }
	foreach($idsByWeight as $path=>$item) { $pathsByWeight[$path] = $final[$path]; }
	$indexed = array();
	foreach ($pathsByWeight as $item) {	$indexed[$item['id']] = $item; }

	foreach ($indexed as $id => &$item) {
		$indexed[$item['parentId']]['children'][$id] = &$item;
		unset($item['id']);
		unset($item['parentId']);
		unset($item['pid']);
		unset($item['weight']);
		if ( isset($indexed[$id]['children']) ) {
			if ( strlen($item['navPlacement']) > 0 ) {
				$rootedMenuItems[] = $item['children'];
			}
			unset($indexed[$id]['children']);
		}
	}
	unset($indexed);
	foreach($rootedMenuItems as $k=>$v) {
		$first = key($v);
		unset($first['id']);
		$sitemap[] = $v[$first];	
	}

	$f3->set("SITEMAP", $sitemap);	
	
	/* In Commnetivity, we used a priority setting and extra logic. Now that we have a combined table, we use the OR and the order of what's checked as our method.
		In thise case, vpath will alayws get selected virst. */
	$query = $dbh->prepare('SELECT * FROM content WHERE virtual_path = "'.$f3->get("vpath").'" OR virtual_path = "'.dirname($f3->get("vpath"))."/*".'" LIMIT 1');

	$query->execute();
	$content = $query->fetchAll(PDO::FETCH_OBJ)[0];
	$security = ( isset($content->security) )  ? json_decode($content->security)  : array();
	$meta     = ( isset($content->meta_data) ) ? json_decode($content->meta_data) : array();
	$theme    = ( isset($content->theme) )     ? json_decode($content->theme)     : array();

	$f3->set("CONTENT", $content);

	$template = ( !isset($theme->template) ) ? "default.html" : $theme->template;
	
	$prepared = (object) array();
	if ( $content->internal_path ) {
		if (file_exists($f3->get('ROOT').$content->internal_path)) {
			$prepared->script = $f3->get('ROOT').$content->internal_path;
		}
	} elseif ( $content->virtual_path ) {	
		if ( $content->virtual_path ) {
			if ( $content->content ) {
				$prepared->content = stripcslashes(htmlspecialchars_decode($content->content));
			} else {
				if (substr($content->virtual_path, -1) == '/' && $f3->get("vpath") != "/" ) {
					if (file_exists($f3->get('ROOT').$content->virtual_path.'index.php')) {
						$prepared->script = $f3->get('ROOT').$content->virtual_path.'index.php';
					}
				}
			}
		} else {
			if ( isset($content->content) && strlen($content->content) > 0 ) {
				$prepared->content = $content->content;
			}
		}
	}

	if ( isset($prepared->internal) ) {
		$prepared->status = 200;
		$prepared->response = $prepared->internal;
	} elseif ( isset($prepared->content) || isset($prepared->script) ) {
		if ( isset($prepared->script) && file_exists($prepared->script)) {
			$prepared->status = 200;
			ob_start();
			include($prepared->script);
			$content_area = ob_get_clean();
			$prepared->content = $content_area;
		} else {
			$prepared->status = 200;
		}
	} else {
		if ( !is_array($permissions->cms) ) {
			$prepared->status = 404;
			$prepared->response  = "<h4>404 Not Found</h4>\n";
			$prepared->response .= "<p>The requested resource could not be found but may be available again in the future.</p>";
		} else {
			$prepared->status = 404;
			$prepared->response  = "<h4>Ready for content</h4>\n";
			$prepared->response .= "<p>Until static or dynamic content is assigned to this VirtualPath or SiteLevel, the public will see a general Error 404 response.</p>";
		}
	}

	$inner_content = ( $prepared->status == 200 ) ? $prepared->content : $prepared->response;

	$f3->get("HOOKS")->do_action('add_route');

	if ( in_array($f3->get("vpath"), array_keys($f3->get("ROUTES")) )) {
		ob_start(); $f3->run(); $inner_content = ob_get_clean();
		$prepared->status = 200;
		if ( $f3->get("page_title") ) {
			$prepared->page_title = $f3->get("page_title");
		}
	}
	
	$f3->get("HOOKS")->do_action('after_route');
	
	$ext = pathinfo(basename($template), PATHINFO_EXTENSION);
	$basename = basename($template, '.'.$ext);
	$possibilities = array_unique(array(
		  $f3->get('ROOT') . "/Templates/" . $basename . '/' . $f3->get("deviceType") . '.' . $ext
		, $f3->get('ROOT') . "/Templates/" . $basename . '.' . $f3->get("deviceType") . '.' . $ext
		, $f3->get('ROOT') . "/Templates/" . $basename . '.' . $ext
		, $f3->get('ROOT') . "/Templates/default/".$f3->get("deviceType").".html"
		, $f3->get('ROOT') . "/Templates/default.".$f3->get("deviceType").".html"
		, $f3->get('ROOT') . "/Templates/default.html"
	));
	foreach($possibilities as $check) {
		if ( file_exists($check) ) {
			$template = Template::instance()->resolve(file_get_contents($check));
			break;
		}
	}
	
	$html = new PureHTML();
	$f3->get("HOOKS")->do_action("pre_content");

	$html->scan($template, "head");  // Every call to scan() will build up the $html object. use head, body or leave empty for both
	$template = $html->scrub($template);

	// Dynamics are standalone PHP files that do not require the use of the F3 system
	if ( strlen($inner_content) > 0 ) {
		$domOfDynamic = new DOMDocument();
		$domOfDynamic->loadHTML($inner_content);
		$frag = $domOfDynamic->saveHTML();
		$html->scan($frag);
		$scrubbed_dynamic = $html->scrub($frag);
		$template = $html->splice($template, $scrubbed_dynamic, "content");
	}

	$dynamics_depth = 5;
	$already_processed = array();
	$already_processed[] = "content";
	for($i=0; $i<=($dynamics_depth-1); $i++) {
		libxml_use_internal_errors(true);
		$tmpDOM = new DOMDocument();
		$tmpDOM->loadHTML(html_entity_decode($template, ENT_HTML5));
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
						$unscrubbed_dynamic_content = trim(htmlspecialchars(Template::instance()->render('/dynamics/'.$tag->getAttribute("id").'.html'), ENT_XML1));
						if ( strlen($unscrubbed_dynamic_content) > 0 ) {
							$html->scan($unscrubbed_dynamic_content);
							$scrubbed_dynamic = $html->scrub($unscrubbed_dynamic_content);
							$template = $html->splice($template, $scrubbed_dynamic, $tag->getAttribute("id"));
						}
					}
					if ( $typeOfDynamic !== false && $typeOfDynamic == "php" ) {
						$domOfDynamic = new DOMDocument();
						if ( strlen($dynamic_output) > 0 ) {
							$domOfDynamic->loadHTML($dynamic_output);
							$frag = $domOfDynamic->saveHTML();
							$html->scan($frag);
							$scrubbed_dynamic = $html->scrub($frag);
							$template = $html->splice($template, $scrubbed_dynamic, $tag->getAttribute("id"));
						}
					}
				}
			}
		}	
	}
	
	$f3->get("HOOKS")->do_action("end_of_body");

	$html->scan($template, "body");
	$doc = $html->rebuild($template);
	$html->title($doc, ( isset($prepared->page_title) ? html_entity_decode($prepared->page_title) : html_entity_decode($content->page_title)));

	$f3->get("HOOKS")->do_action('page_title');
	
	/* Provide a consistent set of objects relating to user profile, permissions and preferences */
	//$profile     = ( isset($_SESSION['username']) ) ? $session->profile($_SESSION['username']) : 0;

	//profile = 0;
	//$permissions = ( isset($profile->permissions) ) ? json_decode($profile->permissions) : array();
	//$preferences = ( isset($profile->preferences) ) ? json_decode($profile->preferences) : array();
	
	//$f3->get("HOOKS")->do_action('Revelation');
	
	// require("Menus.php");
	// require('Auth.php');
	// require('Maietta/init.php');
	// require('CMS.php');
	
	echo $html->beautifyDOM($doc);
});