<?php


require_once "HTTP/WebDAV/Server.php";
require_once "System.php";
    
/**
 * OWL Filesystem access using WebDAV
 *
 * @author  Steve Bourgeois <owl@bozzit.com>
 * @version @package-version@
 */

class HTTP_WebDAV_Server_owl  extends HTTP_WebDAV_Server 
{
    var $owl_debug = 0;
    var $owl_debugfile = "/tmp/WebDAV.DBG";
    var $owl_userid = "";
    var $owl_folderid = "1";
    var $dav_client = "";

    /**
     * Root directory for WebDAV access
     *
     * Defaults to webserver document root (set by ServeRequest)
     *
     * @access private
     * @var    string
     */
    var $base = "";

    /** 
     * MySQL Host where property and locking information is stored
     *
     * @access private
     * @var    string
     */
    var $db_host = "";

    /**
     * MySQL database for property/locking information storage
     *
     * @access private
     * @var    string
     */
    var $db_name = "";

    /**
     * MySQL table name prefix 
     *
     * @access private
     * @var    string
     */
    var $db_prefix = "";

    /**
     * MySQL user for property/locking db access
     *
     * @access private
     * @var    string
     */
    var $db_user = "";

    /**
     * MySQL password for property/locking db access
     *
     * @access private
     * @var    string
     */
    var $db_passwd = "";

    /**
     * Serve a webdav request
     *
     * @access public
     * @param  string  
     */
    function ServeRequest($base = false) 
    {
	   global $userid;

       $this->fOwlWebDavLog ("ServerRequest", "BaseDir: $base");
        // special treatment for litmus compliance test
        // reply on its identifier header
        // not needed for the test itself but eases debugging
        //foreach (apache_request_headers() as $key => $value) {
            //if (stristr($key, "litmus")) {
                //error_log("Litmus test $value");
                //header("X-Litmus-reply: ".$value);
            //}
        //}

        // set root directory, defaults to webserver document root if not set
        if ($base) 
	{
	   if (realpath($base))
	   {
              $this->base = realpath($base); // TODO throw if not a directory
              $this->fOwlWebDavLog ("ServerRequest", "NEWBASE: $this->base");
	   }
	   else
	   {
              $this->fOwlWebDavLog ("ServerRequest", "BAD Directory: $this->base");
              return "403 Forbidden - [OWL] Permissions Denied";
	   }

        } 
	else if (!$this->base) 
	{
           $this->base = $this->_SERVER['DOCUMENT_ROOT'];
        }
                
        // establish connection to property/locking db
        mysql_connect($this->db_host, $this->db_user, $this->db_passwd) or die(mysql_error());
        mysql_select_db($this->db_name) or die(mysql_error());
        // TODO throw on connection problems

        // let the base class do all the work
        parent::ServeRequest();
    }

   /**
    * No authentication is needed here
    *
    * @access private
    * @param  string  HTTP Authentication type (Basic, Digest, ...)
    * @param  string  Username
    * @param  string  Password
    * @return bool    true on successful authentication
    */
    function check_auth($type, $username, $password)
    {
       global $default;
       global $userid;

       $this->fOwlWebDavLog ("check_auth", "AUTHENTICATION REQUESTED FOR User: $username Passwd: \$password type: $type");

       $sql = new Owl_DB;

       $sql->query("SELECT * from $default->owl_users_table where username = '$username' and password = '" . md5($password) . "'");
 
       if ($sql->num_rows($sql) > 0 )
       {
          $sql->next_record();

          $this->owl_userid = $sql->f('id');
          $userid = $this->owl_userid;

          $iHomeDir = $sql->f("homedir");
          $iFirstDir = $sql->f("firstdir");

          getprefs();

          $aRev = array();
          $aRev =    fValidateRevision($sql->f("user_major_revision"),$sql->f("user_minor_revision"));
          $default->major_revision = $aRev['major'];
          $default->minor_revision = $aRev['minor'];


          if ($sql->f("disabled") == 1)
          {
              $this->fOwlWebDavLog ("check_auth", "User: $username is Disabled");
              return false;
          }

          if ($sql->f("user_access") == 1)
          {
              $this->fOwlWebDavLog ("check_auth", "User: $username is Doesn't have access to WebDav");
              return false;
          }

          $iRootDir = $iHomeDir;

          $sRootFolderName = fid_to_name('1'); 
          
          $this->base = $this->base . ereg_replace($sRootFolderName,'', find_path($iRootDir)); 
          $this->owl_folderid = $iRootDir;

          $this->fOwlWebDavLog ("check_auth", "Userid: $this->owl_userid  FirstDir: $this->owl_folderid ");
    
          return true;
       }
       else
       {
          return false;
       }
    }

    /**
     * PROPFIND method handler
     *
     * @param  array  general parameter passing array
     * @param  array  return array for file properties
     * @return bool   true on success
     */
    function PROPFIND(&$options, &$files) 
    {
	    global $userid, $default;

        $this->fOwlWebDavLog ("PROPFIND", "======== Function Called =======");

        // get absolute fs path to requested resource
        $fspath = $this->base . $options["path"];
            
        $this->fOwlWebDavLog ("PROPFIND", "PATH: $fspath");
        // sanity check
        if (!file_exists($fspath)) {
            return false;
        }

        // is this a collection?

        $sFilename = $this->_fBasename($fspath);

        if(strtolower($sFilename) == '.ds_store')
        {
                //Filename is .DS_Store
                // ignore
                return true;
        }
        if($sFilename[0] == '.' && $sFilename[1] == '_'){
           //Filename is ._Filename
           // ignore
           return true;
        }


        if (is_dir($fspath))
        {
    
// ***********************************************
// Find the OWL Folder ID to check Security
// ***********************************************

           $this->owl_folderid = $this->_fGetFolderID($options["path"], $this->owl_folderid);

// ***********************************************
// Check if the folder is accessible by the user
// ***********************************************
//
           If (check_auth($this->owl_folderid, "folder_view", $this->owl_userid, false, false) == 0)
           {
              $this->fOwlWebDavLog ("PROPFIND", "403 Forbidden - [OWL] Permissions Denied");
              return "403 Forbidden - [OWL] Permissions Denied";
           }
        }
        /* else
        {
           $iFileID = $this->_fGetFileID($sFilename, $this->owl_folderid);
           if ($iFileID == 0)
           {
               $this->fOwlWebDavLog ("PROPFIND", "404 Not Found - File '$sFilename' NOT FOUND in " . $this->owl_folderid);
               return "404 Not Found - File NOT FOUND";
           }
           If (check_auth($iFileID, "file_update", $this->owl_userid, false, false) == 0)
           {
//print("<br />NO ACCESS");
              $this->fOwlWebDavLog ("PROPFIND", "403 Forbidden - [OWL] Permissions Denied");
              return "403 Forbidden - [OWL] Permissions Denied";
           }
        } */

        // prepare property array
        $files["files"] = array();

        // store information for the requested path itself
        $files["files"][] = $this->fileinfo($options["path"]);

        // information for contained resources requested?
        if (!empty($options["depth"])) { // TODO check for is_dir() first?
                
            // make sure path ends with '/'
            $options["path"] = $this->_slashify($options["path"]);

            // try to open directory
            $handle = @opendir($fspath);
                
            if ($handle) 
            {
                // ok, now get all its contents
                while ($filename = readdir($handle)) 
                {
                    if ($filename != "." && $filename != "..") 
                    {

// RESTRICTED VIEW BOZZ FILE
        if ($default->restrict_view == 1)
        {
           $fullpath = $fspath."/".$filename;

            $iFolderID = '';
            if (is_dir($fullpath))
            {
               // adding junk to the path, because the fGetFolderID functions returns the PARENT FOLDER ID
               $iFolderID = $this->_fGetFolderID($fullpath . "/junk" , $this->owl_folderid);
               if (check_auth($iFolderID, "folder_view", $userid, false, false) == 0)
               {
                  $this->fOwlWebDavLog ("PROPFIND", "RESTRICTED VIEW FOLDER SKIPPED Path: $fullpath");
                  continue;
               }
            }
            else
            {
               $iFileID = $this->_fGetFileID($filename, $this->owl_folderid);
               if (check_auth($iFileID, "file_download", $userid, false, false) == 0)
               {
                  $this->fOwlWebDavLog ("PROPFIND", "RESTRICTED VIEW FILE SKIPPED Path: $fullpath");
                  continue;
               }
            }
        }
        /* Hide backup Directory Turned On So Hide it except for Administrators  and File Admins */
        if ($default->hide_backup == 1 and !$this->_fIsAdmin())
        {
           $this->fOwlWebDavLog ("PROPFIND", "HIDE BACKUP FOLDER: $fullpath");
           if (is_dir($fullpath) and $filename == $default->version_control_backup_dir_name)
           {
              $this->fOwlWebDavLog ("PROPFIND", "HIDDING BACKUP FOLDER: $fullpath - $filename");
              continue;
           }
        }

            //if ($filename == 'Act2Win') // FOLDER
            //if ($filename == 'Bozz IT Letter.dot') // FILE
            //{
               //continue;
            //}
                    //if ($filename != "." ) {
					    //if ($filename == '..')
						//{
                           //$files["files"][] = $this->fileinfo($options["path"].'..');
						//}
						//else
						//{
                           $files["files"][] = $this->fileinfo($options["path"].$filename);
						//}
                    }
                }
                // TODO recursion needed if "Depth: infinite"
            }
        }

        // ok, all done
        return true;
    } 
        
