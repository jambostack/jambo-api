<?php

namespace App\Dto;

class ExportOptions
{
    public bool $structure = true;
    public bool $content = false;
    public bool $media = false;
    public bool $settings = false;

    public static function fromRequest(array $data): self
    {
        $options = new self();
        if (isset($data['structure'])) { $options->structure = (bool) $data['structure']; }
        if (isset($data['content'])) { $options->content = (bool) $data['content']; }
        if (isset($data['media'])) { $options->media = (bool) $data['media']; }
        if (isset($data['settings'])) { $options->settings = (bool) $data['settings']; }
        return $options;
    }

    /** @return string[] */
    public function getEnabledOptions(): array
    {
        $enabled = [];
        if ($this->structure) { $enabled[] = 'structure'; }
        if ($this->content) { $enabled[] = 'content'; }
        if ($this->media) { $enabled[] = 'media'; }
        if ($this->settings) { $enabled[] = 'settings'; }
        return $enabled;
    }
}
