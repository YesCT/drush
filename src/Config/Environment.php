<?php
namespace Drush\Config;

use Composer\Autoload\ClassLoader;

use Webmozart\PathUtil\Path;

/**
 * Store information about the environment
 */
class Environment
{
    protected $homeDir;
    protected $originalCwd;
    protected $etcPrefix;
    protected $sharePrefix;
    protected $drushBasePath;
    protected $vendorDir;

    protected $docPrefix;

    protected $loader;
    protected $siteLoader;

    /**
     * Environment constructor
     * @param string $homeDir User home directory.
     * @param string $cwd The current working directory at the time Drush was called.
     * @param string $autoloadFile Path to the autoload.php file.
     */
    public function __construct($homeDir, $cwd, $autoloadFile)
    {
        $this->homeDir = $homeDir;
        $this->originalCwd = Path::canonicalize($cwd);
        $this->etcPrefix = '';
        $this->sharePrefix = '';
        $this->drushBasePath = dirname(dirname(__DIR__));
        $this->vendorDir = dirname($autoloadFile);
    }

    /**
     * Load the autoloader for the selected Drupal site
     */
    public function loadSiteAutoloader($root)
    {
        $autloadFilePath = "$root/autoload.php";
        if (!file_exists($autloadFilePath)) {
            return $this->loader;
        }

        if ($this->siteLoader) {
            return $this->siteLoader;
        }

        $this->siteLoader = require $autloadFilePath;
        if ($this->siteLoader === true) {
            // The autoloader was already required. Assume that Drush and Drupal share an autoloader per
            // "Point autoload.php to the proper vendor directory" - https://www.drupal.org/node/2404989
            $this->siteLoader = $this->loader;
        }

        // Ensure that the site's autoloader has highest priority. Usually,
        // the first classloader registered gets the first shot at loading classes.
        // We want Drupal's classloader to be used first when a class is loaded,
        // and have Drush's classloader only be called as a fallback measure.
        $this->siteLoader->unregister();
        $this->siteLoader->register(true);

        return $this->siteLoader;
    }

    /**
     * Convert the environment object into an exported configuration
     * array. This will be fed though the EnvironmentConfigLoader to
     * be added into the ConfigProcessor, where it will become accessible
     * via the configuration object.
     *
     * So, this seems like a good idea becuase we already have ConfigAwareInterface
     * et. al. that makes the config object easily available via dependency
     * injection. Instead of this, we could also add the Environment object
     * to the DI container and make an EnvironmentAwareInterface & etc.
     *
     * Not convinced that is better, but this mapping will grow.
     *
     * @return array Nested associative array that is overlayed on configuration.
     */
    public function exportConfigData()
    {
        // TODO: decide how to organize / name this hierarchy.
        // i.e. which is better:
        //   $config->get('drush.base-dir')
        //     - or -
        //   $config->get('drush.base.dir')
        return [
            // Information about the environment presented to Drush
            'env' => [
                'cwd' => $this->cwd(),
                'home' => $this->homeDir(),
                'is-windows' => $this->isWindows(),
            ],
            // These values are available as global options, and
            // will be passed in to the FormatterOptions et. al.
            'options' => [
                'width' => $this->calculateColumns(),
            ],
            // Information about the directories where Drush found assets, etc.
            'drush' => [
                'base-dir' => $this->drushBasePath,
                'vendor-dir' => $this->vendorPath(),
                'docs-dir' => $this->docsPath(),
                'user-dir' => $this->userConfigPath(),
                'system-dir' => $this->systemConfigPath(),
                'system-command-dir' => $this->systemCommandFilePath(),
            ],
        ];
    }

    /**
     * The base directory of the Drush application itself
     * (where composer.json et.al. are found)
     *
     * @return string
     */
    public function drushBasePath()
    {
        return $this->drushBasePath;
    }

    /**
     * User's home directory
     *
     * @return string
     */
    public function homeDir()
    {
        return $this->homeDir;
    }

    /**
     * The user's Drush configuration directory, ~/.drush
     *
     * @return string
     */
    public function userConfigPath()
    {
        return $this->homeDir() . '/.drush';
    }

    /**
     * The original working directory
     *
     * @return string
     */
    public function cwd()
    {
        return $this->originalCwd;
    }

    /**
     * Return the path to Drush's vendor directory
     *
     * @return string
     */
    public function vendorPath()
    {
        return $this->vendorDir;
    }

    /**
     * The class loader returned when the autoload.php file is included.
     *
     * @return \Composer\Autoload\ClassLoader
     */
    public function loader()
    {
        return $this->loader;
    }

    /**
     * Set the class loader from the autload.php file, if available.
     *
     * @param \Composer\Autoload\ClassLoader $loader
     */
    public function setLoader(ClassLoader $loader)
    {
        $this->loader = $loader;
    }

