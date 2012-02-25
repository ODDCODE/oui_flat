<?php

/**
 * Rah_flat, a plugin for Textpattern CMS.
 * Edit data in database tables (forms, pages) as flat files.
 *
 * @package rah_flat
 * @author Jukka Svahn <http://rahforum.biz>
 * @copyright (c) 2012 Jukka Svahn
 * @license GNU GPLv2
 * @link https://github.com/gocom/rah_flat
 *
 * Copyright (C) 2012 Jukka Svahn <http://rahforum.biz>
 * Licensed under GNU Genral Public License version 2
 * http://www.gnu.org/licenses/gpl-2.0.html
 *
 * Requires Textpattern v4.4.1 (or newer) and PHP v5 (or newer) 
 */

	if(@txpinterface == 'public' || @txpinterface == 'admin')
		new rah_flat();

class rah_flat {

	static public $row_data = array();
	private $cfg = NULL;
	static public $sync = NULL;

	private $db_cache = array();
	private $db_columns = array();
	private $current;

	/**
	 * Initialize importer
	 */

	public function __construct($task='import') {
		
		if(self::$sync !== NULL) {
			return;
		}
		
		self::$sync = array();
		
		if(!defined('rah_flat_cfg')) {
			define('rah_flat_cfg', txpath.'/rah_flat.config.xml');
		}
		
		if(!rah_flat_cfg || !file_exists(rah_flat_cfg) || !is_readable(rah_flat_cfg) || !is_file(rah_flat_cfg)) {
			return;
		}
		
		$cfg = file_get_contents(rah_flat_cfg);
		
		if(!$cfg) {
			return false;
		}
		
		try {
			@$r = new SimpleXMLElement($cfg);
		}
		catch(Exception $e){
			return false;
		}
		
		if(!$r->sync->directory[0]) {
			return false;
		}
		
		$this->cfg = $this->lAtts(array(
			'enabled' => 1,
			'callback_uri' => array('key' => '', 'enabled' => 0),
		), $this->xml_array($r->options));
		
		if(
			$this->cfg['enabled'] != 1 &&
			(
				$this->cfg['callback_uri']['enabled'] != 1 || 
				$this->cfg['callback_uri']['key'] != gps('rah_flat')
			)
		)
			return;
		
		foreach($r->sync->directory as $p) {

			$p = $this->lAtts(array(
				'enabled' => 1,
				'delete' => 0,
				'create' => 1,
				'ignore_empty' => 1,
				'path' => NULL,
				'extension' => 'txp',
				'database' => array('table' => '', 'primary' => '', 'contents' => ''),
				'filename' => array(),
				'ignore' => array(),
				'disable_event' => '',
				'format' => 'flat',
			), $this->xml_array($p));
			
			if($p['enabled'] != 1 || !$p['path'] || !$p['filename']) {
				continue;
			}
			
			if(!empty($p->disable_event) && txpinterface == 'admin') {
				unset($GLOBALS['txp_permissions'][(string) $p->disable_event]);
			}
			
			$filename = array();
			
			foreach($p['filename'] as $var => $att) {
				
				$att = $this->lAtts(array(
					'@attributes' => array('starts' => 0, 'ends' => NULL),
				), $att);
				
				$filename[$var] = $att['@attributes'];
			}
			
			$p['filename'] = $filename;
			
			self::$sync[] = $p;
		}
		
		if($task == 'import') {
			$this->import();
		}
	}
	
	/**
	 * Returns and sets row data
	 * @param array $data
	 * @return array
	 */
	
	static public function row($data=NULL) {
		if(is_array($data)) {
			self::$row_data = $data;
		}
		return self::$row_data;
	}

	/**
	 * Converts SimpleXML's object to multidimensional array
	 * @param obj $obj
	 * @param array $out
	 * @return array
	 */

	protected function xml_array($obj, $out = array()) {
		foreach((array) $obj as $key => $node)
			$out[$key] = is_object($node) || is_array($node) ? $this->xml_array($node) : $node;
		return $out;
	}

