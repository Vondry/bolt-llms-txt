<?php

declare(strict_types=1);

namespace Tomvondracek\LlmsTxt;

use Bolt\Extension\BaseExtension;

final class Extension extends BaseExtension
{
    public function getName(): string
    {
        return 'llms.txt';
    }

    public function initialize(): void
    {
        // The @llms-txt namespace is registered at container level, in the
        // extension's config/services.yaml (copied to
        // config/packages/extension_bolt-llms-txt.yaml by `extensions:configure`),
        // so it also resolves on the CLI. This runtime registration is kept as a
        // fallback for projects that have not run `extensions:configure` yet.
        $this->addTwigNamespace('llms-txt');
    }
}
