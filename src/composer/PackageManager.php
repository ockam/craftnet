<?php

namespace craftcom\composer;

use Composer\Repository\PlatformRepository;
use Composer\Semver\Comparator;
use Composer\Semver\Semver;
use Composer\Semver\VersionParser;
use Craft;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\FileHelper;
use craft\helpers\Json;
use craftcom\composer\jobs\DeletePaths;
use craftcom\composer\jobs\UpdatePackage;
use craftcom\errors\MissingTokenException;
use yii\base\Component;
use yii\base\Exception;
use yii\helpers\Console;

class PackageManager extends Component
{
    /**
     * @var string[]|null
     */
    public $githubFallbackTokens;

    /**
     * @var bool Whether plugins *must* have VCS tokens
     */
    public $requirePluginVcsTokens = true;

    /**
     * @var string
     */
    public $composerWebroot;

    public function init()
    {
        parent::init();

        if (is_string($this->githubFallbackTokens)) {
            $this->githubFallbackTokens = array_filter(explode(',', $this->githubFallbackTokens));
        }
    }

    public function packageExists(string $name): bool
    {
        return (new Query())
            ->from(['craftcom_packages'])
            ->where(['name' => $name])
            ->exists();
    }

    public function packageUpdatedWithin(string $name, int $seconds): bool
    {
        $timestamp = Db::prepareDateForDb(new \DateTime("-{$seconds} seconds"));
        return (new Query())
            ->from(['craftcom_packages'])
            ->where(['name' => $name])
            ->andWhere('[[dateUpdated]] != [[dateCreated]]')
            ->andWhere(['>=', 'dateUpdated', $timestamp])
            ->exists();
    }

