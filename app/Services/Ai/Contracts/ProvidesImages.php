<?php

namespace App\Services\Ai\Contracts;

/**
 * A tool whose last result comes with images for the model to look at. Tool
 * results are text, so the run appends these parts as one user message after
 * the turn's tool results.
 */
interface ProvidesImages
{
    /**
     * Content parts in the transcript's provider-neutral shape (`text`, and
     * `image` with base64 `data`), or [] when the last call attached none.
     *
     * @return list<array<string, mixed>>
     */
    public function takeImages(): array;
}