    /**
     * Get properties for a single file/resource
     *
     * @param  string  resource path
     * @return array   resource properties
     */
    function fileinfo($path) 
    {
       global $userid;
       $this->_fGetDavClient();

        // map URI path to filesystem path
       $fspath = $this->base . $path;

        // create result array
       $info = array();
       // TODO remove slash append code when base clase is able to do it itself
       //$info["path"]  = is_dir($fspath) ? $this->_slashify($path) : $path; 

       if ($this->dav_client == 'MacDarwin' or
           $this->dav_client == 'Cyberduck')
       {
          $fixeduppath = $path;
       }
       else
       {
          $fixeduppath = str_replace(' ', '%20', $path);
       }

       $info["path"]  = str_replace('&', '%26', $fixeduppath);
       $info["props"] = array();
            
       // no special beautified displayname here ...
       $info["props"][] = $this->mkprop("displayname", strtoupper($path));
            
       // creation and modification time
       $info["props"][] = $this->mkprop("creationdate",    filectime($fspath));
       $info["props"][] = $this->mkprop("getlastmodified", filemtime($fspath));

       // type and size (caller already made sure that path exists)
       if (is_dir($fspath)) 
       {
            $this->fOwlWebDavLog ("fileinfo", "Folder: $fspath");
           // directory (WebDAV collection)
           $info["props"][] = $this->mkprop("resourcetype", "collection");
           $info["props"][] = $this->mkprop("getcontenttype", "httpd/unix-directory");             
       } 
       else 
       {
           $this->fOwlWebDavLog ("fileinfo", "File: $fspath");
           // plain file (WebDAV resource)
           $info["props"][] = $this->mkprop("resourcetype", "");
           if (is_readable($fspath)) 
           {
              $this->fOwlWebDavLog ("fileinfo", "File is Readable");
              $info["props"][] = $this->mkprop("getcontenttype", $this->_mimetype($fspath));
           } 
           else 
           {
             $this->fOwlWebDavLog ("fileinfo", "File is NOT Readable");
             $info["props"][] = $this->mkprop("getcontenttype", "application/x-non-readable");
           }               
           $info["props"][] = $this->mkprop("getcontentlength", filesize($fspath));
       }
       return $info;
   }

    /**
     * detect if a given program is found in the search PATH
     *
     * helper function used by _mimetype() to detect if the 
     * external 'file' utility is available
     *
     * @param  string  program name
     * @param  string  optional search path, defaults to $PATH
     * @return bool    true if executable program found in path
     */
    function _can_execute($name, $path = false) 
    {
       global $userid;
       $this->fOwlWebDavLog ("_can_execute", "");
        // path defaults to PATH from environment if not set
        if ($path === false) {
            $path = getenv("PATH");
        }
            
        // check method depends on operating system
        if (!strncmp(PHP_OS, "WIN", 3)) {
            // on Windows an appropriate COM or EXE file needs to exist
            $exts     = array(".exe", ".com");
            $check_fn = "file_exists";
        } else {
            // anywhere else we look for an executable file of that name
            $exts     = array("");
            $check_fn = "is_executable";
        }
            
        // now check the directories in the path for the program
        foreach (explode(PATH_SEPARATOR, $path) as $dir) {
            // skip invalid path entries
            if (!file_exists($dir)) continue;
            if (!is_dir($dir)) continue;

            // and now look for the file
            foreach ($exts as $ext) {
                if ($check_fn("$dir/$name".$ext)) return true;
            }
        }

        return false;
    }

    function _fGetFileID($sFileName, $iParentID)
    {
       global $userid;

       $sFileName = $this->_unslashify($sFileName);

       $this->fOwlWebDavLog ("_fGetFileID", "Filename: $sFileName  Parent: $iParentID");

       $sql = new Owl_DB;

       $sql->query("SELECT id from files where filename = '" . $sql->make_arg_safe($sFileName) . "' and parent = '$iParentID'");

       if ($sql->num_rows($sql) > 0 )
       {
          $sql->next_record();

          $this->fOwlWebDavLog ("_fGetFileID", "FILEID: " . $sql->f('id'));
          return $sql->f('id');
       }
       else
       {
          $this->fOwlWebDavLog ("_fGetFileID", "FILEID: NOTFOUND");
          return 0;
       }
    }
        
    function _fGetFolderID($sFolderName, $iParentID)
    {
       global $default;
       global $userid;
       $this->fOwlWebDavLog ("_fGETFOLDERID", "FolderName: $sFolderName ParentID: $iParentID");

       $aFolders = array();

       if (!is_dir($this->base . $sFolderName)) 
       {
          $aFolders = split('/', substr(dirname($sFolderName), 1));
       }
       else
       {
          $aFolders = split('/', substr($sFolderName, 1));
       }

       $sql = new Owl_DB;

       $this->fOwlWebDavLog ("_fGETFOLDERID", "Folders:  ". print_r($aFolders, true));
       foreach ($aFolders as $sFolderName)
       {
          if (trim($sFolderName) <> "")
          {
             $sql->query("SELECT id from folders where binary name = '" . $sql->make_arg_safe($sFolderName) . "' and parent = '$iParentID'");
             $sql->next_record();
             $iParentID = $sql->f('id');
          }
      }

      if (empty($iParentID))
      {
         $iParentID = '1';
      }

       $this->fOwlWebDavLog ("_fGETFOLDERID", "FOLDER ID: " . $iParentID);
       return $iParentID;

    }

