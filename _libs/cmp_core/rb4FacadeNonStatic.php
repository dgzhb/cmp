<?php
//NOTES:
//从Redbean (v4) 的Facade抄过来，做了修改变成非静态类（便于一个Facade对应一个db），
//主要是把 self::$ 改为 $this-> （但注意 ->$method的就不能乱换）
//还有一些 :: 的微处理
namespace RedBeanPHP {

	use RedBeanPHP\ToolBox as ToolBox;
	use RedBeanPHP\OODB as OODB;
	use RedBeanPHP\QueryWriter as QueryWriter;
	use RedBeanPHP\Adapter\DBAdapter as DBAdapter;
	use RedBeanPHP\AssociationManager as AssociationManager;
	use RedBeanPHP\TagManager as TagManager;
	use RedBeanPHP\DuplicationManager as DuplicationManager;
	use RedBeanPHP\LabelMaker as LabelMaker;
	use RedBeanPHP\Finder as Finder;
	use RedBeanPHP\RedException\SQL as SQL;
	use RedBeanPHP\RedException\Security as Security;
	use RedBeanPHP\Logger as Logger;
	use RedBeanPHP\Logger\RDefault as RDefault;
	use RedBeanPHP\OODBBean as OODBBean;
	use RedBeanPHP\SimpleModel as SimpleModel;
	use RedBeanPHP\SimpleModelHelper as SimpleModelHelper;
	use RedBeanPHP\Adapter as Adapter;
	use RedBeanPHP\QueryWriter\AQueryWriter as AQueryWriter;
	use RedBeanPHP\RedException as RedException;
	use RedBeanPHP\BeanHelper\SimpleFacadeBeanHelper as SimpleFacadeBeanHelper;
	use RedBeanPHP\Driver\RPDO as RPDO;

	class rb4FacadeNonStatic
	{
		/**
		 * RedBeanPHP version constant.
		 */
		const C_REDBEANPHP_VERSION = '4.0';

		/**
		 * @var array
		 */
		private $toolboxes = array();

		/**
		 * @var ToolBox
		 */
		private $toolbox;

		/**
		 * @var OODB
		 */
		private $redbean;

		/**
		 * @var QueryWriter
		 */
		private $writer;

		/**
		 * @var DBAdapter
		 */
		private $adapter;

		/**
		 * @var AssociationManager
		 */
		private $associationManager;

		/**
		 * @var TagManager
		 */
		private $tagManager;

		/**
		 * @var DuplicationManager
		 */
		private $duplicationManager;

		/**
		 * @var LabelMaker
		 */
		private $labelMaker;

		/**
		 * @var Finder
		 */
		private $finder;

		/**
		 * @var string
		 */
		private $currentDB = '';

		/**
		 * @var array
		 */
		private $plugins = array();

		/**
		 * Internal Query function, executes the desired query. Used by
		 * all facade query functions. This keeps things DRY.
		 *
		 * @throws SQL
		 *
		 * @param string $method   desired query method (i.e. 'cell', 'col', 'exec' etc..)
		 * @param string $sql      the sql you want to execute
		 * @param array  $bindings array of values to be bound to query statement
		 *
		 * @return array
		 */
		private function query( $method, $sql, $bindings )
		{
			if ( !$this->redbean->isFrozen() ) {
				try {
					$rs = $this->adapter->$method( $sql, $bindings );
				} catch ( SQL $exception ) {
					if ( $this->writer->sqlStateIn( $exception->getSQLState(),
						array(
							QueryWriter::C_SQLSTATE_NO_SUCH_COLUMN,
							QueryWriter::C_SQLSTATE_NO_SUCH_TABLE )
						)
					) {
						return ( $method === 'getCell' ) ? NULL : array();
					} else {
						throw $exception;
					}
				}

				return $rs;
			} else {
				return $this->adapter->$method( $sql, $bindings );
			}
		}

		/**
		 * Returns the RedBeanPHP version string.
		 * The RedBeanPHP version string always has the same format "X.Y"
		 * where X is the major version number and Y is the minor version number.
		 * Point releases are not mentioned in the version string.
		 *
		 * @return string
		 */
		public function getVersion()
		{
			return $this->C_REDBEANPHP_VERSION;
		}

		/**
		 * Kickstarts redbean for you. This method should be called before you start using
		 * RedBean. The Setup() method can be called without any arguments, in this case it will
		 * try to create a SQLite database in /tmp called red.db (this only works on UNIX-like systems).
		 *
		 * @param string  $dsn      Database connection string
		 * @param string  $username Username for database
		 * @param string  $password Password for database
		 * @param boolean $frozen   TRUE if you want to setup in frozen mode
		 *
		 * @return ToolBox
		 */
		public function setup( $dsn = NULL, $username = NULL, $password = NULL, $frozen = FALSE )
		{
			if ( is_null( $dsn ) ) {
				$dsn = 'sqlite:/' . sys_get_temp_dir() . '/red.db';
			}

			$this->addDatabase( 'default', $dsn, $username, $password, $frozen );
			$this->selectDatabase( 'default' );

			return $this->toolbox;
		}

