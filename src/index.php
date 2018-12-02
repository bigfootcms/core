<?php

/*
	Author: Nicholas Maietta <nick@icode4u.com>
	License: GPLv3
*/

class PureHTML {
    var $stylesheets;
    var $javascripts;

    public function __construct() {
        $this->stylesheets = (object) array("head"=>array(), "body"=>array());
        $this->javascripts = (object) array("head"=>array(), "body"=>array());
		$this->index = array();
    }
    public function title($html, $title="") {
		$html = $this->getInstanceOfDom($html);
		$html->getElementsByTagName("title")->item(0)->nodeValue = $title;
		return $html;
	}
	
    public function scan($html, $target="") {
		$html = $this->getInstanceOfDom($html);
		$blocks = array();
		if ( $target == "body" ) {
			$blocks = array("body");
		} elseif( $target == "head" ) {
			$blocks = array("head");
		} else {
			$blocks = array("body", "head");
		}

		foreach($blocks as $block) {
			$block = ( $block == "head" ) ? $html->getElementsByTagName('head')->item(0) : $html->getElementsByTagName('body')->item(0);
			if ( !empty($block) ) {
				$assets = $block->getElementsByTagName('link');
				for ($i = 0; $i < $assets->length; $i++) {
					$resource = $assets->item($i);
					$attributes = array();
					foreach ($resource->attributes as $attribute=>$value) {
						if ( strlen($value->nodeValue) > 0 ) {
							$attributes[$value->nodeName] = trim($value->nodeValue);
						}
					}
					ksort($attributes);
					$key = sha1(serialize($attributes));
					if ( !in_array($key, $this->index) ) {
						if ( $resource->hasAttribute('body') ) {
							$this->stylesheets->body[] = $attributes;
						} else {
							$this->stylesheets->head[] = $attributes;
						}
						$this->index[] = $key;
					}
				}
				
				$assets = $block->getElementsByTagName('meta');
				for ($i = 0; $i < $assets->length; $i++) {
					$resource = $assets->item($i);
					$attributes = array();
					foreach ($resource->attributes as $attribute=>$value) {
						if ( strlen($value->nodeValue) > 0 ) {
							$attributes[$value->nodeName] = trim($value->nodeValue);
						}
					}
					ksort($attributes);
					$key = sha1(serialize($attributes));
					if ( !in_array($key, $this->index) ) {
						$this->metatags[] = $attributes;
						$this->index[] = $key;
					}
				}
				
				
				$assets = $block->getElementsByTagName('script');
				for ($i = 0; $i < $assets->length; $i++) {
					$resource = $assets->item($i);
					if ( strlen(trim($resource->textContent)) > 12 ) {
						$key = sha1(serialize(trim($resource->textContent)));
						if ( !in_array($key, $this->index) ) {
							if ( $resource->hasAttribute('body') ) {
								$this->javascripts->body[] = rtrim($resource->textContent);
							} else {
								$this->javascripts->head[] = rtrim($resource->textContent);
							}
							$this->index[] = $key;
						}
					} else {
						$attributes = array();
						foreach ($resource->attributes as $attribute=>$value) {
							if ( strlen($value->nodeValue) > 0 ) {
								$attributes[$value->nodeName] = trim($value->nodeValue);
							}
						}
						ksort($attributes);
						$key = sha1(serialize($attributes));
						if ( !in_array($key, $this->index) ) {
							if ( $resource->hasAttribute('body') ) {
								$this->javascripts->body[] = $attributes;
							} else {
								$this->javascripts->head[] = $attributes;
							}
							$this->index[] = $key;
						}
					}
				}	
				$assets = $block->getElementsByTagName('style');
				for ($i = 0; $i < $assets->length; $i++) {
					$resource = $assets->item($i);
					if (  strlen(trim($resource->textContent)) > 6  ) {	// *{v:1;}
						$key = sha1(serialize(trim($resource->textContent)));
						if ( !in_array($key, $this->index) ) {					
							if ( $resource->hasAttribute('body') ) {
								$this->stylesheets->body[] = rtrim($resource->textContent);
							} else {
								$this->stylesheets->head[] = rtrim($resource->textContent);
							}
							$this->index[] = $key;
						}
						
					}
				}
			}
		}
		return $this;
    }

	public function getInstanceOfDom($dom) {
		if ( !$dom instanceof DOMDocument ) {
			$nonDom= $dom;
            libxml_use_internal_errors(true);
            $dom = new DOMDocument('1.0', 'UTF-8');
			$dom->loadHTML($nonDom);
			unset($nonDom);
        }
		return $dom;
	}	

