<?php


class Modman
{

    /**
     * the .modman directory is missing
     */
    const ERR_NOT_INITIALIZED = 1;

    /**
     * no modman file in the linked directory
     */
    const ERR_NO_MODMAN_FILE = 2;

    /**
     * runs and dispatches the modman program
     *
     * @param $aParameters just a representation of $argv (the parameters which were used to launch the program)
     */
    public function run($aParameters)
    {
        try {
            if (!isset($aParameters[1])) {
                // show help if called without parameters
                $this->printHelp();
                exit;
            }

            $bForce = array_search('--force', $aParameters);

            switch ($aParameters[1]) {
                case 'link':
                    if (!isset($aParameters[2])) {
                        throw new Exception('please specify target directory');
                    }
                    $sLinkPath = realpath($aParameters[2]);
                    if (!$sLinkPath) {
                        throw new Exception('Link path is invalid!');
                    }
                    $oLink = new Modman_Command_Link($sLinkPath);
                    $oLink->createSymlinks($bForce);
                    echo 'Successfully create symlink new module \'' . basename($sLinkPath) . '\'' . PHP_EOL;
                    break;
                case 'init':
                    $sCwd = getcwd();
                    $sBaseDir = null;
                    if (isset($aParameters[2])) {
                        $sBaseDir = $aParameters[2];
                    }
                    $sInitPath = realpath($sCwd);
                    $oInit = new Modman_Command_Init();
                    $oInit->doInit($sInitPath, $sBaseDir);
                    break;
                case 'deploy':
                    if (!isset($aParameters[2])) {
                        throw new Exception('please specify module name');
                    }
                    $oDeploy = new Modman_Command_Deploy($aParameters[2]);
                    $oDeploy->doDeploy($bForce);
                    echo $aParameters[2] . ' has been deployed under ' . getcwd() . PHP_EOL;
                    break;
                case 'repair':
                    $bForce = true;
                    break;
                case 'deploy-all':
                    $oDeployAll = new Modman_Command_All('Modman_Command_Deploy');
                    $oDeployAll->doDeploy($bForce);
                    break;
                case 'clean':
                    $oClean = new Modman_Command_Clean();
                    $oClean->doClean();
                    break;
                case 'remove':
                    if (!isset($aParameters[2])) {
                        throw new Exception('please specify module name');
                    }
                    $oRemove = new Modman_Command_Remove($aParameters[2]);
                    $oRemove->doRemove($bForce);
                    break;
                case 'create':
                    $oCreate = new Modman_Command_Create();
                    $sDirectory = null;
                    if (isset($aParameters[2])) {
                        $sDirectory = $aParameters[2];
                    }
                    $iIncludeOffset = array_search('--include', $aParameters);
                    $bListHidden = array_search('--include-hidden', $aParameters);
                    if ($iIncludeOffset) {
                        $oCreate->setIncludeFile($aParameters[$iIncludeOffset + 1]);
                    }
                    $oCreate->doCreate($bForce, $sDirectory, $bListHidden);
                    break;
                case 'clone':
                    if (!isset($aParameters[2])) {
                        throw new Exception('Please specify git repository URL');
                    }
                    $bCreateModman = array_search('--create-modman', $aParameters);
                    $oClone = new Modman_Command_Clone($aParameters[2], new Modman_Command_Create());
                    $oClone->doClone($bForce, $bCreateModman);
                    break;
				case 'whitelist':
					$whitelist = new Modman_Command_BlackAndWhitelist();
					$whitelist->apply('whitelist', $aParameters[2]);
					break;
				case 'blacklist':
					$blacklist = new Modman_Command_BlackAndWhitelist();
					$blacklist->apply('blacklist', $aParameters[2]);
					break;
                default:
                    throw new Exception('command does not exist');
            }
        } catch (Exception $oException) {
            // set small timeout, no big delays for a funny feature
            $rCtx = stream_context_create(array('http' =>
                array(
                    'timeout' => 1,
                )
            ));

            $sMessage = $oException->getMessage();

            echo '-----' . PHP_EOL;
            echo 'An error occured:' . PHP_EOL;
            echo $sMessage . PHP_EOL;
            echo '-----';
            echo PHP_EOL . PHP_EOL;

            $this->printHelp();
        }
    }

    /**
     * prints the help
     */
    public function printHelp()
    {
        $sHelp = <<< EOH
PHP-based module manager, originally implemented as bash-script
(for original implementation see https://github.com/colinmollenhour/modman)

Following general commands are currently supported:
- link (optional --force)
- init (optional <basedir>)
- repair
- deploy <module> (optional --force)
- deploy-all (optional --force)
- clean
- create <module> (optional <dir> --force, --include <include_file> and --include-hidden)
- clone (optional --force, --create-modman)
- whitelist <file|dir>
- blacklist <file|dir>
- remove <module>

Currently supported in modman-files:
- symlinks (with wildcards)
- @import and @shell command
EOH;

        echo $sHelp . PHP_EOL;
    }

}

class Modman_Command_All
{
    private $sClassName;

