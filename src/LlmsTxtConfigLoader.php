<?php

declare(strict_types=1);

namespace Tomvondracek\LlmsTxt;

use Bolt\Configuration\Config;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Loads the extension config outside of the Extension object (controllers and
 * services can't use `BaseExtension::getConfig()`, which derives the filename
 * from the calling class's namespace).
 *
 * Same resolution as Bolt's `ConfigTrait`: the project's
 * `config/extensions/tomvondracek-llmstxt.yaml`, overridden by `…_local.yaml`,
 * falling back to the defaults shipped with the package.
 *
 * The config is kept for the rest of the request; `reset()` (called by Symfony
 * between requests of long-running runtimes) forgets it.
 */
class LlmsTxtConfigLoader implements ResetInterface
{
    public const CONFIG_BASENAME = 'tomvondracek-llmstxt';

    private ?LlmsTxtConfig $config = null;

    public function __construct(
        private readonly Config $boltConfig,
    ) {
    }

    public function load(): LlmsTxtConfig
    {
        if ($this->config instanceof LlmsTxtConfig) {
            return $this->config;
        }

        $directory = $this->boltConfig->getPath('extensions_config');
        $main = $directory . DIRECTORY_SEPARATOR . self::CONFIG_BASENAME . '.yaml';
        $local = $directory . DIRECTORY_SEPARATOR . self::CONFIG_BASENAME . '_local.yaml';

        $files = is_readable($main) ? [$main, $local] : [self::defaultConfigFile(), $local];

        return $this->config = LlmsTxtConfig::fromArray(self::parseFiles($files));
    }

    public function reset(): void
    {
        $this->config = null;
    }

    public static function defaultConfigFile(): string
    {
        return dirname(__DIR__) . '/config/config.yaml';
    }

    /**
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
