<?php

declare(strict_types=1);

namespace Marrow\Module;

/**
 * Discovers modules declared by installed Composer packages, so a package
 * like `acme/blog-module` can be enabled just by requiring it — no manual
 * edit to config/modules.php needed (though one can still be added to
 * opt out via config/modules.php's 'disabled' key).
 *
 * A package opts in by adding to its own composer.json:
 *
 *   "extra": {
 *       "marrow": {
 *           "modules": ["Acme\\BlogModule\\BlogModule"]
 *       }
 *   }
 *
 * This reads vendor/composer/installed.json directly — a file Composer
 * itself always generates on install/update — rather than requiring a
 * custom Composer plugin. Fails silently (returns an empty list) if that
 * file is missing or malformed: package discovery is a convenience, not
 * something a broken/unusual install setup should be able to hard-crash
 * boot over.
 */
final class PackageDiscovery
{
    /** @return string[] FQCNs of modules declared by installed packages, deduplicated. */
    public static function discover(string $basePath): array
    {
        $installedPath = rtrim($basePath, '/\\') . '/vendor/composer/installed.json';

        if (!is_file($installedPath)) {
            return [];
        }

        try {
            $data = json_decode((string) file_get_contents($installedPath), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return [];
        }

        // Composer 2 wraps the package list under a top-level 'packages' key;
        // treat a flat list (older/alternate layouts) the same way.
        $packages = is_array($data) && array_key_exists('packages', $data) ? $data['packages'] : $data;

        $modules = [];
        foreach ((array) $packages as $package) {
            if (!is_array($package)) {
                continue;
            }
            $declared = $package['extra']['marrow']['modules'] ?? [];
            foreach ((array) $declared as $fqcn) {
                if (is_string($fqcn) && $fqcn !== '') {
                    $modules[] = $fqcn;
                }
            }
        }

        return array_values(array_unique($modules));
    }
}
