<?php
# load the extension if needed
if(! extension_loaded('php_sqlite') )
	dl((strtoupper(substr(PHP_OS, 0,3)) == 'WIN')?'php_sqlite.dll':'sqlite.so');

if(! class_exists('db'))
    require(dirname(__file__).'/class-db.php');

/**
* @author Jonathan Gotti <nathan at the-ring dot homelinux dot net>
* @copyleft (l) 2003-2004  Jonathan Gotti
* @package DB
* @license http://opensource.org/licenses/gpl-license.php GNU Public License
* @subpackage SQLITE
* @changelog 2006-05-12 - clean the escape_string() method
*            2006-04-17 - rewrite the class to use abstarction class db
*                       - Conditions params support on methods select_*, update, delete totally rewrite to handle smat question mark
*                         @see db::process_conds()
*                       - get_field and list_fields are now deprecated but still supported (listfield will be indexed by name whatever is $indexed_by_name)
*            2005-02-25 - now the associative_array_from_q2a_res method won't automaticly ksort the results anymore
*                       - re-enable the possibility to choose between SQLITE_ASSOC or SQLITE_NUM
*            2005-02-28 - new method optimize and vacuum
*            2005-04-05 - get_fields will now try to get fields from sqlite_master if no data found in the table
* @todo add transactions support
* @todo add check_conn method
*/
class sqlitedb extends db{
  var $buffer_on = TRUE;
  var $autocreate= FALSE;
  var $db_file = '';
  var $_protect_fldname = null;
  /**
  * create a sqlitedb object for managing locale data
  * if DATA_PATH is define will force access in this directory
  * @param string $Db_file
  * @return sqlitedb object
  */
  function sqlitedb($db_file,$mode=null){
    # readwrite mode to open database
    switch ($mode){
      case 'r':
        $mod = 0444;
        break;
      case 'w':
        $mod = 0666;
        $this->autocreate = TRUE;
        break;
      default:
        if(is_numeric($mode))
          $mod = $mode;
    }
    $this->mode       = (isset($mod)?$mod:0666);
    $this->host = 'localhost';
    $this->db_file = $db_file;
    $this->conn = &$this->db;
    $this->db();
  }
###*** REQUIRED METHODS FOR EXTENDED CLASS ***###
  /** open connection to database */
  function open(){
    //prevent multiple db open
    if($this->db)
      return $this->db;
    if(! $this->db_file )
      return FALSE;
    if(! (is_file($this->db_file) || $this->autocreate) )
      return false;
    if( $this->db = sqlite_open($this->db_file, $this->mode, $error)){
      return $this->db;
    }else{
      echo "$error\n";
      return FALSE;
    }
  }

  /** close connection to previously opened database */
  function close(){
    if( !is_null($this->db) )
      sqlite_close($this->db);
    $this->db = null;
  }

  /**
  * take a resource result set and return an array of type 'ASSOC','NUM','BOTH'
  * @param resource $result_set
  * @param string $result_type in 'ASSOC','NUM','BOTH'
  */
  function fetch_res(&$result_set,$result_type='ASSOC'){
    $result_type = strtoupper($result_type);
    if(! in_array($result_type,array('NUM','ASSOC','BOTH')) )
      $result_type = 'ASSOC';
    eval('$result_type = SQLITE_'.strtoupper($result_type).';');
    
    while($res[]=sqlite_fetch_array($result_set,$result_type));
    unset($res[count($res)-1]);//unset last empty row
    
    if($this->buffer_on)
      $this->num_rows = sqlite_num_rows($this->last_qres);
    else
      $this->num_rows = count($res)-1;
    
    return $this->last_q2a_res = count($res)?$res:FALSE;
  }

  function last_insert_id(){
    return $this->db?sqlite_last_insert_rowid($this->db):FALSE;
  }

  /**
  * perform a query on the database
  * @param string $Q_str
  * @return result id | FALSE
  */
  function query($Q_str){
    if(! $this->db ){
      if(! ($this->autoconnect && $this->open()) )
        return FALSE;
    }
    if($this->buffer_on)
      $this->last_qres = sqlite_query($this->db,$Q_str);
    else
      $this->last_qres = sqlite_unbuffered_query($this->db,$Q_str);
    if(! $this->last_qres)
      $this->set_error(__FUNCTION__);
    return $this->last_qres;
  }
  
