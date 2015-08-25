<?php
namespace Kwf\ComposerExtraAssets;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;

use Kwf\ComposerExtraAssets\VersionMatcher;

class Plugin implements PluginInterface, EventSubscriberInterface
{
    protected $composer;
    protected $io;

    public function activate(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;
        $this->io = $io;
    }

    public static function getSubscribedEvents()
    {
        return array(
            'post-install-cmd' => array(
                array('onPostInstall', 0)
            ),
            'post-update-cmd' => array(
                array('onPostUpdate', 0)
            ),
        );
    }

    public function onPostInstall(Event $event)
    {
        $assetsLockFile = new JsonFile('composer-extra-assets.lock');
        if (!$assetsLockFile->exists()) {
            //no lock exists, behave like composer update
            $this->onPostUpdate($event);
        } else {
            $assetsLock = $assetsLockFile->read();

            $packages = $this->composer->getRepositoryManager()->getLocalRepository()->getCanonicalPackages();
            $mergedNpmPackages = array();
            foreach ($packages as $package) {
                if ($package instanceof \Composer\Package\CompletePackage) {
                    $extra = $package->getExtra();
                    if (!isset($extra['expose-npm-packages']) || $extra['expose-npm-packages'] != true) {
                        if (isset($assetsLock['npm-dependencies'][$package->getName()])) {
                            $this->_installNpm($this->composer->getConfig()->get('vendor-dir') . '/' .$package->getName(), $package, false, array(), $assetsLock['npm-dependencies'][$package->getName()]);
                        }
                    } else {
                        $mergedNpmPackages[] = $package;
                    }
                }
            }

            if (isset($assetsLock['npm-dependencies']['self'])) {
                $this->_installNpm('.', $this->composer->getPackage(), $event->isDevMode(), $mergedNpmPackages, $assetsLock['npm-dependencies']['self']);
            }

            if ($assetsLock['bower-dependencies']) {
                $this->_installBower($assetsLock['bower-dependencies']);
            }
        }
    }

    public function onPostUpdate(Event $event)
    {
        $assetsLock = array(
            'bower-dependencies' => array(),
            'npm-dependencies' => array()
        );

        $packages = $this->composer->getRepositoryManager()->getLocalRepository()->getCanonicalPackages();
        $mergedNpmPackages = array();
        // NPM install for dependencies that are not exposed.
        foreach ($packages as $package) {
            if ($package instanceof \Composer\Package\CompletePackage) {
                $extra = $package->getExtra();
                if (!isset($extra['expose-npm-packages']) || $extra['expose-npm-packages'] != true) {
                    $shrinkwrapDeps = $this->_installNpm($this->composer->getConfig()->get('vendor-dir') . '/' .$package->getName(), $package, false, array(), null);
                    if ($shrinkwrapDeps) {
                        $assetsLock['npm-dependencies'][$package->getName()] = $shrinkwrapDeps;
                    }
                } else {
                    $mergedNpmPackages[] = $package;
                }
            }
        }

        // NPM install for dependencies that are exposed on the root package.
        $shrinkwrapDeps = $this->_installNpm('.', $this->composer->getPackage(), $event->isDevMode(), $mergedNpmPackages, null);
        if ($shrinkwrapDeps) {
            $assetsLock['npm-dependencies']['self'] = $shrinkwrapDeps;
        }

        $this->_createNpmBinaries();

        $requireBower = array();

        if ($event->isDevMode()) {
            $extra = $this->composer->getPackage()->getExtra();
            if (isset($extra['require-dev-bower'])) {
                foreach ($extra['require-dev-bower'] as $packageName => $versionConstraint) {
                    if (isset($requireBower[$packageName])) {
                        $v = VersionMatcher::matchVersions($requireBower[$packageName], $versionConstraint);
                        if ($v === false) {
                            throw new \Exception("{$package->getName()} requires $packageName '$versionConstraint' but we have already incompatible '{$requireBower[$packageName]}'");
                        }
                    } else {
                        $v = $versionConstraint;
                    }
                    $requireBower[$packageName] = $v;
                }
            }
        }

        $packages = array(
            $this->composer->getPackage()
        );
        $packages = array_merge($packages, $this->composer->getRepositoryManager()->getLocalRepository()->getCanonicalPackages());
        foreach ($packages as $package){
            if ($package instanceof \Composer\Package\CompletePackage) {
                $extra = $package->getExtra();
                if (isset($extra['require-bower'])) {
                    foreach ($extra['require-bower'] as $packageName => $versionConstraint) {
                        if (isset($requireBower[$packageName])) {
                            $v = VersionMatcher::matchVersions($requireBower[$packageName], $versionConstraint);
                            if ($v === false) {
                                throw new \Exception("{$package->getName()} requires $packageName '$versionConstraint' but we have already incompatible '{$requireBower[$packageName]}'");
                            }
                        } else {
                            $v = $versionConstraint;
                        }
                        $requireBower[$packageName] = $v;
                    }
                }
            }
        }

        if ($requireBower) {
            $assetsLock['bower-dependencies'] = $this->_installBower($requireBower);
        }

        $assetsLockFile = new JsonFile('composer-extra-assets.lock');
        $assetsLockFile->write($assetsLock);
    }

