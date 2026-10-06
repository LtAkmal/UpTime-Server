<?php

namespace Pterodactyl\Uptime\Services;

use Illuminate\Support\Facades\Cache;

/**
 * What this collector says about its own code. release.json is written by the
 * UpTime-Server installer from a Git checkout; the digest is recomputed from the
 * installed files so drift from the release is visible. This is a self-check: an
 * operator with root access could change both, which the public page states.
 */
class ReleaseInfo
{
    public function __construct(private UptimeSettings $settings)
    {
    }

    /**
     * @return array{version: string, commit: string, repository: string, release_digest: string|null, installed_digest: string, matches: bool|null, installed_at: string|null}
     */
    public function collector(): array
    {
        $file = __DIR__ . '/../release.json';
        $release = is_file($file) ? (json_decode((string) file_get_contents($file), true) ?: []) : [];
        $commit = (string) ($release['commit'] ?? 'unknown');
        $digest = Cache::remember('uptime:collector-digest', 600, fn () => self::digest(dirname(__DIR__)));
        $releaseDigest = isset($release['digest']) && preg_match('/^[0-9a-f]{64}$/', (string) $release['digest']) ? (string) $release['digest'] : null;

        return [
            'version' => (string) ($release['version'] ?? 'dev'),
            'commit' => preg_match('/^[0-9a-f]{7,40}$/', $commit) === 1 ? $commit : 'unknown',
            'repository' => $this->settings->repositoryUrl(),
            'release_digest' => $releaseDigest,
            'installed_digest' => $digest,
            'matches' => $releaseDigest ? hash_equals($releaseDigest, $digest) : null,
            'installed_at' => isset($release['installed_at']) ? (string) $release['installed_at'] : null,
        ];
    }

    /**
     * SHA-256 over "sha256(file)  relative/path\n" lines sorted by path, excluding
     * release.json; the same as `find . -type f ! -name release.json | LC_ALL=C sort |
     * xargs sha256sum | sha256sum` in the collector directory (scripts/collector-digest.sh).
     */
    public static function digest(string $dir): string
    {
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            $relative = substr($file->getPathname(), strlen($dir) + 1);
            if ($file->isFile() && $relative !== 'release.json') {
                $files[$relative] = hash_file('sha256', $file->getPathname());
            }
        }
        ksort($files, SORT_STRING);
        $manifest = '';
        foreach ($files as $path => $hash) {
            $manifest .= $hash . '  ' . $path . "\n";
        }

        return hash('sha256', $manifest);
    }

    /**
     * Commit URL in the public repository, only for a real commit hash.
     */
    public function commitUrl(string $commit): ?string
    {
        $repo = $this->settings->repositoryUrl();

        return $repo !== '' && preg_match('/^[0-9a-f]{7,40}$/', $commit) === 1 ? $repo . '/commit/' . $commit : null;
    }
}
