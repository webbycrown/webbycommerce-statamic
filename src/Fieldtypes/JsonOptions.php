<?php

namespace WebbyCrown\WebbyCommerceStatamic\Fieldtypes;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Statamic\Fieldtypes\Select;

class JsonOptions extends Select
{
    protected $component = 'select';
    protected $categories = ['controls'];
    protected $keywords = ['json', 'options', 'select', 'dropdown', 'file'];

    protected function configFieldItems(): array
    {
        return array_merge(parent::configFieldItems(), [
            [
                'display' => __('JSON Source'),
                'fields' => [
                    'json_file' => [
                        'display' => __('JSON file path'),
                        'instructions' => __('Path to a JSON file containing option data.'),
                        'type' => 'text',
                        'width' => 100,
                    ],
                    'json_root' => [
                        'display' => __('JSON root path'),
                        'instructions' => __('Optional dot path to the array of option objects inside the file.'),
                        'type' => 'text',
                        'width' => 100,
                    ],
                    'json_key' => [
                        'display' => __('JSON key'),
                        'instructions' => __('The key to use as the option value.'),
                        'type' => 'text',
                        'default' => 'id',
                        'width' => 50,
                    ],
                    'json_label' => [
                        'display' => __('JSON label'),
                        'instructions' => __('The key to use as the option label.'),
                        'type' => 'text',
                        'default' => 'name',
                        'width' => 50,
                    ],
                ],
            ],
        ]);
    }

    protected function getOptions(): array
    {
        $options = $this->config('options');

        if (empty($options) && $jsonFile = $this->config('json_file')) {
            if ($path = $this->resolveJsonFilePath($jsonFile)) {
                $options = $this->loadOptionsFromJsonFile($path);
            }
        }

        if ($options instanceof \Illuminate\Support\Collection) {
            $options = $options->all();
        }

        if (array_is_list($options) && ! is_array(Arr::first($options))) {
            $options = collect($options)
                ->map(fn ($value) => ['key' => $value, 'value' => $value])
                ->all();
        }

        if (Arr::isAssoc($options)) {
            $options = collect($options)
                ->map(fn ($value, $key) => ['key' => $key, 'value' => $value])
                ->all();
        }

        return collect($options)
            ->map(fn ($item) => ['value' => $item['key'], 'label' => $item['value']])
            ->values()
            ->all();
    }

    protected function resolveJsonFilePath(string $file): ?string
    {
        if (Str::startsWith($file, ['/','\\']) || preg_match('/^[A-Za-z]:\\\\/', $file)) {
            return File::exists($file) ? $file : null;
        }

        $candidates = [
            base_path($file),
            resource_path($file),
            config_path($file),
            base_path('vendor/webbycrown/webbycommerce-statamic/'.$file),
            __DIR__.'/../../'.$file,
        ];

        foreach ($candidates as $path) {
            if (File::exists($path)) {
                return $path;
            }
        }

        return null;
    }

    protected function loadOptionsFromJsonFile(string $path): array
    {
        $json = json_decode(File::get($path), true);

        if (! is_array($json)) {
            return [];
        }

        $jsonRoot = $this->config('json_root');
        if ($jsonRoot) {
            $json = Arr::get($json, $jsonRoot, []);
        }

        if (Arr::isAssoc($json)) {
            return $json;
        }

        $key = $this->config('json_key') ?? 'id';
        $label = $this->config('json_label') ?? 'name';

        return collect($json)
            ->mapWithKeys(function ($item) use ($key, $label) {
                if (! is_array($item)) {
                    return [$item => $item];
                }

                $itemKey = Arr::get($item, $key, null);
                $itemLabel = Arr::get($item, $label, null);

                if ($itemKey === null || $itemLabel === null) {
                    return [];
                }

                return [$itemKey => $itemLabel];
            })
            ->all();
    }
}