		/**
		 * Starts a transaction within a closure (or other valid callback).
		 * If an\Exception is thrown inside, the operation is automatically rolled back.
		 * If no\Exception happens, it commits automatically.
		 * It also supports (simulated) nested transactions (that is useful when
		 * you have many methods that needs transactions but are unaware of
		 * each other).
		 * ex:
		 *        $from = 1;
		 *        $to = 2;
		 *        $amount = 300;
		 *
		 *        $this->transaction(function() use($from, $to, $amount)
		 *        {
		 *            $accountFrom = $this->load('account', $from);
		 *            $accountTo = $this->load('account', $to);
		 *
		 *            $accountFrom->money -= $amount;
		 *            $accountTo->money += $amount;
		 *
		 *            $this->store($accountFrom);
		 *            $this->store($accountTo);
		 *      });
		 *
		 * @param callable $callback Closure (or other callable) with the transaction logic
		 *
		 * @throws Security
		 *
		 * @return mixed
		 *
		 */
		public function transaction( $callback )
		{
			if ( !is_callable( $callback ) ) {
				throw new RedException( '$this->transaction needs a valid callback.' );
			}

			$depth = 0;
			$result = null;
			try {
				if ( $depth == 0 ) {
					$this->begin();
				}
				$depth++;
				$result = call_user_func( $callback ); //maintain 5.2 compatibility
				$depth--;
				if ( $depth == 0 ) {
					$this->commit();
				}
			} catch (\Exception $exception ) {
				$depth--;
				if ( $depth == 0 ) {
					$this->rollback();
				}
				throw $exception;
			}
			return $result;
		}

		/**
		 * Adds a database to the facade, afterwards you can select the database using
		 * selectDatabase($key), where $key is the name you assigned to this database.
		 *
		 * Usage:
		 *
		 * $this->addDatabase( 'database-1', 'sqlite:/tmp/db1.txt' );
		 * $this->selectDatabase( 'database-1' ); //to select database again
		 *
		 * This method allows you to dynamically add (and select) new databases
		 * to the facade. Adding a database with the same key will cause an exception.
		 *
		 * @param string      $key    ID for the database
		 * @param string      $dsn    DSN for the database
		 * @param string      $user   User for connection
		 * @param NULL|string $pass   Password for connection
		 * @param bool        $frozen Whether this database is frozen or not
		 *
		 * @return void
		 */
		public function addDatabase( $key, $dsn, $user = NULL, $pass = NULL, $frozen = FALSE )
		{
			if ( isset( $this->toolboxes[$key] ) ) {
				throw new RedException( 'A database has already be specified for this key.' );
			}

			if ( is_object($dsn) ) {
				$db  = new RPDO( $dsn );
				$dbType = $db->getDatabaseType();
			} else {
				$db = new RPDO( $dsn, $user, $pass, TRUE );
				$dbType = substr( $dsn, 0, strpos( $dsn, ':' ) );
			}
			#if(!$wkey) throw new \Exception("Empty dsn type wkey");
			$wkey = trim( strtolower( $dbType ) );
			if(!$dbType)trigger_error("Empty db type $dbType",E_USER_ERROR);

			$adapter = new DBAdapter( $db );

			$writers     = array('pgsql'  => 'PostgreSQL',
				'sqlite' => 'SQLiteT',
				'cubrid' => 'CUBRID',
				'mysql'  => 'MySQL');

			//if( !isset( $writers[$wkey] ) )throw new \Exception("Unsupported DSN=$wkey");
			if(!isset( $writers[$wkey]))trigger_error('Unsupported DSN: '.$wkey ,E_USER_ERROR);
			$writerClass = "\\RedBeanPHP\\QueryWriter\\".$writers[$wkey];
			$writer      = new $writerClass( $adapter );
			$redbean     = new OODB( $writer );

			$redbean->freeze( ( $frozen === TRUE ) );
			$this->toolboxes[$key] = new ToolBox( $redbean, $adapter, $writer );
		}

		/**
		 * Selects a different database for the Facade to work with.
		 * If you use the $this->setup() you don't need this method. This method is meant
		 * for multiple database setups. This method selects the database identified by the
		 * database ID ($key). Use addDatabase() to add a new database, which in turn
		 * can be selected using selectDatabase(). If you use $this->setup(), the resulting
		 * database will be stored under key 'default', to switch (back) to this database
		 * use $this->selectDatabase( 'default' ). This method returns TRUE if the database has been
		 * switched and FALSE otherwise (for instance if you already using the specified database).
		 *
		 * @param  string $key Key of the database to select
		 *
		 * @return boolean
		 */
		public function selectDatabase( $key )
		{
			if ( $this->currentDB === $key ) {
				return FALSE;
			}

			$this->configureFacadeWithToolbox( $this->toolboxes[$key] );
			$this->currentDB = $key;

			return TRUE;
		}

		/**
		 * Toggles DEBUG mode.
		 * In Debug mode all SQL that happens under the hood will
		 * be printed to the screen or logged by provided logger.
		 * If no database connection has been configured using $this->setup() or
		 * $this->selectDatabase() this method will throw an exception.
		 * Returns the attached logger instance.
		 *
		 * @param boolean $tf
		 * @param integer $mode (0 = to STDOUT, 1 = to ARRAY)
		 *
		 * @throws Security
		 *
		 * @return Logger\RDefault
		 */
		public function debug( $tf = TRUE, $mode = 0 )
		{
			$logger = new RDefault;

			if ( !isset( $this->adapter ) ) {
				throw new RedException( 'Use $this->setup() first.' );
			}
			$logger->setMode($mode);
			$this->adapter->getDatabase()->setDebugMode( $tf, $logger );

			return $logger;
		}

