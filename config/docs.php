<?php declare(strict_types=1);

return [
    'paths' => [
        base_path('app'),
    ],
    'output' => base_path('docs'),
    'commands' => [
        'build' => 'uvx -w mkdocs-material -w pymdown-extensions mkdocs build',
        'publish' => 'uvx -w mkdocs-material -w pymdown-extensions mkdocs gh-deploy',
        'serve' => 'uvx -w mkdocs-material -w pymdown-extensions mkdocs serve',
    ],
    'config' => [
        'site_name' => 'Xentral Functional Documentation',
        'docs_dir' => 'generated',
        'theme' => [
            'name' => 'material',
            'palette' => ['scheme' => 'default', 'primary' => 'indigo', 'accent' => 'indigo'],
            'features' => [
                'navigation.instant',
                'navigation.tracking',
                'navigation.top',
                'navigation.indexes',
                'content.diagram',
            ],
        ],
        'markdown_extensions' => [
            'admonition',
            'pymdownx.details',
            'attr_list',
            ['pymdownx.highlight' => ['anchor_linenums' => true]],
            'pymdownx.inlinehilite',
            ['pymdownx.superfences' => [
                'custom_fences' => [
                    [
                        'name' => 'mermaid',
                        'class' => 'mermaid',
                        'format' => '!!python/name:pymdownx.superfences.fence_code_format',
                    ],
                ],
            ]],
        ],
    ],
];