	/**
	 * Imports flat static files to the database
	 * @param array $p Configuration options.
	 */
	
	protected function import($p=NULL) {
	
		if($p === NULL) {
			foreach(self::$sync as $p) {
				$this->current = $p;
				$this->import($p);
			}
			return;
		}
		
		extract($p);

		$this->collect_items(
			$database['table'],
			$database['primary'], 
			$database['contents']
		);
		
		if(!$this->db_columns) {
			return;
		}

		$f = new rah_flat_files();

		foreach($filename as $var => $att) {
			$f->map($var, $att['starts'], $att['ends']);
		}
		
		foreach($f->read($path, $extension) as $file => $data) {
			
			$d = $f->parse($file);
			
			if(in_array($d[$database['primary']], (array) $ignore)) {
				continue;
			}

			$status = 
				$this->requires_task(
					$database['table'],
					$d[$database['primary']],
					$data
				);
			
			if(!$status) {
				continue;
			}
			
			if($format != 'xml') {
				$d[$database['contents']] = $data;
			}
			
			if($format == 'flat_meta') {
				
				if(
					substr($file, -9) == '.meta.xml' ||
					!file_exists($file.'.meta.xml') || 
					!is_readable($file.'.meta.xml') || 
					!is_file($file.'.meta.xml')
				)
					continue;
				
				$data = file_get_contents($file.'.meta.xml');
			}
			
			/*
				Parse XML data
			*/
			
			if($format == 'flat_meta' || $format == 'xml') {
				
				try {
					@$r = new SimpleXMLElement($data, LIBXML_NOCDATA);
				}
				
				catch(Exception $e){
					trace_add('[rah_flat: Invalid XML document '.$file.']');
					continue;
				}
				
				if(!$r) {
					continue;
				}
				
				$d = array_merge((array) $d, $this->xml_array($r));
			}
			
			self::row($d);
			callback_event('rah_flat.importing', '', '', $database['table'], $status);
			
			$sql = array();
			
			foreach(self::row() as $name => $value) {
				if(!is_array($value) && in_array(strtolower($name), $this->db_columns)) {
					$sql[$name] = "`{$name}`='".doSlash($value)."'";
				}
			}
			
			if(!$sql) {
				continue;
			}

			if($status == 'insert' && $p['create'] == 1) {
				safe_insert(
					$database['table'],
					implode(',', $sql)
				);
			}
			
			elseif($status == 'update') {
				safe_update(
					$database['table'],
					implode(',', $sql),
					$sql[$database['primary']]
				);
			}
			
			$site_updated = true;
			self::row(array());
		}
		
		if(isset($site_updated)) {
			update_lastmod();
		}
		
		if($p['delete'] == 1) {
			
			$delete = array();

			foreach($this->db_cache[$database['table']] as $name => $md5) {
				if(($md5 !== false || $p['ignore_empty'] != 1) && !in_array($name, (array) $ignore)) {
					callback_event('rah_flat.deleting', '', '', $database['table'], $name);
					$delete[] = "'".doSlash($name)."'";
				}
			}
			
			if($delete) {
				safe_delete(
					$database['table'],
					$database['primary'].' in('.implode(',', $delete).')'
				);
			}
		}
		
		callback_event('rah_flat.imported');
	}
	
	/**
	 * Exports
	 * @todo unimplemented
	 */
	
	protected function export() {
	}

	/**
	 * Get current data from the database
	 * @param string $table
	 * @param string $name
	 * @param string $content
	 * @return array
	 */

	protected function collect_items($table, $name, $content) {
		
		$this->db_columns = doArray((array) @getThings('describe '.$table), 'strtolower');
		
		$rs = 
			safe_rows(
				$name.','.$content,
				$table,
				'1=1'
			);
		
		foreach($rs as $a) {
			$this->db_cache[$table][(string) $a[$name]] = 
				trim($a[$content]) === '' ? false : md5($a[$content]);
		}
		
		return $this->db_cache;
	}

	/**
	 * Checks items status
	 * @param string $table
	 * @param string $name
	 * @param mixed $content
	 * @return mixed
	 */