    /**
     * try to detect the mime type of a file
     *
     * @param  string  file path
     * @return string  guessed mime type
     */
    function _mimetype($fspath) 
    {
       global $userid;
        if (@is_dir($fspath)) {
            // directories are easy
            return "httpd/unix-directory"; 
        } else if (function_exists("mime_content_type")) {
            // use mime magic extension if available
            $mime_type = mime_content_type($fspath);
        } else if ($this->_can_execute("file")) {
            // it looks like we have a 'file' command, 
            // lets see it it does have mime support
            $fp    = popen("file -i '$fspath' 2>/dev/null", "r");
            $reply = fgets($fp);
            pclose($fp);
                
            // popen will not return an error if the binary was not found
            // and find may not have mime support using "-i"
            // so we test the format of the returned string 
                
            // the reply begins with the requested filename
            if (!strncmp($reply, "$fspath: ", strlen($fspath)+2)) {                     
                $reply = substr($reply, strlen($fspath)+2);
                // followed by the mime type (maybe including options)
                if (preg_match('|^[[:alnum:]_-]+/[[:alnum:]_-]+;?.*|', $reply, $matches)) {
                    $mime_type = $matches[0];
                }
            }
        } 
            
        if (empty($mime_type)) {
            // Fallback solution: try to guess the type by the file extension
            // TODO: add more ...
            // TODO: it has been suggested to delegate mimetype detection 
            //       to apache but this has at least three issues:
            //       - works only with apache
            //       - needs file to be within the document tree
            //       - requires apache mod_magic 
            // TODO: can we use the registry for this on Windows?
            //       OTOH if the server is Windos the clients are likely to 
            //       be Windows, too, and tend do ignore the Content-Type
            //       anyway (overriding it with information taken from
            //       the registry)
            // TODO: have a seperate PEAR class for mimetype detection?
            switch (strtolower(strrchr($this->_fBasename($fspath), "."))) {
            case ".html":
                $mime_type = "text/html";
                break;
            case ".gif":
                $mime_type = "image/gif";
                break;
            case ".jpg":
                $mime_type = "image/jpeg";
                break;
            default: 
                $mime_type = "application/octet-stream";
                break;
            }
        }
            
        return $mime_type;
    }

    /**
     * GET method handler
     * 
     * @param  array  parameter passing array
     * @return bool   true on success
     */
    function GET(&$options) 
    {
        global $default;
        global $userid;

        $this->fOwlWebDavLog ("GET", "Options: " . print_r($options,true));
        // get absolute fs path to requested resource

        $fspath = $this->base . $this->_unslashify($options["path"]);

        $this->fOwlWebDavLog ("GET", "FolderID: " . $this->owl_folderid . " Path: " . $fspath);

        // sanity check
        if (!file_exists($fspath))
        {
           return false;
        }
     
        // is this a collection?
        $this->owl_folderid = $this->_fGetFolderID($options["path"], $this->owl_folderid);

        if (is_dir($fspath)) 
        {
           $this->fOwlWebDavLog ("GET", "FOLDER");
           If (check_auth($this->owl_folderid, "folder_view", $this->owl_userid, false, false) == 1)
           {
              $this->fOwlWebDavLog ("GET", "FOLDER: Permissions GRANTED [$this->owl_folderid]");
              return $this->GetDir($fspath, $options);
           }
           else
           {
              $this->fOwlWebDavLog ("GET", "FOLDER: 403 Forbidden - [OWL] Permissions Denied");
              return "403 Forbidden - [OWL] Permissions Denied";
           }

        }
        else
        {
           $sFilename = $this->_fBasename($fspath);
           $iFileID = $this->_fGetFileID($sFilename, $this->owl_folderid);

           if ($iFileID == 0)
           {
               $this->fOwlWebDavLog ("GET", "404 Not Found - File '$sFilename' NOT FOUND in " . $this->owl_folderid);
               return "404 Not Found - File NOT FOUND";
           }
           if (check_auth($iFileID, "file_download", $this->owl_userid, false, false) == 0)
           {
//print("<br />NO ACCESS");
              $this->fOwlWebDavLog ("GET", "FILE: 403 Forbidden - [OWL] Permissions Denied");
              return "403 Forbidden - [OWL] Permissions Denied";
           }
           
           $sql = new Owl_DB;
           $sql->query("SELECT checked_out FROM $default->owl_files_table WHERE id = '$iFileID' and checked_out = '" . $this->owl_userid . "'");

           //if ($sql->num_rows() == 0)
           //{
              //$this->fOwlWebDavLog ("GET", "FILE: 403 Forbidden - [OWL] Couldn't LOCK The file Aborting");
              //return "403 Forbidden - [OWL] Couldn't LOCK The file Aborting";
           //}


        }

        // detect resource type
        $options['mimetype'] = $this->_mimetype($fspath); 
                
        // detect modification time
        // see rfc2518, section 13.7
        // some clients seem to treat this as a reverse rule
        // requiering a Last-Modified header if the getlastmodified header was set
        $options['mtime'] = filemtime($fspath);
            
        // detect resource size
        $options['size'] = filesize($fspath);
            
        // no need to check result here, it is handled by the base class
        $options['stream'] = fopen($fspath, "r");
            
        return true;
    }

    /**
     * GET method handler for directories
     *
     * This is a very simple mod_index lookalike.
     * See RFC 2518, Section 8.4 on GET/HEAD for collections
     *
     * @param  string  directory path
     * @return void    function has to handle HTTP response itself
     */
    function GetDir($fspath, &$options) 
    {
       global $userid, $default;
        $path = $this->_slashify($options["path"]);

//print("<br />PATH:" . $path);
        if ($path != $options["path"]) {
            header("Location: ".$this->base_uri.$path);
            exit;
        }

        // fixed width directory column format
        $format = "%15s  %-19s  %-s\n";

        $handle = @opendir($fspath);
        if (!$handle) {
            return false;
        }

        echo "<html><head><title>Index of ".htmlspecialchars($options['path'])."</title></head>\n";
            
        echo "<h1>Index of ".htmlspecialchars($options['path'])."</h1>\n";
            
        echo "<pre>";
        printf($format, "Size", "Last modified", "Filename");
        echo "<hr>";

        while ($filename = readdir($handle)) 
	{

           // BOZZ RESTRICTED VIEW
           $fullpath = $fspath."/".$filename;

           if ($default->restrict_view == 1)
           {
            $iFolderID = '';
            if (is_dir($fullpath))
            {
               // adding junk to the path, because the fGetFolderID functions returns the PARENT FOLDER ID
               $iFolderID = $this->_fGetFolderID($fullpath . "/junk" , $this->owl_folderid);
               if (check_auth($iFolderID, "folder_view", $userid, false, false) == 0)
               {
                  continue;
               }
            }
            else
            {
               $iFileID = $this->_fGetFileID($filename, $this->owl_folderid);
               if (check_auth($iFileID, "file_download", $userid, false, false) == 0)
               {
                  continue;
               }
            }
           }

            if ($filename != "." && $filename != "..") 
            {
                $name     = htmlspecialchars($filename);
				if ($filename == '..')
				{
				   $sDisplayName = $filename;
				   $sUrl = dirname($_SERVER['PHP_SELF']);
				}
				else
				{
				   $sDisplayName = $name;
				   $sUrl = $_SERVER['PHP_SELF'] . "/" . $name;
				}
                printf($format, 
                       number_format(filesize($fullpath)),
                       strftime("%Y-%m-%d %H:%M:%S", filemtime($fullpath)), 
                       "<a href=\"" . $sUrl . "/\">$sDisplayName</a>");
                       //"<a href=\"" . $sUrl . "/\">$sDisplayName</a> -- FULL: " . $fullpath . " --- " . $iFolderID);
                       //"<a href=\"" . $sUrl . "/\">$sDisplayName</a> -- FolderID: " .$this->owl_folderid);
                       //"<a href=\"" . $sUrl . "/\">$sDisplayName</a> -- FolderID: " . $iFolderID );
                       //"<a href='$name'>$name</a>");
            }
        }

        echo "</pre>";

        closedir($handle);

        echo "</html>\n";

        exit;
    }