		/**
		 * Inspects the database schema. If you pass the type of a bean this
		 * method will return the fields of its table in the database.
		 * The keys of this array will be the field names and the values will be
		 * the column types used to store their values.
		 * If no type is passed, this method returns a list of all tables in the database.
		 *
		 * @param string $type Type of bean (i.e. table) you want to inspect
		 *
		 * @return array
		 */
		public function inspect( $type = NULL )
		{
			return ($type === NULL) ? $this->writer->getTables() : $this->writer->getColumns( $type );
		}

		/**
		 * Stores a bean in the database. This method takes a
		 * OODBBean Bean Object $bean and stores it
		 * in the database. If the database schema is not compatible
		 * with this bean and RedBean runs in fluid mode the schema
		 * will be altered to store the bean correctly.
		 * If the database schema is not compatible with this bean and
		 * RedBean runs in frozen mode it will throw an exception.
		 * This function returns the primary key ID of the inserted
		 * bean.
		 *
		 * The return value is an integer if possible. If it is not possible to
		 * represent the value as an integer a string will be returned.
		 *
		 * @param OODBBean|SimpleModel $bean bean to store
		 *
		 * @return integer|string
		 *
		 * @throws Security
		 */
		public function store( $bean )
		{
			return $this->redbean->store( $bean );
		}

		/**
		 * Toggles fluid or frozen mode. In fluid mode the database
		 * structure is adjusted to accomodate your objects. In frozen mode
		 * this is not the case.
		 *
		 * You can also pass an array containing a selection of frozen types.
		 * Let's call this chilly mode, it's just like fluid mode except that
		 * certain types (i.e. tables) aren't touched.
		 *
		 * @param boolean|array $trueFalse
		 */
		public function freeze( $tf = TRUE )
		{
			$this->redbean->freeze( $tf );
		}

		/**
		 * Loads multiple types of beans with the same ID.
		 * This might look like a strange method, however it can be useful
		 * for loading a one-to-one relation.
		 *
		 * Usage:
		 * list($author, $bio) = $this->load('author, bio', $id);
		 *
		 * @param string|array $types
		 * @param mixed        $id
		 *
		 * @return OODBBean
		 */
		public function loadMulti( $types, $id )
		{
			if ( is_string( $types ) ) {
				$types = explode( ',', $types );
			}

			if ( !is_array( $types ) ) {
				return array();
			}

			foreach ( $types as $k => $typeItem ) {
				$types[$k] = $this->redbean->load( $typeItem, $id );
			}

			return $types;
		}

		/**
		 * Loads a bean from the object database.
		 * It searches for a OODBBean Bean Object in the
		 * database. It does not matter how this bean has been stored.
		 * RedBean uses the primary key ID $id and the string $type
		 * to find the bean. The $type specifies what kind of bean you
		 * are looking for; this is the same type as used with the
		 * dispense() function. If RedBean finds the bean it will return
		 * the OODB Bean object; if it cannot find the bean
		 * RedBean will return a new bean of type $type and with
		 * primary key ID 0. In the latter case it acts basically the
		 * same as dispense().
		 *
		 * Important note:
		 * If the bean cannot be found in the database a new bean of
		 * the specified type will be generated and returned.
		 *
		 * @param string  $type type of bean you want to load
		 * @param integer $id   ID of the bean you want to load
		 *
		 * @throws SQL
		 *
		 * @return OODBBean
		 */
		public function load( $type, $id )
		{
			return $this->redbean->load( $type, $id );
		}

		/**
		 * Removes a bean from the database.
		 * This function will remove the specified OODBBean
		 * Bean Object from the database.
		 *
		 * @param OODBBean|SimpleModel $bean bean you want to remove from database
		 *
		 * @return void
		 *
		 * @throws Security
		 */
		public function trash( $bean )
		{
			$this->redbean->trash( $bean );
		}

		/**
		 * Dispenses a new RedBean OODB Bean for use with
		 * the rest of the methods.
		 *
		 * @param string|array $typeOrBeanArray   type or bean array to import
		 * @param integer      $number            number of beans to dispense
		 * @param boolean	     $alwaysReturnArray if TRUE always returns the result as an array
		 *
		 * @return array|OODBBean
		 *
		 * @throws Security
		 */
		public function dispense( $typeOrBeanArray, $num = 1, $alwaysReturnArray = FALSE )
		{
			if ( is_array($typeOrBeanArray) ) {
				if ( !isset( $typeOrBeanArray['_type'] ) ) throw new RedException('Missing _type field.');
				$import = $typeOrBeanArray;
				$type = $import['_type'];
				unset( $import['_type'] );
			} else {
				$type = $typeOrBeanArray;
			}

			if
				//( !preg_match( '/^[a-z0-9]+$/', $type ) )//original
				( !preg_match( '/^[a-zA-Z0-9_]+$/', $type ) )//hack
			{
				throw new RedException( 'Invalid type: ' . $type );
			}

			$beanOrBeans = $this->redbean->dispense( $type, $num, $alwaysReturnArray );

			if ( isset( $import ) ) {
				$beanOrBeans->import( $import );
			}

			return $beanOrBeans;
		}

