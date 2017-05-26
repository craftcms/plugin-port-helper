<?php
namespace Craft;

use Yii;

$craftPath = realpath(__DIR__).'/';
$namespace = '';
$tablePrefix = false;
$convertLocales = false;

// Validate input variables
if (isset($_SERVER['argv']))
{
    foreach ($argv as $key => $arg) {
        if (strpos($arg, '--projectPath=') !== false) {
            $parts = explode('=', $arg);
            $craftPath = realpath($parts[1]).'/';
            continue;
        }

        if (strpos($arg, '--tablePrefix') !== false) {
            $parts = explode('=', $arg);
            $tablePrefix = $parts[1];
        }

        if (strpos($arg, '--convertLocales') !== false) {
            $convertLocales = true;
        }

        if (strpos($arg, '--namespace') !== false) {
            $parts = explode('=', $arg);
            $namespace = $parts[1];
        }

        if (strpos($arg, '--output') !== false) {
            $parts = explode('=', $arg);
        }
    }
}

if (empty($tablePrefix) || empty($namespace) || !is_dir($craftPath.'config/')) {
    echo "\nUsage: php migrationHelper.php --tablePrefix=prefix --namespace=pluginNamespace [`--projectPath=path] [--convertLocales]\n";
    echo "\t--tablePrefix       Prefix to use to filter tables by. Craft table prefix is optional.\n";
    echo "\t--namespace         Namespace that the plugin will use.\n";
    echo "\t--projectPath       Valid path to the craft folder in a Craft installation. Optional, if it's the current working directory.\n";
    echo "\t--convertLocales    If present, the helper will attempt to convert all Craft 2 Locale references in table schemas to Craft 3 Sites.\n\n";
    die();
}

$app = bootstrap($craftPath);

// Load up all the tables.
/**
 * @var \CMysqlSchema $schema
 */
$schema = craft()->db->getSchema();
$tables = $schema->getTables();
$craftTablePrefix = craft()->db->tablePrefix;
$prefixLen = mb_strlen($craftTablePrefix);

// If user didn't provide the craft table prefix as well, add it now.
if (strpos($tablePrefix, $craftTablePrefix) !== 0) {
    $tablePrefix = $craftTablePrefix.$tablePrefix;
}

$tablesPhp = '';
$dropTablesPhp = '';
$indexesPhp = array();
$foreignKeysPhp = array();
$dropForeignKeysPhp = array();

