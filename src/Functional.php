<?php
declare(strict_types=1);

namespace Xentral\LaravelDocs;

#[\Attribute(\Attribute::TARGET_CLASS)]
class Functional
{
    public function __construct(
        public string $nav,
        public string $text,
        public array $uses = [],
        public array $links = [],
    ) {}
}