    /**
     * POSTPUT method handler
     * 
     * @param  array  parameter passing array
     * @return bool   true on success
     */
    function POSTPUT(&$options) 
    {
        global $default;
        global $userid;

        $this->fOwlWebDavLog ("POSTPUT", "Options: " . print_r($options, true));

        $sql = new Owl_DB;

        $fspath = $this->base . $options["path"];
        $id = $options["fileid"];
        $groupid = owlusergroup($this->owl_userid);

        $sFilename = $this->_fBasename($fspath);

        //if (filesize($fspath) == 0)
        //{
           //return true;
        //}

        if(strtolower($sFilename) == '.ds_store')
        {
           // Ignore the .DS_Store
           return true;
        }

        if($sFilename[0] == '.' && $sFilename[1] == '_')
        {
           $sFilename = substr($sFilename, 2);
           //Ignore the ._filename
           return true;
        }

        fIndexAFile($sFilename, $fspath, $id);
        $this->fOwlWebDavLog ("POSTPUT", "Indexed FILE: $sFilename PATH: $fspath");

        if ($default->thumbnails == 1)
        {
           fGenerateThumbNail($id);
           $this->fOwlWebDavLog ("POSTPUT", "GENERATE ThumbNail");
        }

        if ( fIsQuotaEnabled($userid) )
        {
           $new_quota = fCalculateQuota($options['content_length'], $this->owl_userid, "ADD");
           $this->fOwlWebDavLog ("POSTPUT", "Calculate newquota: $new_quota");
           $sql->query("UPDATE $default->owl_users_table set quota_current = '$new_quota' WHERE id = '$this->owl_userid'");
           $this->fOwlWebDavLog ("POSTPUT", "UPDATE QUOTA");
        }
       
        if ($default->calculate_file_hash == 1)
        {
           $this->fOwlWebDavLog ("POSTPUT", "Calculate FILE HASH");
           $aFileHashes = fCalculateFileHash($fspath);
           $sql->query("INSERT INTO $default->owl_file_hash_table (file_id, hash1, hash2, hash3, signature) VALUES ('$id', '" . $aFileHashes[0] . "', '" . $aFileHashes[1] ."', '" . $aFileHashes[2] . "', 'NOT IMPLEMENTED')");
        }

        $this->fOwlWebDavLog ("POSTPUT", "Virus detection");
        if (fVirusCheck($fspath, $sFilename, true) == 1)
        {
           $sql->query("UPDATE $default->owl_files_table SET infected='1' WHERE id='$id'");
           $this->fOwlWebDavLog ("POSTPUT", "Virus Found Marked File as infected");
        }

        $aSetACL[] = $id;

        fSetDefaultFileAcl($id);
        $this->fOwlWebDavLog ("POSTPUT", "SET DEFAULT FILE ACL");
        fSetInheritedAcl($this->owl_folderid, $id, "FILE");
        $this->fOwlWebDavLog ("POSTPUT", "SET INHERITED FILE ACL");

        if ($options['new'] == 1)
        {
           notify_users($groupid, NEW_FILE, $id);
        }
        else
        {
           notify_users($groupid, UPDATED_FILE, $id);
        }
        $this->fOwlWebDavLog ("POSTPUT", "NOTIFY Users");
        notify_monitored_folders ($this->owl_folderid, $sFilename);
        $this->fOwlWebDavLog ("POSTPUT", "NOTIFY Folder Monitored");

        if ($options['content_length'] == 0)
        {
           $sql->query("UPDATE $default->owl_files_table SET f_size='" . filesize($fspath) ."' WHERE id='$id'");
        }
    }    