	protected function requires_task($table, $name, $content) {
		if(!isset($this->db_cache[$table][$name]))
			return 'insert';
		
		$sum = $this->db_cache[$table][$name];
		unset($this->db_cache[$table][$name]);
		
		if($this->current['format'] == 'xml' || $this->current['format'] == 'flat_meta')
			return 'update';
		
		$md5 = trim($content) === '' ? false : md5($content);
		
		if($md5 === false && $this->current['ignore_empty'] == 1)
			return false;
		
		if($sum === $md5)
			return false;
		
		return 'update';
	}

	/**
	 * Merge and extract two arrays. Populates unset with defaults, discards unknown.
	 * @param $pairs array Defaults options.
	 * @param $atts array User provided options.
	 * @return array
	 */

	protected function lAtts($pairs, $atts) {
		$out = array();

		foreach($pairs as $name => $value) {
			if(!isset($atts[$name]))
				$out[$name] = $value;
			
			else {
				if(is_array($value)) {
					$atts[$name] = (array) $atts[$name];
					$out[$name] = empty($value) ? $atts[$name] : $this->lAtts($value, $atts[$name]);
				} else
					$out[$name] = $atts[$name];
			}
		}

		return $out;
	}
}

/**
 * Handle filesystem tasks, writing, reading.
 */

class rah_flat_files {

	protected $delimiter = '.';
	protected $map = array();
	protected $vars = array();
	protected $files = array();

	/**
	 * Maps filename parts to variables.
	 * @param string $var Variable name
	 * @param int $offset Offset.
	 * @param int $length Length.
	 */

	public function map($var, $offset=NULL, $length=NULL) {
		$this->map[$var] = array($offset, $length);
	}

	/**
	 * Extracts filename's parts as variables
	 * @param string $filename
	 * @return array
	 */
	
	public function parse($filename) {
		$f = array_slice(explode($this->delimiter, basename($filename)), 0, -1);

		foreach($this->map as $var => $cord)
			$this->vars[$var] = implode($this->delimiter, array_slice($f, $cord[0], $cord[1]));
		
		return $this->vars;
	}

	/**
	 * Safely reads files from a directory
	 * @param string $dir Directory to read.
	 * @param string $ext Searched file extension.
	 * @return array List of files
	 */

	public function read($dir, $ext) {
		
		if(strpos($dir, '../') === 0 || strpos($dir, './') === 0)
			$dir = txpath.'/'.$dir;

		$dir = rtrim($dir, '\\/') . '/';
		$ext = trim($ext, '.');
		
		if(
			!file_exists($dir) ||
			!is_readable($dir) ||
			!is_dir($dir)
		)
			return $this->files;
		
		$dir = $this->glob_escape($dir);
		
		foreach(glob($dir.'*.'.$ext , GLOB_NOSORT) as $file)
			if(is_file($file) && is_readable($file))
				$this->files[$file] = file_get_contents($file);
				
		return $this->files;
	}

	/**
	 * Safely writes an array of files
	 * @param $dir string Target directory.
	 * @param $files array Array of files to write.
	 * @return bool
	 */

	public function write($dir, $files) {
		
		if(strpos($dir, '../') === 0 || strpos($dir, './') === 0)
			$dir = txpath.'/'.$dir;
		
		$dir = rtrim($dir, '\\/') . '/';
		
		if(
			!file_exists($dir) ||
			!is_readable($dir) ||
			!is_dir($dir)
		)
			return false;
		
		foreach($files as $file => $data) {
		
			if(
				file_exists($dir.$file) && 
				(
					!is_file($dir.$file) || !is_writable($dir.$file)
				)
			)
				continue;
			
			file_put_contents(
				$dir.$file,
				$data
			);
		}
	}

	/**
	 * Escape glob wildcard characters
	 * @param string $filename
	 * @return string
	 */

	public function glob_escape($filename) {
		return preg_replace('/(\*|\?|\[)/', '[$1]', $filename);
	}
}

?>