    /**
     * constructor for a command
     *
     * @param string $sClassName
     */
    public function __construct($sClassName)
    {
        $this->sClassName = $sClassName;
    }

    /**
     * calls a method on all modules
     *
     * @param string $sMethodName the method name to call
     * @param array $aArguments the parameters to give that method
     */
    public function __call($sMethodName, $aArguments)
    {
        foreach ($this->getAllModules() as $sModuleName) {
            $oClass = new $this->sClassName($sModuleName);
            $oClass->$sMethodName(current($aArguments));
        }
    }

    /**
     * returns all linked modules
     *
     * @return array
     * @throws Exception if modman directory does not exist
     */
    private function getAllModules()
    {

        if (!file_exists(Modman_Command_Init::MODMAN_DIRECTORY_NAME)) {
            throw new Exception ('No modman directory found. You need to call "modman init" to create it.' . PHP_EOL
                . 'Please consider the documentation below.', Modman::ERR_NOT_INITIALIZED);
        }

        $aDirEntries = scandir(Modman_Command_Init::MODMAN_DIRECTORY_NAME);

        unset($aDirEntries[array_search('.', $aDirEntries)]);
        unset($aDirEntries[array_search('..', $aDirEntries)]);

        $iBaseDir = array_search(Modman_Command_Init::getBaseDirFile(), $aDirEntries);

        if ($iBaseDir !== false) {
            unset($aDirEntries[$iBaseDir]);
        }

        return $aDirEntries;
    }
}

class Modman_Command_Init
{

    // directory name
    const MODMAN_DIRECTORY_NAME = '.modman';
    const MODMAN_BASEDIR_FILE = '.basedir';

    /**
     * Creates directory ".modman" if it doesn't exist
     *
     * @param string
     * @param string
     */
    public function doInit($sDirectory, $sBaseDir = null)
    {

        $sModmanDirectory = $sDirectory . DIRECTORY_SEPARATOR . self::MODMAN_DIRECTORY_NAME;

        if (!is_dir($sModmanDirectory)) {
            mkdir($sModmanDirectory);
        }
        if (!is_null($sBaseDir)) {
            file_put_contents(self::getBaseDirFile(), $sBaseDir);
        }

		//Create Blacklist and Whitelist files based on defaults
		$whitelistBlacklist = new Modman_Command_BlackAndWhitelist();
		$whitelistBlacklist->createFile('whitelist');
		$whitelistBlacklist->createFile('blacklist');
    }

    public static function getBaseDirFile()
    {
        return self::MODMAN_DIRECTORY_NAME . DIRECTORY_SEPARATOR . self::MODMAN_BASEDIR_FILE;
    }
}

class Modman_Command_Link
{

    private $sTarget;

    /**
     * constructor
     *
     * @param string $sTarget target to link
     */
    public function __construct($sTarget)
    {

        if (empty($sTarget)) {
            throw new Exception('no source defined');
        }

        $this->sTarget = $sTarget;
    }

    /**
     * creates the symlinks
     *
     * @param bool $bForce if true errors will be ignored
     * @throws Exception if module is already linked
     */
    public function createSymlinks($bForce = false)
    {

        $sModuleName = basename($this->sTarget);
        $sModuleSymlink = Modman_Command_Init::MODMAN_DIRECTORY_NAME . DIRECTORY_SEPARATOR . $sModuleName;

        if (is_link($sModuleSymlink)) {
            throw new Exception($sModuleName . ' is already linked');
        }

        symlink($this->sTarget, $sModuleSymlink);

        $oDeploy = new Modman_Command_Deploy($sModuleName);
        $oDeploy->doDeploy($bForce);
    }
}

class Modman_Command_Link_Line
{

    private $sTarget, $sSymlink;

    /**
     * constructor
     *
     * @param array $aDirectories - key 0 = source; key 1 = target
     */
    public function __construct($aDirectories)
    {

        $this->sTarget = $aDirectories[0];

        if (empty($aDirectories[1])) {
            $this->sSymlink = $this->sTarget;
        } else {
            $this->sSymlink = $aDirectories[1];
        }
    }

    /**
     * returns the target
     *
     * @return string
     */
    public function getTarget()
    {
        return $this->rtrimDS($this->sTarget);
    }

    /**
     * fixes trailing slashes on *nix systems
     *
     * @param $sDir
     * @return string
     */
    private function rtrimDS($sDir)
    {
        return rtrim($sDir, DIRECTORY_SEPARATOR);
    }

    /**
     * returns the symlink base dir
     *
     * @return string
     */
    public function getSymlinkBaseDir()
    {
        return dirname($this->getSymlink());
    }

    /**
     * returns the symlink
     *
     * @return string
     */
    public function getSymlink()
    {

        $sBaseDir = getcwd();
        $sBaseDirFile = Modman_Command_Init::getBaseDirFile();

        if (file_exists($sBaseDirFile)) {
            $sBaseDir = file_get_contents($sBaseDirFile);
        }

        return $sBaseDir . DIRECTORY_SEPARATOR . $this->rtrimDS($this->sSymlink);
    }
}

class Modman_Reader
{

    const MODMAN_FILE_NAME = 'modman';