  /**
  * perform a query on the database like query but return the affected_rows instead of result
  * give a most suitable answer on query such as INSERT OR DELETE
  * Be aware that delete without where clause can return 0 even if several rows were deleted that's a sqlite bug!
  *    i will add a workaround when i'll get some time! (use get_count before and after such query)
  * @param string $Q_str
  * @return int affected_rows
  */
  function query_affected_rows($Q_str){
    if(! $this->query($Q_str) )
      return FALSE;
    return @sqlite_changes($this->db);
  }

  /**
  * return the list of field in $table
  * @param string $table name of the sql table to work on
  * @param bool $extended_info if true will return the result of a show field query in a query_to_array fashion
  *                           (indexed by fieldname instead of int if false)
  * @return array
  */
  function list_table_fields($table,$extended_info=FALSE){
    # Try the simple method
    if( (! $extended_info) && $res = $this->query_to_array("SELECT * FROM $table LIMIT 0,1")){
      return array_keys($res[0]);
    }else{ # There 's no row in this table so we try an alternate method or we want extended infos            
      if(! $fields = $this->query_to_array("SELECT sql FROM sqlite_master WHERE type='table' AND name ='$table'") )
        return FALSE;
      # get fields from the create query
      $flds_str = $fields[0]['sql'];
      $flds_str = substr($flds_str,strpos($flds_str,'('));
      $type = "((?:[a-z]+)\s*(?:\(\s*\d+\s*(?:,\s*\d+\s*)?\))?)?\s*";
      $default = '(?:DEFAULT\s+((["\']).*?(?<!\\\\)\\4|[^\s,]+))?\s*';
      if( preg_match_all('/(\w+)\s+'.$type.$default.'[^,]*(,|\))/i',$flds_str,$m,PREG_SET_ORDER) ){
        $key  = "PRIMARY|UNIQUE|CHECK";
        $null = 'NOT\s*NULL';
        $Extra = 'AUTOINCREMENT';
        $default = 'DEFAULT\s+((["\'])(.*?)(?<!\\\\)\\2|\S+)';
        foreach($m as $v){
          list($field,$name,$type,$default) = $v;
          # print_r($field);
          if(!$extended_info){
            $res[] = $name;
            continue;
          }
          $res[$name] = array('Field'=>$name,'Type'=>$type,'Null'=>'YES','Key'=>'','Default'=>$default,'Extra'=>'');
          if( preg_match("!($key)!i",$field,$n))
            $res[$name]['Key'] = $n[1];
          if( preg_match("!($Extra)!i",$field,$n))
            $res[$name]['Extra'] = $n[1];
          if( preg_match('!(NO)T\s+NULL!i',$field,$n))
            $res[$name]['Null'] = $n[1];
        }
        return $res;
      }
      return FALSE;
    }
  }
  /**
  * get the table list
  * @return array
  */
  function list_tables(){
    if(! $tables = $this->query_to_array('SELECT name FROM sqlite_master WHERE type=\'table\'') )
      return FALSE;
    foreach($tables as $v){
      $ret[] = $v['name'];
    }
    return $ret;
  }

  /** Verifier si cette methode peut s'appliquer a SQLite * /
  function show_table_keys($table){}
  
  /**
  * optimize table statement query
  * @param string $table name of the table to optimize
  * @return bool
  */
  function optimize($table){
    return $this->vacuum($table);
  }
  /**
  * sqlitedb specific method to use the vacuum statement (used as replacement for mysql optimize statements)
  * you should use db::optimize() method instead for better portability
  * @param string $table_or_index name of table or index to vacuum
  * @return bool
  */
  function vacuum($table_or_index){
    return $this->query("VACUUM $table_or_index;");
  }

  function error_no(){
    return $this->db?sqlite_last_error($this->db):FALSE;
  }

  function error_str($errno){
    return sqlite_error_string($errno);
  }

  /**
  * base method you should replace this one in the extended class, to use the appropriate escape func regarding the database implementation
  * @param string $quotestyle (both/single/double) which type of quote to escape
  * @return str
  */
  function escape_string($string,$quotestyle='both'){
    $string = sqlite_escape_string($string);
    switch(strtolower($quotestyle)){
      case 'double':
      case 'd':
      case '"':
        $string = str_replace("''","'",$string);
        $string = str_replace('"','\"',$string);
        break;
      case 'single':
      case 's':
      case "'":
        break;
      case 'both':
      case 'b':
      case '"\'':
      case '\'"':
        $string = str_replace('"','\"',$string);
        break;
    }
    return $string;
  }
}

?>