<?php 

	/** 
	 * @access private
	 * Internal object used to pass an array for filesets to group and user lists
	 */
	class FileSetList {
		public $sets = array();	
	}
	
	class FileSet extends Model {
		const TYPE_PRIVATE 	= 0;
		const TYPE_PUBLIC 	= 1;
		const TYPE_STARRED 	= 2;
		protected $fileSetFiles;
	
		/** 
		 * Returns an object mapping to the global file set, fsID = 0.
		 * This is really only used for permissions mapping
		 */
		 
		public function getGlobal() {
			$fs = new FileSet;
			$fs->fsID = 0;
			return $fs;
		}
		
		public function getMySets($u = false) {
			if ($u == false) {
				$u = new User();
			}
			$db = Loader::db();
			$sets = array();
			$r = $db->Execute('select * from FileSets where fsType = ? or (fsType in (?, ?) and uID = ?) order by fsName asc', array(FileSet::TYPE_PUBLIC, FileSet::TYPE_STARRED, FileSet::TYPE_PRIVATE, $u->getUserID()));
			while ($row = $r->FetchRow()) {
				$fs = new FileSet();
				foreach($row as $key => $value) {
					$fs->{$key} = $value;
				}
				$fsp = new Permissions($fs);
				if ($fsp->canSearchFiles()) {
					$sets[] = $fs;
				}
			}
			return $sets;
		}
		
		public function getByID($fsID) {
			$db = Loader::db();
			$row = $db->GetRow('select * from FileSets where fsID = ?', array($fsID));
			if (is_array($row)) {
				$fs = new FileSet();
				foreach($row as $key => $value) {
					$fs->{$key} = $value;
				}
				return $fs;
			}
		}
		
		public function getFileSetID() {return $this->fsID;}
		public function overrideGlobalPermissions() {return $this->fsOverrideGlobalPermissions;}
		
		public function getFileSetName() {return $this->fsName;}	
		
		/**
		 * Creats a new fileset if set doesn't exists
		 *
		 * If we find a multiple groups with the same properties,
		 * we return an array containing each group
		 * @param string $fs_name
		 * @param int $fs_type
		 * @param int $fs_uid
		 * @return Mixed 
		 */		
		public static function createAndGetSet($fs_name, $fs_type, $fs_uid=false) {
			if (!$fs_uid) {
				$u = new User();
				$fs_uid = $u->uID;
			}
			
			$file_set = new FileSet();
			$criteria = array($fs_name,$fs_type,$fs_uid);
			$matched_sets = $file_set->Find('fsName=? AND fsType=? and uID=?',$criteria);
			
			if (1 === count($matched_sets) ) {
				return $matched_sets[0];
			}
			else if (1 < count($matched_sets)) {
				return $matched_sets;
			}
			else{
				//AS: Adodb Active record is complaining a ?/value array mismatch unless
				//we explicatly set the primary key ID field to null					
				$file_set->fsID		= null;
				$file_set->fsName 	= $fs_name;
				$file_set->fsOverrideGlobalPermissions = 0;
				$file_set->fsType 	= $fs_type;
				$file_set->uID		= $fs_uid;
				$file_set->save();
				return $file_set;
			}			
		}
		
		/**
		* Adds the file to the set
		* @param type $fID  //accepts an ID or a File object
		* @return object
		*/		
		public function addFileToSet($f_id) {
			if (is_object($f_id)) {
				$f_id = $f_id->getFileID();
			}			
			$file_set_file = FileSetFile::createAndGetFile($f_id,$this->fsID);
			return $file_set_file;
		}
		
		public function removeFileFromSet($f_id){
			if (is_object($f_id)) {
				$f_id = $f_id->fID;
			}
			$db = Loader::db();
			$db->Execute('DELETE FROM FileSetFiles 
			WHERE fID = ? 
			AND   fsID = ?', array($f_id, $this->getFileSetID()));
		}

		/**
		* Get a list of files asociated with this set
		*
		* Can obsolete this when we get version of ADOdB with one/many support
		* @return type $var_name
		*/		
		private function populateFiles(){			
			$utility 			= new FileSetFile();
			$this->fileSetFiles = $utility->Find('fsID=?',array($this->fsID));
		}
		
		public function hasFileID($f_id){
			if (!is_array($this->fileSetFiles)) {
				$this->populateFiles();
			}			
			foreach ($this->fileSetFiles as $file) {
				if($file->fID == $f_id){
					return true;
				}
			}
		}

		public function resetPermissions() {
			$db = Loader::db();
			$db->Execute('delete from FileSetPermissions where fsID = ?', array($this->fsID));
			$db->Execute('delete from FilePermissionFileTypes where fsID = ?', array($this->fsID));
		}
		
		public function setPermissions($obj, $canSearch, $canRead, $canWrite, $canAdmin, $canAdd, $extensions = array()) {
			$fsID = $this->fsID;
			$uID = 0;
			$gID = 0;
			$db = Loader::db();
			if (is_a($obj, 'UserInfo')) {
				$uID = $obj->getUserID();
			} else {
				$gID = $obj->getGroupID();
			}
			
			if ($canSearch == FilePermissions::PTYPE_NONE) {
				$canWrite = FilePermissions::PTYPE_NONE;
				$canAdd = FilePermissions::PTYPE_NONE;
				$canAdmin = FilePermissions::PTYPE_NONE;
			}
				
			$db->Replace('FileSetPermissions', array(
				'fsID' => $fsID,
				'uID' => $uID, 
				'gID' => $gID,
				'canRead' => $canRead,
				'canSearch' => $canSearch,
				'canWrite' => $canWrite,
				'canAdmin' => $canAdmin,
				'canAdd' => $canAdd
			), 
			array('fsID', 'gID', 'uID'), true);
			
			$db->Execute("delete from FilePermissionFileTypes where fsID = ? and gID = ? and uID = ?", array($fsID, $uID, $gID));
	
			if ($canAdd == FilePermissions::PTYPE_CUSTOM && is_array($extensions)) {
				foreach($extensions as $e) {
					$db->Execute('insert into FilePermissionFileTypes (fsID, gID, uID, extension) values (?, ?, ?, ?)', array($fsID, $gID, $uID, $e));
				}
			}
		}		
	}
	class FileSetFile extends Model {
		public static function createAndGetFile($f_id, $fs_id){	
			$file_set_file = new FileSetFile();
			$criteria = array($f_id,$fs_id);		
			
			$matched_sets = $file_set_file->Find('fID=? AND fsID=?',$criteria);
			
			if (1 === count($matched_sets) ) {
				return $matched_sets[0];
			}
			else if (1 < count($matched_sets)) {
				return $matched_sets;
			}
			else{
				//AS: Adodb Active record is complaining a ?/value array mismatch unless
				//we explicatly set the primary key ID field to null
				$file_set_file->fsfID = null;
				$file_set_file->fID =  $f_id;			
				$file_set_file->fsID = $fs_id;
				$file_set_file->timestamp = null;
				$file_set_file->Save();
				return $file_set_file;
			}			
		}
	}
	
	