    public function packageVersionsExist(string $name, array $constraints): bool
    {
        // Get all of the known versions for the package
        $versions = (new Query())
            ->select(['version'])
            ->distinct()
            ->from(['craftcom_packageversions pv'])
            ->innerJoin(['craftcom_packages p'], '[[p.id]] = [[pv.packageId]]')
            ->where(['p.name' => $name])
            ->column();

        // Make sure each of the constraints is satisfied by at least one of those versions
        foreach ($constraints as $constraint) {
            $satisfied = false;
            foreach ($versions as $version) {
                if (Semver::satisfies($version, $constraint)) {
                    $satisfied = true;
                    break;
                }
            }
            if (!$satisfied) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param string $name    The package name
     * @param string $version The package version
     *
     * @return PackageRelease|null
     */
    public function getRelease(string $name, string $version)
    {
        $result = $this->_createReleaseQuery($name, $version)->one();

        if (!$result) {
            return null;
        }

        return new PackageRelease($result);
    }

    /**
     * @param string $name         The package name
     * @param string $minStability The minimum required stability (dev, alpha, beta, RC, or stable)
     * @param bool   $sort         Whether the versions should be sorted
     *
     * @return string[] The known package versions
     */
    public function getAllVersions(string $name, string $minStability = 'stable', bool $sort = true): array
    {
        $allowedStabilities = $this->_allowedStabilities($minStability);

        $versions = (new Query())
            ->select(['version'])
            ->distinct()
            ->from(['craftcom_packageversions pv'])
            ->innerJoin(['craftcom_packages p'], '[[p.id]] = [[pv.packageId]]')
            ->where(['p.name' => $name, 'pv.stability' => $allowedStabilities])
            ->column();

        if ($sort) {
            $this->_sortVersions($versions);
        }

        return $versions;
    }

    /**
     * @param string $name         The package name
     * @param string $minStability The minimum required stability (dev, alpha, beta, RC, or stable)
     *
     * @return string|null The latest version, or null if none can be found
     */
    public function getLatestVersion(string $name, string $minStability = 'stable')
    {
        // Get all the versions
        $versions = $this->getAllVersions($name, $minStability);

        // Return the last one
        return array_pop($versions);
    }

    /**
     * @param string $name         The package name
     * @param string $minStability The minimum required stability
     *
     * @return PackageRelease|null The latest release, or null if none can be found
     */
    public function getLatestRelease(string $name, string $minStability = 'stable')
    {
        $version = $this->getLatestVersion($name, $minStability);
        return $this->getRelease($name, $version);
    }

    /**
     * Returns all the versions after a given version
     *
     * @param string $name         The package name
     * @param string $from         The version that others should be after
     * @param string $minStability The minimum required stability
     * @param bool   $sort         Whether the versions should be sorted
     *
     * @return string[] The versions after $from, sorted oldest-to-newest
     */
    public function getVersionsAfter(string $name, string $from, string $minStability = 'stable', bool $sort = true): array
    {
        // Get all the versions
        $versions = $this->getAllVersions($name, $minStability, false);

        // Filter out the ones <= $from
        $versions = array_filter($versions, function($version) use ($from) {
            return Comparator::greaterThan($version, $from);
        });

        if ($sort) {
            $this->_sortVersions($versions);
        }

        return $versions;
    }

    /**
     * Returns all the releases after a given version
     *
     * @param string $name         The package name
     * @param string $from         The version that others should be after
     * @param string $minStability The minimum required stability
     *
     * @return PackageRelease[] The releases after $from, sorted oldest-to-newest
     */
    public function getReleasesAfter(string $name, string $from, string $minStability = 'stable'): array
    {
        $versions = $this->getVersionsAfter($name, $from, $minStability, false);
        $results = $this->_createReleaseQuery($name, $versions)->all();
        $releases = [];

        foreach ($results as $result) {
            $releases[] = new PackageRelease($result);
        }

        // Sort them oldest-to-newest
        $this->_sortVersions($releases);

        return $releases;
    }

    /**
     * @param string $minStability The minimum required stability (dev, alpha, beta, RC, or stable)
     *
     * @return string[]
     */
    private function _allowedStabilities(string $minStability = 'stable'): array
    {
        $allowedStabilities = [];
        switch ($minStability) {
            case 'dev':
                $allowedStabilities[] = 'dev';
            // no break
            case 'alpha':
                $allowedStabilities[] = 'alpha';
            // no break
            case 'beta':
                $allowedStabilities[] = 'beta';
            // no break
            case 'RC':
                $allowedStabilities[] = 'RC';
            // no break
            default:
                $allowedStabilities[] = 'stable';
        }

        return $allowedStabilities;
    }

    /**
     * Sorts a given list of versions from oldest => newest
     *
     * @param string[]|PackageRelease[] &$versions
     */
    private function _sortVersions(array &$versions)
    {
        usort($versions, function($a, $b): int {
            if ($a instanceof PackageRelease) {
                $a = $a->version;
            }
            if ($b instanceof PackageRelease) {
                $b = $b->version;
            }

            if (Comparator::equalTo($a, $b)) {
                return 0;
            }
            return Comparator::lessThan($a, $b) ? -1 : 1;
        });
    }

    /**
     * @param string $name    The dependency package name
     * @param string $version The dependency package version
     *
     * @return bool Whether any managed packages require this dependency/version
     */
    public function isDependencyVersionRequired(string $name, string $version): bool
    {
        $constraints = (new Query())
            ->select(['constraints'])
            ->distinct()
            ->from(['craftcom_packagedeps'])
            ->where(['name' => $name])
            ->column();

        foreach ($constraints as $constraint) {
            if (Semver::satisfies($version, $constraint)) {
                return true;
            }
        }

        return false;
    }

    public function savePackage(Package $package)
    {
        $data = [
            'name' => $package->name,
            'type' => $package->type,
            'managed' => $package->managed,
            'repository' => $package->repository,
            'abandoned' => $package->abandoned,
            'replacementPackage' => $package->replacementPackage,
        ];

        $db = Craft::$app->getDb();

        if ($package->id === null) {
            $db->createCommand()
                ->insert('craftcom_packages', $data)
                ->execute();
            $package->id = $db->getLastInsertID();
        } else {
            $db->createCommand()
                ->update('craftcom_packages', $data, ['id' => $package->id])
                ->execute();
        }
    }

    public function removePackage(string $name)
    {
        Craft::$app->getDb()->createCommand()
            ->delete('craftcom_packages', ['name' => $name])
            ->execute();
    }

    public function getPackage(string $name): Package
    {
        $result = $this->_createPackageQuery()
            ->where(['name' => $name])
            ->one();
        if (!$result) {
            throw new Exception('Invalid package name: '.$name);
        }
        return new Package($result);
    }

    public function getPackageById(int $id): Package
    {
        $result = $this->_createPackageQuery()
            ->where(['id' => $id])
            ->one();
        if (!$result) {
            throw new Exception('Invalid package ID: '.$id);
        }
        return new Package($result);
    }

    private function _createPackageQuery(): Query
    {
        return (new Query())
            ->select(['id', 'name', 'type', 'repository', 'managed', 'latestVersion', 'abandoned', 'replacementPackage'])
            ->from(['craftcom_packages']);
    }

    /**
     * @param string $name
     * @param string|string[] $version
     *
     * @return Query
     */
    private function _createReleaseQuery(string $name, $version): Query
    {
        $vp = new VersionParser();

        if (is_array($version)) {
            foreach ($version as $k => $v) {
                $version[$k] = $vp->normalize($v);
            }
        } else {
            $version = $vp->normalize($version);
        }

        return (new Query())
            ->select([
                'pv.id',
                'pv.packageId',
                'pv.sha',
                'pv.description',
                'pv.version',
                'pv.type',
                'pv.keywords',
                'pv.homepage',
                'pv.time',
                'pv.license',
                'pv.authors',
                'pv.support',
                'pv.conflict',
                'pv.replace',
                'pv.provide',
                'pv.suggest',
                'pv.autoload',
                'pv.includePaths',
                'pv.targetDir',
                'pv.extra',
                'pv.binaries',
                'pv.source',
                'pv.dist',
                'pv.changelog',
            ])
            ->from(['craftcom_packageversions pv'])
            ->innerJoin(['craftcom_packages p'], '[[p.id]] = [[pv.packageId]]')
            ->where(['p.name' => $name, 'pv.normalizedVersion' => $version]);
    }

    /**
     * @param string $name  The Composer package name
     * @param bool   $force Whether to update package releases even if their SHA hasn't changed
     *
     * @throws MissingTokenException if the package is a plugin, but we don't have a VCS token for it
     */
    public function updatePackage(string $name, bool $force = false)
    {
        $package = $this->getPackage($name);
        $vcs = $package->getVcs();
        $plugin = $package->getPlugin();
        $db = Craft::$app->getDb();
        $isConsole = Craft::$app->getRequest()->getIsConsoleRequest();

        if ($isConsole) {
            Console::output("Updating version data for {$name}...");
        }

        // Get all of the already known versions
        $storedVersionInfo = (new Query())
            ->select(['id', 'version', 'sha'])
            ->from(['craftcom_packageversions'])
            ->where(['packageId' => $package->id])
            ->indexBy('version')
            ->all();

        // Get the versions from the VCS
        $versionStability = [];
        $vcsVersionShas = array_filter($vcs->getVersions(), function($version) use ($package, &$versionStability) {
            // Don't include development versions, and versions that aren't actually required by any managed packages
            if (($stability = VersionParser::parseStability($version)) === 'dev') {
                return false;
            }
            $versionStability[$version] = $stability;
            return ($package->managed || $this->isDependencyVersionRequired($package->name, $version));
        }, ARRAY_FILTER_USE_KEY);

        // See which already-stored versions have been deleted/updated
        $storedVersions = array_keys($storedVersionInfo);
        $vcsVersions = array_keys($vcsVersionShas);

        $deletedVersions = array_diff($storedVersions, $vcsVersions);
        $newVersions = array_diff($vcsVersions, $storedVersions);
        $updatedVersions = [];

        foreach (array_intersect($storedVersions, $vcsVersions) as $version) {
            if ($force || $storedVersionInfo[$version]['sha'] !== $vcsVersionShas[$version]) {
                $updatedVersions[] = $version;
            }
        }

        if ($isConsole) {
            Console::stdout(Console::ansiFormat('- new: ', [Console::FG_YELLOW]));
            Console::output(count($newVersions));
            Console::stdout(Console::ansiFormat('- updated: ', [Console::FG_YELLOW]));
            Console::output(count($updatedVersions));
            Console::stdout(Console::ansiFormat('- deleted: ', [Console::FG_YELLOW]));
            Console::output(count($deletedVersions));
        }

        if (!empty($deletedVersions) || !empty($updatedVersions)) {
            if ($isConsole) {
                Console::stdout('Deleting old versions ... ');
            }

            $versionIdsToDelete = [];
            foreach (array_merge($deletedVersions, $updatedVersions) as $version) {
                $versionIdsToDelete[] = $storedVersionInfo[$version]['id'];
            }

            $db->createCommand()
                ->delete('craftcom_packageversions', ['id' => $versionIdsToDelete])
                ->execute();

            if ($isConsole) {
                Console::output('done');
            }
        }

        // We can treat "updated" versions as "new" now.
        $newVersions = array_merge($updatedVersions, $newVersions);

        // Bail early if there's nothing new
        if (empty($newVersions)) {
            if ($isConsole) {
                Console::output('No new versions to process');
            }
            return;
        }

        if ($isConsole) {
            Console::output('Processing new versions ...');
        }

        // Sort by newest => oldest
        usort($newVersions, function(string $version1, string $version2): int {
            if (Comparator::lessThan($version1, $version2)) {
                return 1;
            }
            if (Comparator::equalTo($version1, $version2)) {
                return 0;
            }
            return -1;
        });

        $packageDeps = [];
        $latestVersion = null;
        $foundStable = false;

        foreach ($newVersions as $version) {
            $sha = $vcsVersionShas[$version];
            if (!$foundStable && $versionStability[$version] === 'stable') {
                $latestVersion = $version;
                $foundStable = true;
            } else if ($latestVersion === null) {
                $latestVersion = $version;
            }

            if ($isConsole) {
                Console::stdout(Console::ansiFormat("- processing {$version} ({$sha}) ... ", [Console::FG_YELLOW]));
            }

            $release = new PackageRelease([
                'packageId' => $package->id,
                'version' => $version,
                'sha' => $sha,
            ]);
            $vcs->populateRelease($release);
            $this->savePackageVersion($release);

            if (!empty($release->require)) {
                $depValues = [];
                foreach ($release->require as $depName => $constraints) {
                    $depValues[] = [$package->id, $release->id, $depName, $constraints];
                    if (
                        $depName !== '__root__' &&
                        $depName !== 'composer-plugin-api' &&
                        !preg_match(PlatformRepository::PLATFORM_PACKAGE_REGEX, $depName) &&
                        strpos($depName, 'bower-asset/') !== 0 &&
                        strpos($depName, 'npm-asset/') !== 0
                    ) {
                        $packageDeps[$depName][$constraints] = true;
                    }
                }
                $db->createCommand()
                    ->batchInsert('craftcom_packagedeps', ['packageId', 'versionId', 'name', 'constraints'], $depValues)
                    ->execute();
            }

            if ($isConsole) {
                Console::output(Console::ansiFormat('done', [Console::FG_YELLOW]));
            }
        }

        // Update the package's latestVersion and dateUpdated
        $db->createCommand()
            ->update('craftcom_packages', ['latestVersion' => $latestVersion], ['id' => $package->id])
            ->execute();

        if ($plugin && $latestVersion !== $plugin->latestVersion) {
            $plugin->latestVersion = $latestVersion;
            $db->createCommand()
                ->update('craftcom_plugins', ['latestVersion' => $latestVersion], ['id' => $plugin->id])
                ->execute();
        }

        // For each dependency, see if we already have a version that satisfies the conditions
        if (!empty($packageDeps)) {
            $depsToUpdate = [];
            foreach ($packageDeps as $depName => $depVersions) {
                $update = false;
                if (!$this->packageExists($depName)) {
                    if ($isConsole) {
                        Console::stdout("Adding dependency {$depName} ... ");
                    }
                    $this->savePackage(new Package([
                        'name' => $depName,
                        'type' => 'library',
                        'managed' => false,
                    ]));
                    if ($isConsole) {
                        Console::output('done');
                    }
                    $update = true;
                } else if (!$this->packageVersionsExist($depName, array_keys($depVersions))) {
                    $update = true;
                }
                if ($update) {
                    $depsToUpdate[] = $depName;
                }
            }

            if (!empty($depsToUpdate)) {
                $queue = Craft::$app->getQueue();
                foreach ($depsToUpdate as $depName) {
                    $queue->push(new UpdatePackage([
                        'name' => $depName,
                    ]));
                    if ($isConsole) {
                        Console::output("{$depName} is queued to be updated");
                    }
                }
            }
        }

        if ($isConsole) {
            Console::output('Done processing '.count($newVersions).' versions');
        }
    }

    public function savePackageVersion(PackageRelease $release)
    {
        $db = Craft::$app->getDb();
        $db->createCommand()
            ->insert('craftcom_packageversions', [
                'packageId' => $release->packageId,
                'sha' => $release->sha,
                'description' => $release->description,
                'version' => $release->version,
                'normalizedVersion' => $release->getNormalizedVersion(),
                'stability' => $release->getStability(),
                'type' => $release->type,
                'keywords' => $release->keywords ? Json::encode($release->keywords) : null,
                'homepage' => $release->homepage,
                'time' => $release->time,
                'license' => $release->license ? Json::encode($release->license) : null,
                'authors' => $release->authors ? Json::encode($release->authors) : null,
                'support' => $release->support ? Json::encode($release->support) : null,
                'conflict' => $release->conflict ? Json::encode($release->conflict) : null,
                'replace' => $release->replace ? Json::encode($release->replace) : null,
                'provide' => $release->provide ? Json::encode($release->provide) : null,
                'suggest' => $release->suggest ? Json::encode($release->suggest) : null,
                'autoload' => $release->autoload ? Json::encode($release->autoload) : null,
                'includePaths' => $release->includePaths ? Json::encode($release->includePaths) : null,
                'targetDir' => $release->targetDir,
                'extra' => $release->extra ? Json::encode($release->extra) : null,
                'binaries' => $release->binaries ? Json::encode($release->binaries) : null,
                'source' => $release->source ? Json::encode($release->source) : null,
                'dist' => $release->dist ? Json::encode($release->dist) : null,
                'changelog' => $release->changelog,
            ])
            ->execute();
        $release->id = $db->getLastInsertID();
    }

    public function dumpProviderJson()
    {
        // Fetch all the data
        $packages = $this->_createPackageQuery()
            ->select(['id', 'name', 'abandoned', 'replacementPackage'])
            ->where(['not', ['latestVersion' => null]])
            ->indexBy('id')
            ->all();
        $versions = (new Query())
            ->select([
                'id',
                'packageId',
                'description',
                'version',
                'normalizedVersion',
                'type',
                'keywords',
                'homepage',
                'time',
                'license',
                'authors',
                //'support',
                'conflict',
                'replace',
                'provide',
                'suggest',
                'autoload',
                'includePaths',
                'targetDir',
                'extra',
                'binaries',
                //'source',
                'dist',
            ])
            ->from(['craftcom_packageversions'])
            ->indexBy('id')
            ->all();
        $deps = (new Query())
            ->select(['versionId', 'name', 'constraints'])
            ->from(['craftcom_packagedeps'])
            ->all();

        // Assemble the data
        $depsByVersion = [];
        foreach ($deps as $dep) {
            $depsByVersion[$dep['versionId']][] = $dep;
//            $name = $packages[$dep['packageId']]['name'];
//            $version = $versions[$dep['versionId']]['version'];
//            $providers[$name]['packages'][$name][$version]['require'][$dep['name']] = $dep['constraints'];
        }

        $providers = [];

        foreach ($versions as $version) {
            $package = $packages[$version['packageId']];
            $name = $package['name'];

            if (isset($depsByVersion[$version['id']])) {
                $require = [];
                foreach ($depsByVersion[$version['id']] as $dep) {
                    $require[$dep['name']] = $dep['constraints'];
                }
            } else {
                $require = null;
            }

            // Assemble in the same order as \Packagist\WebBundle\Entity\Version::toArray()
            // `support` and `source` are intentionally ignored for now.
            $data = [
                'name' => $name,
                'description' => (string)$version['description'],
                'keywords' => $version['keywords'] ? Json::decode($version['keywords']) : [],
                'homepage' => (string)$version['homepage'],
                'version' => $version['version'],
                'version_normalized' => $version['normalizedVersion'],
                'license' => $version['license'] ? Json::decode($version['license']) : [],
                'authors' => $version['authors'] ? Json::decode($version['authors']) : [],
                'dist' => $version['dist'] ? Json::decode($version['dist']) : null,
                'type' => $version['type'],
            ];

            if ($version['time'] !== null) {
                $data['time'] = $version['time'];
            }
            if ($version['autoload'] !== null) {
                $data['autoload'] = Json::decode($version['autoload']);
            }
            if ($version['extra'] !== null) {
                $data['extra'] = Json::decode($version['extra']);
            }
            if ($version['targetDir'] !== null) {
                $data['target-dir'] = $version['targetDir'];
            }
            if ($version['includePaths'] !== null) {
                $data['include-path'] = $version['includePaths'];
            }
            if ($version['binaries'] !== null) {
                $data['bin'] = Json::decode($version['binaries']);
            }
            if ($require !== null) {
                $data['require'] = $require;
            }
            if ($version['suggest'] !== null) {
                $data['suggest'] = Json::decode($version['suggest']);
            }
            if ($version['conflict'] !== null) {
                $data['conflict'] = Json::decode($version['conflict']);
            }
            if ($version['provide'] !== null) {
                $data['provide'] = Json::decode($version['provide']);
            }
            if ($version['replace'] !== null) {
                $data['replace'] = Json::decode($version['replace']);
            }
            if ($package['abandoned']) {
                $data['abandoned'] = $package['replacementPackage'] ?: true;
            }
            $data['uid'] = (int)$version['id'];

            $providers[$name]['packages'][$name][$version['version']] = $data;
        }

        // Create the JSON files
        $oldPaths = [];
        $indexData = [];

        foreach ($providers as $name => $providerData) {
            $providerHash = $this->_writeJsonFile($providerData, "{$this->composerWebroot}/p/{$name}/%hash%.json", $oldPaths);
            $indexData['providers'][$name] = ['sha256' => $providerHash];
        }

        $indexPath = 'p/provider/%hash%.json';
        $indexHash = $this->_writeJsonFile($indexData, "{$this->composerWebroot}/{$indexPath}", $oldPaths);

        $rootData = [
            'packages' => [],
            'provider-includes' => [
                $indexPath => ['sha256' => $indexHash],
            ],
            'providers-url' => '/p/%package%/%hash%.json',
        ];

        FileHelper::writeToFile("{$this->composerWebroot}/packages.json", Json::encode($rootData));

        if (!empty($oldPaths)) {
            Craft::$app->getQueue()->delay(60 * 5)->push(new DeletePaths([
                'paths' => $oldPaths,
            ]));
        }
    }

    /**
     * Writes a new JSON file and returns its hash.
     *
     * @param array  $data     The data to write
     * @param string $path     The path to save the content (can contain a %hash% tag)
     * @param array  $oldPaths Array of existing files that should be deleted
     *
     * @return string
     */
    private function _writeJsonFile(array $data, string $path, &$oldPaths): string
    {
        $content = Json::encode($data);
        $hash = hash('sha256', $content);
        $path = str_replace('%hash%', $hash, $path);

        // If nothing's changed, we're done
        if (file_exists($path)) {
            return $hash;
        }

        // Mark any existing files in there for deletion
        $dir = dirname($path);
        if (is_dir($dir) && ($handle = opendir($dir))) {
            while (($file = readdir($handle)) !== false) {
                if ($file === '.' || $file === '..') {
                    continue;
                }
                $oldPaths[] = $dir.'/'.$file;
            }
            closedir($handle);
        }

        // Write the new file
        FileHelper::writeToFile($path, $content);

        return $hash;
    }

    /**
     * Returns a random fallback GitHub API token.
     *
     * @return string|null
     */
    public function getRandomGitHubFallbackToken()
    {
        if (empty($this->githubFallbackTokens)) {
            return null;
        }

        $key = array_rand($this->githubFallbackTokens);
        return $this->githubFallbackTokens[$key];
    }
}
