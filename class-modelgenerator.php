<?php
/**
* @package class-db
* @subpackage abstractModel
* @author Jonathan Gotti <jgotti at jgotti dot org>
* @author Adrien Gibrat <adrien.gibrat at gmail dot com>
* @license http://opensource.org/licenses/lgpl-license.php GNU Lesser General Public License
* @since 2007-10
* @class modelGenerator
* @svnInfos:
*            - $LastChangedDate$
*            - $LastChangedRevision$
*            - $LastChangedBy$
*            - $HeadURL$
* @changelog
* - 2011-08-16 - datas arrays now is properly typed in BASE_models
* - 2011-08-11 - allow user to change the method to determine single form of table names by adding a modelgenerator::$singularizer property
*              - add modelgenerator::$forceHasOneSingular property that will take the first single form returned by singularizer for hasOne property
*                /!\ IMPORTANT /!\ be aware that if you regenerate model from previous modelGenerator version you probably should set modelgenerator::$forceHasOneSingular to false
* - 2011-08-01 - changed proposed model::isUnique() method behaviour to better match is name
* - 2011-05-13 - add some more docBloc documentation for hasOnes/hasManys related methods in generated models
* - 2011-04-01 - add some more docBloc documentation for hasOnes/hasManys related methods in generated models
* - 2011-03-31 - add some docBloc documentation for hasOnes/hasManys properties in generated models
* - 2010-11-24 - correctly define default null values inside models::$datasDefs
* - 2010-09-13 - make $_has[One|Many] static property of extended models public as a workaround for buggy get_class_vars scope resolution
* - 2009-06-25 - better detection of ignored relationship on fields NO NULL with NULL as default
* - 2009-06-03 - split proposed methods to make between BASE and extended models (only filters are proposed in extended)
* - 2009-04-01 - $_avoidEmptyPK is now true by default
* - 2009-03-30 - add support for prefixed tables by adding new static properties $tablePrefixes and $excludePrefixedTables
* - 2009-02-09 - add new BASE_models static methods _GetSupportedAddons _SupportsAddon
*              - add $_avoidEmptyPK property to final models
* - 2008-12-19 - add proposed methods filterFieldName and checkFieldNameExists for datas defined with UNIQUE keys
* - 2008-09-04 - add extended modelCollection
* - 2008-08-27 - new method BASE_modelName::getFilteredInstance();
* - 2008-08-13 - add method proposition to access enum fields possible values
* - 2008-08-05 - add empty property __toString for model rendering as string
* - 2008-08-04 - add additional methods proposition for filtering enums fields
* - 2008-05-08 - add modelAddons property to final class
* - 2008-03-30 - separation of generator and the rest of code
*              - now generator conform to new relation definition as hasOne / hasMany
*                instead of one2one / one2many / many2many
*              - remove withRel parameter
* - 2008-03-23 - better model generation :
*                * support autoMapping
*                * can overwrite / append / or skip existing models
*                * can set a constant as dbConnectionStr
* @code
* // instanctiate a generator
* $g = new modelGenerator('mysqldb://test;localhost;root;','models');
* // set the generator to overwrite base class if they already exists
* $g->onExist = 'o';
* // then let the generator do his work
* $g->doGeneration('DB_CONNECTION_CONSTANT_STRING_TO_USE','testModel');
* @endcode
*/

require_once(dirname(__file__).'/class-db.php');

class modelGenerator{
	public $connectionStr = '';
	/** class-db active instance */
	protected $db = null;
	/** fullpath to output directories */
	public $outputDir = '/tmp/';

	/**
	* do we try to auto map relationships at generation time.
	* be sure to correctly check the results when doing this, automatic generators are really
	* far from perfect, it will handle some case well, forgive some and worst can mistake some other
	* Try this on and if you think thats not good enough turn this off
	* @see coming next documentation on how does this work.
	*/
	public $autoMap = true;
	/**
	* what to do when a given model file is found (extended models are always skip when exists).
	*  - o => overwrite existing models but leave extended models as is
	*  - a => append the new model to the existing one you must have a look to files and delete one of the generated class
	*     can be usefull on updates to check relationships by hands for example. (this is the default behaviour)
	*     leave extended models as is
	*  - s => skip generation of this model so only new models are generated usefull when you add some tables to your database
	*/
	public $onExist = 'a';