		/**
		 * Takes a comma separated list of bean types
		 * and dispenses these beans. For each type in the list
		 * you can specify the number of beans to be dispensed.
		 *
		 * Usage:
		 *
		 * list($book, $page, $text) = $this->dispenseAll('book,page,text');
		 *
		 * This will dispense a book, a page and a text. This way you can
		 * quickly dispense beans of various types in just one line of code.
		 *
		 * Usage:
		 *
		 * list($book, $pages) = $this->dispenseAll('book,page*100');
		 *
		 * This returns an array with a book bean and then another array
		 * containing 100 page beans.
		 *
		 * @param string  $order      a description of the desired dispense order using the syntax above
		 * @param boolean $onlyArrays return only arrays even if amount < 2
		 *
		 * @return array
		 */
		public function dispenseAll( $order, $onlyArrays = FALSE )
		{

			$list = array();

			foreach( explode( ',', $order ) as $order ) {
				if ( strpos( $order, '*' ) !== false ) {
					list( $type, $amount ) = explode( '*', $order );
				} else {
					$type   = $order;
					$amount = 1;
				}

				$list[] = $this->dispense( $type, $amount, $onlyArrays );
			}

			return $list;
		}

		/**
		 * Convience method. Tries to find beans of a certain type,
		 * if no beans are found, it dispenses a bean of that type.
		 *
		 * @param  string $type     type of bean you are looking for
		 * @param  string $sql      SQL code for finding the bean
		 * @param  array  $bindings parameters to bind to SQL
		 *
		 * @return array
		 */
		public function findOrDispense( $type, $sql = NULL, $bindings = array() )
		{
			return $this->finder->findOrDispense( $type, $sql, $bindings );
		}

		/**
		 * Finds a bean using a type and a where clause (SQL).
		 * As with most Query tools in RedBean you can provide values to
		 * be inserted in the SQL statement by populating the value
		 * array parameter; you can either use the question mark notation
		 * or the slot-notation (:keyname).
		 *
		 * @param string $type     type   the type of bean you are looking for
		 * @param string $sql      sql    SQL query to find the desired bean, starting right after WHERE clause
		 * @param array  $bindings values array of values to be bound to parameters in query
		 *
		 * @return array
		 */
		public function find( $type, $sql = NULL, $bindings = array() )
		{
			return $this->finder->find( $type, $sql, $bindings );
		}

		/**
		 * @see $this->find
		 *      The findAll() method differs from the find() method in that it does
		 *      not assume a WHERE-clause, so this is valid:
		 *
		 * $this->findAll('person',' ORDER BY name DESC ');
		 *
		 * Your SQL does not have to start with a valid WHERE-clause condition.
		 *
		 * @param string $type     type   the type of bean you are looking for
		 * @param string $sql      sql    SQL query to find the desired bean, starting right after WHERE clause
		 * @param array  $bindings values array of values to be bound to parameters in query
		 *
		 * @return array
		 */
		public function findAll( $type, $sql = NULL, $bindings = array() )
		{
			return $this->finder->find( $type, $sql, $bindings );
		}

		/**
		 * @see $this->find
		 * The variation also exports the beans (i.e. it returns arrays).
		 *
		 * @param string $type     type   the type of bean you are looking for
		 * @param string $sql      sql    SQL query to find the desired bean, starting right after WHERE clause
		 * @param array  $bindings values array of values to be bound to parameters in query
		 *
		 * @return array
		 */
		public function findAndExport( $type, $sql = NULL, $bindings = array() )
		{
			return $this->finder->findAndExport( $type, $sql, $bindings );
		}

		/**
		 * @see $this->find
		 * This variation returns the first bean only.
		 *
		 * @param string $type     type   the type of bean you are looking for
		 * @param string $sql      sql    SQL query to find the desired bean, starting right after WHERE clause
		 * @param array  $bindings values array of values to be bound to parameters in query
		 *
		 * @return OODBBean
		 */
		public function findOne( $type, $sql = NULL, $bindings = array() )
		{
			return $this->finder->findOne( $type, $sql, $bindings );
		}

		/**
		 * @see $this->find
		 * This variation returns the last bean only.
		 *
		 * @param string $type     type   the type of bean you are looking for
		 * @param string $sql      sql    SQL query to find the desired bean, starting right after WHERE clause
		 * @param array  $bindings values array of values to be bound to parameters in query
		 *
		 * @return OODBBean
		 */
		public function findLast( $type, $sql = NULL, $bindings = array() )
		{
			return $this->finder->findLast( $type, $sql, $bindings );
		}

		/**
		 * Returns an array of beans. Pass a type and a series of ids and
		 * this method will bring you the corresponding beans.
		 *
		 * important note: Because this method loads beans using the load()
		 * function (but faster) it will return empty beans with ID 0 for
		 * every bean that could not be located. The resulting beans will have the
		 * passed IDs as their keys.
		 *
		 * @param string $type type of beans
		 * @param array  $ids  ids to load
		 *
		 * @return array
		 */
		public function batch( $type, $ids )
		{
			return $this->redbean->batch( $type, $ids );
		}

		/**
		 * @see $this->batch
		 *
		 * Alias for batch(). Batch method is older but since we added so-called *All
		 * methods like storeAll, trashAll, dispenseAll and findAll it seemed logical to
		 * improve the consistency of the Facade API and also add an alias for batch() called
		 * loadAll.
		 *
		 * @param string $type type of beans
		 * @param array  $ids  ids to load
		 *
		 * @return array
		 */
		public function loadAll( $type, $ids )
		{
			return $this->redbean->batch( $type, $ids );
		}

		/**
		 * Convenience function to execute Queries directly.
		 * Executes SQL.
		 *
		 * @param string $sql       sql    SQL query to execute
		 * @param array  $bindings  values a list of values to be bound to query parameters
		 *
		 * @return integer
		 */
		public function exec( $sql, $bindings = array() )
		{
			return $this->query( 'exec', $sql, $bindings );
		}