	private function removeNode(&$node) {
		$parent = $node->parentNode;
		$this->removeChildren($node);
		$parent->removeChild($node);
	}
	
	private function removeChildren(&$node) {
		while ($node->firstChild) {
			while ($node->firstChild->firstChild) {
				$this->removeChildren($node->firstChild);
			}
			$node->removeChild($node->firstChild);
		}
	}
	
    public function scrub($html, $what_to_strip="") {
		$html = $this->getInstanceOfDom($html);
        switch($what_to_strip) {
            case "styles":
                $nodes = $html->getElementsByTagName("link");
                while ($nodes->length > 0) { $node = $nodes->item(0); $this->removeNode($node); }
                $nodes = $html->getElementsByTagName("style");
                while ($nodes->length > 0) { $node = $nodes->item(0); $this->removeNode($node); }
                break;
            case "scripts":
                $nodes = $html->getElementsByTagName("script");
                while ($nodes->length > 0) { $node = $nodes->item(0); $this->removeNode($node); }
                break;
            default:
                $nodes = $html->getElementsByTagName("link");
                while ($nodes->length > 0) { $node = $nodes->item(0); $this->removeNode($node); }
                $nodes = $html->getElementsByTagName("style");
                while ($nodes->length > 0) { $node = $nodes->item(0); $this->removeNode($node); }
                $nodes = $html->getElementsByTagName("script");
                while ($nodes->length > 0) { $node = $nodes->item(0); $this->removeNode($node); }				
                break;
        }
		return $html->saveHTML();
    }
	
    public function splice($html, $new, $tag) {
		$html = $this->getInstanceOfDom($html);
		$doc = new DOMDocument();
		$doc->loadHTML('<?xml encoding="UTF-8">' . $new);
		$element = $html->getElementById($tag);
		while($element->childNodes->length){
			$element->removeChild($element->firstChild);
		}
		$xpath = new DOMXPath($doc);
		$body = $xpath->query('/html/body');
		$frag = $html->createDocumentFragment();
		$body = $doc->saveXml($body->item(0));
		$frag->appendXML($body);
		@$element->appendChild($frag);
		return $html->saveHTML($html->documentElement);
    }

