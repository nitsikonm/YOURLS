<?php
/*
Plugin Name: Microsoft SQL Driver for YOURLS 1.7.1+
Plugin URI: https://github.com/crimsonfalconer/yourls-sqlsrv
Description: MSSQL Database plugin
Version: 1.0
Author: crimsonfalconer
Author URI: http://github.com/crimsonfalconer
*/

yourls_db_sqlsrv_connect();

/**
 * Connect to MSSQL DB
 */
function yourls_db_sqlsrv_connect() {
    global $ydb;
    // Use core PDO library
    require_once( YOURLS_INC . '/ezSQL/ez_sql_core.php' );
    require_once( YOURLS_INC . '/ezSQL/ez_sql_core_yourls.php' );
	
    // Overwrite core YOURLS library to allow connection to a MSSQL DB instead of a MySQL server
	require_once( YOURLS_USERDIR . '/ez_sql_sqlsrv.php' );
    require_once( YOURLS_USERDIR . '/ez_sql_sqlsrv_yourls.php' );
	
    $ydb = new ezSQL_sqlsrv_YOURLS( YOURLS_DB_USER, YOURLS_DB_PASS, YOURLS_DB_NAME, YOURLS_DB_HOST );
    $ydb->DB_driver = 'sqlsrv';
    yourls_debug_log( "DB driver: sqlsrv" );
    
    // Custom tables to be created upon install
    yourls_add_filter( 'shunt_yourls_create_sql_tables', 'crimsonfalconer_yourls_sqlsrv_create_sql_tables' );
    
	// Custom stat query to replace MySQL DATE_FORMAT with SQLite strftime
    yourls_add_filter( 'stat_query_dates', 'crimsonfalconer_yourls_sqlsrv_stat_query_dates' );
	yourls_add_filter( 'stat_query_last24h', 'crimsonfalconer_yourls_sqlsrv_stat_query_last24h' );
    
    return $ydb;
}

/**
 * Assume MSSQL server is always alive
 */
function yourls_is_db_alive() {
    return true;
}

/**
 * Die with a DB error message
 *
 * @TODO in version 1.8 : use a new localized string, specific to the problem (ie: "DB is dead")
 *
 * @since 1.7.1
 */
function yourls_db_dead() {
    // Use any /user/db_error.php file
    if( file_exists( YOURLS_USERDIR . '/db_error.php' ) ) {
        include_once( YOURLS_USERDIR . '/db_error.php' );
        die();
    }
    yourls_die( yourls__( 'Incorrect DB config, or could not connect to DB' ), yourls__( 'Fatal error' ), 503 );
}

/**
 * Fix Stat query for MSSQL
 */
function crimsonfalconer_yourls_sqlsrv_stat_query_dates($query) {
	$query = str_replace( "DATE_FORMAT(`click_time`, '%Y')", "DATEPART(yy, click_time)", $query ); 
	$query = str_replace( "DATE_FORMAT(`click_time`, '%m')", "FORMAT(DATEPART(mm, click_time), '00')", $query ); 
	$query = str_replace( "DATE_FORMAT(`click_time`, '%d')", "FORMAT(DATEPART(dd, click_time), '00')", $query ); 
	$query = str_replace( "GROUP BY `year`, `month`, `day`", "GROUP BY DATEPART(yy, click_time), FORMAT(DATEPART(mm, click_time), '00'), FORMAT(DATEPART(dd, click_time), '00')", $query ); 
	return $query;
}

/**
 * Fix hits in last 24 hours query for MSSQL
 */
function crimsonfalconer_yourls_sqlsrv_stat_query_last24h($query)
{
	$query = str_replace( "DATE_FORMAT(`click_time`, '%H %p')", "CONCAT(FORMAT(DATEPART(hh, click_time), '00'), ' ', CASE WHEN DATEPART(HOUR, click_time) < 12 THEN 'AM' ELSE 'PM' END)", $query ); 
	$query = str_replace( "`click_time` > (CURRENT_TIMESTAMP - INTERVAL 1 DAY)", "DATEDIFF(day, click_time, GetDate()) = 0", $query ); 
	$query = str_replace( "GROUP BY `time`", "GROUP BY CONCAT(FORMAT(DATEPART(hh, click_time), '00'), ' ', CASE WHEN DATEPART(HOUR, click_time) < 12 THEN 'AM' ELSE 'PM' END)", $query ); 
	return $query;
}