		/**
		 * Convenience function to execute Queries directly.
		 * Executes SQL.
		 *
		 * @param string $sql       sql    SQL query to execute
		 * @param array  $bindings  values a list of values to be bound to query parameters
		 *
		 * @return array
		 */
		public function getAll( $sql, $bindings = array() )
		{
			return $this->query( 'get', $sql, $bindings );
		}

		/**
		 * Convenience function to execute Queries directly.
		 * Executes SQL.
		 *
		 * @param string $sql       sql    SQL query to execute
		 * @param array  $bindings  values a list of values to be bound to query parameters
		 *
		 * @return string
		 */
		public function getCell( $sql, $bindings = array() )
		{
			return $this->query( 'getCell', $sql, $bindings );
		}

		/**
		 * Convenience function to execute Queries directly.
		 * Executes SQL.
		 *
		 * @param string $sql       sql    SQL query to execute
		 * @param array  $bindings  values a list of values to be bound to query parameters
		 *
		 * @return array
		 */
		public function getRow( $sql, $bindings = array() )
		{
			return $this->query( 'getRow', $sql, $bindings );
		}

		/**
		 * Convenience function to execute Queries directly.
		 * Executes SQL.
		 *
		 * @param string $sql       sql    SQL query to execute
		 * @param array  $bindings  values a list of values to be bound to query parameters
		 *
		 * @return array
		 */
		public function getCol( $sql, $bindings = array() )
		{
			return $this->query( 'getCol', $sql, $bindings );
		}

		/**
		 * Convenience function to execute Queries directly.
		 * Executes SQL.
		 * Results will be returned as an associative array. The first
		 * column in the select clause will be used for the keys in this array and
		 * the second column will be used for the values. If only one column is
		 * selected in the query, both key and value of the array will have the
		 * value of this field for each row.
		 *
		 * @param string $sql       sql    SQL query to execute
		 * @param array  $bindings  values a list of values to be bound to query parameters
		 *
		 * @return array
		 */
		public function getAssoc( $sql, $bindings = array() )
		{
			return $this->query( 'getAssoc', $sql, $bindings );
		}

		/**
		 * Convenience function to execute Queries directly.
		 * Executes SQL.
		 * Results will be returned as an associative array indexed by the first
		 * column in the select.
		 *
		 * @param string $sql       sql    SQL query to execute
		 * @param array  $bindings  values a list of values to be bound to query parameters
		 *
		 * @return array
		 */
		public function getAssocRow( $sql, $bindings = array() )
		{
			return $this->query( 'getAssocRow', $sql, $bindings );
		}

		/**
		 * Makes a copy of a bean. This method makes a deep copy
		 * of the bean.The copy will have the following features.
		 * - All beans in own-lists will be duplicated as well
		 * - All references to shared beans will be copied but not the shared beans themselves
		 * - All references to parent objects (_id fields) will be copied but not the parents themselves
		 * In most cases this is the desired scenario for copying beans.
		 * This function uses a trail-array to prevent infinite recursion, if a recursive bean is found
		 * (i.e. one that already has been processed) the ID of the bean will be returned.
		 * This should not happen though.
		 *
		 * Note:
		 * This function does a reflectional database query so it may be slow.
		 *
		 * @param OODBBean $bean  bean to be copied
		 * @param array            $trail for internal usage, pass array()
		 * @param boolean          $pid   for internal usage
		 *
		 * @return array
		 */
		public function dup( $bean, $trail = array(), $pid = FALSE, $filters = array() )
		{
			$this->duplicationManager->setFilters( $filters );

			return $this->duplicationManager->dup( $bean, $trail, $pid );
		}

		/**
		 * Exports a collection of beans. Handy for XML/JSON exports with a
		 * Javascript framework like Dojo or ExtJS.
		 * What will be exported:
		 * - contents of the bean
		 * - all own bean lists (recursively)
		 * - all shared beans (not THEIR own lists)
		 *
		 * @param    array|OODBBean $beans   beans to be exported
		 * @param    boolean                $parents whether you want parent beans to be exported
		 * @param   array                   $filters whitelist of types
		 *
		 * @return    array
		 */
		public function exportAll( $beans, $parents = FALSE, $filters = array() )
		{
			return $this->duplicationManager->exportAll( $beans, $parents, $filters );
		}

		/**
		 * Converts a series of rows to beans.
		 * This method converts a series of rows to beans.
		 * The type of the desired output beans can be specified in the
		 * first parameter. The second parameter is meant for the database
		 * result rows.
		 *
		 * @param string $type type of beans to produce
		 * @param array  $rows must contain an array of array
		 *
		 * @return array
		 */
		public function convertToBeans( $type, $rows )
		{
			return $this->redbean->convertToBeans( $type, $rows );
		}

		/**
		 * Part of RedBeanPHP Tagging API.
		 * Tests whether a bean has been associated with one ore more
		 * of the listed tags. If the third parameter is TRUE this method
		 * will return TRUE only if all tags that have been specified are indeed
		 * associated with the given bean, otherwise FALSE.
		 * If the third parameter is FALSE this
		 * method will return TRUE if one of the tags matches, FALSE if none
		 * match.
		 *
		 * @param  OODBBean $bean bean to check for tags
		 * @param  array            $tags list of tags
		 * @param  boolean          $all  whether they must all match or just some
		 *
		 * @return boolean
		 */
		public function hasTag( $bean, $tags, $all = FALSE )
		{
			return $this->tagManager->hasTag( $bean, $tags, $all );
		}