    /**
     * Alter our default locations based on the value of environment variables
     *
     * @return $this
     */
    public function applyEnvironment()
    {
        // Copy ETC_PREFIX and SHARE_PREFIX from environment variables if available.
        // This alters where we check for server-wide config and alias files.
        // Used by unit test suite to provide a clean environment.
        $this->setEtcPrefix(getenv('ETC_PREFIX'));
        $this->setSharePrefix(getenv('SHARE_PREFIX'));

        return $this;
    }

    /**
     * Set the directory prefix to locate the directory that Drush will
     * use as /etc (e.g. during the functional tests)
     *
     * @param string $etcPrefix
     * @return $this
     */
    public function setEtcPrefix($etcPrefix)
    {
        if (isset($etcPrefix)) {
            $this->etcPrefix = $etcPrefix;
        }
        return $this;
    }

    /**
     * Set the directory prefix to locate the directory that Drush will
     * use as /user/share (e.g. during the functional tests)
     * @param string $sharePrefix
     * @return $this
     */
    public function setSharePrefix($sharePrefix)
    {
        if (isset($sharePrefix)) {
            $this->sharePrefix = $sharePrefix;
            $this->docPrefix = null;
        }
        return $this;
    }

    /**
     * Return the directory where Drush's documentation is stored. Usually
     * this is within the Drush application, but some Drush RPM distributions
     * & c. for Linux platforms slice-and-dice the contents and put the docs
     * elsewhere.
     *
     * @return string
     */
    public function docsPath()
    {
        if (!$this->docPrefix) {
            $this->docPrefix = $this->findDocsPath($this->drushBasePath);
        }
        return $this->docPrefix;
    }

    /**
     * Locate the Drush documentation. This is recalculated whenever the
     * share prefix is changed.
     *
     * @param string $drushBasePath
     * @return string
     */
    protected function findDocsPath($drushBasePath)
    {
        $candidates = [
            "$drushBasePath/README.md",
            static::systemPathPrefix($this->sharePrefix, '/usr') . '/share/docs/drush/README.md',
        ];
        return $this->findFromCandidates($candidates);
    }

    /**
     * Check a list of directories and return the first one that exists.
     *
     * @param string $candidates
     * @return boolean
     */
    protected function findFromCandidates($candidates)
    {
        foreach ($candidates as $candidate) {
            if (file_exists($candidate)) {
                return dirname($candidate);
            }
        }
        return false;
    }

    /**
     * Return the appropriate system path prefix, unless an override is provided.
     * @param string $override
     * @param string $defaultPrefix
     * @return string
     */
    protected static function systemPathPrefix($override = '', $defaultPrefix = '')
    {
        if ($override) {
            return $override;
        }
        return static::isWindows() ? getenv('ALLUSERSPROFILE') . '/Drush' : $defaultPrefix;
    }

    /**
     * Return the system configuration path (default: /etc/drush)
     *
     * @return string
     */
    public function systemConfigPath()
    {
        return static::systemPathPrefix($this->etcPrefix, '') . '/etc/drush';
    }

    /**
     * Return the system shared commandfile path (default: /usr/share/drush/commands)
     *
     * @return string
     */
    public function systemCommandFilePath()
    {
        return static::systemPathPrefix($this->sharePrefix, '/usr') . '/share/drush/commands';
    }

    /**
     * Determine whether current OS is a Windows variant.
     *
     * @return boolean
     */
    public static function isWindows($os = null)
    {
        return strtoupper(substr($os ?: PHP_OS, 0, 3)) === 'WIN';
    }

    /**
     * Verify that we are running PHP through the command line interface.
     *
     * @return boolean
     *   A boolean value that is true when PHP is being run through the command line,
     *   and false if being run through cgi or mod_php.
     */
    public function verifyCLI()
    {
        return (php_sapi_name() == 'cli' || (is_numeric($_SERVER['argc']) && $_SERVER['argc'] > 0));
    }

    /**
     * Calculate the terminal width used for wrapping table output.
     * Normally this is exported using tput in the drush script.
     * If this is not present we do an additional check using stty here.
     * On Windows in CMD and PowerShell is this exported using mode con.
     *
     * @return integer
     */
    public function calculateColumns()
    {
        if ($columns = getenv('COLUMNS')) {
            return $columns;
        }

        // Trying to export the columns using stty.
        exec('stty size 2>&1', $columns_output, $columns_status);
        if (!$columns_status) {
            $columns = preg_replace('/\d+\s(\d+)/', '$1', $columns_output[0], -1, $columns_count);
        }

        // If stty fails and Drush us running on Windows are we trying with mode con.
        if (($columns_status || !$columns_count) && static::isWindows()) {
            $columns_output = [];
            exec('mode con', $columns_output, $columns_status);
            if (!$columns_status && is_array($columns_output)) {
                $columns = (int)preg_replace('/\D/', '', $columns_output[4], -1, $columns_count);
            }
            // TODO: else { 'Drush could not detect the console window width. Set a Windows Environment Variable of COLUMNS to the desired width.'
        }

        // Failling back to default columns value
        if (empty($columns)) {
            $columns = 80;
        }

        // TODO: should we deal with reserve-margin here, or adjust it later?
        return $columns;
    }
}
