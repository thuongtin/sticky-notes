<?php

/**
 * Sticky Notes
 *
 * An open source lightweight pastebin application
 *
 * @package     StickyNotes
 * @author      Sayak Banerjee
 * @copyright   (c) 2013 Sayak Banerjee <mail@sayakbanerjee.com>
 * @license     http://www.opensource.org/licenses/bsd-license.php
 * @link        http://sayakbanerjee.com/sticky-notes
 * @since       Version 1.0
 * @filesource
 */

/**
 * Setup class
 *
 * Layer for performing install and update activities
 *
 * @package     StickyNotes
 * @subpackage  Libraries
 * @author      Sayak Banerjee
 */
class Setup {

	/**
	 * Tests the database connection
	 *
	 * @static
	 * @return bool|string
	 */
	public static function testConnection()
	{
		try
		{
			Schema::getConnection();

			return TRUE;
		}
		catch (Exception $e)
		{
			return $e->getMessage();
		}
	}

	/**
	 * Processes AJAX requests for installation.
	 * The installer processes one table per action.
	 *
	 * The response is in the following format:
	 * <percent-complete>|<next-action>|<status-message>
	 *
	 * @static
	 * @param  string  $action
	 * @return string
	 */
	public static function install($action)
	{
		// Fetch the installer schema
		$schema = Config::get('schema');

		// Define the tables data
		$tables = $schema['install']['tables'];

		$tableNames = array_keys($tables);

		// We assign 5% to initiate and 5% to completion
		// The weightage of each table is calculated out of 90
		$weight = floor(90 / count($tables));

		// Initialize everything
		if (empty($action))
		{
			$firstTable = $tableNames[0];

			return "5|{$firstTable}|".sprintf(Lang::get('setup.create_table'), $firstTable);
		}

		// This is the last step, but needs to be called out first
		else if ($action == '~complete')
		{
			// Run the post-install closure
			call_user_func($schema['install']['closure']);

			// Mark completion of this stage
			Session::put('setup.stage', 4);

			return "100||".Lang::get('setup.install_complete');
		}

		// This loops across all tables and processes them
		else if (in_array($action, $tableNames))
		{
			try
			{
				// Drop the table
				Schema::dropIfExists($action);

				// Generate schema and create the table
				Schema::create($action, function($table) use ($tables, $action)
				{
					Setup::schema($table, $tables[$action]);
				});

				// Output the next action in queue
				return Setup::nextAction($action, $tableNames, 'setup.create_table');
			}
			catch (Exception $e)
			{
				Session::put('setup.error', $e->getMessage());

				return '-1||'.Lang::get('setup.error_occurred');
			}
		}
	}

	/**
	 * Processes AJAX requests for upgrades.
	 * The updater starts with the passed $version and goes on until the
	 * last one.
	 *
	 * The response is in the following format:
	 * <percent-complete>|<next-action>|<status-message>
	 *
	 * @static
	 * @param  string  $version
	 * @return string
	 */
	public static function update($action)
	{
		// Fetch the updater schema
		$schema = Config::get('schema');

		// Get the update versions and current scope
		$versions = $schema['update'];

		$versionNames = array_keys($versions);

		// Initialize everything
		if ( ! Session::has('setup.updating'))
		{
			Session::put('setup.updating', TRUE);

			return "5|{$action}|".sprintf(Lang::get('setup.process_version'), $action);
		}

		// This is the last step, but needs to be called out first
		else if ($action == '~complete')
		{
			Session::put('setup.stage', 3);

			return "100||".Lang::get('setup.update_complete');
		}

		// Process the version
		else if (array_key_exists($action, $versions))
		{
			try
			{
				// Scope is the current version being processed
				$scope = $versions[$action];

				// Create new tables
				if (isset($scope['newTables']))
				{
					foreach ($scope['newTables'] as $tableName => $schema)
					{
						// Drop the table
						Schema::dropIfExists($tableName);

						// Generate schema and create the table
						Schema::create($tableName, function($table) use ($schema)
						{
							Setup::schema($table, $schema);
						});
					}
				}

				// Update existing tables
				if (isset($scope['modifyTables']))
				{
					foreach ($scope['modifyTables'] as $tableName => $schema)
					{
						// Generate schema and modify the table
						Schema::table($tableName, function($table) use ($schema)
						{
							Setup::schema($table, $schema);
						});
					}
				}

				// Run the closure for this version
				if (isset($scope['closure']))
				{
					call_user_func($scope['closure']);
				}

				// Output the next action in queue
				return Setup::nextAction($action, $versionNames, 'setup.process_version');
			}
			catch (Exception $e)
			{
				Session::put('setup.error', $e->getMessage());

				return '-1||'.Lang::get('setup.error_occurred');
			}
		}
	}

	/**
	 * Applies a specific table schema to a table
	 *
	 * @static
	 * @param  Illuminate\Database\Schema\Blueprint  $table
	 * @param  array  $schema
	 * @return void
	 */
	public static function schema($table, $schema)
	{
		foreach ($schema as $column)
		{
			$coltype = $column->type;

			// Make the column
			if (isset($column->length))
			{
				$context = $table->$coltype($column->name, $column->length);
			}
			else
			{
				$context = $table->$coltype($column->name);
			}

			// Set default value
			if (isset($column->default))
			{
				$context = $context->default($column->default);
			}

			// Set nullable type
			if (isset($column->nullable) AND $column->nullable)
			{
				$context = $context->nullable();
			}

			// Set unsigned for integers
			if (isset($column->unsigned) AND $column->unsigned)
			{
				$context = $context->unsigned();
			}
		}
	}

	/**
	 * Returns the next action response
	 *
	 * @static
	 * @param  string  $action
	 * @param  array  $actions
	 * @param  string  $langKey
	 * @return string
	 */
	public static function nextAction($action, $actions, $langKey)
	{
		// We assign 5% to initiate and 5% to completion
		// The weightage of each version is calculated out of 90
		$weight = floor(90 / count($actions));

		// Get the index of the current action
		$index = array_search($action, $actions);

		// Get the percentage done
		$percent = ($index + 1) * $weight;

		// Get the next action and message
		if ($index < count($actions) - 1)
		{
			$nextAction = $actions[$index + 1];

			$message = sprintf(Lang::get($langKey), $nextAction);
		}
		else
		{
			$nextAction = '~complete';

			$message = Lang::get('setup.almost_done');
		}

		return "{$percent}|{$nextAction}|{$message}";
	}

	/**
	 * Fetches available Sticky Notes versions for update
	 *
	 * @static
	 * @param  bool  $csv
	 * @return array|string
	 */
	public static function updateVersions($csv = FALSE)
	{
		$versions = array();

		$config = Config::get('schema');

		foreach ($config['update'] as $version => $schema)
		{
			$versions[$version] = $version;
		}

		if ($csv)
		{
			$versions = implode(',', $versions);
		}

		return $versions;
	}

}
