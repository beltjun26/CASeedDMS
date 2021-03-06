<?php
#require_once("../inc/inc.ClassSettings.php");
include("../inc/inc.Settings.php");
include("../inc/inc.Extension.php");

function usage() { /* {{{ */
	echo "Usage:\n";
	echo "  seeddms-indexer [-h] [-v] [--config <file>]\n";
	echo "\n";
	echo "Description:\n";
	echo "  This program recreates the full text index of SeedDMS.\n";
	echo "\n";
	echo "Options:\n";
	echo "  -h, --help: print usage information and exit.\n";
	echo "  -v, --version: print version and exit.\n";
	echo "  -c: recreate index.\n";
	echo "  --config: set alternative config file.\n";
} /* }}} */

$version = "0.0.2";
$shortoptions = "hvc";
$longoptions = array('help', 'version', 'config:');
if(false === ($options = getopt($shortoptions, $longoptions))) {
	usage();
	exit(0);
}

/* Print help and exit */
if(isset($options['h']) || isset($options['help'])) {
	usage();
	exit(0);
}

/* Print version and exit */
if(isset($options['v']) || isset($options['verѕion'])) {
	echo $version."\n";
	exit(0);
}

/* Set alternative config file */
if(isset($options['config'])) {
	$settings = new Settings($options['config']);
} else {
	$settings = new Settings();
}

/* recreate index */
$recreate = false;
if(isset($options['c'])) {
	$recreate = true;
}

if(isset($settings->_extraPath))
	ini_set('include_path', $settings->_extraPath. PATH_SEPARATOR .ini_get('include_path'));

require_once("SeedDMS/Core.php");
if($settings->_fullSearchEngine == 'sqlitefts') {
	$indexconf = array(
		'Indexer' => 'SeedDMS_SQLiteFTS_Indexer',
		'Search' => 'SeedDMS_SQLiteFTS_Search',
		'IndexedDocument' => 'SeedDMS_SQLiteFTS_IndexedDocument'
	);

	require_once('SeedDMS/SQLiteFTS.php');
} else {
	$indexconf = array(
		'Indexer' => 'SeedDMS_Lucene_Indexer',
		'Search' => 'SeedDMS_Lucene_Search',
		'IndexedDocument' => 'SeedDMS_Lucene_IndexedDocument'
	);

	require_once('SeedDMS/Lucene.php');
}

function tree($dms, $index, $indexconf, $folder, $indent='') { /* {{{ */
	global $settings;
	echo $indent."D ".$folder->getName()."\n";
	$subfolders = $folder->getSubFolders();
	foreach($subfolders as $subfolder) {
		tree($dms, $index, $indexconf, $subfolder, $indent.'  ');
	}
	$documents = $folder->getDocuments();
	foreach($documents as $document) {
		echo $indent."  ".$document->getId().":".$document->getName()." ";
		$lucenesearch = new $indexconf['Search']($index);
		if(!($hit = $lucenesearch->getDocument($document->getId()))) {
			try {
#				$index->addDocument(new $indexconf['IndexedDocument']($dms, $document, isset($settings->_converters['fulltext']) ? $settings->_converters['fulltext'] : null, false, $settings->_cmdTimeout));
				$idoc = new $indexconf['IndexedDocument']($dms, $document, isset($settings->_converters['fulltext']) ? $settings->_converters['fulltext'] : null, false, $settings->_cmdTimeout);
                                if(isset($GLOBALS['SEEDDMS_HOOKS']['indexDocument'])) {
                                	foreach($GLOBALS['SEEDDMS_HOOKS']['indexDocument'] as $hookObj) {
                                        	if (method_exists($hookObj, 'preIndexDocument')) {
                                                	$hookObj->preIndexDocument(null, $document, $idoc);
                                                }
                           		}
                		}
                                $index->addDocument($idoc);
				echo " (Document added)\n";
			} catch(Exception $e) {
				echo " (Timeout)\n";
			}
		} else {
			try {
				$created = (int) $hit->getDocument()->getFieldValue('created');
			} catch (Exception $e) {
				$created = 0;
			}
			$content = $document->getLatestContent();
			if($created >= $content->getDate()) {
				echo " (Document unchanged)\n";
			} else {
				$index->delete($hit->id);
				try {
#					$index->addDocument(new $indexconf['IndexedDocument']($dms, $document, isset($settings->_converters['fulltext']) ? $settings->_converters['fulltext'] : null, false, $settings->_cmdTimeout));
                                        $idoc = new $indexconf['IndexedDocument']($dms, $document, isset($settings->_converters['fulltext']) ? $settings->_converters['fulltext'] : null, false, $settings->_cmdTimeout);
						if(isset($GLOBALS['SEEDDMS_HOOKS']['indexDocument'])) {
                                                	foreach($GLOBALS['SEEDDMS_HOOKS']['indexDocument'] as $hookObj) {
                                                        	if (method_exists($hookObj, 'preIndexDocument')) {
                                                                	$hookObj->preIndexDocument(null, $document, $idoc);
                                                                }
                                                        }
                                                }
                                        $index->addDocument($idoc);

					echo " (Document updated)\n";
				} catch(Exception $e) {
					echo " (Timeout)\n";
				}
			}
		}
	}
} /* }}} */

$db = new SeedDMS_Core_DatabaseAccess($settings->_dbDriver, $settings->_dbHostname, $settings->_dbUser, $settings->_dbPass, $settings->_dbDatabase);
$db->connect() or die ("Could not connect to db-server \"" . $settings->_dbHostname . "\"");

$dms = new SeedDMS_Core_DMS($db, $settings->_contentDir.$settings->_contentOffsetDir);
if(!$dms->checkVersion()) {
	echo "Database update needed.\n";
	exit(1);
}

$dms->setRootFolderID($settings->_rootFolderID);

if($recreate)
	$index = $indexconf['Indexer']::create($settings->_luceneDir);
else
	$index = $indexconf['Indexer']::open($settings->_luceneDir);
if(!$index) {
	echo "Could not create index.\n";
	exit(1);
}

$indexconf['Indexer']::init($settings->_stopWordsFile);

$folder = $dms->getFolder($settings->_rootFolderID);
tree($dms, $index, $indexconf, $folder);

$index->commit();
$index->optimize();
?>