    function PUT(&$options) 
    {
        global $default, $index_file;
        global $userid;

        $this->fOwlWebDavLog ("PUT", "======== Function Called =======");

        $index_file = 1;

        $fspath = $this->base . $options["path"];

        $this->fOwlWebDavLog ("PUT", "FILE DEST PATH: $fspath");

        if (!@is_dir(dirname($fspath))) {
            return "409 Conflict";
        }

        $options["new"] = ! file_exists($fspath);

        // Windows WebDav Client
        // Appears to create an empty file, then Update it
        // With the Actual file when Uploading  a New File
        //if ($options['content_length'] == 0)
        //{
           //$this->fOwlWebDavLog ("PUT", "0 Length Files");
           //return "200 Ingore zero len files";
        //}
        
        $sFilename = $this->_fBasename($fspath);
        $this->fOwlWebDavLog ("PUT", "FILENAME: $sFilename");

        if(strtolower($sFilename) == '.ds_store')
        {
           // Ignore the .DS_Store
           return "204 No Content";
        }

        if($sFilename[0] == '.' && $sFilename[1] == '_')
        {
           //Ignore the ._filename
           $sFilename = substr($sFilename, 2);
           return "204 No Content";
        }

        $groupid = owlusergroup($this->owl_userid);

        $this->owl_folderid = $this->_fGetFolderID($options["path"], $this->owl_folderid);

        $iFileID = $this->_fGetFileID($sFilename, $this->owl_folderid);

        if ($iFileID == 0)
        {
           if (check_auth($this->owl_folderid, "folder_create", $this->owl_userid) == 1)
           {
              $sql = new Owl_DB;

              $smodified = $sql->now();

              $sql->query("INSERT into $default->owl_files_table (name,filename,f_size,creatorid, updatorid,parent,created, description,metadata,security,groupid,smodified,checked_out, major_revision, minor_revision, url, doctype, approved, expires, infected) values ('$sFilename', '$sFilename', '" . $options['content_length'] . "', '$this->owl_userid', '$this->owl_userid', '$this->owl_folderid', $smodified,'WEBDAV CREATED', '', '', '" . $groupid . "',$smodified,'0','$default->major_revision','$default->minor_revision','0','0','1', '', '$bInfected')");

              $this->fOwlWebDavLog ("PUT", "NEW FILE");

              $id = $sql->insert_id($default->owl_files_table, 'id');
              $options["fileid"] = $id;

              $this->fOwlWebDavLog ("PUT", "SET DEFAULT FILE ACL");
              fSetDefaultFileAcl($id);
              $this->fOwlWebDavLog ("PUT", "SET INHERITED FILE ACL");
              fSetInheritedAcl($this->owl_folderid, $id, "FILE");

              $fp = fopen($fspath, "w");

              return $fp;
           }
           else
           {
              $this->fOwlWebDavLog ("PUT", "403 Forbidden - [OWL] Folder Write Permissions Denied");
              return "403 Forbidden - [OWL] Folder Write Permissions Denied";
           }
        }
        else
        {
           if (check_auth($iFileID, "file_update", $this->owl_userid) == 1)
           {
              $sql = new Owl_DB;
              $sql->query("SELECT * FROM $default->owl_files_table WHERE id='$iFileID'");
              $sql->next_record();

	      if ($default->owl_version_control == 1 and $options['content_length'] > 0 and $sql->f("f_size") > 0)
              {

                 $this->fOwlWebDavLog ("PUT VERSION CONTROL", "[BEGIN] Options: " . print_r($options,true));

                 // save state information
                 //if ($sql->f("checked_out") > 0 and $sql->f("checked_out") <>  $userid)
                 //{
                    //printError($owl_lang->err_update_file_lock . " " . uid_to_name($sql->f("checked_out")));
                 //}

                 $new_name = $this->_fBasename($options["path"]);
                 $newpath = $default->owl_FileDir . DIR_SEP . find_path($this->owl_folderid) . DIR_SEP . $new_name;

                 $this->fOwlWebDavLog ("PUT VERSION CONTROL", "NEW NAME: " . $new_name);
     
                 $major_revision = $backup_major = $sql->f("major_revision");
                 $minor_revision = $backup_minor = $sql->f("minor_revision");
                 $linkedto = $backup_linkedto = $sql->f("linkedto");
                 if (empty($backup_linkedto))
                 {
                    $backup_linkedto = "0";  // unkown
                    $linkedto = "0";  // unkown
                 }
                 $backup_filename = $sql->f("filename");
                 $backup_name = $sql->f("name");

                 $backup_name = stripslashes($backup_name);
                 $backup_name = fOwl_ereg_replace("'", "\\'" , fOwl_ereg_replace("[<>]", "", $backup_name));
     
                 $backup_size = $sql->f("f_size");
                 $backup_creatorid = $sql->f("creatorid");
                 $backup_updatorid = $sql->f("updatorid");
                 if (empty($backup_updatorid))
                 {
                    $backup_updatorid = "0";  // unkown
                 }

                 $backup_smodified = $sql->f("smodified");

                 $dCreateDate = $sql->now();
                 $backup_description = $sql->f("description");

                 // on some system the ' is automaticaly changed to \' and that works
                 // on other system it stays as ' I have no idea why
                 // the 2 lines bellow should take care of any case.
                 //$backup_description = ereg_replace("[\\]'", "'", $backup_description);
                 $backup_description = stripslashes($backup_description);
                 $backup_description = fOwl_ereg_replace("'", "\\'" , $backup_description);
                 $backup_name = stripslashes($backup_name);
                 $backup_name = fOwl_ereg_replace("'", "\\'" , $backup_name);
                 $backup_metadata = $sql->f("metadata");
                 $backup_metadata = stripslashes($backup_metadata);
                 $backup_metadata = fOwl_ereg_replace("'", "\\'" , fOwl_ereg_replace("[<>]", "", $backup_metadata));
     
                 $backup_parent = $sql->f("parent");
                 $backup_security = $sql->f("security");
                 $backup_groupid = $groupid = $sql->f("groupid");
     

                 $new_quota = fCalculateQuota($options['content_length'], $this->owl_userid, "ADD");
                 $filename = $sql->f(filename);
                 $title = $sql->f(name);
                 $description = $sql->f(description);

                 $description = stripslashes($description);
                 $description = fOwl_ereg_replace("'", "\\'" , $description);
                 
                 $title = stripslashes($title);
                 $title = fOwl_ereg_replace("'", "\\'" , fOwl_ereg_replace("[<>]", "", $title));

                 $extension = explode(".", $new_name);
                 // rename the new, backed up (versioned) filename
                 $extensioncounter = 0;
                 while ($extension[$extensioncounter + 1] != null)
                 {
                    // pre-append a "." separator in the name for each
                    // subsequent part of the the name of the file.
                    if ($extensioncounter != 0)
                    {
                       $version_name = $version_name . ".";
                    }
                    $version_name = $version_name . $extension[$extensioncounter];
                    $extensioncounter++;
                 }
      
                 if ($extensioncounter != 0)
                 {
                    $version_name = $version_name . "_$major_revision-$minor_revision.$extension[$extensioncounter]";
                 }
                 else
                 {
                    $version_name = $extension[0] . "_$major_revision-$minor_revision";
                 }

                 // BUG FIX END
                 // specify path for new file in the /backup/ file of each directory.
                 $backuppath = $default->owl_FileDir . DIR_SEP . find_path($this->owl_folderid) . "/$default->version_control_backup_dir_name/$version_name";
                 // Danilo change
                 if (!is_dir("$default->owl_FileDir/" . find_path($this->owl_folderid) . "/$default->version_control_backup_dir_name"))
                 {
                    mkdir("$default->owl_FileDir/" . find_path($this->owl_folderid) . "/$default->version_control_backup_dir_name", $default->directory_mask);
                    // End Danilo change
                    // is there already a backup directory for current dir?
                    if (is_dir("$default->owl_FileDir/" . find_path($this->owl_folderid) . "/$default->version_control_backup_dir_name"))
                    {
                       $sql->query("INSERT into $default->owl_folders_table (name, parent, security, groupid, creatorid, description, linkedto)  values ('$default->version_control_backup_dir_name', '$this->owl_folderid', '" . fCurFolderSecurity($this->owl_folderid) ."', '" . owlfoldergroup($this->owl_folderid) ."', '" . owlfoldercreator($this->owl_folderid) . "', '', '0')");
     
                       $newParent = $sql->insert_id($default->owl_folders_table, 'id');
     
                       fSetDefaultFolderAcl($newParent);
                       $this->fOwlWebDavLog ("PUT VERSION CONTROL", "SET DEFAULT FOLDER ACL");
                       fSetInheritedAcl($this->owl_folderid, $newParent, "FOLDER");
                       $this->fOwlWebDavLog ("PUT VERSION CONTROL", "SET INHERITED FOLDER ACL");
                    }
                    else
                    {
                       $this->fOwlWebDavLog ("PUT VERSION CONTROL", "BACKUP: (Folder Creation Failed)");
                    }
                 }

                 $this->fOwlWebDavLog ("PUT VERSION CONTROL", "MOVE BACKUP: (Source):" . $newpath . " (Dest): " . $backuppath);
                 copy($newpath, $backuppath); // copy existing file to backup folder

                 //copy($userfile['tmp_name'], $newpath);
                 //chmod($newpath, $default->file_mask);
                 //unlink($userfile['tmp_name']);


                 $sql->query("SELECT id FROM $default->owl_folders_table WHERE name='$default->version_control_backup_dir_name' AND parent='$this->owl_folderid'");
                 while ($sql->next_record())
                 {
                     $backup_parent = $sql->f("id");
                 }

                 $versionchange = fIsRevisionMajor($this->owl_userid);
                 if ($versionchange == 'major_revision')
                 {
                    // if someone requested a major revision, must
                    // make the minor revision go back to 0
                    // $versionchange = "minor_revision='0', major_revision";
                    // $new_version_num = $major_revision + 1;
                    $new_major = $major_revision + 1;
                    $versionchange = "minor_revision='0', major_revision";
                    $new_version_num = $new_major;
                 }
                 else
                 {
                    // simply increment minor revision number
                    $new_minor = $minor_revision + 1;
                    $new_major = $major_revision;
                    $versionchange = "major_revision='$new_major', minor_revision";
                    $new_version_num = $new_minor;
                 }
                 // End Daphne Change
                 $groupid = owlusergroup($this->owl_userid);
                 $smodified = $sql->now();
                 // Begin Daphne Change

                 $this->fOwlWebDavLog ("PUT VERSION CONTROL", "VERSION:" . $new_major  . "." . $new_minor);
                 $iDocApproved = "1";
                 // insert entry for backup file
                 $result = $sql->query("INSERT into $default->owl_files_table (name,filename,f_size,creatorid,updatorid,parent,created, smodified,groupid,description,metadata,security,major_revision,minor_revision, doctype, linkedto, approved) values ('$backup_name','$version_name','$backup_size','$backup_creatorid','$backup_updatorid','$backup_parent',$dCreateDate,'$backup_smodified','$backup_groupid', '$backup_description','$backup_metadata','$backup_security','$backup_major','$backup_minor', '$doctype', '$backup_linkedto', '1')") or unlink($backuppath);
                 if (!$result && $default->owl_use_fs) unlink($newpath);
     
                 $idbackup = $sql->insert_id($default->owl_files_table, 'id');

                 $sql->query("UPDATE $default->owl_files_table SET f_size='" .$options['content_length'] ."', smodified=$smodified, $versionchange='$new_version_num',description='$newdesc', approved = '$iDocApproved', updatorid='$this->owl_userid'  WHERE id='$iFileID'") or unlink($newpath);
                 // UPDATE THE VERSION of the linked files as well.

                 $sql->query("UPDATE $default->owl_files_table SET f_size='" .$options['content_length'] ."', smodified=$smodified, $versionchange='$new_version_num',description='$newdesc', updatorid='$this->owl_userid'  WHERE linkedto='$iFileID'") or unlink($newpath);

                 $sql->query("UPDATE $default->owl_searchidx SET owlfileid='$idbackup'  WHERE owlfileid='$iFileID'");

                 fCopyFileAcl($iFileID, $idbackup);

                 $this->fOwlWebDavLog ("PUT", "FILE UPDATED (version Control)"); 
                 $fp = fopen($fspath, "w");
                 $options["fileid"] = $iFileID;

                 return $fp;
              }
              else
              {
                 $sql = new Owl_DB;

                 $smodified = $sql->now();

                 $sql->query("UPDATE $default->owl_files_table SET f_size='" . $options['content_length'] . "', smodified=$smodified, updatorid='$this->owl_userid'  WHERE id='$iFileID'");
                 $this->fOwlWebDavLog ("PUT", "FILE UPDATED");
                
                 $fp = fopen($fspath, "w");
                 $options["fileid"] = $iFileID;

                 return $fp;
              }

           }
           else
           {
              $this->fOwlWebDavLog ("PUT", "403 Forbidden - [OWL] Permissions Denied");
              return "403 Forbidden - [OWL] Permissions Denied";
           }
        }
    }

