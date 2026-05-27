<?php

#[Attribute(Attribute::TARGET_METHOD)]
class Delete
{
    public function __construct(public readonly string $path) {}
}