    private $aFileContent = array();
    private $aObjects = array();
    private $sClassName;
    private $aShells = array();
    private $sModuleDirectory;

    /**
     * constructor
     *
     * @param string $sDirectory - where to read
     */
    public function __construct($sDirectory)
    {

        $this->sModuleDirectory = $sDirectory;
        $this->aFileContent = file($sDirectory . DIRECTORY_SEPARATOR . self::MODMAN_FILE_NAME);
        $sFileName = $sDirectory . DIRECTORY_SEPARATOR . self::MODMAN_FILE_NAME;

        if (!file_exists($sFileName)) {
            throw new Exception ('The directory you would like to link has no modman file.' . PHP_EOL
                . 'Cannot link to this directory.', Modman::ERR_NO_MODMAN_FILE);
        }

        $this->aFileContent = file($sFileName);
    }

    /**
     * returns an array of objects per row
     *
     * @param string $sClassName class which should be used to initialize each row
     * @return array
     */
    public function getObjectsPerRow($sClassName)
    {
        $this->sClassName = $sClassName;
        foreach ($this->aFileContent as $sLine) {
            if (substr($sLine, 0, 1) == '#') {
                // skip comments
                continue;
            }

            $aParameters = $this->getParamsArray($sLine);

            if (substr($sLine, 0, 7) == '@import') {
                $this->doImport($aParameters);

                continue;
            } else if (substr($sLine, 0, 6) == '@shell') {
                unset($aParameters[0]);
                $this->aShells[] = implode(' ', $aParameters);

                continue;
            } else if (substr($sLine, 0, 1) == '@') {
                echo 'Do not understand: ' . $sLine . PHP_EOL;

                continue;
            }

            if (strstr($sLine, '*')) {

                foreach (glob($this->sModuleDirectory . DIRECTORY_SEPARATOR . $aParameters[0]) as $sFilename) {
                    $sRelativeFilename = substr($sFilename, strlen($this->sModuleDirectory . DIRECTORY_SEPARATOR));
                    $sRelativeTarget = str_replace(str_replace('*', '', $aParameters[0]), $aParameters[1], $sRelativeFilename);
                    $this->aObjects[] = new $sClassName(array($sRelativeFilename, $sRelativeTarget));
                }

            } else {
                $this->aObjects[] = new $sClassName($aParameters);
            }
        }
        return $this->aObjects;
    }

    /**
     * returns the params of a line as array
     *
     * @param string $sRow line separated by spaces
     * @return array
     */
    private function getParamsArray($sRow)
    {
        return explode(' ', preg_replace('/\s+/', ' ', $sRow));
    }

    /**
     * imports another file
     *
     * @param array $aCommandParams params submitted to import
     * @throws Exception if the path could not be parsed
     */
    private function doImport($aCommandParams)
    {
        $sDirectoryName = realpath($this->sModuleDirectory . DIRECTORY_SEPARATOR . $aCommandParams[1]);
        if (!$sDirectoryName) {
            throw new Exception('The import path could not be parsed!');
        }

        $oModmanReader = new Modman_Reader($sDirectoryName);
        $aObjects = $oModmanReader->getObjectsPerRow($this->sClassName);

        // Hack to make paths relative to $this->sModuleDirectory
        // Fixes the case when the paths are relative to nested folder, eg "../../file"
        $sBaseDir = getcwd();
        $aObjectsFixed = array();
        foreach ($aObjects as $iLine => $oLine) {
            $sTarget = $oLine->getTarget();
            $sTarget = realpath($sDirectoryName . DIRECTORY_SEPARATOR . $sTarget);
            $sTarget = str_replace($this->sModuleDirectory, '', $sTarget);
            $sTarget = trim($sTarget, DIRECTORY_SEPARATOR);

            $sSymlink = $oLine->getSymlink();
            $sSymlink = str_replace($sBaseDir, '', $sSymlink);
            $sSymlink = trim($sSymlink, DIRECTORY_SEPARATOR);

            $aObjectsFixed[] = new $this->sClassName(array($sTarget, $sSymlink));
        }

        $this->aObjects = array_merge($this->aObjects, $aObjectsFixed);
    }

    /**
     * returns all collected shell commands
     *
     * @return array
     */
    public function getShells()
    {
        return $this->aShells;
    }

}

class Modman_Reader_Conflicts
{

    private $aConflicts = array();

    /**
     * checks for conflicts in file
     *
     * @param string $sSymlink symlink name
     * @param string $sType type (either dir or file)
     * @param string $sTarget = false target where the symlink should link to
     */
    public function checkForConflict($sSymlink, $sType, $sTarget = false)
    {
        if (is_link($sSymlink)) {

            if (!($sType == 'link' && realpath($sSymlink) == realpath($sTarget))) {
                $this->aConflicts[$sSymlink] = 'link';
            }

        } else if (file_exists($sSymlink)) {

            if (is_dir($sSymlink)) {

                if ($sType == 'dir') {
                    return;
                }

                $this->aConflicts[$sSymlink] = 'dir';
            } else {
                $this->aConflicts[$sSymlink] = 'file';
            }

        }
    }