		/**
		 * Part of RedBeanPHP Tagging API.
		 * Removes all specified tags from the bean. The tags specified in
		 * the second parameter will no longer be associated with the bean.
		 *
		 * @param  OODBBean $bean    tagged bean
		 * @param  array            $tagList list of tags (names)
		 *
		 * @return void
		 */
		public function untag( $bean, $tagList )
		{
			$this->tagManager->untag( $bean, $tagList );
		}

		/**
		 * Part of RedBeanPHP Tagging API.
		 * Tags a bean or returns tags associated with a bean.
		 * If $tagList is NULL or omitted this method will return a
		 * comma separated list of tags associated with the bean provided.
		 * If $tagList is a comma separated list (string) of tags all tags will
		 * be associated with the bean.
		 * You may also pass an array instead of a string.
		 *
		 * @param OODBBean $bean    bean
		 * @param mixed            $tagList tags
		 *
		 * @return string
		 */
		public function tag( OODBBean $bean, $tagList = NULL )
		{
			return $this->tagManager->tag( $bean, $tagList );
		}

		/**
		 * Part of RedBeanPHP Tagging API.
		 * Adds tags to a bean.
		 * If $tagList is a comma separated list of tags all tags will
		 * be associated with the bean.
		 * You may also pass an array instead of a string.
		 *
		 * @param OODBBean $bean    bean
		 * @param array            $tagList list of tags to add to bean
		 *
		 * @return void
		 */
		public function addTags( OODBBean $bean, $tagList )
		{
			$this->tagManager->addTags( $bean, $tagList );
		}

		/**
		 * Part of RedBeanPHP Tagging API.
		 * Returns all beans that have been tagged with one of the tags given.
		 *
		 * @param string $beanType type of bean you are looking for
		 * @param array  $tagList  list of tags to match
		 *
		 * @return array
		 */
		public function tagged( $beanType, $tagList )
		{
			return $this->tagManager->tagged( $beanType, $tagList );
		}

		/**
		 * Part of RedBeanPHP Tagging API.
		 * Returns all beans that have been tagged with ALL of the tags given.
		 *
		 * @param string $beanType type of bean you are looking for
		 * @param array  $tagList  list of tags to match
		 *
		 * @return array
		 */
		public function taggedAll( $beanType, $tagList )
		{
			return $this->tagManager->taggedAll( $beanType, $tagList );
		}

		/**
		 * Wipes all beans of type $beanType.
		 *
		 * @param string $beanType type of bean you want to destroy entirely
		 *
		 * @return boolean
		 */
		public function wipe( $beanType )
		{
			return $this->redbean->wipe( $beanType );
		}

		/**
		 * Counts the number of beans of type $type.
		 * This method accepts a second argument to modify the count-query.
		 * A third argument can be used to provide bindings for the SQL snippet.
		 *
		 * @param string $type     type of bean we are looking for
		 * @param string $addSQL   additional SQL snippet
		 * @param array  $bindings parameters to bind to SQL
		 *
		 * @return integer
		 *
		 * @throws SQL
		 */
		public function count( $type, $addSQL = '', $bindings = array() )
		{
			return $this->redbean->count( $type, $addSQL, $bindings );
		}

		/**
		 * Configures the facade, want to have a new Writer? A new Object Database or a new
		 * Adapter and you want it on-the-fly? Use this method to hot-swap your facade with a new
		 * toolbox.
		 *
		 * @param ToolBox $tb toolbox
		 *
		 * @return ToolBox
		 */
		public function configureFacadeWithToolbox( ToolBox $tb )
		{
			$oldTools                 = $this->toolbox;

			$this->toolbox            = $tb;

			$this->writer             = $this->toolbox->getWriter();
			$this->adapter            = $this->toolbox->getDatabaseAdapter();
			$this->redbean            = $this->toolbox->getRedBean();
			$this->finder             = new Finder( $this->toolbox );

			$this->associationManager = new AssociationManager( $this->toolbox );

			$this->redbean->setAssociationManager( $this->associationManager );

			$this->labelMaker         = new LabelMaker( $this->toolbox );

			$helper                   = new SimpleModelHelper();

			$helper->attachEventListeners( $this->redbean );

			$this->redbean->setBeanHelper( new SimpleFacadeBeanHelper );

			$this->associationManager->addEventListener( 'delete', $helper );

			$this->duplicationManager = new DuplicationManager( $this->toolbox );
			$this->tagManager         = new TagManager( $this->toolbox );

			return $oldTools;
		}

		/**
		 * Facade Convience method for adapter transaction system.
		 * Begins a transaction.
		 *
		 * @return bool
		 */
		public function begin()
		{
			if ( !$this->redbean->isFrozen() ) return FALSE;

			$this->adapter->startTransaction();

			return TRUE;
		}

		/**
		 * Facade Convience method for adapter transaction system.
		 * Commits a transaction.
		 *
		 * @return bool
		 */
		public function commit()
		{
			if ( !$this->redbean->isFrozen() ) return FALSE;

			$this->adapter->commit();

			return TRUE;
		}

		/**
		 * Facade Convience method for adapter transaction system.
		 * Rolls back a transaction.
		 *
		 * @return bool
		 */
		public function rollback()
		{
			if ( !$this->redbean->isFrozen() ) return FALSE;

			$this->adapter->rollback();

			return TRUE;
		}