	/**
	* will only include tables that starts with given prefixes
	* if $excludePrefixedTables is set to true then will include all tables that don't start with given prefixes.
	* You can pass here a list of table prefixes separated by '|' (ie: 'excludeprefix1_|excludeprefix2')
	* Note: prefixes given are case sensitive!
	*/
	static public $tablePrefixes='';
	/**
	* this property only make sense when $tablePrefixes is set.
	* By default only tables matching given $tablePrefixes are included in model generation process
	* but setting this to true model generation will then only include talbes that don't match the given prefixes.
  */
	static public $excludePrefixedTables=false;

	/**
	* this allow to override the mechanism to detect singular form of a plural name a basic one is setted as default.
	* the method will receive a single word and has to return an array of possibles values
	*/
	static public $singularizer = array('modelGenerator','singular');
	static public $forceHasOneSingular = true;

	/**
	* create an instance of modelGenerator.
	* @param string $dbConenctionStr
	* @param string $outputDir
	* @param int    $dbVerboseLevel
	*/
	function __construct($dbConnectionStr,$outputDir=null,$dbVerboseLevel=0){
		$this->connectionStr = $dbConnectionStr;
		$this->db = db::getInstance($this->connectionStr);
		$this->db->beverbose = (int) $dbVerboseLevel;
		if(! is_null($outputDir) )
			$this->outputDir = substr($outputDir,-1)==='/'?$outputDir:$outputDir.'/';
		if(! is_dir($this->outputDir) ){
			if( false===mkdir($this->outputDir,0770,true))
				throw new Exception("Can't create output dir $this->outputDir.");
		}
	}