    /**
     * returns if there are any conflicts
     *
     * @return bool true if conflicts exist
     */
    public function hasConflicts()
    {
        return (count($this->aConflicts) > 0);
    }

    /**
     * returns conflicts as human readable string
     *
     * @return string
     */
    public function getConflictsString()
    {

        $sString = '';

        foreach ($this->aConflicts as $sFilename => $sType) {
            switch ($sType) {
                case 'dir':
                    $sString .= $sFilename . ' is an existing directory.' . PHP_EOL;
                    break;
                case 'file':
                    $sString .= $sFilename . ' is an existing file.' . PHP_EOL;
                    break;
                case 'link':
                    $sString .= $sFilename . ' is an existing link pointing to ' . realpath($sFilename) . '.' . PHP_EOL;
                    break;
            }
        }

        return $sString;
    }

    /**
     * removes a linked module
     */
    public function cleanup()
    {

        $oResourceRemover = new Modman_Resource_Remover();

        foreach ($this->aConflicts as $sFilename => $sType) {

            switch ($sType) {
                case 'dir':
                    $oResourceRemover->doRemoveFolderRecursively($sFilename);
                    break;

                case 'file':
                case 'link':
                    $oResourceRemover->doRemoveResource($sFilename);
                    break;
            }
        }
    }
}

class Modman_Command_Deploy
{

    private $sModuleName;

    /**
     * constructor
     *
     * @param string $sModuleName which module to deploy
     * @throws Exception
     */
    public function __construct($sModuleName)
    {

        if (empty($sModuleName)) {
            throw new Exception('please provide a module name to deploy');
        }

        $this->sModuleName = $sModuleName;
    }

    /**
     * executes the deploy
     *
     * @param bool $bForce =false true if errors should be ignored
     * @throws Exception on error
     */
    public function doDeploy($bForce = false)
    {

        if ($this->sModuleName === Modman_Command_Init::MODMAN_BASEDIR_FILE) {
            return;
        }

        $oModmanModuleSymlink = new Modman_Module_Symlink($this->sModuleName);
        $sTarget = $oModmanModuleSymlink->getModmanModuleSymlinkPath();

        $this->oReader = new Modman_Reader($sTarget);
        $aLines = $this->oReader->getObjectsPerRow('Modman_Command_Link_Line');
        $oConflicts = new Modman_Reader_Conflicts();

        foreach ($aLines as $iLine => $oLine) {
            /* @var $oLine Modman_Command_Link_Line */
            if ($oLine->getTarget() AND $oLine->getSymlink()) {
                $sDirectoryName = $oLine->getSymlinkBaseDir();
                if (!is_dir($sDirectoryName)) {
                    $oConflicts->checkForConflict($sDirectoryName, 'dir');
                }
                $oConflicts->checkForConflict($oLine->getSymlink(), 'link', $oLine->getTarget());
            } else {
                unset($aLines[$iLine]);
            }
        }

        if ($oConflicts->hasConflicts()) {
            $sConflictsString = 'conflicts detected: ' . PHP_EOL .
                $oConflicts->getConflictsString() . PHP_EOL;
            if ($bForce) {
                echo $sConflictsString;
                echo 'Doing cleanup ... ' . PHP_EOL;
                $oConflicts->cleanup();
            } else {
                throw new Exception($sConflictsString .
                    'use --force'
                );
            }
        }
        foreach ($aLines as $oLine) {
            /* @var $oLine Modman_Command_Link_Line */
            $sFullTarget = $sTarget . DIRECTORY_SEPARATOR . $oLine->getTarget();
            if (!file_exists($sFullTarget)) {
                throw new Exception('can not link to non-existing file ' . $sFullTarget);
            }
            // create directories if path does not exist
            $sDirectoryName = $oLine->getSymlinkBaseDir();
            if (!is_dir($sDirectoryName)) {
                echo 'Create directory ' . $sDirectoryName . PHP_EOL;
                mkdir($sDirectoryName, 0777, true);
            }
            if (!is_link($oLine->getSymlink())) {
                echo ' Applied: ' . $oLine->getSymlink() . ' ' . $sFullTarget . PHP_EOL;
                symlink(
                    $sFullTarget,
                    $oLine->getSymlink()
                );
            }
        }

        foreach ($this->oReader->getShells() as $sShell) {

            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                $sShell = str_replace('rm -rf', 'deltree', $sShell);
            }

            $sShell = str_replace('$MODULE', $sTarget, $sShell);
            $sShell = str_replace('$PROJECT', getcwd(), $sShell);

            system($sShell);
        }
    }
}


class Modman_Module_Symlink
{
    private $sModuleName;

    /**
     * constructor
     *
     * @param string $sModuleName module name
     * @throws Exception
     */
    public function __construct($sModuleName)
    {
        if (empty($sModuleName)) {
            throw new Exception('please provide a module name to deploy');
        }
        $this->sModuleName = $sModuleName;
    }