    /**
     * MKCOL method handler
     *
     * @param  array  general parameter passing array
     * @return bool   true on success
     */
    function MKCOL($options) 
    {           
        global $default;
        global $userid;

        $this->fOwlWebDavLog ("MKCOL", "Options: \n" . print_r($options, true));

        $path   = $this->base .$options["path"];
        $parent = dirname($path);
        $name   = $this->_fBasename($path);

        if (!file_exists($parent)) {
            return "409 Conflict";
        }

        if (!is_dir($parent)) {
            return "403 Forbidden";
        }

        if ( file_exists($parent."/".$name) ) {
            return "405 Method not allowed";
        }

        if (!empty($this->_SERVER["CONTENT_LENGTH"])) { // no body parsing yet
            return "415 Unsupported media type";
        }
            

        // THIS IS A NEW FOLDER???
        $this->owl_folderid = $this->_fGetFolderID(dirname($options["path"]), $this->owl_folderid);

        if (check_auth($this->owl_folderid, "folder_create", $this->owl_userid) == 1)
        {
           $stat = mkdir($parent."/".$name, 0777);
           if (!$stat) 
           {
               rmdir($parent."/".$name);
               return "403 Forbidden - [OWL] mkdir() Failed to Create the Folder";                 
           }
           $sql = new Owl_DB;
           $smodified = $sql->now();
           $sql->query("INSERT into $default->owl_folders_table (name,parent,description,groupid,creatorid, smodified, linkedto) values ('$name', '$this->owl_folderid', 'WEBDAV CREATED', '" . owlusergroup($this->owl_userid) . "', '$this->owl_userid', $smodified , '0')");
           $this->fOwlWebDavLog ("MKCOL", "FOLDER CREATE");

           $newParent = $sql->insert_id($default->owl_folders_table, 'id');
      
           fSetDefaultFolderAcl($newParent);
           $this->fOwlWebDavLog ("MKCOL", "SET DEFAULT FOLDER ACL");
           fSetInheritedAcl($this->owl_folderid, $newParent, "FOLDER");
           $this->fOwlWebDavLog ("MKCOL", "SET INHERITED FOLDER ACL");
        }
        else
        {
           $this->fOwlWebDavLog ("MKCOL", "403 Forbidden - [OWL] Folder Create Permission Denied");
           return "403 Forbidden - [OWL] Folder Create Permission Denied";                 
        }

        return ("201 Created");
    }
        
        
    /**
     * DELETE method handler
     *
     * @param  array  general parameter passing array
     * @return bool   true on success
     */
    function DELETE($options) 
    {
        global $default, $userid;

        $this->fOwlWebDavLog ("DELETE", "Options: \n" . print_r($options, true));

        $path = $this->base . "/" .$options["path"];

        $sFilename = $this->_fBasename($path);
        $this->owl_folderid = $this->_fGetFolderID($options["path"], $this->owl_folderid);
        $iFileID = $this->_fGetFileID($sFilename, $this->owl_folderid);

        if (!file_exists($path)) {
            return "404 Not found";
        }

        if (is_dir($path)) 
        {
            if (check_auth($this->owl_folderid, "folder_delete", $this->owl_userid) == 1)
            {
               $this->fOwlWebDavLog ("DELETE", "FOLDERID: $this->owl_folderid");
               delTree($this->owl_folderid);
               System::rm("-rf $path");
	       myDelete($path);
            }
            else
            {
               $this->fOwlWebDavLog ("DELETE", "403 Forbidden - [OWL] Folder Delete Permission Denied");
               return "403 Forbidden - [OWL] Folder Delete Permission Denied";
            }
        } 
        else 
        {
           if (check_auth($iFileID, "file_delete", $this->owl_userid) == 1)
           {
              $this->fOwlWebDavLog ("DELETE", "FILEID: $iFileID");
              $userid = $this->owl_userid;
              delFile($iFileID, "file_delete");
              unlink($path);
           }
           else
           {
               $this->fOwlWebDavLog ("DELETE", "403 Forbidden - [OWL] File [$iFileID] Delete Permission Denied");
               return "403 Forbidden - [OWL] File Delete Permission Denied";
           }
        }

        return "204 No Content";
    }


    /**
     * MOVE method handler
     *
     * @param  array  general parameter passing array
     * @return bool   true on success
     */
    function MOVE($options)
    {
       global $userid;
       $this->fOwlWebDavLog ("MOVE", print_r($options, true));

        $iFolderID = $this->_fGetFolderID($options["path"], $this->owl_folderid);
        $sFilename = basename($options["path"]);
        $sDestFilename = basename($options["dest"]);
        $iFileID = $this->_fGetFileID($sFilename, $iFolderID);
        $this->fOwlWebDavLog ("MOVE", "FileID: " . $iFileID );
        $this->fOwlWebDavLog ("MOVE", "FolderID: " . $iFolderID);

        if ($iFileID > 0)
        {
           if (check_auth($iFileID, "file_delete", $this->owl_userid) == 1)
           {
               return $this->COPY($options, true);
           }
           else
           {
               $this->fOwlWebDavLog ("MOVE", "403 Forbidden - [OWL] File [$iFileID] MOVE/COPY Permission Denied");
               return "403 Forbidden - [OWL] File Move/Copy  Permission Denied";
           }
        }
        else
        {
           if (check_auth($iFolderID, "folder_delete", $this->owl_userid) == 1)
           {
               return $this->COPY($options, true);
           }
           else
           {
               $this->fOwlWebDavLog ("MOVE", "403 Forbidden - [OWL] Folder [$iFolderID] MOVE/COPY Permission Denied");
               return "403 Forbidden - [OWL] Folder Move/Copy  Permission Denied";
           }
        }
    }