// Each table
foreach ($tables as $tableName => $table)
{
    /**
     * @var \CMysqlTableSchema $table
     */
    // Skip if not allowed by prefix
    if (strpos($table->name, $tablePrefix) !== 0) {
        continue;
    }

    $tableNameSansPrefix = mb_substr($table->name, $prefixLen);
    $analyzedTable = MigrationHelper::getTable($tableNameSansPrefix);

    $tableNamePhp = '\'{{%'.$tableNameSansPrefix.'}}\'';

    $hasNormalPk = false;
    $columnsPhp = array();

    $columns = $table->getColumnNames();


    // Using Yii's schema stuff here because it's pretty convenient for columns.
    foreach ($columns as $columnName) {
        // Skip field_* columns
        if (strpos($columnName, 'field_') === 0) {
            continue;
        }

        /** @var \CMysqlColumnSchema $column */
        $column = $table->getColumn($columnName);
        $columnType = false;

        // Convert locales, if needed
        if ($columnName == 'locale' && $convertLocales) {
            $columnName = 'siteId';
            $column->size = 11;
            $column->dbType = 'int(11)';
        }

        // Is this the one and only PK, and an integer?
        $isNormalPk = (
            $column->isPrimaryKey &&
            (strpos($column->dbType, 'int') !== false) &&
            count($table->primaryKey) == 1 &&
            $column->autoIncrement &&
            !$column->allowNull
        );

        if ($isNormalPk) {
            if (strpos($column->dbType, 'bigint') === false) {
                $columnType = 'PK';
            } else {
                $columnType = 'BIGPK';
            }
            $hasNormalPk = true;
        } else if ($columnType == 'integer' && $column->size == 1) {
            $columnType = 'BOOLEAN';
        } else if ($columnName == 'uid') {
            $columnType = 'uid';
        }

        $typeFuncPhp = null;

        // Special types
        switch ($columnType) {
            case 'PK':
                $typeFuncPhp = 'primaryKey('.($column->size != 11 ? $column->size : '').')';
                break;
            case 'BIGPK':
                $typeFuncPhp = 'bigPrimaryKey('.($column->size != 20 ? $column->size : '').')';
                break;
            case 'BOOLEAN':
                $typeFuncPhp = 'boolean()';
                break;
            case 'uid':
                $typeFuncPhp = 'uid()';
                break;
        }

        // Regexps are the most reliable way here, sadly.
        if (!$typeFuncPhp) {
            if (preg_match('/^char\(/', $column->dbType)) {
                $typeFuncPhp = 'char('.$column->size.')';
            } else if (preg_match('/^varchar\(/', $column->dbType)) {
                $typeFuncPhp = 'string('.($column->size != 255 ? $column->size : '').')';
            } else if (preg_match('/^text/', $column->dbType)) {
                $typeFuncPhp = 'text()';
            } else if (preg_match('/^varchar\(/', $column->dbType)) {
                $typeFuncPhp = 'string('.($column->size != 255 ? $column->size : '').')';
            } else if (preg_match('/^text/', $column->dbType)) {
                $typeFuncPhp = 'text()';
            } else if (preg_match('/^int/', $column->dbType)) {
                $typeFuncPhp = 'integer()';
            } else if (preg_match('/^tinyint/', $column->dbType)) {
                // Assume boolean for tinyint(1)
                if ($column->size === 1) {
                    $typeFuncPhp = 'boolean()';
                } else {
                    $typeFuncPhp = 'smallInteger()';
                }
            } else if (preg_match('/^smallint/', $column->dbType)) {
                $typeFuncPhp = 'smallInteger()';
            } else if (preg_match('/^bigint/', $column->dbType)) {
                $typeFuncPhp = 'bigInteger()';
            } else if (preg_match('/^float/', $column->dbType)) {
                $typeFuncPhp = 'float('.$column->precision.')';
            } else if (preg_match('/^double/', $column->dbType)) {
                $typeFuncPhp = 'double('.$column->precision.')';
            } else if (preg_match('/^decimal/', $column->dbType)) {
                $typeFuncPhp = 'decimal('.$column->precision.', '.$column->scale.')';
            } else if (preg_match('/^datetime/', $column->dbType)) {
                $typeFuncPhp = 'dateTime('.$column->precision.')';
            } else if (preg_match('/^timestamp/', $column->dbType)) {
                $typeFuncPhp = 'timestamp('.$column->precision.')';
            } else if ($column->dbType === 'date') {
                $typeFuncPhp = 'date()';
            } else if (preg_match('/^time\(/', $column->dbType)) {
                $typeFuncPhp = 'time('.$column->precision.')';
            } else if (preg_match('/^binary/', $column->dbType)) {
                $typeFuncPhp = 'binary('.$column->size.')';
            } else if (preg_match('/^tinytext/', $column->dbType)) {
                $typeFuncPhp = 'tinyText()';
            } else if (preg_match('/^mediumtext/', $column->dbType)) {
                $typeFuncPhp = 'mediumText()';
            } else if (preg_match('/^longtext/', $column->dbType)) {
                $typeFuncPhp = 'longText()';
            } else if (preg_match('/^enum/', $column->dbType)) {
                // Get the values
                if (!preg_match('/^enum\(([^\)]+)\)$/', $column->dbType, $matches)) {
                    throw new \Exception('Invalid ENUM column: '.$column->dbType);
                }
                $values = array_map(function($value) {
                    return trim($value, ' \'"');
                }, explode(',', $matches[1]));
                $typeFuncPhp = "enum('$columnName', ['".implode("', '", $values)."'])";
            } else {
                throw new \Exception('Unknown column type: '.$column->dbType);
            }
        }


        $columnPhp = "'$columnName' => \$this->$typeFuncPhp";

        // Add extras
        if ($columnType != 'uid' && $typeFuncPhp !== 'boolean()') {
            if (!$column->allowNull && !$isNormalPk) {
                $columnPhp .= '->notNull()';
            }

            if ($column->defaultValue !== null) {
                $defaultValue = var_export($column->defaultValue, true);

                // Normalize the default value
                if (preg_match('/^(float|double|decimal)/', $column->dbType)) {
                    $defaultValue = (float) $defaultValue;
                } else if (strpos($column->dbType, 'unsigned') !== false) {
                    $defaultValue = (int) $defaultValue;
                }


                $columnPhp .= '->defaultValue('.$defaultValue.')';
            }

            if (strpos($column->dbType, 'unsigned') !== false) {
                $columnPhp .= '->unsigned()';
            }
        }

        $columnsPhp[] = $columnPhp.',';
    }

    // Is there a composite primary key, or a non-integer primary key?
    if (!$hasNormalPk && $table->primaryKey) {
        $key = is_array($table->primaryKey) ? implode(', ', $table->primaryKey) : $table->primaryKey;
        $columnsPhp[] = '\'PRIMARY KEY('.$key.')\',';
    }


    // However, Craft migration stuff actually exposes indexes, so use that here.
    foreach ($analyzedTable->indexes as $index) {
        $indexColumnsPhp = '\''.implode(',', $index->columns).'\'';


        // Convert locales, if needed
        if ($convertLocales) {
            $indexColumnsPhp = preg_replace('/(\'|,)locale(\'|,)/i', '$1siteId$2', $indexColumnsPhp);
        }

        $uniquePhp = $index->unique ? 'true' : 'false';
        $indexesPhp[] = "\$this->createIndex(\$this->db->getIndexName($tableNamePhp, $indexColumnsPhp, $uniquePhp), $tableNamePhp, $indexColumnsPhp, $uniquePhp);";
    }

    // Also for FKs
    foreach ($analyzedTable->fks as $fk) {
        $fkColumnsPhp = '\''.implode(',', $fk->columns).'\'';
        $refTablePhp = '\'{{%'.$fk->refTable.'}}\'';
        $refColumnsPhp = '\''.implode(',', $fk->refColumns).'\'';
        $deletePhp = $fk->onDelete ? "'{$fk->onDelete}'" : 'null';
        $updatePhp = $fk->onUpdate ? "'{$fk->onUpdate}'" : 'null';

        // Convert locales, if needed
        if ($convertLocales) {
            $fkColumnsPhp = preg_replace('/(\'|,)locale(\'|,)/i', '$1siteId$2', $fkColumnsPhp);
            $refTablePhp = str_replace('%locales}', '%sites}', $refTablePhp);
            $refColumnsPhp = preg_replace('/(\'|,)locale(\'|,)/i', '$1id$2', $refColumnsPhp);
        }

        $foreignKeysPhp[] = "\$this->addForeignKey(\$this->db->getForeignKeyName($tableNamePhp, $fkColumnsPhp), $tableNamePhp, $fkColumnsPhp, $refTablePhp, $refColumnsPhp, $deletePhp, $updatePhp);";
        $dropForeignKeysPhp[] = "MigrationHelper::dropForeignKeyIfExists($tableNamePhp, ['".implode("','", explode(',', trim($fkColumnsPhp,"'")))."'], \$this);";

    }

    $columnsPhp = implode("\n            ", $columnsPhp);

    $tablesPhp .= <<<EOD
        \$this->createTable({$tableNamePhp}, [
            $columnsPhp
        ]);


EOD;

    $dropTablesPhp .= "\t\t\$this->dropTable($tableNamePhp);\n";
}