    /**
     * returns module symlink path
     *
     * @return string
     * @throws Exception if symlink is not linked
     */
    public function getModmanModuleSymlinkPath()
    {
        $sModmanModuleSymlink = $this->getModmanModuleSymlink();

        if (!is_link($sModmanModuleSymlink) AND !is_dir($sModmanModuleSymlink)) {
            throw new Exception($this->sModuleName . ' is not initialized, please clone or link it');
        }

        $sTarget = realpath($sModmanModuleSymlink);

        return $sTarget;
    }

    /**
     * returns module symlink
     *
     * @return string
     */
    public function getModmanModuleSymlink()
    {
        $sModmanModuleSymlink = Modman_Command_Init::MODMAN_DIRECTORY_NAME . DIRECTORY_SEPARATOR . $this->sModuleName;
        return $sModmanModuleSymlink;
    }
}

class Modman_Command_Clean
{
    private $aDeadSymlinks = array();

    /**
     * executes the clean command
     */
    public function doClean()
    {
        $oResourceRemover = new Modman_Resource_Remover();

        foreach ($this->getDeadSymlinks() as $sSymlink) {
            echo 'Remove ' . $sSymlink . '.' . PHP_EOL;
            $oResourceRemover->doRemoveResource($sSymlink);
        }
    }

    /**
     * returns dead symlinks
     *
     * @param string $sDirectory =NULL define directory to work on, if not defined uses getcwd()
     * @return array list of dead symlinks
     */
    private function getDeadSymlinks($sDirectory = NULL)
    {
        if (is_null($sDirectory)) {
            $sDirectory = getcwd();
        }
        $this->scanForDeadSymlinks($sDirectory);

        return $this->aDeadSymlinks;
    }

    /**
     * recursive scan for dead symlinks
     *
     * @param string $sDirectory
     */
    private function scanForDeadSymlinks($sDirectory)
    {
        foreach (scandir($sDirectory) as $sFilename) {

            if ($sFilename == '.' OR $sFilename == '..') {
                continue;
            }

            $sFullFilename = $sDirectory . DIRECTORY_SEPARATOR . $sFilename;

            if (is_dir($sFullFilename)) {
                $this->scanForDeadSymlinks($sFullFilename);
            }
            else if (is_link($sFullFilename) AND !file_exists(realpath($sFullFilename))) {
                $this->aDeadSymlinks[] = $sFullFilename;
            }
        }
    }
}

class Modman_Command_Remove
{
    /**
     * constructor
     *
     * @param string $sModuleName define module name
     * @throws Exception
     */
    public function __construct($sModuleName)
    {
        if (empty($sModuleName)) {
            throw new Exception('please provide a module name to deploy');
        }
        $this->sModuleName = $sModuleName;
    }

    /**
     * executres remove
     *
     * @param bool $bForce = false, true ignores errors
     * @throws Exception on error
     */
    public function doRemove($bForce = false)
    {
        $oModmanModuleSymlink = new Modman_Module_Symlink($this->sModuleName);
        $sTarget = $oModmanModuleSymlink->getModmanModuleSymlinkPath();

        $this->oReader = new Modman_Reader($sTarget);
        $aLines = $this->oReader->getObjectsPerRow('Modman_Command_Link_Line');

        $oResourceRemover = new Modman_Resource_Remover();

        foreach ($aLines as $oLine) {
            $sOriginalPath = $oLine->getTarget();
            $sSymlinkPath = $oLine->getSymlink();
            if (is_link($sSymlinkPath)
                AND file_exists($sTarget . DIRECTORY_SEPARATOR . $sOriginalPath)
            ) {

                if (is_link($sSymlinkPath)) {
                    $oResourceRemover->doRemoveResource($sSymlinkPath);
                } elseif ($bForce) {
                    $oResourceRemover->doRemoveResource($sSymlinkPath);
                } else {
                    throw new Exception('Problem with removing ' . $sSymlinkPath . ' - use --force');
                }
            }
        }

        $oResourceRemover->doRemoveResource($oModmanModuleSymlink->getModmanModuleSymlink());
    }
}

class Modman_Command_Create
{

    const MAGENTO_MODULE_CODE_RELATIVE_PATH_DEPTH = 4;
    const MAGENTO_MODULE_DESIGN_RELATIVE_PATH_DEPTH = 7;
    const MODMAN_DIRECTORY_NAME = '.modman';

    private $aLinks = array();
    private $sIncludeFilePath;
    private $bListHidden = false;

    /**
     * sets the include file
     *
     * @param string $sFilename
     * @throws Exception if $sFilename does not exist
     */
    public function setIncludeFile($sFilename)
    {
        $sFilePath = realpath($sFilename);
        if (!$sFilePath) {
            throw new Exception("please provide a valid include file");
        } else {
            $this->sIncludeFilePath = $sFilePath;
        }
    }