    /**
     * COPY method handler
     *
     * @param  array  general parameter passing array
     * @return bool   true on success
     */
    function COPY($options, $del=false) 
    {
        global $userid, $default;
        $isSourceAFolder = false;

        $this->fOwlWebDavLog ("COPY", print_r($options,true));

        // TODO Property updates still broken (Litmus should detect this?)

        if (!empty($this->_SERVER["CONTENT_LENGTH"])) { // no body parsing yet
            return "415 Unsupported media type";
        }

        $this->fOwlWebDavLog ("COPY", "1");

        // no copying to different WebDAV Servers yet
        // Treat it as a local request
        if (isset($options["dest_url"]))
        {
            $this->fOwlWebDavLog ("COPY", "1b");
            $aDest = array();
            $aDest = preg_split('/index.php/', $options["dest_url"]);

            if (array_key_exists('1', $aDest))
            {
               $options["dest"] = $aDest['1'];
            }
            else
            {
               return "502 bad gateway";
            }
        }

        $source = $this->base .$options["path"];
        $this->fOwlWebDavLog ("COPY", "2: $source");
        if (!file_exists($source)) return "404 Not found";

        $this->fOwlWebDavLog ("COPY", "2a");
        $dest         = $this->base . $options["dest"];
        $new          = !file_exists($dest);
        $existing_col = false;

        if (!$new) {
        $this->fOwlWebDavLog ("COPY", "2b");
            if ($del && is_dir($dest)) {
                if (!$options["overwrite"]) {
                    return "412 precondition failed";
                }
                $dest .= $this->_fBasename($source);
                if (file_exists($dest)) {
                    $options["dest"] .= $this->_fBasename($source);
                } else {
                    $new          = true;
                    $existing_col = true;
                }
            }
        }
        $this->fOwlWebDavLog ("COPY", "3");

        if (!$new) 
        {
           $this->fOwlWebDavLog ("COPY", "3a");
           if ($options["overwrite"]) 
           {
              $this->fOwlWebDavLog ("COPY", "3b");
              $stat = $this->DELETE(array("path" => $options["dest"]));
              if (($stat{0} != "2") && (substr($stat, 0, 3) != "404")) 
              {
                 return $stat; 
              }
           } 
           else 
           {
              return "412 precondition failed";
           }
        }

        $this->fOwlWebDavLog ("COPY", "4");
        if (is_dir($source) && ($options["depth"] != "infinity")) 
        {
           $this->fOwlWebDavLog ("COPY", "4a");
           // RFC 2518 Section 9.2, last paragraph
           return "400 Bad request";
        }

        $this->fOwlWebDavLog ("COPY", "5");
        if ($del) 
        {
           $this->fOwlWebDavLog ("COPY", "5a");
           if (is_dir($source)) 
	   {
               $isSourceAFolder = true;
               $this->fOwlWebDavLog ("COPY", "5b: $query");
               $iSourceFolderID = $this->_fGetFolderID($options["path"], $this->owl_folderid);
               $this->fOwlWebDavLog ("COPY", "5c SOURCE FOLDER ID: $iSourceFolderID");
           }
           if (!rename($source, $dest)) {
               return "500 Internal server error";
           }
           $destpath = $this->_unslashify($options["dest"]);
        }  
        else 
        {
           if (is_dir($source)) 
           {
               $files = System::find($source);
               $files = array_reverse($files);
           } else {
               $files = array($source);
           }

           if (!is_array($files) || empty($files)) 
           {
               return "500 Internal server error";
           }

           foreach ($files as $file) 
           {
              if (is_dir($file)) {
                  $file = $this->_slashify($file);
              }

                $destfile = str_replace($source, $dest, $file);
                    
                if (is_dir($file)) {
                    if (!is_dir($destfile)) {
                        // TODO "mkdir -p" here? (only natively supported by PHP 5) 
                        if (!@mkdir($destfile)) {
                            return "409 Conflict";
                        }
                    } 
                } else {
                    if (!@copy($file, $destfile)) {
                        return "409 Conflict";
                    }
                }
            }
        }

        $this->fOwlWebDavLog ("COPY", "6");
// FOLDER RENAME CODE
// BEGIN 
 
        $this->fOwlWebDavLog ("COPY", print_r($options, true));
        $this->fOwlWebDavLog ("COPY", "ID IN: ". $this->owl_folderid);
        $iFolderID = $this->_fGetFolderID($options["path"], $this->owl_folderid);
        $this->fOwlWebDavLog ("COPY", "ID OUT: ". $iFolderID);

        $sFilename = $this->_fBasename($options["path"]);
        $sDestFilename = $this->_fBasename($options["dest"]);
        $this->fOwlWebDavLog ("COPY", "IS THIS A FILE?: " . $sFilename . " Parent: " . $iFolderID);
	$iFileID = $this->_fGetFileID($sFilename, $iFolderID);
        $this->fOwlWebDavLog ("COPY", "FILE ID: ". $iFileID);


        if ($iFileID > 0)
	{
           $this->fOwlWebDavLog ("COPY", "THIS IS A FILE");
           $this->fOwlWebDavLog ("COPY", "SOURCE: " . $iFileID . " - " . $sFilename);
           $dest = $this->_fGetFolderID(dirname($options["dest"]), $this->owl_folderid);
	   $sql = new Owl_DB;
           $sql->query("UPDATE $default->owl_files_table set name='" . $sDestFilename . "', filename='" . $sDestFilename . "', parent = '$dest' WHERE id='$iFileID'");
           $this->fOwlWebDavLog ("COPY", "FILE RENAMED: " . $source . " Dest: " . $dest);
	}
	else
	{
                   $source = $this->_fGetFolderID($options["path"], $this->owl_folderid);
                   $dest = $this->_fGetFolderID(dirname($options["dest"]), $this->owl_folderid);
	           $sFilename = fid_to_name($source);

		   if ($isSourceAFolder) 
		   {
                      $this->fOwlWebDavLog ("COPY", "THIS IS A FOLDER: " . $sFilename);
		      $sql = new Owl_DB;
                      $sql->query("UPDATE $default->owl_folders_table set name='" . $this->_fBasename($options["dest"]) . "', parent = '$dest' WHERE id='$iSourceFolderID'");
		   }
		   else
		   {
                      $this->fOwlWebDavLog ("COPY", "THIS IS NOT A FOLDER: " . $sFilename . " DEST: " . dirname($options["dest"]));
                      return "412 Conflict - [OWL] Aborting";
		   }

   
           $this->fOwlWebDavLog ("COPY", "FOLDER RENAMED: " . $iFolderID . " Dest: " . $dest . "\n$abc");
	}
// END 

        return ($new && !$existing_col) ? "201 Created" : "204 No Content";         
    }

    /**
     * PROPPATCH method handler
     *
     * @param  array  general parameter passing array
     * @return bool   true on success
     */
    function PROPPATCH(&$options) 
    {
       global $userid;
        $this->fOwlWebDavLog ("PROPPATCH", "");

        global $prefs, $tab;

        $msg  = "";
        $path = $options["path"];
        $dir  = dirname($path)."/";
        $base = $this->_fBasename($path);
            
        foreach ($options["props"] as $key => $prop) {
            if ($prop["ns"] == "DAV:") {
                $options["props"][$key]['status'] = "403 Forbidden";
            } else {
                if (isset($prop["val"])) {
                    $query = "REPLACE INTO {$this->db_prefix}properties 
                                           SET path = '$options[path]'
                                             , name = '$prop[name]'
                                             , ns= '$prop[ns]'
                                             , value = '$prop[val]'";
                } else {
                    $query = "DELETE FROM {$this->db_prefix}properties 
                                        WHERE path = '$options[path]' 
                                          AND name = '$prop[name]' 
                                          AND ns = '$prop[ns]'";
                }       
                mysql_query($query);
            }
        }
                        
        return "";
    }


