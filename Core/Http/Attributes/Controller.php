<?php

#[Attribute(Attribute::TARGET_CLASS)]
class Controller
{
    public function __construct(public readonly string $basePath) {}
}