/**
 * Create tables using MSSQL field types
 */
function crimsonfalconer_yourls_sqlsrv_create_sql_tables() {
	global $ydb;
	
	$error_msg = array();
	$success_msg = array();

	// Create Table MSSQL Query
	$create_tables = array();
	$create_tables[YOURLS_DB_TABLE_URL] =
		'CREATE TABLE dbo.'.YOURLS_DB_TABLE_URL.' ('.
		'[keyword] varchar(200) NOT NULL,'.
		'[url] varchar(MAX) NOT NULL,'.
		'[title] varchar(MAX) NOT NULL,'.
		'[timestamp] varchar(50) NOT NULL,'.
		'[ip] varchar(41) NOT NULL,'.
		'[clicks] int NOT NULL'.
		') '.
		'ALTER TABLE dbo.'.YOURLS_DB_TABLE_URL.' ADD CONSTRAINT PK_URL PRIMARY KEY CLUSTERED (keyword) '.
		'CREATE NONCLUSTERED INDEX IX_Timestamp ON dbo.'.YOURLS_DB_TABLE_URL.' (timestamp) '.
		'CREATE NONCLUSTERED INDEX IX_IP ON dbo.'.YOURLS_DB_TABLE_URL.' (ip) ';

	$create_tables[YOURLS_DB_TABLE_OPTIONS] =
		'CREATE TABLE dbo.'.YOURLS_DB_TABLE_OPTIONS.' ('.
		'[option_id] bigint IDENTITY NOT NULL,'.
		'[option_name] varchar(64) NOT NULL,'.
		'[option_value] varchar(MAX) NOT NULL'.
		') '.
		'ALTER TABLE dbo.'.YOURLS_DB_TABLE_OPTIONS.' ADD CONSTRAINT PK_OPTIONS PRIMARY KEY CLUSTERED (option_id, option_name) '.
		'CREATE NONCLUSTERED INDEX IX_OPTION_NAME ON dbo.'.YOURLS_DB_TABLE_OPTIONS.' (option_name) ';

	$create_tables[YOURLS_DB_TABLE_LOG] =
		'CREATE TABLE dbo.'.YOURLS_DB_TABLE_LOG.' ('.
		'[click_id] int IDENTITY NOT NULL,'.
		'[click_time] varchar(50) NOT NULL,'.
		'[shorturl] varchar(200) NOT NULL,'.
		'[referrer] varchar(200) NOT NULL,'.
		'[user_agent] varchar(255) NOT NULL,'.
		'[ip_address] varchar(41) NOT NULL,'.
		'[country_code] char(2) NOT NULL'.
		') '.
		'ALTER TABLE dbo.'.YOURLS_DB_TABLE_LOG.' ADD CONSTRAINT PK_LOG PRIMARY KEY CLUSTERED (click_id) '.
		'CREATE NONCLUSTERED INDEX IX_SHORTURL ON dbo.'.YOURLS_DB_TABLE_LOG.' (shorturl)';

	$create_table_count = 0;
	$create_table_success_query = "SELECT * FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME LIKE";
	$ydb->show_errors = true;

	// Create tables
	foreach ( $create_tables as $table_name => $table_query ) {
		$ydb->query( $table_query );
		$create_success = $ydb->query( "$create_table_success_query '$table_name'" );
		if( $create_success ) {
			$create_table_count++;
			$success_msg[] = yourls_s( "Table '%s' created.", $table_name ); 
		} else {
			$error_msg[] = yourls_s( "Error creating table '%s'.", $table_name ); 
		}
	}
	
	// Initializes the option table
	if( !yourls_initialize_options() )
		$error_msg[] = yourls__( 'Could not initialize options' );
	
	// Insert sample links
	if( !yourls_insert_sample_links() )
		$error_msg[] = yourls__( 'Could not insert sample short URLs' );
	
	// Check results of operations
	if ( sizeof( $create_tables ) == $create_table_count ) {
		$success_msg[] = yourls__( 'YOURLS tables successfully created.' );
	} else {
		$error_msg[] = yourls__( 'Error creating YOURLS tables.' ); 
	}

	return array( 'success' => $success_msg, 'error' => $error_msg );
}