    public function rebuild($html, $resources=false) {
		$dom = $this->getInstanceOfDom($html);

		$nodes = $dom->getElementsByTagName("meta");
		while ($nodes->length > 0) { $node = $nodes->item(0); $this->removeNode($node); }	

		foreach($this->metatags as $resources) {
			$domDocument = new DOMDocument();
			$domElement = $domDocument->createElement('meta');
			foreach($resources as $element=>$value) {
				$domAttribute = $domDocument->createAttribute($element);
				$domAttribute->value = $value;
				$domElement->appendChild($domAttribute);
			}
			$domDocument->appendChild($domElement);
			$frag = $dom->createDocumentFragment();
			$items = explode("\n", $domDocument->saveXML()); array_shift($items);
			$frag->appendXML(implode("\n", $items));
			$dom->getElementsByTagName('head')->item(0)->appendChild($frag);
		}
		
		foreach($this->stylesheets as $dom_location=>$resources) {
			foreach($resources as $resource) {
				$domDocument = new DOMDocument();
				if ( is_array($resource) )  {
					$domElement = $domDocument->createElement('link');
					$keys = array_keys($resource);
					$sorted_resource = array_merge(array_flip(array('href', 'integrity', 'crossorigin', 'hreflang', 'defer', 'rel', 'media', 'sizes',  'type')), $resource);
					foreach($sorted_resource as $element=>$value) {
						if ( !in_array($element, $keys) ) {
							continue;
						}
						$domAttribute = $domDocument->createAttribute($element);
						$domAttribute->value = $value;
						$domElement->appendChild($domAttribute);
					}
					$domDocument->appendChild($domElement);
					$frag = $dom->createDocumentFragment();
					$items = explode("\n", $domDocument->saveXML()); array_shift($items);
					$frag->appendXML(implode("\n", $items));
					if ( $dom_location == "body" ) {	
						$dom->getElementsByTagName('body')->item(0)->appendChild($frag);
					} else {
						$dom->getElementsByTagName('head')->item(0)->appendChild($frag);
					}
				}
			}
		}
		
		foreach($this->javascripts as $dom_location=>$resources) {
			foreach($resources as $resource) {
				$domDocument = new DOMDocument();
				if ( is_array($resource) ) {
					$domElement = $domDocument->createElement('script');
					$keys = array_keys($resource);
					$sorted_resource = array_merge(array_flip(array('src', 'integrity', 'crossorigin', 'async', 'defer', 'charset', 'type')), $resource);					
					foreach($sorted_resource as $element=>$value) {
						if ( !in_array($element, $keys) ) {
							continue;
						}
						$domAttribute = $domDocument->createAttribute($element);
						$domAttribute->value = $value;
						$domElement->appendChild($domAttribute);
					}
					$domDocument->appendChild($domElement);
					$items = explode("\n", $domDocument->saveXML()); array_shift($items);
					$frag = $dom->createDocumentFragment();
					$frag->appendXML(implode("\n", $items));
					if ( $dom_location == "body"  ) {
						$dom->getElementsByTagName('body')->item(0)->appendChild($frag);
					} else {
						$dom->getElementsByTagName('head')->item(0)->appendChild($frag);
					}
				}
				if ( is_string($resource) && strlen(trim($resource)) > 6 ) {
					$domElement = $domDocument->createElement('script', trim($resource));
					$domDocument->appendChild($domElement);
					$items = explode("\n", $domDocument->saveXML()); array_shift($items);
					$frag = $dom->createDocumentFragment();
					$frag->appendXML(implode("\n", $items));
					if ( $dom_location == "body" ) {
						$dom->getElementsByTagName('body')->item(0)->appendChild($frag);
					} else {
						$dom->getElementsByTagName('head')->item(0)->appendChild($frag);
					}
				}
			}
		}
		
		foreach($this->stylesheets as $dom_location=>$resources) {
			foreach($resources as $resource) {
				$domDocument = new DOMDocument();
				if ( !is_array($resource) ) {
					if ( strlen(trim($resource)) > 0 ) {
						$domElement = $domDocument->createElement('style', trim($resource));
						$domDocument->appendChild($domElement);
						$items = explode("\n", $domDocument->saveXML()); array_shift($items);
						$frag = $dom->createDocumentFragment();
						$frag->appendXML(implode("\n", $items));
						if ( $dom_location == "body" ) {
							$dom->getElementsByTagName('body')->item(0)->appendChild($frag);
						} else {
							$dom->getElementsByTagName('head')->item(0)->appendChild($frag);
						}
					}
				}
			}
		}
		
		return $dom;
    }
	
	function hasChild($p) {
		if ($p->hasChildNodes()) {
			foreach ($p->childNodes as $c) {
				if ($c->nodeType == XML_ELEMENT_NODE) {
					return true;
				}
			}
		}
		return false;
	}
	
	function beautifyDOM($doc, $depth=1) {
		/* Beautify the HTML output */
		$xpath = new DOMXPath($doc);
		foreach($xpath->query("//text()") as $node) {
			$node->nodeValue = preg_replace(["/^[\s\r\n]+/", "/[\s\r\n]+$/"], "", $node->nodeValue);
			if( strlen($node->nodeValue) == 0 ) $node->parentNode->removeChild($node);
		}
		$format = (function($dom, $currentNode=false, $depth=0) use (&$format) { 
			if ( $currentNode === false ) {
				$dom->removeChild($dom->firstChild);
				$currentNode = $dom;
			}
			$indentCurrent = ( ( $currentNode->nodeType == XML_TEXT_NODE) && ($currentNode->parentNode->childNodes->length == 1) ) ? false : true;
			if ( $indentCurrent && $depth > 1 ) {
				$textNode = $dom->createTextNode("\n" . str_repeat("  ", $depth));
				$currentNode->parentNode->insertBefore($textNode, $currentNode);
			}
			if ( $currentNode->childNodes ) {
				$indentClosingTag = false;
				foreach($currentNode->childNodes as $childNode) {
					$indentClosingTag = $format($dom, $childNode, $depth+1);
				}
				if ( $indentClosingTag ) {
					$textNode = ( isset($currentNode->tagName) && $currentNode->tagName != "html" ) ? $dom->createTextNode("\n" . str_repeat("  ", $depth)) : $dom->createTextNode("\n");
					$currentNode->appendChild($textNode);
				}
			}
			return $indentCurrent;
		});
		$format($doc);
		return "<!DOCTYPE html>\n" . $doc->saveHTML();
	}
}

?>