		/**
		 * Returns a list of columns. Format of this array:
		 * array( fieldname => type )
		 * Note that this method only works in fluid mode because it might be
		 * quite heavy on production servers!
		 *
		 * @param  string $table   name of the table (not type) you want to get columns of
		 *
		 * @return array
		 */
		public function getColumns( $table )
		{
			return $this->writer->getColumns( $table );
		}

		/**
		 * Generates question mark slots for an array of values.
		 *
		 * @param array $array
		 *
		 * @return string
		 */
		public function genSlots( $array )
		{
			return ( count( $array ) ) ? implode( ',', array_fill( 0, count( $array ), '?' ) ) : '';
		}

		/**
		 * Nukes the entire database.
		 * This will remove all schema structures from the database.
		 * Only works in fluid mode. Be careful with this method.
		 *
		 * @warning dangerous method, will remove all tables, columns etc.
		 *
		 * @return void
		 */
		public function nuke()
		{
			if ( !$this->redbean->isFrozen() ) {
				$this->writer->wipeAll();
			}
		}

		/**
		 * Short hand function to store a set of beans at once, IDs will be
		 * returned as an array. For information please consult the $this->store()
		 * function.
		 * A loop saver.
		 *
		 * @param array $beans list of beans to be stored
		 *
		 * @return array
		 */
		public function storeAll( $beans )
		{
			$ids = array();
			foreach ( $beans as $bean ) {
				$ids[] = $this->store( $bean );
			}

			return $ids;
		}

		/**
		 * Short hand function to trash a set of beans at once.
		 * For information please consult the $this->trash() function.
		 * A loop saver.
		 *
		 * @param array $beans list of beans to be trashed
		 *
		 * @return void
		 */
		public function trashAll( $beans )
		{
			foreach ( $beans as $bean ) {
				$this->trash( $bean );
			}
		}

		/**
		 * Toggles Writer Cache.
		 * Turns the Writer Cache on or off. The Writer Cache is a simple
		 * query based caching system that may improve performance without the need
		 * for cache management. This caching system will cache non-modifying queries
		 * that are marked with special SQL comments. As soon as a non-marked query
		 * gets executed the cache will be flushed. Only non-modifying select queries
		 * have been marked therefore this mechanism is a rather safe way of caching, requiring
		 * no explicit flushes or reloads. Of course this does not apply if you intend to test
		 * or simulate concurrent querying.
		 *
		 * @param boolean $yesNo TRUE to enable cache, FALSE to disable cache
		 *
		 * @return void
		 */
		public function useWriterCache( $yesNo )
		{
			$this->getWriter()->setUseCache( $yesNo );
		}


		/**
		 * A label is a bean with only an id, type and name property.
		 * This function will dispense beans for all entries in the array. The
		 * values of the array will be assigned to the name property of each
		 * individual bean.
		 *
		 * @param string $type   type of beans you would like to have
		 * @param array  $labels list of labels, names for each bean
		 *
		 * @return array
		 */
		public function dispenseLabels( $type, $labels )
		{
			return $this->labelMaker->dispenseLabels( $type, $labels );
		}

		/**
		 * Generates and returns an ENUM value. This is how RedBeanPHP handles ENUMs.
		 * Either returns a (newly created) bean respresenting the desired ENUM
		 * value or returns a list of all enums for the type.
		 *
		 * To obtain (and add if necessary) an ENUM value:
		 *
		 * $tea->flavour = $this->enum( 'flavour:apple' );
		 *
		 * Returns a bean of type 'flavour' with  name = apple.
		 * This will add a bean with property name (set to APPLE) to the database
		 * if it does not exist yet.
		 *
		 * To obtain all flavours:
		 *
		 * $this->enum('flavour');
		 *
		 * To get a list of all flavour names:
		 *
		 * $this->gatherLabels( $this->enum( 'flavour' ) );
		 *
		 * @param string $enum either type or type-value
		 *
		 * @return array|OODBBean
		 */
		public function enum( $enum )
		{
			return $this->labelMaker->enum( $enum );
		}

		/**
		 * Gathers labels from beans. This function loops through the beans,
		 * collects the values of the name properties of each individual bean
		 * and stores the names in a new array. The array then gets sorted using the
		 * default sort function of PHP (sort).
		 *
		 * @param array $beans list of beans to loop
		 *
		 * @return array
		 */
		public function gatherLabels( $beans )
		{
			return $this->labelMaker->gatherLabels( $beans );
		}

		/**
		 * Closes the database connection.
		 *
		 * @return void
		 */
		public function close()
		{
			if ( isset( $this->adapter ) ) {
				$this->adapter->close();
			}
		}

		/**
		 * Simple convenience function, returns ISO date formatted representation
		 * of $time.
		 *
		 * @param mixed $time UNIX timestamp
		 *
		 * @return string
		 */
		public function rb4_isoDate( $time = NULL )
		{
			//throw new Exception("isoDate is deprecated for 32bit-2038-bug");
			return my_isoDate($time);
			//if ( !$time ) {
			//	$time = time();
			//}

			//return @date( 'Y-m-d', $time );
		}

		/**
		 * Simple convenience function, returns ISO date time
		 * formatted representation
		 * of $time.
		 *
		 * @param mixed $time UNIX timestamp
		 *
		 * @return string
		 */
		public function rb4_isoDateTime( $time = NULL )
		{
			//throw new Exception("isoDateTime is deprecated for 32bit-2038-bug");
			return my_isoDateTime($time);
			//if ( !$time ) $time = time();

			//return @date( 'Y-m-d H:i:s', $time );
		}