    /**
     * executes create command
     *
     * @param bool $force - if true errors will be ignored
     * @param bool $directory - directory to create
     * @param bool $listHidden = false, if true hidden files will be listed
     * @throws Exception on errors
     */
    public function doCreate($force, $directory, $listHidden = false)
    {
        $this->bListHidden = $listHidden;

        if ($directory && is_dir($directory)) {
            chdir($directory);
        }
        else{
            throw new Exception($directory . ' is not a directory');
        }

        $aDirectoryStructure = $this->getDirectoryStructure(getcwd());

        $this->generateLinkListFromDirectoryStructure($aDirectoryStructure);

        if ($this->existsModmanFile() && !$force) {
            throw new Exception('modman file ' . $this->getModmanFilePath() . ' already exists. Use --force');
        }
        else {
            $this->generateModmanFile();
        }
    }

    /**
     * returns directory structure
     *
     * @param string $sDirectoryPath
     * @return array with directory structure
     */
    private function getDirectoryStructure($sDirectoryPath)
    {
        $aResult = array();

        $aCurrentDirectoryListing = scandir($sDirectoryPath);
        foreach ($aCurrentDirectoryListing as $sNode) {
            $sDirectoryPathToCheck = $sDirectoryPath . DIRECTORY_SEPARATOR . $sNode;
            if ((!$this->isHiddenNode($sNode) OR $this->bListHidden)
                AND !in_array($sNode, array_merge( $this->getBlacklist(), array('.', '..', 'modman', 'README', 'README.md', 'composer.json', 'atlassian-ide-plugin.xml') ) )
            ) {
                if (is_dir($sDirectoryPathToCheck)
                    AND !$this->isDirectoryEmpty($sDirectoryPathToCheck)
                    AND !$this->isMagentoModuleDirectory($sDirectoryPathToCheck)
                ) {
                    $aResult[$sNode] = $this->getDirectoryStructure($sDirectoryPathToCheck);
                } else {
                    $aResult[] = $sNode;
                }
            }
        }

        $this->appendWhitelist($aResult);

        return $aResult;
    }

    private function appendWhitelist(&$aResult) {

        $filehandler = fopen( self::MODMAN_DIRECTORY_NAME . DIRECTORY_SEPARATOR . 'whitelist', 'r', FILE_USE_INCLUDE_PATH );

        while (($buffer = fgets($filehandler, 4096)) !== false) {
            $aResult[]  =   $buffer;
        }
        fclose($filehandler);

        return $aResult;
    }

    private function getBlacklist() {

        $aResult        =   array();
        $filehandler    =   fopen( self::MODMAN_DIRECTORY_NAME . DIRECTORY_SEPARATOR . 'blacklist', 'r', FILE_USE_INCLUDE_PATH );

        while (($buffer = fgets($filehandler, 4096)) !== false) {
            $aResult[]  =   $buffer;
        }
        fclose($filehandler);

        return $aResult;
    }

    /**
     * checks if node is a hidden once
     *
     * @param string $sNode
     * @return bool true for hidden files
     */
    private function isHiddenNode($sNode)
    {
        return strlen($sNode) > 2 AND substr($sNode, 0, 1) == '.';
    }

    /**
     * checks if a directory is empty
     *
     * @param string $sDirectoryPath
     * @return bool true if directory is empty
     */
    private function isDirectoryEmpty($sDirectoryPath)
    {
        $aCurrentDirectoryListing = scandir($sDirectoryPath);
        return count($aCurrentDirectoryListing) <= 2;
    }

    /**
     * checks if directory is a magento module
     *
     * @param string $sDirectoryPathToCheck
     * @return bool true if directory is a magento module
     */
    private function isMagentoModuleDirectory($sDirectoryPathToCheck)
    {
        $aPathParts = explode(DIRECTORY_SEPARATOR, $sDirectoryPathToCheck);

        $iAppPosition = array_search('app', $aPathParts);
        if (!$iAppPosition) {
            return false;
        }
        return (
            $this->isMagentoModuleCodeDirectory($aPathParts, $iAppPosition)
            OR $this->isMagentoModuleDesignDirectory($aPathParts, $iAppPosition)
        );
    }

    /**
     * checks if directory is the magento code directory
     *
     * @param array $aPathParts - all path parts from this directory
     * @param integer $iAppPosition - position of app directory in $aPathParts
     * @return bool true if directory is the magento code directory
     */
    private function isMagentoModuleCodeDirectory($aPathParts, $iAppPosition)
    {
        if (!isset($aPathParts[$iAppPosition + self::MAGENTO_MODULE_CODE_RELATIVE_PATH_DEPTH])) {
            return false;
        }

        if ($aPathParts[$iAppPosition + 1] == 'code'
            AND in_array($aPathParts[$iAppPosition + 2], array('community', 'local'))
        ) {
            return true;
        }
    }

    /**
     * checks if directory is the magento design directory
     *
     * @param array $aPathParts - all path parts from this directory
     * @param integer $iAppPosition - position of app directory in $aPathParts
     * @return bool true if directory is the magento design directory
     */
    private function isMagentoModuleDesignDirectory($aPathParts, $iAppPosition)
    {
        if (!isset($aPathParts[$iAppPosition + self::MAGENTO_MODULE_DESIGN_RELATIVE_PATH_DEPTH])) {
            return false;
        }

        if ($aPathParts[$iAppPosition + 1] == 'design'
            AND (
                $aPathParts[$iAppPosition + 2] == 'frontend'
                OR $aPathParts[$iAppPosition + 2] == 'adminhtml'
                OR $aPathParts[$iAppPosition + 2] == 'install'
            )
            AND $aPathParts[$iAppPosition + 3] == 'base'
            AND $aPathParts[$iAppPosition + 4] == 'default'
            AND $aPathParts[$iAppPosition + 5] == 'template'
        ) {
            return true;
        }
    }

