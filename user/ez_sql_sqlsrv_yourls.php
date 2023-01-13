<?php

class ezSQL_sqlsrv_YOURLS extends ezSQL_sqlsrv {

	/**
	 * Spoof MySQL version
	 *
	 * @since 1.7
	 */
	function mysql_version() {
		return "5.5.10-standard";
	}
	
	/**
	 * Perform msSQL query
	 *
	 * Added to the original function: logging of all queries
	 *
	 */
	function query( $query ) {
	
		// Keep history of all queries
		$this->debug_log[] = $query;

		// Original function
		return parent::query( $query );
	}
	
}