		/**
		 * Optional accessor for neat code.
		 * Sets the database adapter you want to use.
		 *
		 * @param Adapter $adapter
		 *
		 * @return void
		 */
		public function setDatabaseAdapter( Adapter $adapter )
		{
			$this->adapter = $adapter;
		}

		/**
		 * Optional accessor for neat code.
		 * Sets the database adapter you want to use.
		 *
		 * @param QueryWriter $writer
		 *
		 * @return void
		 */
		public function setWriter( QueryWriter $writer )
		{
			$this->writer = $writer;
		}

		/**
		 * Optional accessor for neat code.
		 * Sets the database adapter you want to use.
		 *
		 * @param OODB $redbean
		 */
		public function setRedBean( OODB $redbean )
		{
			$this->redbean = $redbean;
		}

		/**
		 * Optional accessor for neat code.
		 * Sets the database adapter you want to use.
		 *
		 * @return DBAdapter
		 */
		public function getDatabaseAdapter()
		{
			return $this->adapter;
		}

		/**
		 * Returns the current duplication manager instance.
		 *
		 * @return DuplicationManager
		 */
		public function getDuplicationManager()
		{	
			return $this->duplicationManager;
		}

		/**
		 * Optional accessor for neat code.
		 * Sets the database adapter you want to use.
		 *
		 * @return QueryWriter
		 */
		public function getWriter()
		{
			return $this->writer;
		}

		/**
		 * Optional accessor for neat code.
		 * Sets the database adapter you want to use.
		 *
		 * @return OODB
		 */
		public function getRedBean()
		{
			return $this->redbean;
		}

		/**
		 * Returns the toolbox currently used by the facade.
		 * To set the toolbox use $this->setup() or $this->configureFacadeWithToolbox().
		 * To create a toolbox use Setup::kickstart(). Or create a manual
		 * toolbox using the ToolBox class.
		 *
		 * @return ToolBox
		 */
		public function getToolBox()
		{
			return $this->toolbox;
		}

		public function getExtractedToolbox()
		{
			return array(
				$this->redbean,
				$this->adapter,
				$this->writer,
				$this->toolbox
			);
		}

		/**
		 * Facade method for AQueryWriter::renameAssociation()
		 *
		 * @param string|array $from
		 * @param string       $to
		 *
		 * @return void
		 */
		public function renameAssociation( $from, $to = NULL )
		{
			AQueryWriter::renameAssociation( $from, $to );
		}

		/**
		 * Little helper method for Resty Bean Can server and others.
		 * Takes an array of beans and exports each bean.
		 * Unlike exportAll this method does not recurse into own lists
		 * and shared lists, the beans are exported as-is, only loaded lists
		 * are exported.
		 *
		 * @param array $beans beans
		 *
		 * @return array
		 */
		public function beansToArray( $beans )
		{
			$list = array();
			foreach( $beans as $bean ) {
				$list[] = $bean->export();
			}
			return $list;
		}

		/**
		 * Dynamically extends the facade with a plugin.
		 * Using this method you can register your plugin with the facade and then
		 * use the plugin by invoking the name specified plugin name as a method on
		 * the facade.
		 *
		 * Usage:
		 *
		 * $this->ext( 'makeTea', function() { ... }  );
		 *
		 * Now you can use your makeTea plugin like this:
		 *
		 * $this->makeTea();
		 *
		 * @param string   $pluginName name of the method to call the plugin
		 * @param callable $callable   a PHP callable
		 */
		public function ext( $pluginName, $callable )
		{
			if ( !ctype_alnum( $pluginName ) ) {
				throw new RedException( 'Plugin name may only contain alphanumeric characters.' );
			}
			$this->plugins[$pluginName] = $callable;
		}

		/**
		 * Call for use with dynamic plugins. This magic method will
		 * intercept calls and route them to the specified plugin.
		 *
		 * @param string $pluginName name of the plugin
		 * @param array  $params     list of arguments to pass to plugin method
		 *
		 * @return mixed
		 */
		//public function __callStatic( $pluginName, $params )
		//{
		//	if ( !ctype_alnum( $pluginName) ) {
		//		throw new RedException( 'Plugin name may only contain alphanumeric characters.' );
		//	}
		//	if ( !isset( $this->plugins[$pluginName] ) ) {
		//		throw new RedException( 'Plugin \''.$pluginName.'\' does not exist, add this plugin using: $this->ext(\''.$pluginName.'\')' );
		//	}
		//	return call_user_func_array( $this->plugins[$pluginName], $params );
		//}
	}

}

//之前一些参考代码。。。放着供参考..
//magic one
//public function __call($name, $arguments){
//	//$db=$this->db;
//	////$rt=call_user_func(array($db,$name),$arguments,$a2,$a3,$a4,$a5,$a6);
//	//$rt=call_user_func_array(array($db,$name),$arguments);//TODO why seems a little bug since 5.3.3???
//	////$rt=$db->name($arguments,$a2,$a3,$a4,$a5,$a6);
//	////println("$name called");
//	//>5.3.0
//	$rt=forward_static_call_array(array('R',$name),$arguments);
//	return $rt;
//}
//public function __callStatic($name, $arguments){
//	$rt=forward_static_call_array(array('R',$name),$arguments);
//	return $rt;
//		return call_user_func_array( self::$plugins[$pluginName], $params );
//}