	/**
	* generate class for each table in database.
	* @param string $dbConnectionDefined  an optionnal defined dbConnectionStr to use as a replacement for connectionStr
	*                                     really usefull when you want to change the database connection string without
	*                                     editing all models.
	* @param string $prefix               add a prefix to generated models
	*/
	function doGeneration($dbConnectionDefined=null,$prefix=''){
		#- get table list
		$tables  = $this->db->list_tables();
		if(! $tables)
			return false;
		if( !empty(self::$tablePrefixes) ){
			foreach($tables as $k=>$v){
				$match = preg_match('!^('.self::$tablePrefixes.')!',$v);
				if( $match && self::$excludePrefixedTables)
					unset($tables[$k]);
				elseif( (!$match) && ! self::$excludePrefixedTables)
					unset($tables[$k]);
			}
		}

		#- get singles names
		foreach($tables as $tb){
			$singlesVal = array_filter((array) call_user_func(self::$singularizer,$tb));
			foreach($singlesVal as $s){
				$singles[$s] = $tb;
			}
		}
		#- then fields info for each tables
		foreach($tables as $tb){
			$tbFields[$tb] = $this->db->list_table_fields($tb,true);
			foreach($tbFields[$tb] as $fK=>$fInfos){
				if(strpos($fInfos['Key'],'PRI')===0){
					$tbFields[$tb]['PK'] = $fInfos['Field'];
					break;
				}
			}
		}
		$relMatch=array(
			'requiredBy' => 'dependOn',
			'ignored'    => 'ignored',
			'dependOn'   => 'requiredBy',
		);
		if( $this->autoMap ){
			#- when we have all datas we start automapping of relationships
			# start with one2one
			foreach($tbFields as $tb=>$fields){
				foreach($fields as $fK=>$fInfos){
					if($fK==='PK') continue;
					$fName = $fInfos['Field'];
					$relDflt = false;
					#- hasOne relations
					#- if( ($foreignTb = array_search($fName,$singles))){
					$foreignTb = false;
					if( !empty($singles[$fName]) ){
						$foreignTb = $singles[$fName];
						if(! empty($tbFields[$foreignTb]['PK'] ) ){
							$hasOne=array(
								'modelName'=>$foreignTb,
								'localField'=>$fName,
								#- ~ 'foreignField'=>$tbFields[$foreignTb]['PK'], // foreign Field is PK just ignore it
							);
							if( (! in_array($fInfos['Default'],array('',null),true)) || $fInfos['Null'] === 'YES'){
								$hasOne['relType'] = "ignored";
								$relDflt = $fInfos['Default']===''?null:$fInfos['Default'];
							}else{
								$hasOne['relType'] = "dependOn";
							}
							$tbFields[$tb]['hasOne'][$fName] = $hasOne;
						}else{
							$foreignTb = false;
						}
					}elseif( in_array($fName,$tables) ){
						if(! empty($tbFields[$fName]['PK'] ) ){
							$foreignTb = $fName;
							#- ~ $tbFields[$tb]['one2one'][$fName] = $foreignTb ;
							$hasOne=array(
								'modelName'=>$foreignTb,
								'localField'=>$fName,
								#- ~ 'foreignField'=>$tbFields[$foreignTb]['PK'], // foreign Field is PK just ignore it
							);
							if( (! in_array($fInfos['Default'],array('',null),true)) || $fInfos['Null'] === 'YES'){
								$hasOne['relType'] = "ignored";
								$relDflt = $fInfos['Default']===''?null:$fInfos['Default'];
							}else{
								$hasOne['relType'] = "dependOn";
							}
							$tbFields[$tb]['hasOne'][$fName] = $hasOne;
						}else{
							$foreignTb = false;
						}
					}

					#- if we've found a hasOne map the corresponding hasOne or hasMany
					if( !empty($foreignTb) && !empty($tbFields[$tb]['PK']) ){
						if($fInfos['Key'] === 'UNI' || $fInfos['Key'] === 'PRI'){
							$tbFields[$foreignTb]['hasOne'][$tb] = array(
								'modelName'   =>$tb,
								#- ~ 'localField'  =>$tbFields[$foreignTb]['PK'],
								'foreignField'=>$fName,
								'relType'     => $relMatch[$hasOne['relType']]
							);
							if($relDflt!==false)
								$tbFields[$foreignTb]['hasOne'][$tb]['foreignDefault'] = $relDflt;
						}else{
							$tbFields[$foreignTb]['hasMany'][$tb] = array(
								'modelName'   =>$tb,
								#- ~ 'localField'  =>$tbFields[$foreignTb]['PK'],
								'foreignField'=>$fName,
								'relType'     => $relMatch[$hasOne['relType']]
							);
							if($relDflt!==false)
								$tbFields[$foreignTb]['hasMany'][$tb]['foreignDefault'] = $relDflt;
						}
					}
				}

				#- now try to manage many2many links (only simple correspondance table with two fields at the moment
				if( empty($fields['PK']) && isset($tbFields[$tb]['hasOne']) && count($tbFields[$tb]['hasOne'])===2 ){
					$m2ms = array();
					foreach($tbFields[$tb]['hasOne'] as $relDef)
						$m2ms[] = $relDef;
					#- ~ $tbFields[$m2ms[0][0]]['many2many'][$m2ms[1][0]] = array($tb,$m2ms[0][1],$m2ms[1][1]);
					#- ~ $tbFields[$m2ms[1][0]]['many2many'][$m2ms[0][0]] = array($tb,$m2ms[1][1],$m2ms[0][1]);
					#- @todo manage the fact that one can be a hasOne instead of hasMany (even if it's weird to manage it that way)
					$tbFields[$m2ms[0]['modelName']]['hasMany'][$m2ms[1]['modelName']] = array(
						'modelName'       => $m2ms[1]['modelName'],
						'linkTable'       => $tb,
						#- ~ 'localField'      => $m2ms[0]['foreignField'], //must be model primaryKey
						'linkLocalField'  => $m2ms[0]['localField'],
						#- ~ 'foreignField'    => $m2ms[1]['foreignField'], // must be model PrimaryKey
						'linkForeignField'=> $m2ms[1]['localField'],
						'relType'         => 'ignored'
					);
					$tbFields[$m2ms[1]['modelName']]['hasMany'][$m2ms[0]['modelName']] = array(
						'modelName'       => $m2ms[0]['modelName'],
						'linkTable'       => $tb,
						#- ~ 'localField'      => $m2ms[1]['foreignField'], //must be model primaryKey
						'linkLocalField'  => $m2ms[1]['localField'],
						#- ~ 'foreignField'    => $m2ms[0]['foreignField'], // must be model PrimaryKey
						'linkForeignField'=> $m2ms[0]['localField'],
						'relType'         => 'ignored'
					);
				}
			}
		}
		#- generate models
		foreach($tbFields as $tbName=>$modelDesc)
			$this->createModel($tbName,$modelDesc,$dbConnectionDefined,$prefix);
	}


