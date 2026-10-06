<?php

declare(strict_types=1);

namespace Tomvondracek\LlmsTxt;

use Bolt\Configuration\Config;
use Symfony\Component\Yaml\Yaml;

/**
 * Loads the extension config outside of the Extension object (controllers and
 * services can't use `BaseExtension::getConfig()`, which derives the filename
 * from the calling class's namespace).
 *
 * Same resolution as Bolt's `ConfigTrait`: the project's
 * `config/extensions/tomvondracek-llmstxt.yaml`, overridden by `…_local.yaml`,
 * falling back to the defaults shipped with the package.
 */
final readonly class LlmsTxtConfigLoader
{
    public const CONFIG_BASENAME = 'tomvondracek-llmstxt';

    public function __construct(
        private Config $boltConfig,
    ) {
    }

    public function load(): LlmsTxtConfig
    {
        $directory = $this->boltConfig->getPath('extensions_config');
        $main = $directory . DIRECTORY_SEPARATOR . self::CONFIG_BASENAME . '.yaml';
        $local = $directory . DIRECTORY_SEPARATOR . self::CONFIG_BASENAME . '_local.yaml';

        $files = is_readable($main) ? [$main, $local] : [self::defaultConfigFile(), $local];

        return LlmsTxtConfig::fromArray(self::parseFiles($files));
    }

    /**
     * @internal
     */
    public static function defaultConfigFile(): string
    {
        return dirname(__DIR__) . '/config/config.yaml';
    }

    /**
     * @internal
     *
     * @param list<string> $files
     *
     * @return array<array-key, mixed>
     */
    public static function parseFiles(array $files): array
    {
        $config = [];

        foreach ($files as $file) {
            if (! is_readable($file)) {
                continue;
            }

            $parsed = Yaml::parseFile($file);
            if (is_array($parsed)) {
                $config = array_merge($config, $parsed);
            }
        }

        return $config;
    }
}