$dropTablesPhp = ltrim($dropTablesPhp);
$tablesPhp = trim($tablesPhp);
$indexesPhp = implode("\n        ", $indexesPhp);
$foreignKeysPhp = implode("\n        ", $foreignKeysPhp);
$dropForeignKeysPhp = implode("\n        ", $dropForeignKeysPhp);

// Roll out the template.
$migrationContent = <<<EOD
<?php
namespace $namespace\migrations;

use Craft;
use craft\db\Migration;
use craft\helpers\MigrationHelper;

/**
 * Installation Migration
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since  3.0
 */
class Install extends Migration
{

    /**
     * @inheritdoc
     */
    public function safeUp()
    {
        \$this->createTables();
        \$this->createIndexes();
        \$this->addForeignKeys();

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown()
    {
        \$this->dropForeignKeys();
        \$this->dropTables();

        return true;
    }
    
    // Protected Methods
    // =========================================================================

    /**
     * Creates the tables.
     *
     * @return void
     */
    protected function createTables()
    {
        $tablesPhp
    }
    
    /**
     * Drop the tables
     * 
     * @return coid
     */
    protected function dropTables()
    {
        $dropTablesPhp
    }
    
    /**
     * Creates the indexes.
     *
     * @return void
     */
    protected function createIndexes()
    {
        $indexesPhp
    }

    /**
     * Adds the foreign keys.
     *
     * @return void
     */
    protected function addForeignKeys()
    {
        $foreignKeysPhp
    }
    
    /**
     * Drop the foreign keys
     *
     * @return void
     */
    protected function dropForeignKeys()
    {
        $dropForeignKeysPhp
    }
}

EOD;

echo $migrationContent;
exit();

/**
 * Bootstrap a Craft instance given a path to the app folder.
 */
function bootstrap($basePath)
{
    defined('CRAFT_APP_PATH') || define('CRAFT_APP_PATH', $basePath.'app/');
    defined('CRAFT_VENDOR_PATH') || define('CRAFT_VENDOR_PATH', CRAFT_APP_PATH.'vendor/');
    defined('CRAFT_FRAMEWORK_PATH') || define('CRAFT_FRAMEWORK_PATH', CRAFT_APP_PATH.'framework/');

    // The app/ folder goes inside craft/ by default, so work backwards from app/
    defined('CRAFT_BASE_PATH') || define('CRAFT_BASE_PATH', $basePath);

    // Everything else should be relative from craft/ by default
    defined('CRAFT_CONFIG_PATH') || define('CRAFT_CONFIG_PATH', CRAFT_BASE_PATH.'config/');
    defined('CRAFT_PLUGINS_PATH') || define('CRAFT_PLUGINS_PATH', CRAFT_BASE_PATH.'plugins/');
    defined('CRAFT_STORAGE_PATH') || define('CRAFT_STORAGE_PATH', CRAFT_BASE_PATH.'storage/');
    defined('CRAFT_TEMPLATES_PATH') || define('CRAFT_TEMPLATES_PATH', CRAFT_BASE_PATH.'templates/');
    defined('CRAFT_TRANSLATIONS_PATH') || define('CRAFT_TRANSLATIONS_PATH', CRAFT_BASE_PATH.'translations/');
    defined('CRAFT_ENVIRONMENT') || define('CRAFT_ENVIRONMENT', 'console');

    /**
     * Yii command line script file configured for Craft.
     */

    // fix for fcgi
    defined('STDIN') or define('STDIN', fopen('php://stdin', 'r'));

    ini_set('log_errors', 1);
    ini_set('error_log', CRAFT_STORAGE_PATH.'runtime/logs/phperrors.log');

    error_reporting(E_ALL & ~E_STRICT);
    ini_set('display_errors', 1);
    defined('YII_DEBUG') || define('YII_DEBUG', true);
    defined('YII_TRACE_LEVEL') || define('YII_TRACE_LEVEL', 3);

    require_once CRAFT_FRAMEWORK_PATH.'yii.php';
    require_once CRAFT_APP_PATH.'Craft.php';
    require_once CRAFT_APP_PATH.'Info.php';

    // Guzzle makes use of these PHP constants, but they aren't actually defined in some compilations of PHP.
    // See http://it.blog.adclick.pt/php/fixing-php-notice-use-of-undefined-constant-curlopt_timeout_ms-assumed-curlopt_timeout_ms/
    defined('CURLOPT_TIMEOUT_MS') || define('CURLOPT_TIMEOUT_MS', 155);
    defined('CURLOPT_CONNECTTIMEOUT_MS') || define('CURLOPT_CONNECTTIMEOUT_MS', 156);

    // Load up Composer's files
    require CRAFT_VENDOR_PATH.'autoload.php';

    // Disable the PHP include path
    Yii::$enableIncludePath = false;

    require_once(CRAFT_APP_PATH.'/etc/console/ConsoleApp.php');

    // Because CHttpRequest is one of those stupid Yii files that has multiple classes defined in it.
    require_once(CRAFT_APP_PATH.'framework/web/CHttpRequest.php');

    Yii::setPathOfAlias('app', CRAFT_APP_PATH);
    Yii::setPathOfAlias('plugins', CRAFT_PLUGINS_PATH);

    return Yii::createApplication('Craft\ConsoleApp', CRAFT_APP_PATH.'/etc/config/console.php');
}