    /**
     * LOCK method handler
     *
     * @param  array  general parameter passing array
     * @return bool   true on success
     */
    function LOCK(&$options) 
    {
        global $default;
       global $userid;

        $this->fOwlWebDavLog ("LOCK", "Options: \n" . print_r($options, true));

        // get absolute fs path to requested resource
        $fspath = $this->base . $options["path"];

        // TODO recursive locks on directories not supported yet
        if (is_dir($fspath) && !empty($options["depth"])) {
            return "409 Conflict";
        }

        $options["timeout"] = time()+300; // 5min. hardcoded


        //if (isset($options["update"])) 
        //{ // Lock Update
           $sFilename = $this->_fBasename($fspath);
           $this->owl_folderid = $this->_fGetFolderID($options["path"], $this->owl_folderid);
           $iFileID = $this->_fGetFileID($sFilename, $this->owl_folderid);

           if (check_auth($iFileID, "file_lock", $this->owl_userid) == 1)
           {
              $sql = new Owl_DB;

              // check that file hasn't been reserved while updates have gone through
              $sql->query("SELECT checked_out FROM $default->owl_files_table WHERE id = '$iFileID'");
        
              while ($sql->next_record())
              {
                 $file_lock = $sql->f("checked_out");
              }
        
              if ($file_lock == 0)
              {
                 // reserve the file
                 $sql->query("UPDATE $default->owl_files_table set checked_out='$this->owl_userid' WHERE id='$iFileID'");
                 $this->fOwlWebDavLog ("LOCK", "[OWL] File Locked $iFileID");
                 //owl_syslog(FILE_LOCKED, $userid, flid_to_filename($id), $parent, $owl_lang->log_detail, "FILE");
              }
              else
              {

                 $sql->query("SELECT checked_out FROM $default->owl_files_table WHERE id = '$iFileID' and checked_out = '" . $this->owl_userid . "'");
                 if ($sql->num_rows() == 0)
                 {
                    $this->fOwlWebDavLog ("LOCK", "403 Forbidden - [OWL] Couldn't LOCK The file Aborting");
                    return "403 Forbidden - [OWL] Couldn't LOCK The file Aborting";
                 }
                 else
                 {
                    $this->fOwlWebDavLog ("LOCK", "[OWL] File ALREADY LOCKED BY:" . $file_lock);
                    return "200 OK";
                 }

              }
           }
           else
           {
                 $this->fOwlWebDavLog ("LOCK", "[OWL] User has No permission to Lock the File " . $this->owl_userid);
		 //return "403 Forbidden - [OWL] Couldn't LOCK The file Aborting";
                 return "200 OK";
           }

            /* $where = "WHERE path = '$options[path]' AND token = '$options[update]'";

            $query = "SELECT owner, exclusivelock FROM {$this->db_prefix}locks $where";
            $res   = mysql_query($query);
            $row   = mysql_fetch_assoc($res);
            mysql_free_result($res);

            if (is_array($row)) {
                $query = "UPDATE {$this->db_prefix}locks 
                                 SET expires = '$options[timeout]' 
                                   , modified = ".time()."
                              $where";
                mysql_query($query);


                $options['owner'] = $row['owner'];
                $options['scope'] = $row["exclusivelock"] ? "exclusive" : "shared";
                $options['type']  = $row["exclusivelock"] ? "write"     : "read";
                return true;
            } else {
                return false;
            }
        }
            
        $query = "INSERT INTO {$this->db_prefix}locks
                        SET token   = '$options[locktoken]'
                          , path    = '$options[path]'
                          , created = ".time()."
                          , modified = ".time()."
                          , owner   = '$options[owner]'
                          , expires = '$options[timeout]'
                          , exclusivelock  = " .($options['scope'] === "exclusive" ? "1" : "0")
            ;
        mysql_query($query);

        return mysql_affected_rows() ? "200 OK" : "409 Conflict";  */
        return "200 OK";

    }

    /**
     * UNLOCK method handler
     *
     * @param  array  general parameter passing array
     * @return bool   true on success
     */
    function UNLOCK(&$options) 
    {
        global $default;
       global $userid;

        $this->fOwlWebDavLog ("UNLOCK", "Options: " . print_r($options, true));

        $fspath = $this->base . $options["path"];

        // sanity check
        if (!file_exists($fspath))
        {
           return false;
        }

        $sFilename = $this->_fBasename($fspath);
        $this->owl_folderid = $this->_fGetFolderID($options["path"], $this->owl_folderid);
        $iFileID = $this->_fGetFileID($sFilename, $this->owl_folderid);

           if (check_auth($iFileID, "file_lock", $this->owl_userid) == 1)
           {
              $sql = new Owl_DB;

              // check that file hasn't been reserved while updates have gone through
              $sql->query("SELECT checked_out FROM $default->owl_files_table WHERE id = '$iFileID' and checked_out = '$this->owl_userid'");
              if ($sql->num_rows() == 1)
              {
                 // UNLOCK the file
                 $sql->query("UPDATE $default->owl_files_table set checked_out='0' WHERE id='$iFileID'");
                 $this->fOwlWebDavLog ("UNLOCK", "[OWL] File UnLocked $iFileID");
              }
           }



        //return mysql_affected_rows() ? "204 No Content" : "409 Conflict";
        return "204 No Content";
    }

    /**
     * checkLock() helper
     *
     * @param  string resource path to check for locks
     * @return bool   true on success
     */
    function fOwlWebDavLog ($sFunction, $sMessage) 
    {
       global $userid;
       if ($this->owl_debug)
        {
             $file = fopen($this->owl_debugfile, 'a+');
             fwrite($file, "[" .date("Y-m-d H:i:s"). "][$sFunction]: $sMessage\n");
             fclose($file);
        }
    }

    function fGetInitialRevision () 
    {
    }

    /**
     * checkLock() helper
     *
     * @param  string resource path to check for locks
     * @return bool   true on success
     */
    function checkLock($path) 
    {
       global $userid;
        $result = false;
            
        $query = "SELECT owner, token, created, modified, expires, exclusivelock
                  FROM {$this->db_prefix}locks
                 WHERE path = '$path'
               ";
        $res = mysql_query($query);

        if ($res) {
            $row = mysql_fetch_array($res);
            mysql_free_result($res);

            if ($row) {
                $result = array( "type"    => "write",
                                 "scope"   => $row["exclusivelock"] ? "exclusive" : "shared",
                                 "depth"   => 0,
                                 "owner"   => $row['owner'],
                                 "token"   => $row['token'],
                                 "created" => $row['created'],   
                                 "modified" => $row['modified'],   
                                 "expires" => $row['expires']
                                 );
            }
        }

        return $result;
    }

   function _fGetDavClient()
    {
       $userAgentValue = $_SERVER['HTTP_USER_AGENT'];

       $this->fOwlWebDavLog ("_fGetDavClient", "USER AGENT: $userAgentValue");
       // Mac Finder
       if (stristr($userAgentValue,"Macintosh") || stristr($userAgentValue,"Darwin"))
       {
          $this->dav_client = "MacDarwin";
       }
       // Mac Goliath
       if (stristr($userAgentValue,"Cyberduck"))
       {
          $this->dav_client = "Cyberduck";
       }
       // Mac Goliath
       if (stristr($userAgentValue,"Goliath"))
       {
          $this->dav_client = "Goliath";
       }
       // MS Windows Dav Client
       if (stristr($userAgentValue,"Microsoft-WebDAV"))
       {
          $this->dav_client = "Microsoft-WebDAV";
       }
       $this->fOwlWebDavLog ("_fGetDavClient", "Dav Client: $this->dav_client");
     }


    function _fBasename($path, $suffix = '') 
    {
      $path = preg_replace('#^.+[\\/]#', '', trim($path, '/'));
      if ($suffix) 
      {
        $path = preg_replace('/|/'. preg_quote($suffix) .'$|', '', $path);
      }
      return $path;
    }

    function _fIsAdmin ()
    {
       global $default;

       $this->fOwlWebDavLog ("_fIsAdmin", "Check Current User for Admin Access Privilege");

       $groupid = owlusergroup($this->owl_userid);

       if ($groupid == 0)
       {
          return true;
       }

       $sql = new Owl_DB;
       $sql->query("SELECT userid,groupid from $default->owl_users_grpmem_table where userid = '$this->owl_userid' and groupid = '0'");

       if ($sql->num_rows($sql) == 0)
       {
          return false;
       }
       else
       {
          return true;
       }
       return false;
    }


}


/*
 * Local variables:
 * tab-width: 4
 * c-basic-offset: 4
 * indent-tabs-mode:nil
 * End:
 */