    private function _installBower($requireBower)
    {
        $out = array();
        $retVar = null;
        exec("bower --version 2>&1", $out, $retVar);
        if ($retVar) {
            //bower isn't installed globally, install locally
            $dir = $this->composer->getConfig()->get('vendor-dir').'/koala-framework/composer-extra-assets';
            $this->_installNpmDependencies($dir, array(
                'bower' => '*'
            ));
            $node = $this->composer->getConfig()->get('bin-dir').'/node';
            $bowerBin = "$node ".$dir . "/node_modules/bower/bin/bower";
        } else {
            $bowerBin = 'bower';
        }

        $jsonFile = new JsonFile('bower.json');

        if ($jsonFile->exists()) {
            $packageJson = $jsonFile->read();
            if (!isset($packageJson['name']) || $packageJson['name'] != 'temp-composer-extra-asssets') { //assume we can overwrite our own temp one
                throw new \Exception("Can't install npm dependencies as there is already a bower.json");
            }
        } else {
            $packageJson = array(
                'name' => 'temp-composer-extra-asssets',
                'description' => "This file is auto-generated by 'koala-framework/composer-extra-assets'. You can " .
                    "modify this file but the 'dependencies' section will be overwritten each time you run " .
                    "composer install or composer update. You must not change the 'name' section.",
            );
        }
        $packageJson['dependencies'] = $requireBower;
        $jsonFile->write($packageJson);
        if (!file_exists('.bowerrc')) {
            $vd = $this->composer->getConfig()->get('vendor-dir');
            if (substr($vd, 0, strlen(getcwd())) == getcwd()) {
                //make vendor-dir relative go cwd
                $vd = substr($vd, strlen(getcwd())+1);
            }
            $config = array(
                'directory' => $vd . '/bower_components'
            );
            file_put_contents('.bowerrc', json_encode($config,  JSON_PRETTY_PRINT |  JSON_UNESCAPED_SLASHES |
                JSON_UNESCAPED_UNICODE));
        }
        $this->io->write("");
        $this->io->write("installing bower dependencies...");

        $cmd = "$bowerBin --allow-root install";
        passthru($cmd, $retVar);
        if ($retVar) {
            throw new \RuntimeException('bower install failed');
        }

        $cmd = "$bowerBin --allow-root prune";
        passthru($cmd, $retVar);
        if ($retVar) {
            throw new \RuntimeException('bower prune failed');
        }


        $config = json_decode(file_get_contents('.bowerrc'), true);
        $installedBowerFiles = glob($config['directory'].'/*/.bower.json');

        //detect actually installed versions
        $ret = array();
        foreach ($installedBowerFiles as $installedBowerFile) {
            $installedBower = json_decode(file_get_contents($installedBowerFile), true);
            $dep = $installedBower['_source'].'#'.$installedBower['_release'];
            $ret[$installedBower['name']] = $dep;
        }
        return $ret;
    }

    private function _installNpm($path, $package, $devMode, array $mergedPackages, $shrinkwrapDependencies)
    {
        $dependencies = array();

        $extra = $package->getExtra();
        if ($devMode) {
            if (isset($extra['require-dev-npm']) && count($extra['require-dev-npm'])) {
                $dependencies = $this->mergeNpmVersions($dependencies, $extra['require-dev-npm']);
            }

        }

        if (isset($extra['require-npm']) && count($extra['require-npm'])) {
            $dependencies = $this->mergeNpmVersions($dependencies, $extra['require-npm']);
        }

        foreach ($mergedPackages as $dep) {
            $packageExtra = $dep->getExtra();
            if (isset($packageExtra['require-npm']) && count($packageExtra['require-npm'])) {
                $dependencies = $this->mergeNpmVersions($dependencies, $packageExtra['require-npm']);
            }
        }

        $ret = null;
        if ($dependencies) {
            $ret = $this->_installNpmDependencies($path, $dependencies, $shrinkwrapDependencies);
        }
        return $ret;
    }