    /**
     * generates link list from directory structure
     *
     * @param array $aDirectoryStructure created by $this->getDirectoryStructure(string)
     * @param array $aPathElements = array()
     */
    private function generateLinkListFromDirectoryStructure($aDirectoryStructure, $aPathElements = array())
    {

        foreach ($aDirectoryStructure as $sDirectory => $mElements) {

            if (!is_array($mElements) ) {

                $_fullPath = realpath(implode(DIRECTORY_SEPARATOR, $aPathElements) . DIRECTORY_SEPARATOR . $mElements);

                if(is_dir($_fullPath) && $this->isDirectoryEmpty($_fullPath)){
                   continue;
                }
                else if (is_dir($_fullPath) || is_file($_fullPath) ){
                    
                    if(count($aPathElements) > 0 ) {
                        $record = implode(
                                DIRECTORY_SEPARATOR, $aPathElements) .
                                DIRECTORY_SEPARATOR .
                                $mElements;

                        $this->aLinks[] = $record;
                    }

                }
            }
            else {
                $this->generateLinkListFromDirectoryStructure($mElements, array_merge($aPathElements, array($sDirectory)));
            }
        }
    }

    /**
     * checks if modman file exists
     *
     * @return bool true if modman file exists
     */
    private function existsModmanFile()
    {
        return file_exists($this->getModmanFilePath());
    }

    /**
     * returns modman file path
     *
     * @return string
     */
    private function getModmanFilePath()
    {
        return getcwd() . DIRECTORY_SEPARATOR . Modman_Reader::MODMAN_FILE_NAME;
    }

    /**
     * generates modman file
     */
    private function generateModmanFile()
    {
        if (file_exists($this->getModmanFilePath())) {
            unlink($this->getModmanFilePath());
        }

        $sOutput = '';

        foreach ($this->aLinks as $sLink) {

            $sLink = '/' . $sLink;

            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                $sLink = str_replace('\\', '/', $sLink);
            }

            $sOutput .= $sLink . ' ' . $sLink . PHP_EOL;
        }

        $rModmanFile = fopen($this->getModmanFilePath(), 'w');

        fputs($rModmanFile, $sOutput);

        // if include file defined, include it to the modman
        if ($this->sIncludeFilePath) {
            $sIncludeFileContent = file_get_contents($this->sIncludeFilePath);
            fputs($rModmanFile, "\n" . $sIncludeFileContent);
        }

        fclose($rModmanFile);
    }

}

class Modman_Command_Clone
{

    /**
     * Url if git repo to be cloned
     *
     * @var string
     */
    private $sGitUrl;
    /**
     * name of the folder for the git repo to be cloned into
     *
     * @var string
     */
    private $sFolderName;
    /**
     * command to create modman file
     *
     * @var Modman_Command_Create
     */
    private $oCreate;

    /**
     * saves the git url and pre-calculates local folder name
     *
     * @param string $sGitUrl
     * @param Modman_Command_Create $oCreate
     */
    public function __construct($sGitUrl, Modman_Command_Create $oCreate)
    {
        $this->sGitUrl = $sGitUrl;
        $this->sFolderName = $this->getFolderNameFromParam($sGitUrl);
        $this->oCreate = $oCreate;
    }

    /**
     * calculates local folder name from git url or directory
     *
     * @param string $sGitUrl
     * @return string
     */
    private function getFolderNameFromParam($sGitUrl)
    {
        // is this a url
        if (strstr($sGitUrl, '/')) {
            $aSlashParts = explode('/', $sGitUrl);
            if (strpos($aSlashParts[count($aSlashParts) - 1], '.git') !== false) {
                $aDotParts = explode('.', $aSlashParts[count($aSlashParts) - 1]);
                $sFolderName = $aDotParts[0];
            } else {
                $sFolderName = $aSlashParts[count($aSlashParts) - 1];
            }
            // or a directory?
        } else {
            $sFolderName = basename($sGitUrl);
        }

        return $sFolderName;
    }

    /**
     * main method to create a clone of a git repo
     *
     * @param bool $bForce
     * @param bool $bCreateModman
     * @throws Exception
     */
    public function doClone($bForce = false, $bCreateModman = false)
    {
        $sCwd = getcwd();
        $sInitPath = realpath($sCwd);
        $oInit = new Modman_Command_Init();
        $oInit->doInit($sInitPath);

        if ($this->existsModuleFolder()) {
            if (!$bForce) {
                throw new Exception('Module already exists. Please use --force to overwrite existing folder');
            } else {
                $this->deleteModuleFolder($this->getModuleFolderPath());
            }
        }
        $this->executeClone();

        if (!$this->existsModmanFile() AND $bCreateModman) {
            $this->doCreateModmanFile();
        }

        $oDeploy = new Modman_Command_Deploy($this->sFolderName);
        $oDeploy->doDeploy($bForce);

    }

