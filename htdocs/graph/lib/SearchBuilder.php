<?php

if (!defined('LOG_DIR')) {
	trigger_error('LOG_DIR is undefined !', E_USER_ERROR);
}

class SearchBuilder extends Data {
	private $type;
	
	public function __construct($type) {
		$this->type = $type;
	}
		
	private function getIds($func_name, $arg = null) {
		$storage = $this->getStorage($this->type);
		if (!$storage instanceof SearchableStorage) {
			return [];
		}
		$sids = $storage->$func_name($arg);
		$gids = new BigArray();
		foreach($sids as $sid) {
			$gids->push($this->gid($sid, $this->type));
		}
		unset($sids);
		return $gids;
	}
	
	public function buildAll(){
		$key = "{$this->type}_Builder";
		if(Locker::lock($key)) {
			$this->build($this->getIds('getAllIds'));
			Locker::unlock($key);
		}
	}

	public function buildModified($since){
		$since = $since ?: $this->readLastModified();
		if($since) {
			$this->build($this->getIds('getModifiedIds', $since));
		} else {
			trigger_error("You Need do a full build for `{$this->type}` first", E_USER_ERROR);
		}
	}

	private function build($id_list) {
		$last_modified = 0;
		foreach($id_list as $id) {
			$node = new Node($id);
			try {
				$node->load();
			} catch (Exception $exc) {
				file_put_contents(LOG_DIR . '/es_build_error.log', $exc->getMessage() . "\n", FILE_APPEND);
				continue;
			}
			Searcher::index($node->type(), $this->buildDoc($node));
			file_put_contents(LOG_DIR . "/build_{$node->type()}.log", date("Y-m-d H:i:s") . ": {$id}\n", FILE_APPEND);
			
			//这部分是buildModified()的逻辑，兼容没有modifiedTime表的更新。和MysqlStorage::getModifiedIds()的逻辑对应。
			if (isset($node->createdTime)) {
				$col = isset($node->modifiedTime) ? 'modifiedTime' : 'createdTime' ;
				if ( $node->$col > $last_modified ) {
					$last_modified = $node->$col;
					$this->saveLastModified($last_modified);
				}
			}
		}
	}
	
	private function logFile() {
		return LOG_DIR . "/last_{$this->type}.log";
	}

	private function saveLastModified($time) {
		if($this->readLastModified() < $time) {
			file_put_contents($this->logFile(), $time);
		}
	}

	private function readLastModified() {
		$file = $this->logFile();
		if(file_exists($file)) {
			return file_get_contents($file);
		} else {
			return 0;
		}
	}

	private function buildDoc($node) {
		$doc = [];

		foreach ($node as $name => $value) {
			if ($value instanceof Node) {
				$doc[$name] = $value->id;
			} elseif (!is_scalar($value) || strlen($value) == 0 || is_array($value)) {
				continue;
			} elseif (preg_match("/.*(Date|Time)$/i", $name, $match)) {
				$doc[$name] = date('Ymd\THis\Z', intval($value));
			} else {
				$doc[$name] = $value;
			}
		}
		return $doc;
	}

}

// 单机锁
class Locker {
	private static $file_handlers = [];

	public static function lock($key) {
		if(isset(self::$file_handlers[$key])) return false;
		self::$file_handlers[$key] = fopen(TEMP_DIR . '/' . $key . 'locker', 'w+');
		return flock(self::$file_handlers[$key], LOCK_EX | LOCK_NB);	// 独占锁、非阻塞
	}
	
	public static function unlock($key) {
		if (isset(self::$file_handlers[$key])) {
			fclose(self::$file_handlers[$key]);
			@unlink(TEMP_DIR . '/' . $key . 'locker');
			unset(self::$file_handlers[$key]);
		}
	}
}