    /**
     * Merges 2 version of arrays.
     *
     * @param array $array1
     * @param array $array2
     * @return array
     */
    private function mergeNpmVersions(array $array1, array $array2) {
        foreach ($array2 as $package => $version) {
            if (!isset($array1[$package])) {
                $array1[$package] = $version;
            } else {
                if ($array1[$package] != $version) {
                    $array1[$package] .= " ".$version;
                }
            }
        }
        return $array1;
    }

    private function _installNpmDependencies($path, $dependencies, $shrinkwrapDependencies)
    {
        $prevCwd = getcwd();
        chdir($path);
        $jsonFile = new JsonFile('package.json');
        if ($jsonFile->exists()) {
            $packageJson = $jsonFile->read();
            if (!isset($packageJson['name']) || $packageJson['name'] != 'composer-extra-asssets') { //assume we can overwrite our own temp one
                throw new \Exception("Can't install npm dependencies as there is already a package.json");
            }
        } else {
            $packageJson = array(
                'name' => 'composer-extra-asssets',
                'description' => "This file is auto-generated by 'koala-framework/composer-extra-assets'. You can " .
                    "modify this file but the 'dependencies' section will be overwritten each time you run " .
                    "composer install or composer update. You must not change the 'name' section.",
                'readme' => ' ',
                'repository' => array('type'=>'git'),
            );
        }
        $packageJson['dependencies'] = $dependencies;
        $jsonFile->write($packageJson);

        $shrinkwrapJsonFile = new JsonFile('npm-shrinkwrap.json');
        if ($shrinkwrapDependencies) {
            $shrinkwrapJson = array(
                'name' => 'composer-extra-asssets',
                'dependencies' => $shrinkwrapDependencies,
            );
            $shrinkwrapJsonFile->write($shrinkwrapJson);
        } else {
            if ($shrinkwrapJsonFile->exists()) {
                unlink('npm-shrinkwrap.json');
            }
        }

        $this->io->write("");
        $this->io->write("installing npm dependencies in '$path'...");
        $npm = $this->composer->getConfig()->get('bin-dir').'/npm';
        $cmd = "$npm install";
        passthru($cmd, $retVar);
        if ($retVar) {
            throw new \RuntimeException('npm install failed');
        }

        if (!$shrinkwrapDependencies) {
            $cmd = "$npm update --depth=9999";
            passthru($cmd, $retVar);
            if ($retVar) {
                throw new \RuntimeException('npm update failed');
            }
        }

        $cmd = "$npm prune";
        passthru($cmd, $retVar);
        if ($retVar) {
            throw new \RuntimeException('npm prune failed');
        }

        $cmd = "$npm shrinkwrap";
        passthru($cmd, $retVar);
        if ($retVar) {
            throw new \RuntimeException('npm shrinkwrap failed');
        }
        $shrinkwrap = json_decode(file_get_contents('npm-shrinkwrap.json'), true);

        if ($shrinkwrapDependencies) {
            if (!$this->_shrinkwrapVersionsEqual($shrinkwrap['dependencies'], $shrinkwrapDependencies)) {
                //npm install doesn't downgrade packages in npm-shrinkwrap.json
                throw new \RuntimeException("npm shrinkwrap returned different result than install should have produced. Probably packages need to be downgraded. Consider deleting '$path/node_modules'.");
            }
        }


        if ($path != '.') {
            unlink('package.json');
        }
        unlink('npm-shrinkwrap.json');

        chdir($prevCwd);

        return $shrinkwrap['dependencies'];
    }

    private function _shrinkwrapVersionsEqual($a, $b)
    {
        foreach ($a as $depName=>$i) {
            if (!isset($b[$depName]['version'])) {
                return false;
            }
            if ($b[$depName]['version'] != $i['version']) {
                return false;
            }
            if (isset($b[$depName]['dependencies']) != isset($i['dependencies'])) {
                return false;
            }
            if (isset($i['dependencies'])) {
                if (!$this->_shrinkwrapVersionsEqual($i['dependencies'], $b[$depName]['dependencies'])) {
                    return false;
                }
            }
        }
        foreach ($b as $depName=>$i) {
            if (!isset($a[$depName])) {
                return false;
            }
        }
        return true;
    }

    private function _createNpmBinaries() {
        // Let's link binaries, if any:
        $linkWriter = new LinkWriter($this->composer->getConfig()->get('bin-dir'));

        $binaries = glob("node_modules/.bin/*");
        foreach ($binaries as $binary) {
            $linkWriter->writeLink($binary);
        }
    }
}