	function createModel($tableName,array $modelDesc=null,$dbConnectionDefined=null,$prefix='model'){
		#- try to auto detect modelDescription if not given
		if( empty($modelDesc) ){
			$modelDesc = $this->db->list_table_fields($tableName,true);
			if(! $modelDesc )
				return false;
		}

		#- lookup for primary key if not set
		if(! isset($modelDesc['PK']) ){
			foreach($modelDesc as $f){
				if((! empty($f['Key'])) && strrpos($f['Key'],'PRI')===0){
					$modelDesc['PK'] = $f['Field'];
					break;
				}
			}
			if( empty($modelDesc['PK']) )
				return false; # we don't manage models without PK
		}

		#- initVars
		$BASEmethods = $methods = $datas = $datasTypes = $one2one = $one2many = $many2many = $classDocBloc = array();
		$modelName = self::__prefix($tableName,$prefix);

		if( is_null($dbConnectionDefined) )
			$dbConnectionDefined = "'$this->connectionStr'";

		#- prepare the datas array
		foreach( $modelDesc as $k=>$f ){
			if( in_array($k,array('PK','hasMany','hasOne'),true))
				continue;
			if( strrpos($f['Key'],'PRI')===0 && $f['Default'] === null )
				$f['Default'] = 0;
			if( $f['Default']===null && strtoupper($f['Null'])==='YES' ){
				$dflt = 'null';
			}elseif( preg_match('!^\s*((tiny|big|medium|small)?int|timestamp|float|real|double|decimal)!i',$f['Type']) ){
				$dflt = empty($f['Default'])?0:$f['Default'];
			}else{
				$dflt = "'$f[Default]'";
			}

			$datas[]       = "'$f[Field]' => $dflt, //$f[Type]";
			$datasTypes[]  = "'$f[Field]' => array('Type'=>'".str_replace("'","\'",$f['Type'])."', 'Extra'=>'$f[Extra]', 'Null' =>'$f[Null]', 'Key' =>'$f[Key]', 'Default'=>$dflt),";
			if( preg_match('!^\s*ENUM\s*\((.*)\)\s*$!i',$f['Type'],$m)){
				$vals = $m[1];
				$methods[] = "
	/**
	* proposed filter to check setted value against enum possible values
	*/
	public function filter".ucFirst($f['Field'])."(\$val){
		if(! in_array(\$val,self::get".ucFirst($f['Field'])."PossibleValues())){
			\$this->appendFilterMsg('invalid $f[Field] value');
			return false;
		}
		return \$val;
	}";
				$BASEmethods[]="
	/**
	* @return array list of possible values for correponding enum fields
	*/
	static public function get".ucFirst($f['Field'])."PossibleValues(){
		return array($vals);
	}
	";
			}elseif(strpos($f['Key'],'UNI')===0){
				$ucFirst = ucFirst($f['Field']);
				$BASEmethods[] = "
	/**
	* check if the given value already exists in database
	*/
	static public function check".$ucFirst."Exists(\$v,\$returnInstance=false,\$ignoredPK=null){
		return self::modelCheckFieldDatasExists('$modelName', '$f[Field]', \$v, \$returnInstance, \$ignoredPK);
	}
	";
				$methods[]="
	/**
	* proposed filter to avoid setting an already existing values to a unique field.
	*/
	public function filter".$ucFirst."(\$val){
		if( !\$this->isUnique$ucFirst() ){
			\$this->appendFilterMsg(\"can't set $f[Field] to an already used value: \$val\");
			return false;
		}
		return \$val;
	}
	/**
	* check if the $f[Field] value is unique or already exists in database
	*/
	public function isUnique$ucFirst(\$v=null){
		return !abstractModel::modelCheckFieldDatasExists('$modelName', '$f[Field]', \$v===null?\$this->$f[Field]:\$v, false, \$this->isTemporary()?null:\$this->PK);
	}
				";
			}
		}

		#- ~ prepare hasOne
		$hasOnes[] = "// 'relName'=> array('modelName'=>'modelName','relType'=>'ignored|dependOn|requireBy',['localField'=>'fldNameIfNotPrimaryKey','foreignField'=>'fldNameIfNotPrimaryKey','foreignDefault'=>'ForeignFieldValueOnDelete'])";
		if(! empty($modelDesc['hasOne']) ){
			foreach($modelDesc['hasOne'] as $relName=>$hasOne){
				$oneStr = '';
				if( self::$forceHasOneSingular ){
					$single = array_filter((array) call_user_func(self::$singularizer,$relName));
					if( $single = array_shift($single) ){
						$relName = $single;
					}
				}
				foreach($hasOne as $k=>$v){
					$oneStr .= "\n\t\t\t'$k'=>".($v===null?'null':"'".($k==='modelName'?self::__prefix($v,$prefix):$v)."'").",";
				}
				$hasOnes[] = "'$relName' => array($oneStr\n\t\t),";
				$classDocBloc[] = "@property $hasOne[modelName] \$$relName";
				$classDocBloc[] = "@method $hasOne[modelName] get".ucfirst($relName)."()";
			}
		}
		#- ~ prepare hasMany
		$hasManys[] = "//   'relName'=> array('modelName'=>'modelName','relType'=>'ignored|dependOn|requireBy','foreignField'=>'fieldNameIfNotPrimaryKey'[,'localField'=>'fieldNameIfNotPrimaryKey','foreignDefault'=>'ForeignFieldValueOnDelete','orderBy'=>'orderBy'=>'fieldName [asc|desc][,fieldName [asc|desc],...]']),";
		$hasManys[] = "//or 'relName'=> array('modelName'=>'modelName','linkTable'=>'tableName','linkLocalField'=>'fldName','linkForeignField'=>'fldName','relType'=>'ignored|dependOn|requireBy',['orderBy'=>'fieldName [asc|desc][,fieldName [asc|desc],...]']),";
		if(! empty($modelDesc['hasMany']) ){
			foreach($modelDesc['hasMany'] as $relName=>$hasMany){
				$manyStr = '';
				foreach($hasMany as $k=>$v)
					$manyStr .= "\n\t\t\t'$k'=>".($v===null?'null':"'".($k==='modelName'?self::__prefix($v,$prefix):$v)."'").",";
				$hasManys[] = "'$relName' => array($manyStr\n\t\t),";
				$classDocBloc[] = "@property $hasMany[modelName]Collection \$$relName";
				$classDocBloc[] = "@method \$this append".ucfirst($hasMany['modelName'])."()";
				$classDocBloc[] = "@method $hasMany[modelName] appendNew".ucfirst($hasMany['modelName'])."()";
				$classDocBloc[] = "@method \$this set".ucfirst($hasMany['modelName'])."Collection()";
				$classDocBloc[] = "@method $hasMany[modelName]Collection get".ucfirst($hasMany['modelName'])."() @see getRelated() methods for more infos";
				$classDocBloc[] = "@method $hasMany[modelName]Collection getFiltered".ucfirst($hasMany['modelName'])."(\$propertyName,\$exp,\$comparisonOperator=null) sort of \$this->getRelated()->getFilteredBy() trying to be optimized";
			}
		}

		$str = "<?php
/**
* autoGenerated on ".date('Y-m-d')."
* @package models
* @subpackage $prefix
* @class BASE_$modelName".(empty($classDocBloc)?'':"\n* ".implode("\n* ",$classDocBloc))."
*/
class BASE_$modelName extends abstractModel{

	protected \$datas = array(
		".implode("\n\t\t",$datas)."
	);

	static protected \$filters = array();

	static protected \$hasOne = array(
		".implode("\n\t\t",$hasOnes)."
	);
	static protected \$hasMany = array(
		".implode("\n\t\t",$hasManys)."
	);

	/** database link */
	static protected \$dbConnectionDescriptor = $dbConnectionDefined;
	protected \$dbAdapter = null;

	static protected \$modelName = 'BASE_$modelName';
	static protected \$tableName = '$tableName';
	static protected \$primaryKey = '$modelDesc[PK]';

	/**
	* field information about type, default values and so on
	*/
	static protected \$datasDefs = array(
		".implode("\n\t\t",$datasTypes)."
	);

	/**
	* @return $modelName
	*/
	static public function getNew(){
		return abstractModel::getModelInstance('$modelName');
	}

	/**
	* @see abstractModel::getModelInstance
	* @return $modelName or null on error
	*/
	static public function getInstance(\$PK=null){
		return abstractModel::getModelInstance('$modelName',\$PK);
	}

	/**
	* @see abstractModel::getMultipleModelInstances
	* @return ".$modelName."Collection
	*/
	static public function getMultipleInstances(array \$PKs){
		return abstractModel::getMultipleModelInstances('$modelName',\$PKs);
	}

	/**
	* @see abstractModel::getFilteredModelInstances
	* @return ".$modelName."Collection
	*/
	static public function getFilteredInstances(\$filter=null){
		return abstractModel::getFilteredModelInstances('$modelName',\$filter);
	}

	/**
	* @see abstractModel::getFilteredModelInstance
	* @return $modelName instance or null if not found
	*/
	static public function getFilteredInstance(\$filter=null){
		return abstractModel::getFilteredModelInstance('$modelName',\$filter);
	}

	/**
	* @see abstractModel::getFilteredModelInstancesByField
	* @return ".$modelName."Collection
	*/
	static public function getFilteredInstancesByField(\$field,\$filterType,\$args=null){
		return abstractModel::getFilteredModelInstancesByField('$modelName',\$field,\$filterType,\$args);
	}

	/**
	* @see abstractModel::getModelInstanceFromDatas
	* @return $modelName
	*/
	static public function getInstanceFromDatas(\$datas,\$dontOverideIfExists=false,\$bypassFilters=false){
		return abstractModel::getModelInstanceFromDatas('$modelName',\$datas,\$dontOverideIfExists,\$bypassFilters);
	}

	/**
	* @see abstractModel::getAllModelInstances
	* @return ".$modelName."Collection
	*/
	static public function getAllInstances(\$withRelated=null,\$orderedBY=null){
		return abstractModel::getAllModelInstances('$modelName',\$withRelated,\$orderedBY);
	}

	/**
	* @see abstractModel::getPagedModelInstances
	* @return array array(".$modelName."Collection,string navigationstring,int totalrows);
	*/
	static public function getPagedInstances(\$filter=null,\$pageId=1,\$pageSize=10,\$withRelated=null){
		return abstractModel::getPagedModelInstances('$modelName',\$filter,\$pageId,\$pageSize,\$withRelated);
	}
	/**
	* @see abstractModel::_setModelPagedNav
	*/
	static public function _setPagedNav(array \$sliceAttrs=null){
		return abstractModel::_setModelPagedNav('$modelName',\$sliceAttrs);
	}

	/**
	* @see abstractModel::_modelGetSupportedAddons
	* @return array;
	*/
	static public function _getSupportedAddons(){
		return abstractModel::_modelGetSupportedAddons('$modelName');
	}

	/**
	* @see abstractModel::_modelGetSupportedAddons
	* @return bool
	*/
	static public function _supportsAddon(\$modelAddon,\$caseInsensitive=false){
		return abstractModel::_modelGetSupportedAddons('$modelName',\$modelAddon,\$caseInsensitive);
	}

	/**
	* @see abstractModel::getModelCount
	* @return int or false on error
	*/
	static public function getCount(\$filter=null){
		return abstractModel::getModelCount('$modelName',\$filter);
	}

	/**
	* permit to access static dynamic methods such as getByFieldnameLessThan for php >= 5.3
	* where fieldName is a modelName::\$datas key
	* (will work like this: modelName::getByFieldnameLessThan(\$value));
	* In the time waiting for this to be handled in future version of php (at this time latest stable release is still 5.2)
	* we can use it like this: modelName::__callstatic('getByFieldnameLessThan',array(\$value))
	* (with late stating binding enable we should move this to abstractModel and replace '\$modelName' by static)
	*/
	static public function __callstatic(\$m,\$a){
		#- manage common filter getter
		if(preg_match('!getBy('.implode('|',array_keys(self::\$datasDefs)).')((?:(?:Less|Greater)(?:Equal)?Than)|Between|(?:Not)?(?:Null|Equal|Like|In))$!',\$m,\$match)){
			return call_user_func('abstractModel::getFilteredModelInstancesByField','$modelName',\$match[1],\$match[2],\$a);
		}
	}

	/**
	* return a static property of the model (even protected).
	* @param string \$propName name of the static property to retrieve.
	* @return mixed
	*/
	static public function _getStatic(\$propName){
		return abstractModel::_getModelStaticProp('$modelName',\$propName);
	}

	static public function _setDbConnectionDescriptor(\$descriptor,\$detach=false){
		self::\$dbConnectionDescriptor = \$descriptor;
		if( \$detach){
			abstractModel::getModelLivingInstances('$modelName')->detach();
		}
	}

	/**
	* @return db
	*/
	static public function _getDbAdapter(){
		return abstractModel::getModelDbAdapter('$modelName');
	}

	/**
	* check if modelName has some related models definitions.
	* @param string \$relType   check only for related with the given relType (ignored|requiredBy|dependOn)
	* @param bool   \$returnDef if true return an array(hasOne=>array(relName => array relDef),hasMany=>array(relName => array relDef))
	* @return bool or array depend on \$returnDef value
	*/
	static public function hasRelDefs(\$relType=null,\$returnDef=false){
		return abstractModel::modelHasRelDefs('$modelName',\$relType,\$returnDef);
	}"
	.(empty($BASEmethods)?'':"\n\n\t###--- AUTOGENERATION PROCESS PROPOSED THOOSE ADDITIONAL METHODS ---###".implode('',$BASEmethods)).
	"
}
";
$str2 = "<?php
/**
* autoGenerated on ".date('Y-m-d')."
* @package models
* @subpackage $prefix
* @class $modelName
*/

require dirname(__file__).'/BASE_$modelName.php';

class $modelName extends BASE_$modelName{
	// define here all your user defined stuff so you don't bother updating BASE_$modelName class
	// should also be the place to redefine anything to overwrite what has been unproperly generated in BASE_$modelName class
	/**
	* list of filters used as callback when setting datas in fields.
	* this permitt to automate the process of checking datas setted.
	* array('fieldName' => array( callable filterCallBack, array additionalParams, str errorLogMsg, mixed \$errorValue=false);
	* 	minimal callback prototype look like this:
	* 	function functionName(mixed \$value)
	* 	callback have to return the sanitized value or false if this value is not valid
	* 	logMsg can be retrieved by the metod getFiltersMsgs();
	* 	additionnalParams and errorLogMsg are optionnals and can be set to null to be ignored
	* 	(or simply ignored but only if you don't mind of E_NOTICE as i definitely won't use the @ trick)
	*   \$errorValue is totally optional and permit to specify a different error return value for filter than false
	*   (can be usefull when you use filter_var to check boolean for example)
	* )
	*/
	static protected \$filters = array();

	static protected \$modelName = '$modelName';

	/** formatString to display model as string */
	static public \$__toString = '';

	/** names of modelAddons this model can manage */
	static protected \$modelAddons = array();
	/**
	* if true then the model can't have an empty primaryKey value (empty as in php empty() function)
	* so passing an empty PrimaryKey at getInstance time will result to be equal to a getNew call
	*/
	static protected \$_avoidEmptyPK = true;
	/**
	* Make use $modelName::\$_hasOne and/or $modelName::\$_hasMany if you want to override thoose defined in BASE_$modelName
	* any key set to an empty value will be dropped, others will be appended if not exists or override if exists
	* MUST BE PUBLIC (yes it's a shame) but get_class_vars presents bug in some php version
	* static public \$_hasOne = array();
	* static public \$_hasMany = array();
	*/"
	.(empty($methods)?'':"\n\n\t###--- AUTOGENERATION PROCESS PROPOSED THOOSE ADDITIONAL METHODS ---###".implode('',$methods)).
	"
}
/**
* @class ".$modelName."Collection
*/
class ".$modelName."Collection extends modelCollection{
	/**
	* you can override here default modelCollection methods
	*/
	protected \$collectionType = '$modelName';

	public function __construct(array \$modelList=null){
		parent::__construct(\$this->collectionType,\$modelList);
	}
	static public function init(array \$modelList=null){
		return new ".$modelName."Collection(\$modelList);
	}
}
";
		$baseFile = $this->outputDir."BASE_$modelName.php";
		$userFile = $this->outputDir.$modelName.'.php';
		if( (! is_file($baseFile)) || $this->onExist === 'o' )
			file_put_contents($baseFile,$str);
		elseif( $this->onExist === 'a' )
			file_put_contents($baseFile,$str,FILE_APPEND);

		if(! file_exists($this->outputDir."$modelName".'.php') )
			file_put_contents($this->outputDir."$modelName".'.php',$str2);
	}

	static protected function __prefix($name,$prefix){
		if( !empty(self::$tablePrefixes)){
			$name=preg_replace('!^('.self::$tablePrefixes.')!','',$name);
		}
		return $prefix.(preg_match('![a-z]$!i',$prefix)?ucFirst($name):$name);
	}

	static public function singular($plural){
		$res = array();
		if( preg_match("!ies$!",$plural))
			$res[] = substr($plural,0,-3).'y';
		if( preg_match("!(s|x)$!",$plural) )
			$res[] = substr($plural,0,-1);
		return $res;
	}

}