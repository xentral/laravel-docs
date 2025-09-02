<?php declare(strict_types=1);

namespace Workbench\App;

use Laravel\Boost\Install\GuidelineComposer;

class PackageGuidelineComposer extends GuidelineComposer
{
    protected string $userGuidelineDir = '../../../../.ai/guidelines';
}