    /**
     * checks if module folder exists
     *
     * @return bool
     */
    private function existsModuleFolder()
    {
        return is_dir($this->getModuleFolderPath());
    }

    /**
     * returns path to module folder
     *
     * @return string
     */
    private function getModuleFolderPath()
    {
        return getcwd() . DIRECTORY_SEPARATOR
        . Modman_Command_Init::MODMAN_DIRECTORY_NAME . DIRECTORY_SEPARATOR
        . $this->sFolderName;
    }

    /**
     * deletes module folder
     *
     * @param string $sFolderName
     * @return bool
     */
    private function deleteModuleFolder($sFolderName)
    {
        $oRemover = new Modman_Resource_Remover();
        $oRemover->doRemoveFolderRecursively($sFolderName);
    }

    /**
     * executes git clone command
     */
    private function executeClone()
    {
        shell_exec(
            'git clone ' . escapeshellarg($this->sGitUrl) . ' '
            . escapeshellarg($this->getModuleFolderPath())
        );
    }

    /**
     * checks if modman file exists in module folder
     *
     * @return bool
     */
    private function existsModmanFile()
    {
        return is_file(
            $this->getModuleFolderPath() . DIRECTORY_SEPARATOR
            . Modman_Reader::MODMAN_FILE_NAME
        );
    }

    /**
     * creates modman file in the module folder
     */
    private function doCreateModmanFile()
    {
        $sCurrentDirectory = getcwd();
        chdir($this->getModuleFolderPath());
        $this->oCreate->doCreate(false);
        chdir($sCurrentDirectory);
    }
}

class Modman_Resource_Remover
{

    /**
     * deletes folder recursively
     *
     * @param string $sFolderName
     * @return bool
     */
    public function doRemoveFolderRecursively($sFolderName)
    {

        $oDirectoryIterator = new RecursiveDirectoryIterator($sFolderName);
        /** @var SplFileInfo $oElement */
        foreach (new RecursiveIteratorIterator($oDirectoryIterator, RecursiveIteratorIterator::CHILD_FIRST) as $oElement) {
            $this->doRemoveResource($oElement->getPathname());
        }
        $this->doRemoveResource($sFolderName);
    }

    /**
     * removes a resource
     *
     * @param string $sElementPath resource to remove
     */
    public function doRemoveResource($sElementPath)
    {
        if (is_dir($sElementPath)) {
            if( $this->isFolderEmpty($sElementPath) ){
                rmdir($sElementPath);
            }
            else if ( is_link($sElementPath) ) {
                unlink($sElementPath);
            }
        }
        else if (is_file($sElementPath)) {
            // workaround for windows to delete read-only flag
            // which prevents file from being deleted properly
            chmod($sElementPath, 0777);
            unlink($sElementPath);
        }
        else if (is_link($sElementPath)) {
            unlink($sElementPath);
        }
    }

    /**
     * checks if the folder is empty
     *
     * @param string $sDirectoryPath
     * @return bool
     */
    private function isFolderEmpty($sDirectoryPath)
    {
        return count(scandir($sDirectoryPath)) == 2;
    }
}


class Modman_Command_BlackAndWhitelist {

	const MODMAN_DIRECTORY_NAME = '.modman';

	public function __construct() {
		return $this;
	}

	/**
	 * Apply file to white or blacklist
	 *
	 * @param string $filename
	 * @throws Exception
	 */
	public function apply($type, $filename)
	{
		if (empty($filename)) {
			throw new Exception('please provide a file or directory to whitelist');
		}

		if(is_dir($filename) || is_file($filename)) {

			$filehandler = fopen( self::MODMAN_DIRECTORY_NAME . DIRECTORY_SEPARATOR . $type, 'a+' );

			if( strpos(file_get_contents(self::MODMAN_DIRECTORY_NAME . DIRECTORY_SEPARATOR . $type), $filename) === false) {
                fwrite($filehandler, $filename . PHP_EOL);
			}

			fclose($filehandler);

		} else {
			throw new Exception('Specify a valid and existing file or directory');
		}
	}


	public function createFile($type) {

		if(!empty($type)) {
			unlink(self::MODMAN_DIRECTORY_NAME . DIRECTORY_SEPARATOR . $type);

			$filehandler = fopen(self::MODMAN_DIRECTORY_NAME . DIRECTORY_SEPARATOR . $type, 'w+');

			if ($type == 'whitelist') {
				$files = $this->getWhilelistFiles();
			} else if($type == 'blacklist') {
				$files = $this->getBlacklistFiles();
			}

			foreach($files as $file) {
                fputs($filehandler, $file . PHP_EOL);
			}
			fclose($filehandler);
		}
	}


	private function getWhilelistFiles() {
		return array(
			'.bowerrc'
		);
	}


	private function getBlacklistFiles() {
		return array(
			'Gruntfile.js',
			'app/etc/local.xml',
			'.htaccess'
		);
	}

}




$oModman = new Modman();
$oModman->run($argv);